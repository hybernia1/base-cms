<?php
namespace App\Service;

use RedBeanPHP\R as R;

class SecurityLog
{
    public static function record(string $type, string $message, array $context = []): void
    {
        $bean = R::dispense('log');
        $bean->type = $type;
        $bean->message = $message;
        $bean->ip_address = self::clientIp();
        $bean->path = $_SERVER['REQUEST_URI'] ?? null;
        $bean->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $bean->context = $context ? json_encode($context) : null;
        $bean->created_at = date('Y-m-d H:i:s');

        try {
            R::store($bean);
        } catch (\Throwable $e) {
            error_log('[SecurityLog] Nepodařilo se zapsat záznam: ' . $e->getMessage());
        }
    }

    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip === null) {
            return null;
        }

        return substr($ip, 0, 45);
    }
}
