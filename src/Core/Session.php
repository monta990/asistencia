<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) max(300, (int) Config::get('session_idle_timeout', 1800)));
        $secureCookie = self::isHttps();
        ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
        $sessionPath = (string) Config::get('storage_sessions', '');
        if ($sessionPath !== '' && is_dir($sessionPath)) {
            if (session_save_path($sessionPath) === false) {
                throw new \RuntimeException('No se pudo configurar el directorio privado de sesiones.');
            }
        }
        session_name((string) Config::get('session_name', 'ZKTIMESESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::cookiePath(),
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('No se pudo iniciar la sesión.');
        }
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($now - $last > (int) Config::get('session_idle_timeout', 1800)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        // Solo se acepta X-Forwarded-Proto cuando lo envía un proxy explícitamente confiable.
        // Un cliente directo no puede forzar la bandera Secure enviando ese encabezado.
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $trustedRaw = getenv('ZKTIME_TRUSTED_PROXIES');
        $trusted = is_string($trustedRaw)
            ? array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $trustedRaw) ?: []), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false))
            : [];
        return $remoteAddress !== ''
            && in_array($remoteAddress, $trusted, true)
            && strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    private static function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
        $directory = rtrim(dirname($script), '/.');
        return $directory === '' ? '/' : $directory . '/';
    }
}
