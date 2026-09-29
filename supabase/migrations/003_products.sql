-- =============================================================================
-- 003 · Catálogo: categorías, tallas, colores, productos, variantes,
--       imágenes, inventario (kardex), favoritos y configuración de la tienda
-- -----------------------------------------------------------------------------
-- Normaliza el modelo original, donde cada "producto" en localStorage era una
-- única fila {id, imagen(base64), cantidad, talla, color, precio}. Ahora:
--   producto (nombre, descripción, precio, categoría, estado)
--     └─ variantes_producto (talla × color → stock propio, SKU, precio opcional)
--     └─ imagenes_producto  (URLs en Supabase Storage, no base64)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Categorías (admite subcategorías mediante parent_id)
-- ---------------------------------------------------------------------------
create table public.categorias (
  id          bigint generated always as identity primary key,
  parent_id   bigint references public.categorias (id) on delete set null,
  nombre      text not null check (char_length(nombre) between 2 and 60),
  slug        text not null unique check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  descripcion text check (descripcion is null or char_length(descripcion) <= 500),
  imagen_url  text check (imagen_url is null or char_length(imagen_url) <= 500),
  activa      boolean not null default true,
  orden       integer not null default 0,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint categorias_nombre_unico unique (nombre)
);

create trigger categorias_set_updated_at
  before update on public.categorias
  for each row execute function app_private.set_updated_at();

-- ---------------------------------------------------------------------------
-- Tallas y colores (catálogos maestros reutilizables)
-- ---------------------------------------------------------------------------
create table public.tallas (
  id     bigint generated always as identity primary key,
  codigo text not null unique check (char_length(codigo) between 1 and 10),
  nombre text not null check (char_length(nombre) between 1 and 40),
  orden  integer not null default 0
);

create table public.colores (
  id     bigint generated always as identity primary key,
  nombre text not null unique check (char_length(nombre) between 2 and 40),
  hex    text not null check (hex ~ '^#[0-9A-Fa-f]{6}$')
);

-- ---------------------------------------------------------------------------
-- Productos
-- ---------------------------------------------------------------------------
create table public.productos (
  id              bigint generated always as identity primary key,
  categoria_id    bigint not null references public.categorias (id) on delete restrict,
  nombre          text not null check (char_length(nombre) between 2 and 120),
  slug            text not null unique check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  descripcion     text not null default '' check (char_length(descripcion) <= 4000),
  precio          numeric(12,2) not null check (precio > 0),
  precio_anterior numeric(12,2) check (precio_anterior is null or precio_anterior > precio),
  estado          text not null default 'borrador' check (estado in ('borrador', 'activo', 'inactivo')),
  destacado       boolean not null default false,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  busqueda        tsvector generated always as (
                    setweight(to_tsvector('spanish', coalesce(nombre, '')), 'A') ||
                    setweight(to_tsvector('spanish', coalesce(descripcion, '')), 'B')
                  ) stored
);

comment on column public.productos.precio is 'Precio vigente. Los pedidos guardan su propio precio histórico en pedido_detalle.';
comment on column public.productos.precio_anterior is 'Precio "antes" para mostrar descuentos. Debe ser mayor al precio actual.';

create index productos_categoria_idx on public.productos (categoria_id);
create index productos_estado_idx    on public.productos (estado);
create index productos_busqueda_idx  on public.productos using gin (busqueda);
create index productos_nombre_trgm_idx on public.productos using gin (nombre extensions.gin_trgm_ops);

create trigger productos_set_updated_at
  before update on public.productos
  for each row execute function app_private.set_updated_at();

-- ---------------------------------------------------------------------------
-- Variantes (talla × color). El stock se controla por variante.
-- ---------------------------------------------------------------------------
create table public.variantes_producto (
  id           bigint generated always as identity primary key,
  producto_id  bigint not null references public.productos (id) on delete cascade,
  talla_id     bigint not null references public.tallas (id)    on delete restrict,
  color_id     bigint not null references public.colores (id)   on delete restrict,
  sku          text not null unique check (sku ~ '^[A-Z0-9-]{3,40}$'),
  precio       numeric(12,2) check (precio is null or precio > 0),
  stock        integer not null default 0 check (stock >= 0),
  stock_minimo integer not null default 3 check (stock_minimo >= 0),
  activa       boolean not null default true,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now(),
  constraint variantes_producto_combinacion_unica unique (producto_id, talla_id, color_id)
);

comment on column public.variantes_producto.precio is 'Opcional. Si es NULL se usa productos.precio.';
comment on column public.variantes_producto.stock is 'Existencias actuales. Solo se modifica mediante funciones autorizadas (kardex).';

create index variantes_producto_producto_idx on public.variantes_producto (producto_id);
create index variantes_producto_talla_idx    on public.variantes_producto (talla_id);
create index variantes_producto_color_idx    on public.variantes_producto (color_id);

create trigger variantes_producto_set_updated_at
  before update on public.variantes_producto
  for each row execute function app_private.set_updated_at();

-- ---------------------------------------------------------------------------
-- Imágenes de producto (opcionalmente asociadas a un color)
-- ---------------------------------------------------------------------------
create table public.imagenes_producto (
  id           bigint generated always as identity primary key,
  producto_id  bigint not null references public.productos (id) on delete cascade,
  color_id     bigint references public.colores (id) on delete set null,
  url          text not null check (char_length(url) <= 1000),
  storage_path text check (storage_path is null or char_length(storage_path) <= 300),
  alt          text not null default '' check (char_length(alt) <= 200),
  orden        integer not null default 0,
  es_principal boolean not null default false,
  created_at   timestamptz not null default now()
);

