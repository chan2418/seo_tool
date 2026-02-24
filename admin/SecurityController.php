<?php

require_once __DIR__ . '/../services/AdminControlService.php';

class SecurityController
{
    private AdminControlService $service;

    public function __construct(?AdminControlService $service = null)
    {
        $this->service = $service ?? new AdminControlService();
    }

    public function index(): array
    {
        return $this->service->getSecurityPageData();
    }

    public function mutate(int $adminUserId, string $action, array $payload): array
    {
        return $this->service->handleSecurityAction($adminUserId, $action, $payload);
    }
}
