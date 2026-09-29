-- =============================================================================
-- 004 · Carrito, pedidos, estados, historial y lógica de negocio transaccional
-- -----------------------------------------------------------------------------
-- Reemplaza el flujo original (carrito.html / ventas.html / caja.html), que
-- descontaba stock y registraba ventas en localStorage desde el navegador,
-- confiando en precios y cantidades manipulables por el usuario.
--
-- Reglas implementadas aquí (servidor / base de datos):
--   * El carrito NO guarda precios: se calculan siempre desde la BD.
--   * crear_pedido() valida y descuenta stock con bloqueo de filas
--     (SELECT ... FOR UPDATE) en UNA sola transacción: o se crea el pedido
--     con sus detalles y se descuenta el inventario, o no ocurre nada.
--   * pedido_detalle guarda el precio unitario histórico.
--   * El cliente nunca envía precio, total, ni propietario del pedido.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Estados de pedido y transiciones permitidas (datos, no código)
-- ---------------------------------------------------------------------------
create table public.estados_pedido (
  codigo      text primary key check (codigo ~ '^[a-z_]{3,30}$'),
  nombre      text not null,
  descripcion text not null default '',
  orden       integer not null,
  es_final    boolean not null default false,
  color       text not null default 'secondary'
);

insert into public.estados_pedido (codigo, nombre, descripcion, orden, es_final, color) values
  ('pendiente',  'Pendiente',   'Pedido recibido, pendiente de confirmación de pago', 1, false, 'warning'),
  ('confirmado', 'Confirmado',  'Pago confirmado',                                    2, false, 'info'),
  ('preparando', 'Preparando',  'El pedido se está alistando',                        3, false, 'primary'),
  ('enviado',    'Enviado',     'Entregado a la transportadora',                      4, false, 'primary'),
  ('entregado',  'Entregado',   'Pedido entregado al cliente',                        5, true,  'success'),
  ('cancelado',  'Cancelado',   'Pedido cancelado; el inventario fue repuesto',       6, true,  'danger');

create table public.transiciones_estado_pedido (
  desde text not null references public.estados_pedido (codigo),
  hasta text not null references public.estados_pedido (codigo),
  primary key (desde, hasta),
  check (desde <> hasta)
);

insert into public.transiciones_estado_pedido (desde, hasta) values
  ('pendiente',  'confirmado'), ('pendiente',  'cancelado'),
  ('confirmado', 'preparando'), ('confirmado', 'cancelado'),
  ('preparando', 'enviado'),    ('preparando', 'cancelado'),
  ('enviado',    'entregado');

