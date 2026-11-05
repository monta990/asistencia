<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

final class SecretVault
{
    private static function key(): string
    {
        $path = rtrim((string) Config::get('storage_private'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app.key';
        if (!is_file($path)) {
            throw new \RuntimeException('No existe la llave privada. Recarga la aplicación para que se inicialice el almacenamiento o revisa los permisos IIS.');
        }
        $key = file_get_contents($path);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new \RuntimeException('La llave privada de cifrado está dañada.');
        }
        return $key;
    }

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipherText) || strlen($tag) !== 16) {
            throw new \RuntimeException('No se pudo cifrar el secreto SMTP.');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            throw new \RuntimeException('El secreto SMTP almacenado no es válido.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if (!is_string($plain)) {
            throw new \RuntimeException('No se pudo descifrar la contraseña SMTP. Comprueba que la llave app.key no haya cambiado.');
        }
        return $plain;
    }
}
