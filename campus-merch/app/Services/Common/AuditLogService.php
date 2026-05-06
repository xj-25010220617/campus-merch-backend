<?php

namespace App\Services\Common;

use App\Models\AuditLog;

class AuditLogService
{
    public function record(
        int $operatorId,
        string $action,
        string $targetType,
        int $targetId,
        ?array $beforeData = null,
        ?array $afterData = null,
        ?string $remark = null
    ): AuditLog {
        return AuditLog::create([
            'operator_id' => $operatorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'before_data' => $beforeData,
            'after_data' => $afterData,
            'remark' => $remark,
        ]);
    }

    public function recordOrderTransition(
        int $operatorId,
        int $orderId,
        string $fromStatus,
        string $toStatus,
        ?string $remark = null
    ): AuditLog {
        return $this->record(
            operatorId: $operatorId,
            action: 'order_transition',
            targetType: 'order',
            targetId: $orderId,
            beforeData: ['status' => $fromStatus],
            afterData: ['status' => $toStatus],
            remark: $remark,
        );
    }
}