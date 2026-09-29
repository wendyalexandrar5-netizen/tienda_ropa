<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Services\CatalogService;

/**
 * Gestión de clientes y administradores (reemplaza el enlace roto a
 * "usuarios.html" del proyecto original).
 */
final class AdminUserService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $q */
    public function list(array $q): array
    {
        $where = ['true'];
        $params = [];
        $search = is_string($q['q'] ?? null) ? trim($q['q']) : '';
        if ($search !== '') {
            $where[] = "(pr.email ilike :q escape '\\' or (pr.nombre || ' ' || pr.apellido) ilike :q escape '\\' or pr.telefono ilike :q escape '\\')";
            $params['q'] = '%' . CatalogService::escapeLike(mb_substr($search, 0, 100)) . '%';
        }
        if (in_array($q['rol'] ?? '', ['cliente', 'admin'], true)) {
            $where[] = 'pr.rol = :rol';
            $params['rol'] = $q['rol'];
        }
        if (in_array($q['estado'] ?? '', ['activo', 'bloqueado'], true)) {
            $where[] = 'pr.estado = :estado';
            $params['estado'] = $q['estado'];
        }
        $page = max(1, (int) ($q['pagina'] ?? $q['page'] ?? 1));
        $perPage = 20;
        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;

        $res = $this->db->fetchJson(
            "with base as (select pr.* from public.profiles pr where " . implode(' and ', $where) . ")
             select jsonb_build_object(
               'total', (select count(*) from base),
               'items', coalesce((select jsonb_agg(to_jsonb(x)) from (
                  select b.id, b.email, b.nombre, b.apellido, b.telefono, b.rol, b.estado, b.fecha_registro,
                         (select count(*) from public.pedidos pe where pe.usuario_id = b.id) as pedidos,
                         (select coalesce(sum(pe.total), 0) from public.pedidos pe where pe.usuario_id = b.id and pe.estado <> 'cancelado') as total_comprado,
                         (select max(pe.created_at) from public.pedidos pe where pe.usuario_id = b.id) as ultimo_pedido
                    from base b
                   order by b.fecha_registro desc
                   limit :limit offset :offset) x), '[]'::jsonb))",
            $params,
        );
        $total = (int) $res['total'];
        return ['items' => $res['items'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function get(string $id): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Usuario no encontrado.');
        }
        $user = $this->db->fetchJson(
            "select to_jsonb(pr) || jsonb_build_object(
               'direcciones', (select coalesce(jsonb_agg(to_jsonb(d) - 'usuario_id' order by d.es_principal desc), '[]') from public.direcciones d where d.usuario_id = pr.id),
               'pedidos', (select coalesce(jsonb_agg(jsonb_build_object('id', pe.id, 'numero', pe.numero, 'estado', pe.estado,
                                'estado_nombre', e.nombre, 'estado_color', e.color, 'total', pe.total, 'created_at', pe.created_at)
                                order by pe.created_at desc), '[]')
                             from public.pedidos pe join public.estados_pedido e on e.codigo = pe.estado where pe.usuario_id = pr.id),
               'total_comprado', (select coalesce(sum(pe.total), 0) from public.pedidos pe where pe.usuario_id = pr.id and pe.estado <> 'cancelado'))
             from public.profiles pr where pr.id = cast(:id as uuid)",
            ['id' => strtolower($id)],
        );
        if ($user === null) {
            throw HttpException::notFound('Usuario no encontrado.');
        }
        return $user;
    }

    /** @param array<string, mixed> $input */
    public function update(string $id, array $input, string $adminId): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Usuario no encontrado.');
        }
        $v = new Validator($input);
        $rol = $v->enum('rol', ['cliente', 'admin'], true, 'rol');
        $estado = $v->enum('estado', ['activo', 'bloqueado'], true, 'estado');
        $v->check();
        if (strtolower($id) === $adminId) {
            throw new HttpException(403, 'OPERACION_NO_PERMITIDA', 'No puedes cambiar tu propio rol o estado.');
        }
        $n = $this->db->execute(
            'update public.profiles set rol = :rol, estado = :estado where id = cast(:id as uuid)',
            ['rol' => $rol, 'estado' => $estado, 'id' => $id],
        );
        if ($n === 0) {
            throw HttpException::notFound('Usuario no encontrado.');
        }
        return $this->get($id);
    }
}
