<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): void
    {
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            http_response_code(419);
            throw new \RuntimeException('La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.');
        }
    }
}