create index imagenes_producto_producto_idx on public.imagenes_producto (producto_id, orden);
create unique index imagenes_producto_una_principal_idx
  on public.imagenes_producto (producto_id) where es_principal;

-- ---------------------------------------------------------------------------
-- Inventario: kardex / libro de movimientos.
-- El stock vigente está en variantes_producto.stock; cada cambio queda
-- registrado aquí (quién, cuándo, cuánto, por qué y stock resultante).
-- ---------------------------------------------------------------------------
create table public.movimientos_inventario (
  id               bigint generated always as identity primary key,
  variante_id      bigint not null references public.variantes_producto (id) on delete cascade,
  tipo             text   not null check (tipo in ('entrada', 'salida', 'ajuste', 'venta', 'venta_pos', 'devolucion')),
  cantidad         integer not null check (cantidad <> 0),
  stock_anterior   integer not null check (stock_anterior >= 0),
  stock_resultante integer not null check (stock_resultante >= 0),
  motivo           text check (motivo is null or char_length(motivo) <= 300),
  pedido_id        uuid,   -- FK añadida en 004 (pedidos aún no existe)
  usuario_id       uuid references public.profiles (id) on delete set null,
  created_at       timestamptz not null default now(),
  constraint movimientos_inventario_cuadre check (stock_resultante = stock_anterior + cantidad)
);

create index movimientos_inventario_variante_idx on public.movimientos_inventario (variante_id, created_at desc);
create index movimientos_inventario_fecha_idx    on public.movimientos_inventario (created_at desc);

-- ---------------------------------------------------------------------------
-- Favoritos (lista de deseos)
-- ---------------------------------------------------------------------------
create table public.favoritos (
  usuario_id  uuid   not null default auth.uid() references public.profiles (id) on delete cascade,
  producto_id bigint not null references public.productos (id) on delete cascade,
  created_at  timestamptz not null default now(),
  primary key (usuario_id, producto_id)
);

create index favoritos_producto_idx on public.favoritos (producto_id);

-- ---------------------------------------------------------------------------
-- Configuración / contenido administrable de la tienda
-- ---------------------------------------------------------------------------
create table public.configuracion_tienda (
  clave       text primary key check (clave ~ '^[a-z0-9_]{2,60}$'),
  valor       jsonb not null,
  descripcion text not null default '',
  publica     boolean not null default true,
  updated_at  timestamptz not null default now()
);

create trigger configuracion_tienda_set_updated_at
  before update on public.configuracion_tienda
  for each row execute function app_private.set_updated_at();

insert into public.configuracion_tienda (clave, valor, descripcion, publica) values
  ('nombre_tienda',       '"FIRE CAT"',                                        'Nombre comercial de la tienda', true),
  ('eslogan',             '"La mejor calidad en venta de ropa y textiles"',     'Texto de bienvenida (heredado del proyecto original)', true),
  ('moneda',              '"COP"',                                             'Código ISO 4217 de la moneda', true),
  ('costo_envio',         '12000',                                             'Costo de envío estándar', true),
  ('envio_gratis_desde',  '250000',                                            'Subtotal a partir del cual el envío es gratis (0 = nunca)', true),
  ('umbral_stock_bajo',   '5',                                                 'Stock a partir del cual una variante se considera baja', false),
  ('mensaje_banner',      '"Envío gratis en compras superiores a $250.000"',   'Mensaje de la barra superior', true),
  ('whatsapp',            '""',                                                'Número de contacto (opcional)', true),
  ('email_contacto',      '"hola@firecat.test"',                               'Correo de contacto visible', true)
on conflict (clave) do nothing;

-- Lectura segura de un valor de configuración desde funciones del servidor.
create or replace function app_private.config_numero(p_clave text, p_defecto numeric)
returns numeric
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((select (valor #>> '{}')::numeric from public.configuracion_tienda where clave = p_clave), p_defecto)
$$;

-- ---------------------------------------------------------------------------
-- Vista de catálogo (SECURITY INVOKER: respeta la RLS del usuario que consulta)
-- ---------------------------------------------------------------------------
create view public.v_catalogo_productos
with (security_invoker = true)
as
select
  p.id,
  p.slug,
  p.nombre,
  p.descripcion,
  p.precio,
  p.precio_anterior,
  p.estado,
  p.destacado,
  p.created_at,
  c.id     as categoria_id,
  c.nombre as categoria_nombre,
  c.slug   as categoria_slug,
  coalesce(min(coalesce(v.precio, p.precio)) filter (where v.activa), p.precio) as precio_desde,
  coalesce(sum(v.stock) filter (where v.activa), 0)::integer                   as stock_total,
  (
    select i.url
      from public.imagenes_producto i
     where i.producto_id = p.id
     order by i.es_principal desc, i.orden, i.id
     limit 1
  ) as imagen_url,
  (
    select i.url
      from public.imagenes_producto i
     where i.producto_id = p.id
     order by i.es_principal desc, i.orden, i.id
     offset 1 limit 1
  ) as imagen_secundaria_url
from public.productos p
join public.categorias c on c.id = p.categoria_id
left join public.variantes_producto v on v.producto_id = p.id
group by p.id, c.id;
