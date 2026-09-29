<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Auth\CurrentUser;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Storage\ImageStorage;
use App\Storage\ImageValidator;

/**
 * Gestión del catálogo por administradores: productos, variantes, imágenes,
 * categorías, tallas y colores. Además del control en el backend (middleware
 * 'admin'), cada escritura está protegida por RLS (is_admin()).
 */
final class AdminCatalogService
{
    public function __construct(
        private readonly Database $db,
        private readonly ImageStorage $storage,
        private readonly ImageValidator $images,
    ) {
    }

    // ================================================================ Productos

    /** @param array<string, mixed> $q */
    public function listProducts(array $q): array
    {
        $where = ['true'];
        $params = [];
        $search = is_string($q['q'] ?? null) ? trim($q['q']) : '';
        if ($search !== '') {
            $where[] = "(p.nombre ilike :q escape '\\' or exists (select 1 from public.variantes_producto v where v.producto_id = p.id and v.sku ilike :q escape '\\'))";
            $params['q'] = '%' . \App\Services\CatalogService::escapeLike(mb_substr($search, 0, 100)) . '%';
        }
        if (in_array($q['estado'] ?? '', ['borrador', 'activo', 'inactivo'], true)) {
            $where[] = 'p.estado = :estado';
            $params['estado'] = $q['estado'];
        }
        if (isset($q['categoria_id']) && is_string($q['categoria_id']) && ctype_digit($q['categoria_id'])) {
            $where[] = 'p.categoria_id = :cat';
            $params['cat'] = (int) $q['categoria_id'];
        }
        $page = max(1, (int) ($q['pagina'] ?? $q['page'] ?? 1));
        $perPage = 20;
        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;

        $res = $this->db->fetchJson(
            "with base as (select p.* from public.productos p where " . implode(' and ', $where) . ")
             select jsonb_build_object(
               'total', (select count(*) from base),
               'items', coalesce((select jsonb_agg(to_jsonb(x)) from (
                  select b.id, b.nombre, b.slug, b.precio, b.precio_anterior, b.estado, b.destacado, b.updated_at,
                         c.nombre as categoria_nombre,
                         (select count(*) from public.variantes_producto v where v.producto_id = b.id) as variantes,
                         (select coalesce(sum(v.stock), 0) from public.variantes_producto v where v.producto_id = b.id and v.activa) as stock_total,
                         (select count(*) from public.variantes_producto v where v.producto_id = b.id and v.activa and v.stock <= v.stock_minimo) as variantes_stock_bajo,
                         (select i.url from public.imagenes_producto i where i.producto_id = b.id order by i.es_principal desc, i.orden, i.id limit 1) as imagen_url
                    from base b join public.categorias c on c.id = b.categoria_id
                   order by b.updated_at desc, b.id desc
                   limit :limit offset :offset) x), '[]'::jsonb))",
            $params,
        );
        $total = (int) $res['total'];
        return ['items' => $res['items'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** @return array<string, mixed> */
    public function getProduct(string $id): array
    {
        $pid = $this->id($id, 'Producto no encontrado.');
        $product = $this->db->fetchJson(
            "select to_jsonb(p) - 'busqueda' || jsonb_build_object(
               'categoria_nombre', c.nombre,
               'imagenes', (select coalesce(jsonb_agg(jsonb_build_object('id', i.id, 'url', i.url, 'alt', i.alt, 'orden', i.orden,
                                  'es_principal', i.es_principal, 'color_id', i.color_id) order by i.es_principal desc, i.orden, i.id), '[]')
                              from public.imagenes_producto i where i.producto_id = p.id),
               'variantes', (select coalesce(jsonb_agg(jsonb_build_object('id', v.id, 'sku', v.sku, 'talla_id', v.talla_id, 'talla', t.codigo,
                                  'color_id', v.color_id, 'color', co.nombre, 'hex', co.hex, 'precio', v.precio,
                                  'precio_efectivo', coalesce(v.precio, p.precio), 'stock', v.stock, 'stock_minimo', v.stock_minimo,
                                  'activa', v.activa) order by co.nombre, t.orden), '[]')
                               from public.variantes_producto v
                               join public.tallas t on t.id = v.talla_id
                               join public.colores co on co.id = v.color_id
                              where v.producto_id = p.id),
               'unidades_vendidas', (select coalesce(sum(d.cantidad), 0) from public.pedido_detalle d
                                       join public.pedidos pe on pe.id = d.pedido_id
                                      where d.producto_id = p.id and pe.estado <> 'cancelado'))
             from public.productos p join public.categorias c on c.id = p.categoria_id
            where p.id = :id",
            ['id' => $pid],
        );
        if ($product === null) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        return $product;
    }

    /** @param array<string, mixed> $input */
    public function createProduct(array $input): array
    {
        $data = $this->validateProduct($input);
        $data['slug'] = $data['slug'] ?: $this->uniqueSlug((string) $data['nombre']);
        $id = $this->db->fetchValue(
            'insert into public.productos (categoria_id, nombre, slug, descripcion, precio, precio_anterior, estado, destacado)
             values (:categoria_id, :nombre, :slug, :descripcion, :precio, :precio_anterior, :estado, :destacado)
             returning id',
            $data,
        );
        return $this->getProduct((string) $id);
    }

    /** @param array<string, mixed> $input */
    public function updateProduct(string $id, array $input): array
    {
        $pid = $this->id($id, 'Producto no encontrado.');
        $data = $this->validateProduct($input);
        $data['slug'] = $data['slug'] ?: $this->uniqueSlug((string) $data['nombre'], $pid);
        $n = $this->db->execute(
            'update public.productos set categoria_id = :categoria_id, nombre = :nombre, slug = :slug, descripcion = :descripcion,
                    precio = :precio, precio_anterior = :precio_anterior, estado = :estado, destacado = :destacado
              where id = :id',
            $data + ['id' => $pid],
        );
        if ($n === 0) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        return $this->getProduct((string) $pid);
    }

    public function deleteProduct(string $id, CurrentUser $admin): void
    {
        $pid = $this->id($id, 'Producto no encontrado.');
        $paths = $this->db->transaction(function (Database $db) use ($pid) {
            $paths = array_column($db->fetchAll('select storage_path from public.imagenes_producto where producto_id = :id and storage_path is not null', ['id' => $pid]), 'storage_path');
            if ($db->execute('delete from public.productos where id = :id', ['id' => $pid]) === 0) {
                throw HttpException::notFound('Producto no encontrado.');
            }
            return $paths;
        });
        foreach ($paths as $path) {
            $this->storage->delete((string) $path, $admin->accessToken);
        }
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    private function validateProduct(array $input): array
    {
        $v = new Validator($input);
        $data = [
            'categoria_id' => $v->int('categoria_id', true, 1, PHP_INT_MAX, 'categoría'),
            'nombre' => $v->string('nombre', true, 2, 120, null, true, 'nombre'),
            'slug' => $v->string('slug', false, 2, 140, '/^[a-z0-9]+(-[a-z0-9]+)*$/', true, 'URL (slug)'),
            'descripcion' => $v->string('descripcion', false, 0, 4000, null, false, 'descripción') ?? '',
            'precio' => $v->decimal('precio', true, 1, 99_999_999, 'precio'),
            'precio_anterior' => $v->decimal('precio_anterior', false, 1, 99_999_999, 'precio anterior'),
            'estado' => $v->enum('estado', ['borrador', 'activo', 'inactivo'], false, 'estado') ?? 'borrador',
            'destacado' => $v->bool('destacado'),
        ];
        if ($data['precio_anterior'] !== null && $data['precio'] !== null && $data['precio_anterior'] <= $data['precio']) {
            $v->addError('precio_anterior', 'El precio anterior debe ser mayor que el precio actual (o dejarse vacío).');
        }
        $v->check();
        // numeric: se envían como texto para no perder precisión decimal.
        $data['precio'] = (string) $data['precio'];
        $data['precio_anterior'] = $data['precio_anterior'] !== null ? (string) $data['precio_anterior'] : null;
        return $data;
    }

    private function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = (string) $this->db->fetchValue('select app_private.slugify(:n)', ['n' => $name]);
        $base = $base !== '' ? substr($base, 0, 120) : 'producto';
        $slug = $base;
        for ($i = 2; $i < 100; $i++) {
            $exists = $this->db->fetchValue('select 1 from public.productos where slug = :s and id <> coalesce(:id, 0)', ['s' => $slug, 'id' => $exceptId]);
            if (!$exists) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }

    // ================================================================ Variantes

    /** @param array<string, mixed> $input */
    public function createVariant(string $productId, array $input): array
    {
        $pid = $this->id($productId, 'Producto no encontrado.');
        $v = new Validator($input);
        $tallaId = $v->int('talla_id', true, 1, PHP_INT_MAX, 'talla');
        $colorId = $v->int('color_id', true, 1, PHP_INT_MAX, 'color');
        $sku = $v->string('sku', false, 3, 40, '/^[A-Za-z0-9-]+$/', true, 'SKU');
        $precio = $v->decimal('precio', false, 1, 99_999_999, 'precio');
        $stockMin = $v->int('stock_minimo', false, 0, 100000, 'stock mínimo') ?? 3;
        $stockInicial = $v->int('stock_inicial', false, 0, 100000, 'stock inicial') ?? 0;
        $v->check();

        return $this->db->transaction(function (Database $db) use ($pid, $tallaId, $colorId, $sku, $precio, $stockMin, $stockInicial) {
            if ($sku === null) {
                $sku = (string) $db->fetchValue(
                    "select upper('FC-' || lpad(cast(:p as text), 3, '0') || '-' || t.codigo || '-' || left(replace(app_private.slugify(c.nombre), '-', ''), 3))
                       from public.tallas t, public.colores c where t.id = :t and c.id = :c",
                    ['p' => $pid, 't' => $tallaId, 'c' => $colorId],
                );
                if ($sku === '') {
                    throw HttpException::validation(['talla_id' => 'Talla o color inválidos.']);
                }
            }
            $varId = $db->fetchValue(
                'insert into public.variantes_producto (producto_id, talla_id, color_id, sku, precio, stock_minimo)
                 values (:p, :t, :c, :sku, :precio, :min) returning id',
                ['p' => $pid, 't' => $tallaId, 'c' => $colorId, 'sku' => strtoupper($sku), 'precio' => $precio !== null ? (string) $precio : null, 'min' => $stockMin],
            );
            if ($stockInicial > 0) {
                $db->fetchValue(
                    "select public.admin_ajustar_inventario(:id, :cant, 'entrada', 'Stock inicial de la variante')",
                    ['id' => (int) $varId, 'cant' => $stockInicial],
                );
            }
            return $this->getProduct((string) $pid);
        });
    }

    /** @param array<string, mixed> $input */
    public function updateVariant(string $variantId, array $input): array
    {
        $vid = $this->id($variantId, 'Variante no encontrada.');
        $v = new Validator($input);
        $sku = $v->string('sku', true, 3, 40, '/^[A-Za-z0-9-]+$/', true, 'SKU');
        $precio = $v->decimal('precio', false, 1, 99_999_999, 'precio');
        $stockMin = $v->int('stock_minimo', true, 0, 100000, 'stock mínimo');
        $activa = $v->bool('activa', true);
        $v->check();

        // "stock" no se acepta aquí: el inventario solo cambia vía kardex.
        $pid = $this->db->fetchValue(
            'update public.variantes_producto set sku = :sku, precio = :precio, stock_minimo = :min, activa = :activa
              where id = :id returning producto_id',
            ['sku' => strtoupper((string) $sku), 'precio' => $precio !== null ? (string) $precio : null, 'min' => $stockMin, 'activa' => $activa, 'id' => $vid],
        );
        if ($pid === null) {
            throw HttpException::notFound('Variante no encontrada.');
        }
        return $this->getProduct((string) $pid);
    }

    public function deleteVariant(string $variantId): array
    {
        $vid = $this->id($variantId, 'Variante no encontrada.');
        $pid = $this->db->fetchValue('delete from public.variantes_producto where id = :id returning producto_id', ['id' => $vid]);
        if ($pid === null) {
            throw HttpException::notFound('Variante no encontrada.');
        }
        return $this->getProduct((string) $pid);
    }

    // ================================================================= Imágenes

    /** @param array<string, mixed> $file  @param array<string, mixed> $input */
    public function uploadImage(string $productId, array $file, array $input, CurrentUser $admin): array
    {
        $pid = $this->id($productId, 'Producto no encontrado.');
        $product = $this->db->fetchOne('select id, nombre from public.productos where id = :id', ['id' => $pid]);
        if ($product === null) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        $count = (int) $this->db->fetchValue('select count(*) from public.imagenes_producto where producto_id = :id', ['id' => $pid]);
        if ($count >= 12) {
            throw new HttpException(422, 'LIMITE_IMAGENES', 'Cada producto admite máximo 12 imágenes.');
        }

        $v = new Validator($input);
        $alt = $v->string('alt', false, 0, 200, null, true, 'texto alternativo') ?? (string) $product['nombre'];
        $colorId = $v->int('color_id', false, 1, PHP_INT_MAX, 'color');
        $v->check();

        $image = $this->images->validate($file);
        $stored = $this->storage->put(ImageValidator::randomName($image['extension']), $image['contents'], $image['mime'], $admin->accessToken);

        try {
            $this->db->execute(
                'insert into public.imagenes_producto (producto_id, color_id, url, storage_path, alt, orden, es_principal)
                 values (:p, :c, :url, :path, :alt, :orden, :principal)',
                ['p' => $pid, 'c' => $colorId, 'url' => $stored['url'], 'path' => $stored['path'], 'alt' => $alt, 'orden' => $count, 'principal' => $count === 0],
            );
        } catch (\Throwable $e) {
            $this->storage->delete($stored['path'], $admin->accessToken);
            throw $e;
        }
        return $this->getProduct((string) $pid);
    }

    public function setMainImage(string $productId, string $imageId): array
    {
        $pid = $this->id($productId, 'Producto no encontrado.');
        $iid = $this->id($imageId, 'Imagen no encontrada.');
        $this->db->transaction(function (Database $db) use ($pid, $iid) {
            if (!$db->fetchValue('select 1 from public.imagenes_producto where id = :i and producto_id = :p', ['i' => $iid, 'p' => $pid])) {
                throw HttpException::notFound('Imagen no encontrada.');
            }
            $db->execute('update public.imagenes_producto set es_principal = false where producto_id = :p and es_principal', ['p' => $pid]);
            $db->execute('update public.imagenes_producto set es_principal = true where id = :i', ['i' => $iid]);
        });
        return $this->getProduct((string) $pid);
    }

    public function deleteImage(string $productId, string $imageId, CurrentUser $admin): array
    {
        $pid = $this->id($productId, 'Producto no encontrado.');
        $iid = $this->id($imageId, 'Imagen no encontrada.');
        $row = $this->db->transaction(function (Database $db) use ($pid, $iid) {
            $row = $db->fetchOne('delete from public.imagenes_producto where id = :i and producto_id = :p returning storage_path, es_principal', ['i' => $iid, 'p' => $pid]);
            if ($row === null) {
                throw HttpException::notFound('Imagen no encontrada.');
            }
            if ($row['es_principal']) {
                $db->execute(
                    'update public.imagenes_producto set es_principal = true
                      where id = (select id from public.imagenes_producto where producto_id = :p order by orden, id limit 1)',
                    ['p' => $pid],
                );
            }
            return $row;
        });
        if (!empty($row['storage_path'])) {
            $this->storage->delete((string) $row['storage_path'], $admin->accessToken);
        }
        return $this->getProduct((string) $pid);
    }

    // =============================================================== Categorías

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.orden, x.nombre), '[]') from (
               select c.*, (select count(*) from public.productos p where p.categoria_id = c.id) as productos
                 from public.categorias c) x"
        );
    }

    /** @param array<string, mixed> $input */
    public function saveCategory(?string $id, array $input): array
    {
        $v = new Validator($input);
        $data = [
            'nombre' => $v->string('nombre', true, 2, 60, null, true, 'nombre'),
            'slug' => $v->string('slug', false, 2, 80, '/^[a-z0-9]+(-[a-z0-9]+)*$/', true, 'URL (slug)'),
            'descripcion' => $v->string('descripcion', false, 0, 500, null, true, 'descripción'),
            'imagen_url' => $v->string('imagen_url', false, 0, 500, '#^(/[A-Za-z0-9/_.-]+|https://[A-Za-z0-9./_-]+)$#', true, 'imagen'),
            'activa' => $v->bool('activa', true),
            'orden' => $v->int('orden', false, 0, 1000, 'orden') ?? 0,
        ];
        $v->check();
        if ($data['slug'] === null) {
            $data['slug'] = (string) $this->db->fetchValue('select app_private.slugify(:n)', ['n' => $data['nombre']]);
        }

        if ($id === null) {
            $this->db->execute(
                'insert into public.categorias (nombre, slug, descripcion, imagen_url, activa, orden)
                 values (:nombre, :slug, :descripcion, :imagen_url, :activa, :orden)',
                $data,
            );
        } else {
            $cid = $this->id($id, 'Categoría no encontrada.');
            $n = $this->db->execute(
                'update public.categorias set nombre = :nombre, slug = :slug, descripcion = :descripcion,
                        imagen_url = :imagen_url, activa = :activa, orden = :orden where id = :id',
                $data + ['id' => $cid],
            );
            if ($n === 0) {
                throw HttpException::notFound('Categoría no encontrada.');
            }
        }
        return $this->categories();
    }

    public function deleteCategory(string $id): void
    {
        $cid = $this->id($id, 'Categoría no encontrada.');
        if ($this->db->fetchValue('select 1 from public.productos where categoria_id = :id limit 1', ['id' => $cid])) {
            throw new HttpException(409, 'EN_USO', 'No se puede eliminar: la categoría tiene productos. Desactívala o mueve sus productos.');
        }
        if ($this->db->execute('delete from public.categorias where id = :id', ['id' => $cid]) === 0) {
            throw HttpException::notFound('Categoría no encontrada.');
        }
    }

    // ========================================================= Tallas y colores

    /** @return list<array<string, mixed>> */
    public function sizes(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.orden, x.codigo), '[]') from (
               select t.*, (select count(*) from public.variantes_producto v where v.talla_id = t.id) as variantes
                 from public.tallas t) x"
        );
    }

    /** @param array<string, mixed> $input */
    public function saveSize(?string $id, array $input): array
    {
        $v = new Validator($input);
        $data = [
            'codigo' => $v->string('codigo', true, 1, 10, '/^[A-Za-z0-9]+$/', true, 'código'),
            'nombre' => $v->string('nombre', true, 1, 40, null, true, 'nombre'),
            'orden' => $v->int('orden', false, 0, 1000, 'orden') ?? 0,
        ];
        $v->check();
        $data['codigo'] = strtoupper((string) $data['codigo']);
        if ($id === null) {
            $this->db->execute('insert into public.tallas (codigo, nombre, orden) values (:codigo, :nombre, :orden)', $data);
        } else {
            $sid = $this->id($id, 'Talla no encontrada.');
            if ($this->db->execute('update public.tallas set codigo = :codigo, nombre = :nombre, orden = :orden where id = :id', $data + ['id' => $sid]) === 0) {
                throw HttpException::notFound('Talla no encontrada.');
            }
        }
        return $this->sizes();
    }

    public function deleteSize(string $id): void
    {
        $sid = $this->id($id, 'Talla no encontrada.');
        if ($this->db->execute('delete from public.tallas where id = :id', ['id' => $sid]) === 0) {
            throw HttpException::notFound('Talla no encontrada.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function colors(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.nombre), '[]') from (
               select c.*, (select count(*) from public.variantes_producto v where v.color_id = c.id) as variantes
                 from public.colores c) x"
        );
    }

    /** @param array<string, mixed> $input */
    public function saveColor(?string $id, array $input): array
    {
        $v = new Validator($input);
        $data = [
            'nombre' => $v->string('nombre', true, 2, 40, null, true, 'nombre'),
            'hex' => $v->string('hex', true, 7, 7, '/^#[0-9A-Fa-f]{6}$/', true, 'color HEX'),
        ];
        $v->check();
        $data['hex'] = strtoupper((string) $data['hex']);
        if ($id === null) {
            $this->db->execute('insert into public.colores (nombre, hex) values (:nombre, :hex)', $data);
        } else {
            $cid = $this->id($id, 'Color no encontrado.');
            if ($this->db->execute('update public.colores set nombre = :nombre, hex = :hex where id = :id', $data + ['id' => $cid]) === 0) {
                throw HttpException::notFound('Color no encontrado.');
            }
        }
        return $this->colors();
    }

    public function deleteColor(string $id): void
    {
        $cid = $this->id($id, 'Color no encontrado.');
        if ($this->db->execute('delete from public.colores where id = :id', ['id' => $cid]) === 0) {
            throw HttpException::notFound('Color no encontrado.');
        }
    }

    private function id(string $raw, string $notFound): int
    {
        if (!Validator::isPositiveInt($raw)) {
            throw HttpException::notFound($notFound);
        }
        return (int) $raw;
    }
}
