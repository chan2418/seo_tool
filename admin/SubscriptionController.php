<?php

require_once __DIR__ . '/../services/AdminControlService.php';

class SubscriptionController
{
    private AdminControlService $service;

    public function __construct(?AdminControlService $service = null)
    {
        $this->service = $service ?? new AdminControlService();
    }

    public function index(array $filters): array
    {
        return $this->service->getSubscriptionsPageData($filters);
    }

    public function mutate(int $adminUserId, string $action, array $payload): array
    {
        return $this->service->handleSubscriptionAction($adminUserId, $action, $payload);
    }
}
