<?php

require_once __DIR__ . '/../services/AdminControlService.php';

class UserController
{
    private AdminControlService $service;

    public function __construct(?AdminControlService $service = null)
    {
        $this->service = $service ?? new AdminControlService();
    }

    public function index(array $filters): array
    {
        return $this->service->getUsersPageData($filters);
    }

    public function mutate(int $adminUserId, string $action, array $payload): array
    {
        return $this->service->handleUserAction($adminUserId, $action, $payload);
    }
}