-- ---------------------------------------------------------------------------
-- Carrito persistente (sincronizable entre web y app móvil)
-- ---------------------------------------------------------------------------
create table public.carritos (
  id         uuid primary key default gen_random_uuid(),
  usuario_id uuid not null unique default auth.uid() references public.profiles (id) on delete cascade,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create trigger carritos_set_updated_at
  before update on public.carritos
  for each row execute function app_private.set_updated_at();

create table public.carrito_detalle (
  id          bigint generated always as identity primary key,
  carrito_id  uuid   not null references public.carritos (id) on delete cascade,
  variante_id bigint not null references public.variantes_producto (id) on delete cascade,
  cantidad    integer not null check (cantidad between 1 and 20),
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint carrito_detalle_variante_unica unique (carrito_id, variante_id)
);

create index carrito_detalle_variante_idx on public.carrito_detalle (variante_id);

create trigger carrito_detalle_set_updated_at
  before update on public.carrito_detalle
  for each row execute function app_private.set_updated_at();

-- Validación de negocio del carrito: aplica sin importar el canal (API propia,
-- Data API de Supabase o app móvil). No se puede agregar más de lo disponible
-- ni productos/variantes inactivos.
create or replace function app_private.validar_item_carrito()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_stock  integer;
  v_activa boolean;
  v_estado text;
  v_nombre text;
begin
  select v.stock, v.activa, p.estado, p.nombre
    into v_stock, v_activa, v_estado, v_nombre
    from public.variantes_producto v
    join public.productos p on p.id = v.producto_id
   where v.id = new.variante_id;

  if not found or not v_activa or v_estado <> 'activo' then
    raise exception 'PRODUCTO_NO_DISPONIBLE'
      using detail = 'El producto seleccionado no está disponible.';
  end if;

  if new.cantidad > v_stock then
    raise exception 'STOCK_INSUFICIENTE'
      using detail = format('Solo hay %s unidad(es) disponibles de "%s" en la talla/color seleccionados.', v_stock, v_nombre);
  end if;

  return new;
end;
$$;

create trigger carrito_detalle_validar
  before insert or update of cantidad, variante_id on public.carrito_detalle
  for each row execute function app_private.validar_item_carrito();

-- ---------------------------------------------------------------------------
-- Pedidos
-- ---------------------------------------------------------------------------
create table public.pedidos (
  id                 uuid primary key default gen_random_uuid(),
  numero             bigint generated always as identity (start with 1001) unique,
  usuario_id         uuid references public.profiles (id) on delete set null,
  estado             text not null default 'pendiente' references public.estados_pedido (codigo),
  canal              text not null default 'web' check (canal in ('web', 'app', 'pos')),
  metodo_pago        text not null check (metodo_pago in ('contra_entrega', 'transferencia', 'efectivo', 'tarjeta')),
  subtotal           numeric(12,2) not null default 0 check (subtotal >= 0),
  costo_envio        numeric(12,2) not null default 0 check (costo_envio >= 0),
  descuento          numeric(12,2) not null default 0 check (descuento >= 0),
  total              numeric(12,2) not null default 0 check (total >= 0),
  pago_recibido      numeric(12,2) check (pago_recibido is null or pago_recibido >= 0),
  cambio             numeric(12,2) check (cambio is null or cambio >= 0),
  direccion_id       uuid references public.direcciones (id) on delete set null,
  direccion_envio    jsonb,
  cliente_nombre     text check (cliente_nombre is null or char_length(cliente_nombre) <= 120),
  notas              text check (notas is null or char_length(notas) <= 500),
  clave_idempotencia text check (clave_idempotencia is null or clave_idempotencia ~ '^[A-Za-z0-9_-]{8,64}$'),
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),
  constraint pedidos_total_cuadra check (total = subtotal + costo_envio - descuento),
  constraint pedidos_idempotencia_unica unique (usuario_id, clave_idempotencia)
);

comment on column public.pedidos.direccion_envio is 'Copia (snapshot) de la dirección al momento de la compra.';
comment on column public.pedidos.pago_recibido   is 'Venta en tienda (POS): efectivo recibido. Heredado de la "caja" original.';

create index pedidos_usuario_idx on public.pedidos (usuario_id, created_at desc);
create index pedidos_estado_idx  on public.pedidos (estado);
create index pedidos_fecha_idx   on public.pedidos (created_at desc);

create trigger pedidos_set_updated_at
  before update on public.pedidos
  for each row execute function app_private.set_updated_at();

create table public.pedido_detalle (
  id              bigint generated always as identity primary key,
  pedido_id       uuid   not null references public.pedidos (id) on delete cascade,
  variante_id     bigint references public.variantes_producto (id) on delete set null,
  producto_id     bigint references public.productos (id) on delete set null,
  producto_nombre text   not null,
  talla           text   not null,
  color           text   not null,
  sku             text   not null,
  imagen_url      text,
  precio_unitario numeric(12,2) not null check (precio_unitario > 0),
  cantidad        integer not null check (cantidad > 0),
  subtotal        numeric(12,2) generated always as (precio_unitario * cantidad) stored
);

comment on column public.pedido_detalle.precio_unitario is 'Precio histórico: el vigente al momento de la compra.';

create index pedido_detalle_pedido_idx   on public.pedido_detalle (pedido_id);
create index pedido_detalle_producto_idx on public.pedido_detalle (producto_id);

create table public.historial_estados_pedido (
  id              bigint generated always as identity primary key,
  pedido_id       uuid not null references public.pedidos (id) on delete cascade,
  estado_anterior text references public.estados_pedido (codigo),
  estado_nuevo    text not null references public.estados_pedido (codigo),
  comentario      text check (comentario is null or char_length(comentario) <= 300),
  usuario_id      uuid references public.profiles (id) on delete set null,
  created_at      timestamptz not null default now()
);

create index historial_estados_pedido_pedido_idx on public.historial_estados_pedido (pedido_id, created_at);

alter table public.movimientos_inventario
  add constraint movimientos_inventario_pedido_fk
  foreign key (pedido_id) references public.pedidos (id) on delete set null;
