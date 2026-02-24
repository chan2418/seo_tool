<?php

require_once __DIR__ . '/../models/AuditLogModel.php';

class AuditLogService
{
    private AuditLogModel $model;

    public function __construct(?AuditLogModel $model = null)
    {
        $this->model = $model ?? new AuditLogModel();
    }

    public function log(
        ?int $actorUserId,
        string $actionType,
        array $metadata = [],
        ?int $targetUserId = null,
        ?int $projectId = null
    ): void {
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            $ip = null;
        }
        $this->model->create($actorUserId, $targetUserId, $projectId, $actionType, $ip, $metadata);
    }

    public function recent(int $limit = 100, ?string $actionType = null): array
    {
        return $this->model->getRecent($limit, $actionType);
    }
}
