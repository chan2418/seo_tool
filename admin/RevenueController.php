<?php

require_once __DIR__ . '/../services/AdminControlService.php';

class RevenueController
{
    private AdminControlService $service;

    public function __construct(?AdminControlService $service = null)
    {
        $this->service = $service ?? new AdminControlService();
    }

    public function index(): array
    {
        return $this->service->getRevenuePageData();
    }
}
