<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\Services\MailerService;

$test = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$reflection = new ReflectionClass(MailerService::class);
$method = $reflection->getMethod('buildDepartmentReplyTo');
$result = $method->invoke(null,
    ['nominas@cyalimentos.com', 'sistemas@cyalimentos.com'],
    ['asistente@cyalimentos.com', 'contabilidad@cyalimentos.com', 'sistemas@cyalimentos.com'],
    'sistemas@cyalimentos.com',
    true
);
$test($result === ['nominas@cyalimentos.com', 'asistente@cyalimentos.com', 'contabilidad@cyalimentos.com'], 'responsible must be excluded from Reply-To, others retained');

$resultWithoutDepartment = $method->invoke(null,
    ['nominas@cyalimentos.com', 'sistemas@cyalimentos.com'],
    ['sistemas@cyalimentos.com'],
    'sistemas@cyalimentos.com',
    false
);
$test($resultWithoutDepartment === ['nominas@cyalimentos.com'], 'responsible must also be excluded from global Reply-To');

echo "OK: responsible delivery policy / Reply-To exclusion\n";
