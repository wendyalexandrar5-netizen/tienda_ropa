<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Estadísticas calculadas en PostgreSQL (007_reports.sql) sin tablas de
 * agregados: siempre reflejan el estado real de los datos.
 */
final class ReportService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function dashboard(): array
    {
        $today = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-29 days'));
        return [
            'resumen' => $this->db->fetchJson('select public.admin_resumen_dashboard()'),
            'ventas_30_dias' => $this->sales($from, $today, 'day'),
            'pedidos_pendientes' => $this->db->fetchJson(
                "select coalesce(jsonb_agg(to_jsonb(x)), '[]') from (
                   select pe.id, pe.numero, pe.total, pe.created_at, pe.canal,
                          coalesce(nullif(trim(pr.nombre || ' ' || pr.apellido), ''), pr.email, 'Cliente') as cliente
                     from public.pedidos pe left join public.profiles pr on pr.id = pe.usuario_id
                    where pe.estado = 'pendiente' order by pe.created_at limit 8) x"
            ),
            'stock_bajo' => array_slice($this->lowStock(), 0, 8),
            'top_productos' => $this->topProducts($from, $today, 5),
        ];
    }

    public function sales(string $from, string $to, string $group = 'day'): array
    {
        [$from, $to] = $this->range($from, $to);
        if (!in_array($group, ['day', 'week', 'month'], true)) {
            $group = 'day';
        }
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r) order by r.periodo), '[]') from public.admin_reporte_ventas(cast(:d as date), cast(:h as date), :g) r",
            ['d' => $from, 'h' => $to, 'g' => $group],
        );
    }

    public function topProducts(string $from, string $to, int $limit = 10): array
    {
        [$from, $to] = $this->range($from, $to);
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r)), '[]') from public.admin_reporte_productos_mas_vendidos(cast(:d as date), cast(:h as date), :l) r",
            ['d' => $from, 'h' => $to, 'l' => max(1, min($limit, 50))],
        );
    }

    public function topCategories(string $from, string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r)), '[]') from public.admin_reporte_categorias_mas_vendidas(cast(:d as date), cast(:h as date)) r",
            ['d' => $from, 'h' => $to],
        );
    }

    public function ordersByStatus(string $from, string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r)), '[]') from public.admin_reporte_pedidos_por_estado(cast(:d as date), cast(:h as date)) r",
            ['d' => $from, 'h' => $to],
        );
    }

    public function lowStock(?int $threshold = null): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r)), '[]') from public.admin_reporte_inventario_bajo(:u) r",
            ['u' => $threshold],
        );
    }

    public function customers(string $from, string $to, string $group = 'month'): array
    {
        [$from, $to] = $this->range($from, $to);
        if (!in_array($group, ['day', 'week', 'month'], true)) {
            $group = 'month';
        }
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(r) order by r.periodo), '[]') from public.admin_reporte_clientes(cast(:d as date), cast(:h as date), :g) r",
            ['d' => $from, 'h' => $to, 'g' => $group],
        );
    }

    /** @return array{0: string, 1: string} */
    private function range(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
                throw HttpException::validation(['fecha' => 'Formato de fecha inválido (AAAA-MM-DD).']);
            }
        }
        if ($to < $from) {
            throw HttpException::validation(['fecha' => 'La fecha final debe ser posterior a la inicial.']);
        }
        return [$from, $to];
    }
}