create index movimientos_inventario_pedido_idx on public.movimientos_inventario (pedido_id);

-- =============================================================================
-- FUNCIONES INTERNAS
-- =============================================================================

-- Registra un movimiento de kardex y actualiza el stock de una variante que
-- YA está bloqueada (FOR UPDATE) por el llamador.
create or replace function app_private.mover_stock(
  p_variante_id bigint,
  p_cantidad    integer,
  p_tipo        text,
  p_motivo      text,
  p_pedido_id   uuid,
  p_usuario_id  uuid
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_anterior integer;
  v_nuevo    integer;
begin
  select stock into v_anterior
    from public.variantes_producto
   where id = p_variante_id
   for update;

  if not found then
    raise exception 'VARIANTE_NO_ENCONTRADA' using detail = 'La variante no existe.';
  end if;

  v_nuevo := v_anterior + p_cantidad;
  if v_nuevo < 0 then
    raise exception 'STOCK_INSUFICIENTE'
      using detail = format('El movimiento dejaría el stock en negativo (actual: %s).', v_anterior);
  end if;

  update public.variantes_producto set stock = v_nuevo where id = p_variante_id;

  insert into public.movimientos_inventario
    (variante_id, tipo, cantidad, stock_anterior, stock_resultante, motivo, pedido_id, usuario_id)
  values
    (p_variante_id, p_tipo, p_cantidad, v_anterior, v_nuevo, p_motivo, p_pedido_id, p_usuario_id);

  return v_nuevo;
end;
$$;

-- Valida líneas [{variante_id, cantidad}], bloquea las variantes, verifica
-- stock y disponibilidad, inserta pedido_detalle con el precio REAL de la BD
-- y descuenta inventario. Devuelve el subtotal calculado.
create or replace function app_private.procesar_lineas_pedido(
  p_pedido_id  uuid,
  p_lineas     jsonb,
  p_tipo_mov   text,
  p_usuario_id uuid
)
returns numeric
language plpgsql
security definer
set search_path = ''
as $$
declare
  r            record;
  v_subtotal   numeric(12,2) := 0;
  v_esperadas  integer;
  v_procesadas integer := 0;
begin
  if p_lineas is null or jsonb_typeof(p_lineas) <> 'array' or jsonb_array_length(p_lineas) = 0 then
    raise exception 'CARRITO_VACIO' using detail = 'No hay productos para procesar.';
  end if;

  if jsonb_array_length(p_lineas) > 100 then
    raise exception 'CANTIDAD_INVALIDA' using detail = 'Demasiadas líneas en el pedido.';
  end if;

  -- Validación estricta de cada línea ANTES de agregar (una cantidad negativa
  -- no puede "compensar" a otra positiva).
  if exists (
    select 1
      from jsonb_array_elements(p_lineas) e
     where jsonb_typeof(e -> 'variante_id') <> 'number'
        or jsonb_typeof(e -> 'cantidad') <> 'number'
        or (e ->> 'cantidad') !~ '^[0-9]+$'
        or (e ->> 'variante_id') !~ '^[0-9]+$'
        or (e ->> 'cantidad')::integer not between 1 and 100
  ) then
    raise exception 'CANTIDAD_INVALIDA' using detail = 'Cada línea debe tener una variante válida y una cantidad entre 1 y 100.';
  end if;

  select count(distinct (e ->> 'variante_id')::bigint)
    into v_esperadas
    from jsonb_array_elements(p_lineas) e;

  for r in
    select v.id           as variante_id,
           v.sku,
           v.stock,
           v.activa,
           coalesce(v.precio, p.precio) as precio,
           p.id           as producto_id,
           p.nombre       as producto_nombre,
           p.estado       as producto_estado,
           t.codigo       as talla,
           co.nombre      as color,
           l.cantidad,
           (select i.url from public.imagenes_producto i
             where i.producto_id = p.id
               and (i.color_id is null or i.color_id = v.color_id)
             order by (i.color_id = v.color_id) desc nulls last, i.es_principal desc, i.orden, i.id
             limit 1)     as imagen_url
      from (
        select (e ->> 'variante_id')::bigint     as variante_id,
               sum((e ->> 'cantidad')::integer)::integer as cantidad
          from jsonb_array_elements(p_lineas) e
         group by 1
      ) l
      join public.variantes_producto v on v.id = l.variante_id
      join public.productos p          on p.id = v.producto_id
      join public.tallas t             on t.id = v.talla_id
      join public.colores co           on co.id = v.color_id
     order by v.id                      -- orden fijo: evita interbloqueos
       for update of v
  loop
    v_procesadas := v_procesadas + 1;

    if not r.activa or r.producto_estado <> 'activo' then
      raise exception 'PRODUCTO_NO_DISPONIBLE'
        using detail = format('"%s" (%s / %s) ya no está disponible.', r.producto_nombre, r.talla, r.color);
    end if;

    if r.cantidad > r.stock then
      raise exception 'STOCK_INSUFICIENTE'
        using detail = format('Stock insuficiente para "%s" (%s / %s): disponible %s, solicitado %s.',
                              r.producto_nombre, r.talla, r.color, r.stock, r.cantidad);
    end if;

    insert into public.pedido_detalle
      (pedido_id, variante_id, producto_id, producto_nombre, talla, color, sku, imagen_url, precio_unitario, cantidad)
    values
      (p_pedido_id, r.variante_id, r.producto_id, r.producto_nombre, r.talla, r.color, r.sku, r.imagen_url, r.precio, r.cantidad);

    perform app_private.mover_stock(r.variante_id, -r.cantidad, p_tipo_mov,
                                    'Pedido ' || p_pedido_id::text, p_pedido_id, p_usuario_id);

    v_subtotal := v_subtotal + (r.precio * r.cantidad);
  end loop;

  if v_procesadas <> v_esperadas then
    raise exception 'PRODUCTO_NO_DISPONIBLE' using detail = 'Uno o más productos ya no existen.';
  end if;

  return v_subtotal;
end;
$$;

-- Repone el inventario de un pedido cancelado (una sola vez).
create or replace function app_private.reponer_inventario_pedido(p_pedido_id uuid, p_usuario_id uuid)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  r record;
begin
  for r in
    select d.variante_id, sum(d.cantidad)::integer as cantidad
      from public.pedido_detalle d
     where d.pedido_id = p_pedido_id
       and d.variante_id is not null
     group by d.variante_id
     order by d.variante_id
  loop
    perform app_private.mover_stock(r.variante_id, r.cantidad, 'devolucion',
                                    'Cancelación pedido ' || p_pedido_id::text, p_pedido_id, p_usuario_id);
  end loop;
end;
$$;

-- =============================================================================
-- FUNCIONES PÚBLICAS (RPC) – invocables por la API propia o por la Data API
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Checkout: crea el pedido a partir del carrito del usuario autenticado.
-- ---------------------------------------------------------------------------
create or replace function public.crear_pedido(
  p_direccion_id       uuid,
  p_metodo_pago        text,
  p_notas              text default null,
  p_clave_idempotencia text default null,
  p_canal              text default 'web'
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid         uuid := (select auth.uid());
  v_carrito_id  uuid;
  v_lineas      jsonb;
  v_pedido_id   uuid;
  v_direccion   public.direcciones%rowtype;
  v_subtotal    numeric(12,2);
  v_envio       numeric(12,2);
  v_gratis      numeric(12,2);
begin
  if v_uid is null then
    raise exception 'NO_AUTENTICADO' using detail = 'Debes iniciar sesión para comprar.', errcode = '42501';
  end if;
  if not public.is_active_user() then
    raise exception 'USUARIO_BLOQUEADO' using detail = 'Tu cuenta no puede realizar compras.', errcode = '42501';
  end if;
  if p_metodo_pago not in ('contra_entrega', 'transferencia') then
    raise exception 'METODO_PAGO_INVALIDO' using detail = 'Método de pago no permitido.';
  end if;
  if p_canal not in ('web', 'app') then
    raise exception 'DATOS_INVALIDOS' using detail = 'Canal no permitido.';
  end if;

  -- Idempotencia: si el mismo cliente reenvía la misma solicitud, se
  -- devuelve el pedido ya creado en lugar de duplicarlo.
  if p_clave_idempotencia is not null then
    select id into v_pedido_id
      from public.pedidos
     where usuario_id = v_uid and clave_idempotencia = p_clave_idempotencia;
    if found then
      return v_pedido_id;
    end if;
  end if;

  select * into v_direccion
    from public.direcciones
   where id = p_direccion_id and usuario_id = v_uid;
  if not found then
    raise exception 'DIRECCION_INVALIDA' using detail = 'La dirección de envío no existe o no te pertenece.';
  end if;

  select id into v_carrito_id from public.carritos where usuario_id = v_uid for update;

  select coalesce(jsonb_agg(jsonb_build_object('variante_id', variante_id, 'cantidad', cantidad)), '[]'::jsonb)
    into v_lineas
    from public.carrito_detalle
   where carrito_id = v_carrito_id;

  if v_carrito_id is null or jsonb_array_length(v_lineas) = 0 then
    raise exception 'CARRITO_VACIO' using detail = 'Tu carrito está vacío.';
  end if;

  insert into public.pedidos
    (usuario_id, estado, canal, metodo_pago, direccion_id, direccion_envio, notas, clave_idempotencia)
  values
    (v_uid, 'pendiente', p_canal, p_metodo_pago, v_direccion.id,
     jsonb_build_object(
       'destinatario', v_direccion.destinatario, 'telefono', v_direccion.telefono,
       'direccion', v_direccion.direccion, 'detalle', v_direccion.detalle,
       'ciudad', v_direccion.ciudad, 'departamento', v_direccion.departamento,
       'codigo_postal', v_direccion.codigo_postal, 'pais', v_direccion.pais),
     nullif(trim(left(p_notas, 500)), ''),
     p_clave_idempotencia)
  returning id into v_pedido_id;

  v_subtotal := app_private.procesar_lineas_pedido(v_pedido_id, v_lineas, 'venta', v_uid);

  v_gratis := app_private.config_numero('envio_gratis_desde', 0);
  v_envio  := case when v_gratis > 0 and v_subtotal >= v_gratis then 0
                   else app_private.config_numero('costo_envio', 0) end;

  update public.pedidos
     set subtotal = v_subtotal, costo_envio = v_envio, descuento = 0, total = v_subtotal + v_envio
   where id = v_pedido_id;

  insert into public.historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, comentario, usuario_id)
  values (v_pedido_id, null, 'pendiente', 'Pedido creado', v_uid);

  delete from public.carrito_detalle where carrito_id = v_carrito_id;

  return v_pedido_id;
end;
$$;

-- ---------------------------------------------------------------------------
-- El cliente puede cancelar SU pedido mientras esté pendiente.
-- ---------------------------------------------------------------------------
create or replace function public.cancelar_mi_pedido(p_pedido_id uuid)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid    uuid := (select auth.uid());
  v_estado text;
begin
  if v_uid is null then
    raise exception 'NO_AUTENTICADO' using detail = 'Debes iniciar sesión.', errcode = '42501';
  end if;

  select estado into v_estado
    from public.pedidos
   where id = p_pedido_id and usuario_id = v_uid
   for update;

  if not found then
    -- Mismo mensaje exista o no el pedido: no revela pedidos ajenos.
    raise exception 'PEDIDO_NO_ENCONTRADO' using detail = 'Pedido no encontrado.';
  end if;
  if v_estado <> 'pendiente' then
    raise exception 'TRANSICION_INVALIDA' using detail = 'Solo se pueden cancelar pedidos pendientes.';
  end if;

  update public.pedidos set estado = 'cancelado' where id = p_pedido_id;
  perform app_private.reponer_inventario_pedido(p_pedido_id, v_uid);
  insert into public.historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, comentario, usuario_id)
  values (p_pedido_id, v_estado, 'cancelado', 'Cancelado por el cliente', v_uid);
