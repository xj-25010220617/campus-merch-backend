<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Http\Requests\File\UploadOrderDesignRequest;
use App\Models\Order;
use App\Services\File\OrderAttachmentService;
use Illuminate\Http\JsonResponse;

class OrderDesignController extends Controller
{
    public function __construct(
        private readonly OrderAttachmentService $attachmentService
    ) {}

    /**
     * POST /api/orders/{id}/design
     * 为已预订订单上传定制设计稿/图案
     */
    public function store(int $id, UploadOrderDesignRequest $request): JsonResponse
    {
        // 查询订单，确保归属当前用户（用户只能给自己的订单上传设计稿）
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('design_file');

        $attachment = $this->attachmentService->uploadDesign($order, $file);

        return response()->json([
            'code' => 0,
            'message' => '设计稿上传成功，订单已进入待审核状态。',
            'data' => [
                'id' => $attachment->id,
                'order_id' => $attachment->order_id,
                'origin_name' => $attachment->origin_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'ext' => $attachment->ext,
                'temporary_url' => app(\App\Services\File\OssUploadService::class)
                    ->getTemporaryUrl($attachment->storage_path),
                'order_status' => $order->fresh()->status,
            ],
        ], 201);
    }
}
