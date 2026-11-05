<?php
declare(strict_types=1);

use ZkTimeNet\Core\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

putenv('ZKTIME_TRUSTED_PROXIES=192.0.2.10,198.51.100.7');
$_SERVER['HTTPS'] = 'off';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['REMOTE_ADDR'] = '203.0.113.25';
$assert(!Session::isHttps(), 'a direct client cannot spoof X-Forwarded-Proto');

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$assert(Session::isHttps(), 'a declared TLS proxy can mark the request as HTTPS');

$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
$assert(!Session::isHttps(), 'a trusted proxy reporting HTTP does not enable HTTPS');

$_SERVER['HTTPS'] = 'on';
$assert(Session::isHttps(), 'native HTTPS is always recognized');

putenv('ZKTIME_TRUSTED_PROXIES');
echo "OK: trusted proxy HTTPS detection and direct-header spoofing protection.\n";
