<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

final class Config
{
    private static array $values = [];

    public static function load(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    /** Actualiza un valor calculado en tiempo de ejecución sin reemplazar la configuración base. */
    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }
}
