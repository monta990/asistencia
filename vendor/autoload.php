<?php
declare(strict_types=1);

/** Autoloader PSR-4 local del proyecto. No requiere Composer ni terceros. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ZkTimeNet\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
