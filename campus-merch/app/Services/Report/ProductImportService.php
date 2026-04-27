<?php

namespace App\Services\Report;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class ProductImportService
{
    /**
     * 导入模板列定义（首行）
     */
    private const TEMPLATE_COLUMNS = [
        'name' => ['required' => true, 'label' => '商品名称'],
        'category' => ['required' => true, 'label' => '分类'],
        'type' => ['required' => true, 'label' => '类型'],
        'spec' => ['required' => false, 'label' => '规格说明'],
        'price' => ['required' => true, 'label' => '单价'],
        'stock' => ['required' => true, 'label' => '库存数量'],
        'cover_url' => ['required' => false, 'label' => '封面图URL'],
        'custom_rule' => ['required' => false, 'label' => '定制规则'],
    ];

    /**
     * 最大允许导入行数（含表头）
     */
    private const MAX_ROWS = 2001;

    /**
     * 从 Excel 文件批量导入商品
     *
     * 策略：合法数据入库，非法数据跳过并返回行级错误明细（部分成功）
     */
    public function import(UploadedFile $file): array
    {
        // 解析 Excel 文件获取原始数据
        $rows = $this->parseExcel($file);

        $errors = [];
        $validData = [];
        $startRow = 2; // 第1行为表头

        foreach ($rows as $rowIndex => $rowData) {
            $excelRow = $startRow + $rowIndex;
            $rowErrors = $this->validateRow($rowData, $excelRow);

            if (! empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
                continue;
            }

            $validData[] = $this->normalizeProductData($rowData);
        }

        // 批量写入数据库（事务包裹，全部成功或全部回滚合法部分）
        $insertedCount = 0;
        if (! empty($validData)) {
            \DB::transaction(function () use ($validData, &$insertedCount) {
                foreach ($validData as $data) {
                    // 按 name+category 做唯一判断，存在则更新，不存在则创建（upsert 语义）
                    Product::updateOrCreate(
                        [
                            'name' => $data['name'],
                            'category' => $data['category'],
                        ],
                        array_merge($data, [
                            'status' => 'active',
                        ])
                    );
                    $insertedCount++;
                }
            });
        }

        return [
            'success_count' => $insertedCount,
            'fail_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 解析 Excel 文件为二维数组
     *
     * @return array<int, array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    private function parseExcel(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            if ($highestRow > self::MAX_ROWS) {
                throw new \InvalidArgumentException(
                    "导入文件行数({$highestRow})超过限制(" . (self::MAX_ROWS - 1) . "条数据)"
                );
            }

            // 读取表头（第一行），映射列名
            $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, false)[0] ?? [];
            $headerMap = $this->buildHeaderMap($headerRow);

            if (empty(array_filter($headerMap))) {
                throw new \InvalidArgumentException(
                    'Excel 表头为空或无法识别。请使用标准模板格式：name, category, type, spec, price, stock, cover_url, custom_rule'
                );
            }

            // 读取数据行
            $dataRows = [];
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = $sheet->rangeToArray(
                    "A{$row}:" . $sheet->getHighestColumn() . "{$row}",
                    null,
                    true,
                    false
                )[0] ?? [];

                // 跳过完全空白的行
                if (! array_filter($rowData, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }

                // 将数据按列名映射
                $mappedRow = [];
                foreach ($headerMap as $colIndex => $columnName) {
                    if ($columnName !== null) {
                        $mappedRow[$columnName] = $rowData[$colIndex] ?? null;
                    }
                }
                $dataRows[] = $mappedRow;
            }

            return $dataRows;
        } catch (ReaderException $e) {
            throw new \InvalidArgumentException('无法解析该 Excel 文件，请确保文件格式正确（支持 .xlsx, .xls, .csv）。');
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw new \InvalidArgumentException('读取 Excel 文件时出错：' . $e->getMessage());
        }
    }

    /**
     * 构建列名映射（从表头行到标准字段名）
     *
     * @param array<int, string|null> $headerRow
     * @return array<int, string|null>
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        $knownColumns = array_keys(self::TEMPLATE_COLUMNS);

        foreach ($headerRow as $index => $cellValue) {
            $trimmed = trim((string) $cellValue);
            if (in_array($trimmed, $knownColumns, true)) {
                $map[$index] = $trimmed;
            } else {
                $map[$index] = null;
            }
        }

        return $map;
    }

    /**
     * 校验单行数据
     *
     * @return array<int, array{row: int, field: string, reason: string}>
     */
    private function validateRow(array $row, int $excelRow): array
    {
        $errors = [];

        foreach (self::TEMPLATE_COLUMNS as $field => $config) {
            $value = trim((string) ($row[$field] ?? ''));

            // 必填校验
            if ($config['required'] && $value === '') {
                $errors[] = [
                    'row' => $excelRow,
                    'field' => $field,
                    'reason' => "【{$config['label']}】为必填项",
                ];
                continue;
            }

            // 跳过非必填且为空的字段
            if (! $config['required'] && $value === '') {
                continue;
            }

            // 字段特定校验
            switch ($field) {
                case 'price':
                    $priceVal = filter_var($value, FILTER_VALIDATE_FLOAT);
                    if ($priceVal === false || $priceVal <= 0) {
                        $errors[] = [
                            'row' => $excelRow,
                            'field' => $field,
                            'reason' => '价格必须为正数',
                        ];
                    }
                    break;

                case 'stock':
                    $stockVal = filter_var($value, FILTER_VALIDATE_INT);
                    if ($stockVal === false || $stockVal < 0) {
                        $errors[] = [
                            'row' => $excelRow,
                            'field' => $field,
                            'reason' => '库存不能为负数',
                        ];
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * 标准化商品数据，转换为数据库可接受的格式
     */
    private function normalizeProductData(array $row): array
    {
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'category' => trim((string) ($row['category'] ?? '')),
            'type' => trim((string) ($row['type'] ?? '')),
            'spec' => ! empty(trim((string) ($row['spec'] ?? ''))) ? trim((string) $row['spec']) : null,
            'price' => (float) ($row['price'] ?? 0),
            'stock' => (int) ($row['stock'] ?? 0),
            'reserved_stock' => 0,
            'sold_stock' => 0,
            'cover_url' => ! empty(trim((string) ($row['cover_url'] ?? ''))) ? trim((string) $row['cover_url']) : null,
            'custom_rule' => ! empty(trim((string) ($row['custom_rule'] ?? ''))) ? trim((string) $row['custom_rule']) : null,
            'version' => 0,
        ];
    }
}
