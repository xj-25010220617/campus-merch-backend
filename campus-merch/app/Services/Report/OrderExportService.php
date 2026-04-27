<?php

namespace App\Services\Report;

use App\Models\Order;
use App\Services\File\OssUploadService;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderExportService
{
    /**
     * 导出列定义（动态映射）
     */
    private const COLUMNS = [
        'order_id' => ['label' => '订单编号', 'width' => 10],
        'user_name' => ['label' => '预订人', 'width' => 15],
        'product_name' => ['label' => '商品名称', 'width' => 25],
        'category' => ['label' => '分类', 'width' => 12],
        'quantity' => ['label' => '数量', 'width' => 8],
        'unit_price' => ['label' => '单价', 'width' => 12],
        'total_price' => ['label' => '总价', 'width' => 12],
        'size' => ['label' => '尺寸/规格', 'width' => 12],
        'color' => ['label' => '颜色偏好', 'width' => 12],
        'status' => ['label' => '状态', 'width' => 14],
        'shipping_address' => ['label' => '发货地址', 'width' => 35],
        'remark' => ['label' => '备注', 'width' => 25],
        'design_file_url' => ['label' => '定制稿链接', 'width' => 50],
        'created_at' => ['label' => '下单时间', 'width' => 20],
    ];

    public function __construct(
        private readonly OssUploadService $ossService
    ) {}

    /**
     * 导出订单报表为 Excel 文件（流式输出，防 OOM）
     *
     * @param array<string, mixed> $filters 筛选条件：status, start_date, end_date, keyword, product_id
     */
    public function export(array $filters = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($filters) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('订单发货清单');

            // 写入表头
            $colIndex = 1;
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4472C4']],
                'alignment' => ['horizontal' => 'center'],
            ];
            foreach (self::COLUMNS as $_key => $config) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                $cell = $sheet->getCell("{$colLetter}1");
                $cell->setValue($config['label']);
                $colIndex++;
            }
            // 应用表头样式
            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

            // 分块查询数据并逐行写入（避免一次性加载全量数据到内存）
            $query = $this->buildQuery($filters);
            $rowIndex = 2;

            $query->chunk(200, function ($orders) use ($sheet, &$rowIndex) {
                foreach ($orders as $order) {
                    $this->writeRow($sheet, $rowIndex, $order);
                    $rowIndex++;
                }

                // 每写完一个 chunk 后清理循环引用，降低内存峰值
                if ($rowIndex % 500 === 0) {
                    gc_collect_cycles();
                }
            });

            // 自动调整列宽
            $colIdx = 1;
            foreach (self::COLUMNS as $_key => $config) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $column = $sheet->getColumnDimension($colLetter);
                $column->setWidth($config['width']);
                $colIdx++;
            }

            // 冻结首行
            $sheet->freezePane('A2');

            // 流式写入输出
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

            // 显式释放内存
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $writer);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="orders_export_' . date('Y_m_d_His') . '.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * 构建带筛选条件的查询
     */
    private function buildQuery(array $filters): Builder
    {
        $query = Order::with(['user:id,name,email', 'product:id,name,category', 'attachments'])
            ->orderByDesc('id');

        // 按状态筛选
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 按时间范围筛选
        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        // 按商品 ID 筛选
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        // 关键词搜索（用户名、商品名、备注）
        if (! empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('user', fn ($sq) => $sq->where('name', 'like', $keyword))
                  ->orWhereHas('product', fn ($sq) => $sq->where('name', 'like', $keyword))
                  ->orWhere('remark', 'like', $keyword)
                  ->orWhere('shipping_address', 'like', $keyword);
            });
        }

        return $query;
    }

    /**
     * 将单条订单数据写入 Excel 行
     */
    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowIndex, Order $order): void
    {
        $colIndex = 1;
        $data = $this->mapOrderToRowData($order);

        foreach (self::COLUMNS as $_key => $__config) {
            $value = $data[$_key] ?? '';
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue("{$colLetter}{$rowIndex}", $value);
            $colIndex++;
        }
    }

    /**
     * 将订单模型映射为行数据（含 OSS 临时链接）
     */
    private function mapOrderToRowData(Order $order): array
    {
        // 获取有效定制稿的临时访问链接
        $designUrls = $order->attachments
            ->where('is_deleted', false)
            ->map(fn ($att) => $this->ossService->getTemporaryUrl($att->storage_path))
            ->filter()
            ->values()
            ->toArray();

        // 状态中文映射
        $statusLabels = [
            'draft' => '草稿',
            'booked' => '已预订',
            'design_pending' => '待审核',
            'ready' => '制作中',
            'completed' => '已完成',
            'rejected' => '已驳回',
        ];

        return [
            'order_id' => $order->id,
            'user_name' => $order->user?->name ?? '-',
            'product_name' => $order->product?->name ?? '-',
            'category' => $order->product?->category ?? '-',
            'quantity' => $order->quantity,
            'unit_price' => number_format((float) $order->unit_price, 2),
            'total_price' => number_format((float) $order->total_price, 2),
            'size' => $order->size ?? '',
            'color' => $order->color ?? '',
            'status' => $statusLabels[$order->status] ?? $order->status,
            'shipping_address' => $order->shipping_address ?? '',
            'remark' => $order->remark ?? '',
            'design_file_url' => implode("\n", $designUrls),
            'created_at' => $order->created_at?->format('Y-m-d H:i') ?? '',
        ];
    }
}
