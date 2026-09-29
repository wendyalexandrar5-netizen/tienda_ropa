<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Perfil, direcciones y favoritos del cliente autenticado. Cada consulta
 * se filtra por auth.uid() y además está protegida por RLS.
 */
final class AccountService
{
    public function __construct(private readonly Database $db)
    {
    }

    // ------------------------------------------------------------ Perfil

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $profile = $this->db->fetchJson(
            "select jsonb_build_object('id', id, 'email', email, 'nombre', nombre, 'apellido', apellido,
                    'telefono', telefono, 'rol', rol, 'estado', estado, 'fecha_registro', fecha_registro)
               from public.profiles where id = (select auth.uid())"
        );
        if ($profile === null) {
            throw HttpException::notFound('Perfil no encontrado.');
        }
        return $profile;
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    public function updateProfile(array $input): array
    {
        // Solo nombre, apellido y teléfono. rol/estado/email/id se ignoran
        // aquí y además están bloqueados por GRANT de columnas + trigger.
        $v = new Validator($input);
        $nombre = $v->string('nombre', true, 2, 80, "/^[\\p{L}' .-]+$/u", true, 'nombre');
        $apellido = $v->string('apellido', true, 2, 80, "/^[\\p{L}' .-]+$/u", true, 'apellido');
        $telefono = $v->string('telefono', false, 7, 20, '/^[0-9+() -]+$/', true, 'teléfono');
        $v->check();

        $this->db->execute(
            'update public.profiles set nombre = :n, apellido = :a, telefono = :t where id = (select auth.uid())',
            ['n' => $nombre, 'a' => $apellido, 't' => $telefono],
        );
        return $this->profile();
    }

    // -------------------------------------------------------- Direcciones

    /** @return list<array<string, mixed>> */
    public function addresses(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(d) - 'usuario_id' order by d.es_principal desc, d.created_at), '[]')
               from public.direcciones d where d.usuario_id = (select auth.uid())"
        );
    }

    /** @return array<string, mixed> */
    public function address(string $id): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Dirección no encontrada.');
        }
        $row = $this->db->fetchJson(
            "select to_jsonb(d) - 'usuario_id' from public.direcciones d
              where d.id = cast(:id as uuid) and d.usuario_id = (select auth.uid())",
            ['id' => $id],
        );
        if ($row === null) {
            throw HttpException::notFound('Dirección no encontrada.');
        }
        return $row;
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    public function createAddress(array $input): array
    {
        $data = $this->validateAddress($input);
        $count = (int) $this->db->fetchValue('select count(*) from public.direcciones where usuario_id = (select auth.uid())');
        if ($count >= 10) {
            throw new HttpException(422, 'LIMITE_DIRECCIONES', 'Puedes guardar máximo 10 direcciones.');
        }
        if ($count === 0) {
            $data['es_principal'] = true;
        }
        $id = $this->db->fetchValue(
            'insert into public.direcciones (alias, destinatario, telefono, direccion, detalle, ciudad, departamento, codigo_postal, pais, es_principal)
             values (:alias, :destinatario, :telefono, :direccion, :detalle, :ciudad, :departamento, :codigo_postal, :pais, :es_principal)
             returning id',
            $data,
        );
        return $this->address((string) $id);
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    public function updateAddress(string $id, array $input): array
    {
        $this->address($id);
        $data = $this->validateAddress($input) + ['id' => $id];
        $this->db->execute(
            'update public.direcciones set alias = :alias, destinatario = :destinatario, telefono = :telefono,
                    direccion = :direccion, detalle = :detalle, ciudad = :ciudad, departamento = :departamento,
                    codigo_postal = :codigo_postal, pais = :pais, es_principal = :es_principal
              where id = cast(:id as uuid) and usuario_id = (select auth.uid())',
            $data,
        );
        return $this->address($id);
    }

    public function deleteAddress(string $id): void
    {
        $this->address($id);
        $this->db->execute(
            'delete from public.direcciones where id = cast(:id as uuid) and usuario_id = (select auth.uid())',
            ['id' => $id],
        );
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    private function validateAddress(array $input): array
    {
        $v = new Validator($input);
        $data = [
            'alias' => $v->string('alias', false, 1, 40, null, true, 'alias') ?? 'Casa',
            'destinatario' => $v->string('destinatario', true, 2, 120, null, true, 'destinatario'),
            'telefono' => $v->string('telefono', true, 7, 20, '/^[0-9+() -]+$/', true, 'teléfono'),
            'direccion' => $v->string('direccion', true, 5, 200, null, true, 'dirección'),
            'detalle' => $v->string('detalle', false, 0, 200, null, true, 'detalle'),
            'ciudad' => $v->string('ciudad', true, 2, 80, null, true, 'ciudad'),
            'departamento' => $v->string('departamento', true, 2, 80, null, true, 'departamento'),
            'codigo_postal' => $v->string('codigo_postal', false, 3, 12, '/^[0-9A-Za-z -]+$/', true, 'código postal'),
            'pais' => $v->string('pais', false, 2, 60, null, true, 'país') ?? 'Colombia',
            'es_principal' => $v->bool('es_principal'),
        ];
        $v->check();
        return $data;
    }

    // ---------------------------------------------------------- Favoritos

    /** @return list<array<string, mixed>> */
    public function favorites(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.agregado desc), '[]') from (
               select c.id, c.slug, c.nombre, c.precio, c.precio_anterior, c.precio_desde, c.imagen_url,
                      c.imagen_secundaria_url, c.categoria_nombre, c.categoria_slug, c.stock_total,
                      c.stock_total > 0 as disponible, '[]'::jsonb as colores, f.created_at as agregado
                 from public.favoritos f
                 join public.v_catalogo_productos c on c.id = f.producto_id and c.estado = 'activo'
                where f.usuario_id = (select auth.uid())) x"
        );
    }

    /** @return list<int> */
    public function favoriteIds(): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('select producto_id from public.favoritos where usuario_id = (select auth.uid())'),
            'producto_id',
        ));
    }

    public function addFavorite(mixed $productId): void
    {
        $v = new Validator(['producto_id' => $productId]);
        $id = $v->int('producto_id', true, 1, PHP_INT_MAX, 'producto');
        $v->check();
        $exists = $this->db->fetchValue("select 1 from public.productos where id = :id and estado = 'activo'", ['id' => $id]);
        if (!$exists) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        $this->db->execute(
            'insert into public.favoritos (usuario_id, producto_id) values ((select auth.uid()), :id) on conflict do nothing',
            ['id' => $id],
        );
    }

    public function removeFavorite(string $productId): void
    {
        if (!Validator::isPositiveInt($productId)) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        $this->db->execute(
            'delete from public.favoritos where usuario_id = (select auth.uid()) and producto_id = :id',
            ['id' => (int) $productId],
        );
    }
}