end;
$$;

-- ---------------------------------------------------------------------------
-- ADMIN · Cambiar estado de un pedido (respeta transiciones permitidas).
-- ---------------------------------------------------------------------------
create or replace function public.admin_cambiar_estado_pedido(
  p_pedido_id  uuid,
  p_estado     text,
  p_comentario text default null
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid    uuid := (select auth.uid());
  v_actual text;
begin
  if not public.is_admin() then
    raise exception 'ACCESO_DENEGADO' using detail = 'Se requiere rol administrador.', errcode = '42501';
  end if;

  select estado into v_actual from public.pedidos where id = p_pedido_id for update;
  if not found then
    raise exception 'PEDIDO_NO_ENCONTRADO' using detail = 'Pedido no encontrado.';
  end if;

  if not exists (select 1 from public.transiciones_estado_pedido where desde = v_actual and hasta = p_estado) then
    raise exception 'TRANSICION_INVALIDA'
      using detail = format('No se puede pasar de "%s" a "%s".', v_actual, p_estado);
  end if;

  update public.pedidos set estado = p_estado where id = p_pedido_id;

  if p_estado = 'cancelado' then
    perform app_private.reponer_inventario_pedido(p_pedido_id, v_uid);
  end if;

  insert into public.historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, comentario, usuario_id)
  values (p_pedido_id, v_actual, p_estado, nullif(trim(left(p_comentario, 300)), ''), v_uid);
