<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

final class Html
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }


    /** Renderiza un icono SVG ligero para identificar visualmente acciones. */
    public static function icon(string $name): string
    {
        $icons = [
            'add' => '<path d="M12 5v14M5 12h14"/>',
            'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1Z"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'palette' => '<path d="M12 3a9 9 0 1 0 0 18h1.2a2 2 0 0 0 1.5-3.3 1.7 1.7 0 0 1 1.3-2.8H18a3 3 0 0 0 3-3c0-5-4-8.9-9-8.9Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="11" cy="7" r="1"/><circle cx="15" cy="8" r="1"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
            'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
            'cancel' => '<path d="m18 6-12 12M6 6l12 12"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
            'excel' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13l4 6m0-6-4 6"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"',
            'zip' => '<path d="M3 7h18v13H3zM3 7l2-4h14l2 4"/><path d="M11 4h2m-2 3h2m-2 3h2m-2 3h2m-2 3h2"/>',
            'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5m4-1v5l3 2"/>',
            'detail' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'resend' => '<path d="M20 7v5h-5"/><path d="M20 12a8 8 0 1 0 2 5"/>',
            'configure' => '<path d="M4 21v-7m0-4V3m8 18v-9m0-4V3m8 18v-5m0-4V3"/><path d="M2 14h4m4-6h4m4 6h4"/>',
            'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5m5-5v12"/>',
            'process' => '<path d="M4 4h16v16H4z"/><path d="m9 8 7 4-7 4Z"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/>',
            'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3m.1 4h.01"/>',
            'close' => '<path d="m18 6-12 12M6 6l12 12"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'theme' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>',
            'logout' => '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
            'login' => '<path d="M14 8l4 4-4 4m4-4H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>',
            'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3m-4 5v2"/>',
            'trash' => '<path d="M3 6h18m-2 0-1 14H6L5 6m3 0V4h8v2m-7 4v6m4-6v6"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'refresh' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.5 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.5-3"/>',
            'back' => '<path d="m12 19-7-7 7-7M5 12h14"/>',
            'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
            'variable' => '<path d="m8 6-5 6 5 6m8-12 5 6-5 6m-2-14-4 16"/>',
            'toggle-on' => '<rect x="2" y="6" width="20" height="12" rx="6"/><circle cx="16" cy="12" r="3"/>',
            'toggle-off' => '<rect x="2" y="6" width="20" height="12" rx="6"/><circle cx="8" cy="12" r="3"/>',
            'start' => '<path d="m8 5 12 7-12 7Z"/>',
            'close-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6m0-6 6 6"/>',
        ];
        $svg = $icons[$name] ?? $icons['file'];
        return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $svg . '</svg>';
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : null;
    }
}
