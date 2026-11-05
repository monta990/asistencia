<?php
declare(strict_types=1);

/** Registra departamentos detectados automáticamente, pendientes de configuración. */
return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE departments ADD COLUMN needs_configuration INTEGER NOT NULL DEFAULT 0 CHECK (needs_configuration IN (0, 1))");
    $pdo->exec('CREATE INDEX idx_departments_enabled_configuration ON departments(enabled, needs_configuration)');
};
