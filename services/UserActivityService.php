<?php

require_once __DIR__ . '/../models/UserActivityLogModel.php';

class UserActivityService
{
    private UserActivityLogModel $model;

    public function __construct(?UserActivityLogModel $model = null)
    {
        $this->model = $model ?? new UserActivityLogModel();
    }

    public function log(?int $userId, string $actionType, array $metadata = []): void
    {
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            $ip = null;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            $ua = null;
        }
        $this->model->create($userId, $actionType, $metadata, $ip, $ua);
    }

    public function recentByUser(int $userId, int $limit = 30): array
    {
        return $this->model->getRecentByUser($userId, $limit);
    }

    public function hasAction(int $userId, string $actionType): bool
    {
        return $this->model->existsUserAction($userId, $actionType);
    }
}
