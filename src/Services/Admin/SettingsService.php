<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Configuración y contenido administrable de la tienda.
 * Cada clave tiene un tipo y validación propios (lista blanca).
 */
final class SettingsService
{
    /** clave => [tipo, etiqueta, min, max] */
    public const SCHEMA = [
        'nombre_tienda' => ['texto', 'Nombre de la tienda', 2, 60],
        'eslogan' => ['texto', 'Eslogan', 0, 120],
        'mensaje_banner' => ['texto', 'Mensaje de la barra superior', 0, 140],
        'email_contacto' => ['email', 'Correo de contacto', 0, 120],
        'whatsapp' => ['telefono', 'WhatsApp', 0, 20],
        'costo_envio' => ['numero', 'Costo de envío', 0, 1_000_000],
        'envio_gratis_desde' => ['numero', 'Envío gratis desde (0 = nunca)', 0, 100_000_000],
        'umbral_stock_bajo' => ['numero', 'Umbral de stock bajo', 0, 1000],
        'moneda' => ['texto', 'Moneda (ISO 4217)', 3, 3],
        'zona_horaria' => ['zona', 'Zona horaria de reportes', 3, 60],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function all(): array
    {
        $rows = $this->db->fetchJson(
            "select coalesce(jsonb_agg(jsonb_build_object('clave', clave, 'valor', valor, 'descripcion', descripcion,
                    'publica', publica, 'updated_at', updated_at) order by clave), '[]') from public.configuracion_tienda"
        );
        foreach ($rows as &$row) {
            $schema = self::SCHEMA[$row['clave']] ?? null;
            $row['tipo'] = $schema[0] ?? 'texto';
            $row['etiqueta'] = $schema[1] ?? $row['clave'];
        }
        return $rows;
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): array
    {
        $values = is_array($input['valores'] ?? null) ? $input['valores'] : $input;
        $v = new Validator($values);
        $updates = [];
        foreach (self::SCHEMA as $key => [$type, $label, $min, $max]) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = match ($type) {
                'numero' => $v->decimal($key, true, (float) $min, (float) $max, $label),
                'email' => ($values[$key] === '' ? '' : $v->email($key, false)),
                'telefono' => $v->string($key, false, 0, (int) $max, '/^[0-9+() -]*$/', true, $label) ?? '',
                'zona' => in_array($values[$key], timezone_identifiers_list(), true) ? $values[$key] : $v->addError($key, 'Zona horaria inválida.'),
                default => $v->string($key, $min > 0, (int) $min, (int) $max, null, true, $label) ?? '',
            };
            $updates[$key] = $value;
        }
        $v->check();
        if ($updates === []) {
            throw HttpException::validation(['valores' => 'No se envió ninguna configuración válida.']);
        }

        $this->db->transaction(function (Database $db) use ($updates) {
            foreach ($updates as $key => $value) {
                $db->execute(
                    'update public.configuracion_tienda set valor = cast(:valor as jsonb) where clave = :clave',
                    ['clave' => $key, 'valor' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                );
            }
        });
        return $this->all();
    }
}
