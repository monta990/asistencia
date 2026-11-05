<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

/** Coordina peticiones normales con operaciones exclusivas de restauración. */
final class MaintenanceGate
{
    /** @var resource|null */
    private static $handle = null;
    private static bool $exclusive = false;
    private static bool $shutdownRegistered = false;

    public static function acquireShared(): void
    {
        if (PHP_SAPI === 'cli' || is_resource(self::$handle)) {
            return;
        }
        $root = rtrim((string) Config::get('data_root', ''), "\\/");
        if ($root === '') {
            throw new \RuntimeException('No se pudo determinar la carpeta de datos para coordinar el mantenimiento.');
        }
        $path = $root . DIRECTORY_SEPARATOR . '.zktime-maintenance.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el control de mantenimiento del sistema.');
        }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            http_response_code(503);
            header('Retry-After: 15');
            header('Cache-Control: no-store');
            exit('El sistema está aplicando una restauración de respaldo. Intenta nuevamente en unos momentos.');
        }
        self::$handle = $handle;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'release']);
        }
    }

    /** Libera el bloqueo compartido de esta petición y espera el control exclusivo. */
    public static function switchToExclusive(int $timeoutSeconds = 30): void
    {
        if (!is_resource(self::$handle)) {
            throw new \RuntimeException('No se pudo obtener el control de mantenimiento de esta petición.');
        }
        if (self::$exclusive) {
            return;
        }
        flock(self::$handle, LOCK_UN);
        $deadline = microtime(true) + max(1, min(120, $timeoutSeconds));
        do {
            if (flock(self::$handle, LOCK_EX | LOCK_NB)) {
                self::$exclusive = true;
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Hay operaciones activas que no han terminado. No se restauró el respaldo; vuelve a intentarlo cuando el sistema esté sin actividad.');
    }

    public static function release(): void
    {
        if (is_resource(self::$handle)) {
            @flock(self::$handle, LOCK_UN);
            @fclose(self::$handle);
        }
        self::$handle = null;
        self::$exclusive = false;
    }
}
