<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

final class TemplateRenderer
{
    public const VARIABLES = [
        '{{departamento}}' => 'Nombre visible del departamento',
        '{{etiqueta_departamento}}' => 'Nombre de departamento dentro del reporte ZKTimeNet',
        '{{semana}}' => 'Número de semana ISO (por ejemplo, 44)',
        '{{anio}}' => 'Año ISO de la semana',
        '{{fecha_inicio}}' => 'Fecha inicial del periodo (dd/mm/aaaa)',
        '{{fecha_fin}}' => 'Fecha final del periodo (dd/mm/aaaa)',
        '{{archivo}}' => 'Nombre del Excel adjunto',
        '{{reporte}}' => 'Nombre del archivo original',
        '{{empresa}}' => 'Nombre de la empresa',
        '{{aplicacion}}' => 'Nombre configurable de la aplicación',
        '{{plataforma}}' => 'Etiqueta de la plataforma interna',
        '{{lema}}' => 'Lema o descripción de la marca',
        '{{fecha_proceso}}' => 'Fecha y hora de generación',
        '{{usuario}}' => 'Usuario que procesó el reporte',
        '{{total_departamentos}}' => 'Cantidad de archivos de departamento generados',
    ];

    public static function render(string $template, array $context): string
    {
        $replace = [];
        foreach (self::VARIABLES as $token => $_description) {
            $key = trim($token, '{}');
            $replace[$token] = (string) ($context[$key] ?? '');
        }
        return strtr($template, $replace);
    }

    public static function html(string $plainText): string
    {
        return nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
