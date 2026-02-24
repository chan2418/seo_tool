<?php
require_once __DIR__ . '/../models/AuditModel.php';

class SubscriptionService {
    
    public function canPerformAudit($userId) {
        if (!isset($_SESSION['plan_type'])) return false;
        
        $planType = strtolower((string) $_SESSION['plan_type']);
        if (in_array($planType, ['pro', 'agency'], true)) {
            return true; // Unlimited
        }
        
        // Free Plan: Check daily limit (3 per day)
        $auditModel = new AuditModel();
        $audits = $auditModel->getUserAudits($userId);
        
        $todayCount = 0;
        $today = date('Y-m-d');
        
        foreach ($audits as $audit) {
            $auditDate = date('Y-m-d', strtotime($audit['created_at']));
            if ($auditDate === $today) {
                $todayCount++;
            }
        }
        
        return $todayCount < 3;
    }
}
