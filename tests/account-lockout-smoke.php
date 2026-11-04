<?php
declare(strict_types=1);

use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Version;
use ZkTimeNet\Repositories\UserRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: se requiere PDO_SQLite para probar bloqueo de cuentas.\n");
    exit(2);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_lockout_' . bin2hex(random_bytes(5));
if (!mkdir($temporary, 0700, true)) {
    throw new RuntimeException('No se pudo crear la carpeta temporal.');
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $assertions++;
};

try {
    session_save_path($temporary);
    session_id('lockout-' . bin2hex(random_bytes(6)));
    if (!session_start()) {
        throw new RuntimeException('No se pudo iniciar la sesión temporal del test.');
    }

    $pdo = new PDO('sqlite:' . $temporary . DIRECTORY_SEPARATOR . 'accounts.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE schema_version (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO schema_version (id, version) VALUES (1, ' . Version::LOCKOUT_SCHEMA . ')');
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL DEFAULT \'\',
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_login_at TEXT NULL,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL,
        session_version INTEGER NOT NULL DEFAULT 1
    )');
    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, created_at, name, role, is_active, failed_login_attempts, locked_until, session_version) VALUES (:username, :hash, :created, :name, :role, 1, 0, NULL, 1)');
    $insert->execute(['username' => 'admin.one', 'hash' => password_hash('Correct-Pass-1!', PASSWORD_DEFAULT), 'created' => date(DATE_ATOM), 'name' => 'Administrador Uno', 'role' => 'admin']);
    $adminOne = (int) $pdo->lastInsertId();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $assert(!Auth::attempt($pdo, 'admin.one', 'incorrecta'), 'las contraseñas incorrectas no autentican');
    }
    $assert((int) $pdo->query('SELECT failed_login_attempts FROM users WHERE id = ' . $adminOne)->fetchColumn() === 4, 'los primeros cuatro fallos se conservan');
    $assert(!Auth::attempt($pdo, 'admin.one', 'incorrecta'), 'el quinto fallo tampoco autentica');

    $locked = $pdo->query('SELECT failed_login_attempts, locked_until, session_version FROM users WHERE id = ' . $adminOne)->fetch();
    $attemptCount = (int) ($locked['failed_login_attempts'] ?? -1);
    $lockedTimestamp = strtotime((string) ($locked['locked_until'] ?? ''));
    $sessionVersion = (int) ($locked['session_version'] ?? -1);
    $assert(
        $attemptCount === 5 && $lockedTimestamp !== false && $lockedTimestamp > time() && $sessionVersion === 2,
        'el quinto fallo debe guardar 5 intentos, una expiración futura y revocar la sesión; obtenido: attempts='
        . $attemptCount . ', locked_until=' . (string) ($locked['locked_until'] ?? 'NULL')
        . ', session_version=' . $sessionVersion
    );
    $assert(!Auth::attempt($pdo, 'admin.one', 'Correct-Pass-1!'), 'la contraseña correcta no evita un bloqueo activo');

    $pdo->prepare('UPDATE users SET locked_until = :expired WHERE id = :id')->execute(['expired' => date(DATE_ATOM, time() - 5), 'id' => $adminOne]);
    $assert(Auth::attempt($pdo, 'admin.one', 'Correct-Pass-1!'), 'la cuenta puede entrar cuando expira el bloqueo');
    $sessionUser = Auth::user();
    $assert(is_array($sessionUser) && (int) $sessionUser['session_version'] === 2, 'la nueva sesión adopta la versión actual');
    $assert((int) $pdo->query('SELECT failed_login_attempts FROM users WHERE id = ' . $adminOne)->fetchColumn() === 0, 'el acceso correcto limpia los fallos');
    $pdo->exec('UPDATE users SET session_version = session_version + 1 WHERE id = ' . $adminOne);
    $assert(!Auth::refresh($pdo), 'una modificación de versión revoca la sesión anterior');

    $insert->execute(['username' => 'admin.two', 'hash' => password_hash('Correct-Pass-2!', PASSWORD_DEFAULT), 'created' => date(DATE_ATOM), 'name' => 'Administrador Dos', 'role' => 'admin']);
    $adminTwo = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE users SET failed_login_attempts = 4, locked_until = :until WHERE id = :id')->execute(['until' => date(DATE_ATOM, time() + 600), 'id' => $adminTwo]);
    (new UserRepository($pdo))->unlock($adminTwo);
    $unlocked = $pdo->query('SELECT failed_login_attempts, locked_until, session_version FROM users WHERE id = ' . $adminTwo)->fetch();
    $assert((int) $unlocked['failed_login_attempts'] === 0 && $unlocked['locked_until'] === null, 'un administrador puede desbloquear otro administrador');
    $assert((int) $unlocked['session_version'] === 2, 'desbloquear invalida sesiones anteriores');

    echo "OK: {$assertions} comprobaciones funcionales de bloqueo, expiración, desbloqueo y revocación.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($temporary);
}
