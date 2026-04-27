<?php

namespace App\Services\File;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAttachment;
use Illuminate\Http\UploadedFile;

class OrderAttachmentService
{
    public function __construct(
        private readonly OssUploadService $ossService
    ) {}

    /**
     * 为订单上传设计稿/定制图案
     *
     * 业务规则：
     * - 仅 booked 状态的订单可上传设计稿（状态机约束）
     * - 上传后订单自动流转至 design_pending
     * - 文件元数据落库关联订单
     */
    public function uploadDesign(Order $order, UploadedFile $file): OrderAttachment
    {
        // 状态机校验：仅 booked 状态可上传设计稿
        if ($order->status !== OrderStatus::BOOKED->value) {
            throw new \InvalidArgumentException(
                "当前订单状态为「{$order->status}」，仅「已预订」状态的订单可上传设计稿。"
            );
        }

        // 上传文件到 OSS / 本地存储
        $uploadResult = $this->ossService->upload($file, "merch-designs/{$order->id}");

        // 元数据落库 + 订单状态流转（事务包裹）
        return \DB::transaction(function () use ($order, $uploadResult) {
            /** @var OrderAttachment $attachment */
            $attachment = OrderAttachment::create([
                'order_id' => $order->id,
                'type' => 'design',
                'origin_name' => $uploadResult['name'],
                'storage_path' => $uploadResult['path'],
                'mime_type' => $uploadResult['mime'],
                'size' => $uploadResult['size'],
                'ext' => $uploadResult['ext'],
                'is_deleted' => false,
            ]);

            // 订单状态流转：booked → design_pending
            $order->update(['status' => OrderStatus::DESIGN_PENDING->value]);

            return $attachment;
        });
    }

    /**
     * 标记附件为逻辑删除（订单驳回时调用）
     */
    public function markAsDeleted(OrderAttachment $attachment, string $_reason = ''): bool
    {
        return $attachment->update([
            'is_deleted' => true,
        ]);
    }

    /**
     * 获取订单的有效附件列表（含临时访问链接）
     */
    public function getValidAttachments(Order $order): array
    {
        return $order->attachments()
            ->where('is_deleted', false)
            ->get()
            ->map(fn (OrderAttachment $att) => array_merge($att->toArray(), [
                'temporary_url' => $this->ossService->getTemporaryUrl($att->storage_path),
            ]))
            ->toArray();
    }
}
