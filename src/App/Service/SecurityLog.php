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
        $bean->ip_address = $context['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $bean->path = $context['path'] ?? ($_SERVER['REQUEST_URI'] ?? null);
        $bean->user_agent = $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        $bean->context = json_encode($context);
        $bean->created_at = date('Y-m-d H:i:s');

        R::store($bean);
    }
}