end;
$$;

-- ---------------------------------------------------------------------------
-- ADMIN · Ajuste de inventario (entrada, salida, ajuste) con kardex.
-- Único camino para modificar el stock fuera de ventas / cancelaciones.
-- ---------------------------------------------------------------------------
create or replace function public.admin_ajustar_inventario(
  p_variante_id bigint,
  p_cantidad    integer,
  p_tipo        text,
  p_motivo      text
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
begin
  if not public.is_admin() then
    raise exception 'ACCESO_DENEGADO' using detail = 'Se requiere rol administrador.', errcode = '42501';
  end if;
  if p_tipo not in ('entrada', 'salida', 'ajuste') then
    raise exception 'DATOS_INVALIDOS' using detail = 'Tipo de movimiento inválido.';
  end if;
  if p_cantidad is null or p_cantidad = 0 or abs(p_cantidad) > 100000 then
    raise exception 'CANTIDAD_INVALIDA' using detail = 'La cantidad debe ser distinta de cero.';
  end if;
  if (p_tipo = 'entrada' and p_cantidad < 0) or (p_tipo = 'salida' and p_cantidad > 0) then
    raise exception 'CANTIDAD_INVALIDA' using detail = 'El signo de la cantidad no corresponde al tipo de movimiento.';
  end if;
  if coalesce(trim(p_motivo), '') = '' then
    raise exception 'DATOS_INVALIDOS' using detail = 'Indica el motivo del movimiento.';
  end if;

  return app_private.mover_stock(p_variante_id, p_cantidad, p_tipo, left(trim(p_motivo), 300), null, (select auth.uid()));
end;
$$;

-- ---------------------------------------------------------------------------
-- ADMIN · Venta en tienda física (POS / "Caja").
-- Conserva la funcionalidad original de carrito.html + caja.html (pago
-- recibido y cambio), pero calculada en el servidor y de forma atómica.
-- ---------------------------------------------------------------------------
create or replace function public.admin_registrar_venta_pos(
  p_items          jsonb,
  p_pago_recibido  numeric,
  p_metodo_pago    text default 'efectivo',
  p_cliente_nombre text default null
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid       uuid := (select auth.uid());
  v_pedido_id uuid;
  v_subtotal  numeric(12,2);
begin
  if not public.is_admin() then
    raise exception 'ACCESO_DENEGADO' using detail = 'Se requiere rol administrador.', errcode = '42501';
  end if;
  if p_metodo_pago not in ('efectivo', 'tarjeta', 'transferencia') then
    raise exception 'METODO_PAGO_INVALIDO' using detail = 'Método de pago no permitido.';
  end if;
  if p_pago_recibido is null or p_pago_recibido < 0 then
    raise exception 'PAGO_INSUFICIENTE' using detail = 'Indica el valor recibido.';
  end if;

  insert into public.pedidos (usuario_id, estado, canal, metodo_pago, cliente_nombre)
  values (null, 'entregado', 'pos', p_metodo_pago, nullif(trim(left(p_cliente_nombre, 120)), ''))
  returning id into v_pedido_id;

  v_subtotal := app_private.procesar_lineas_pedido(v_pedido_id, p_items, 'venta_pos', v_uid);

  if p_pago_recibido < v_subtotal then
    raise exception 'PAGO_INSUFICIENTE'
      using detail = format('El pago (%s) es menor que el total (%s).', p_pago_recibido, v_subtotal);
  end if;

  update public.pedidos
     set subtotal = v_subtotal, costo_envio = 0, descuento = 0, total = v_subtotal,
         pago_recibido = p_pago_recibido, cambio = p_pago_recibido - v_subtotal
   where id = v_pedido_id;

  insert into public.historial_estados_pedido (pedido_id, estado_anterior, estado_nuevo, comentario, usuario_id)
  values (v_pedido_id, null, 'entregado', 'Venta en tienda (POS)', v_uid);

  return v_pedido_id;
end;
$$;
