-- =============================================================================
-- 005 · Seguridad: privilegios (GRANT), Row Level Security y políticas
-- -----------------------------------------------------------------------------
-- Modelo en tres capas:
--   1) GRANT     → qué operaciones/columnas puede tocar cada rol de Postgres
--                  (anon = visitante, authenticated = usuario con sesión).
--   2) RLS       → qué FILAS puede ver/modificar cada usuario.
--   3) Funciones → operaciones de negocio sensibles (checkout, inventario,
--                  estados) como SECURITY DEFINER con validaciones internas.
--
-- Supabase concede por defecto ALL a anon/authenticated sobre tablas nuevas
-- del esquema public; aquí se revoca todo y se concede solo lo necesario.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Revocar todo y conceder el mínimo necesario
-- ---------------------------------------------------------------------------
revoke all on all tables    in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;
revoke execute on all functions in schema public from public, anon, authenticated;

-- Las tablas creadas en el futuro tampoco quedarán abiertas por defecto.
alter default privileges in schema public revoke all on tables    from anon, authenticated;
alter default privileges in schema public revoke all on sequences from anon, authenticated;
alter default privileges in schema public revoke execute on functions from public, anon, authenticated;

-- Catálogo: lectura pública (las filas visibles las limita RLS).
grant select on public.categorias, public.tallas, public.colores, public.productos,
                public.variantes_producto, public.imagenes_producto,
                public.estados_pedido, public.transiciones_estado_pedido,
                public.configuracion_tienda, public.v_catalogo_productos
  to anon, authenticated;

-- Escritura de catálogo: solo usuarios autenticados (RLS exige admin).
grant insert, update, delete on public.categorias, public.tallas, public.colores,
                                public.productos, public.imagenes_producto
  to authenticated;

-- Variantes: el admin puede crear/editar, pero la columna stock NO se puede
-- escribir directamente → solo mediante admin_ajustar_inventario / pedidos.
grant insert (producto_id, talla_id, color_id, sku, precio, stock_minimo, activa)
  on public.variantes_producto to authenticated;
grant update (talla_id, color_id, sku, precio, stock_minimo, activa)
  on public.variantes_producto to authenticated;
grant delete on public.variantes_producto to authenticated;

-- Kardex: solo lectura (para admin, vía RLS). Se escribe desde funciones.
grant select on public.movimientos_inventario to authenticated;

-- Perfil: el usuario solo puede editar sus datos personales.
grant select on public.profiles to authenticated;
grant update (nombre, apellido, telefono) on public.profiles to authenticated;
-- El admin cambia rol/estado mediante la columna específica (RLS + trigger guard).
grant update (rol, estado) on public.profiles to authenticated;

grant select, insert, update, delete on public.direcciones to authenticated;
grant select, insert, delete        on public.favoritos   to authenticated;
grant select, insert, delete        on public.carritos    to authenticated;
grant select, insert, delete        on public.carrito_detalle to authenticated;
grant update (cantidad)             on public.carrito_detalle to authenticated;

-- Pedidos: solo lectura directa. Crear/cambiar estado = funciones RPC.
grant select on public.pedidos, public.pedido_detalle, public.historial_estados_pedido to authenticated;

grant update (valor, descripcion, publica) on public.configuracion_tienda to authenticated;

-- Secuencias identity de tablas que el admin inserta directamente.
grant usage on all sequences in schema public to authenticated;

-- Funciones RPC expuestas.
grant execute on function public.is_admin()                                         to anon, authenticated;
grant execute on function public.is_active_user()                                   to authenticated;
grant execute on function public.crear_pedido(uuid, text, text, text, text)         to authenticated;
grant execute on function public.cancelar_mi_pedido(uuid)                            to authenticated;
grant execute on function public.admin_cambiar_estado_pedido(uuid, text, text)      to authenticated;
grant execute on function public.admin_ajustar_inventario(bigint, integer, text, text) to authenticated;
grant execute on function public.admin_registrar_venta_pos(jsonb, numeric, text, text) to authenticated;

-- ---------------------------------------------------------------------------
-- 2. Activar RLS en TODAS las tablas del esquema public
-- ---------------------------------------------------------------------------
alter table public.profiles                   enable row level security;
alter table public.direcciones                enable row level security;
alter table public.categorias                 enable row level security;
alter table public.tallas                     enable row level security;
alter table public.colores                    enable row level security;
alter table public.productos                  enable row level security;
alter table public.variantes_producto         enable row level security;
alter table public.imagenes_producto          enable row level security;
alter table public.movimientos_inventario     enable row level security;
alter table public.favoritos                  enable row level security;
alter table public.configuracion_tienda       enable row level security;
alter table public.estados_pedido             enable row level security;
alter table public.transiciones_estado_pedido enable row level security;
alter table public.carritos                   enable row level security;
alter table public.carrito_detalle            enable row level security;
alter table public.pedidos                    enable row level security;
alter table public.pedido_detalle             enable row level security;
alter table public.historial_estados_pedido   enable row level security;

-- ---------------------------------------------------------------------------
-- 3. Políticas
--    Nota de rendimiento: se usa (select auth.uid()) / (select public.is_admin())
--    para que Postgres evalúe la función una sola vez por consulta.
-- ---------------------------------------------------------------------------

-- PROFILES ------------------------------------------------------------------
create policy profiles_select_propio_o_admin on public.profiles
  for select to authenticated
  using (id = (select auth.uid()) or (select public.is_admin()));

create policy profiles_update_propio on public.profiles
  for update to authenticated
  using (id = (select auth.uid()))
  with check (id = (select auth.uid()));

create policy profiles_update_admin on public.profiles
  for update to authenticated
  using ((select public.is_admin()))
  with check ((select public.is_admin()));
-- Sin políticas INSERT/DELETE: el perfil lo crea el trigger de auth.users y
-- se elimina en cascada al borrar el usuario de Supabase Auth.

-- DIRECCIONES ---------------------------------------------------------------
create policy direcciones_select_propias_o_admin on public.direcciones
  for select to authenticated
  using (usuario_id = (select auth.uid()) or (select public.is_admin()));

create policy direcciones_insert_propias on public.direcciones
  for insert to authenticated
  with check (usuario_id = (select auth.uid()));

create policy direcciones_update_propias on public.direcciones
  for update to authenticated
  using (usuario_id = (select auth.uid()))
  with check (usuario_id = (select auth.uid()));

create policy direcciones_delete_propias on public.direcciones
  for delete to authenticated
  using (usuario_id = (select auth.uid()));

-- CATEGORÍAS ----------------------------------------------------------------
create policy categorias_select_publico on public.categorias
  for select to anon, authenticated
  using (activa or (select public.is_admin()));

create policy categorias_insert_admin on public.categorias
  for insert to authenticated with check ((select public.is_admin()));
create policy categorias_update_admin on public.categorias
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy categorias_delete_admin on public.categorias
  for delete to authenticated using ((select public.is_admin()));

-- TALLAS / COLORES ----------------------------------------------------------
create policy tallas_select_publico on public.tallas
  for select to anon, authenticated using (true);
create policy tallas_insert_admin on public.tallas
  for insert to authenticated with check ((select public.is_admin()));
create policy tallas_update_admin on public.tallas
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy tallas_delete_admin on public.tallas
  for delete to authenticated using ((select public.is_admin()));

create policy colores_select_publico on public.colores
  for select to anon, authenticated using (true);
create policy colores_insert_admin on public.colores
  for insert to authenticated with check ((select public.is_admin()));
create policy colores_update_admin on public.colores
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy colores_delete_admin on public.colores
  for delete to authenticated using ((select public.is_admin()));

-- PRODUCTOS -----------------------------------------------------------------
create policy productos_select_activos_o_admin on public.productos
  for select to anon, authenticated
  using (
    (estado = 'activo' and exists (select 1 from public.categorias c where c.id = categoria_id and c.activa))
    or (select public.is_admin())
  );

create policy productos_insert_admin on public.productos
  for insert to authenticated with check ((select public.is_admin()));
create policy productos_update_admin on public.productos
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy productos_delete_admin on public.productos
  for delete to authenticated using ((select public.is_admin()));

-- VARIANTES -----------------------------------------------------------------
create policy variantes_select_publico on public.variantes_producto
  for select to anon, authenticated
  using (
    (activa and exists (select 1 from public.productos p where p.id = producto_id and p.estado = 'activo'))
    or (select public.is_admin())
  );

create policy variantes_insert_admin on public.variantes_producto
  for insert to authenticated with check ((select public.is_admin()));
create policy variantes_update_admin on public.variantes_producto
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy variantes_delete_admin on public.variantes_producto
  for delete to authenticated using ((select public.is_admin()));

-- IMÁGENES ------------------------------------------------------------------
create policy imagenes_select_publico on public.imagenes_producto
  for select to anon, authenticated
  using (
    exists (select 1 from public.productos p where p.id = producto_id and p.estado = 'activo')
    or (select public.is_admin())
  );

create policy imagenes_insert_admin on public.imagenes_producto
  for insert to authenticated with check ((select public.is_admin()));
create policy imagenes_update_admin on public.imagenes_producto
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));
create policy imagenes_delete_admin on public.imagenes_producto
  for delete to authenticated using ((select public.is_admin()));

-- INVENTARIO (kardex) -------------------------------------------------------
create policy movimientos_select_admin on public.movimientos_inventario
  for select to authenticated using ((select public.is_admin()));
-- Sin INSERT/UPDATE/DELETE: solo funciones SECURITY DEFINER escriben aquí.

-- FAVORITOS -----------------------------------------------------------------
create policy favoritos_select_propios on public.favoritos
  for select to authenticated using (usuario_id = (select auth.uid()));
create policy favoritos_insert_propios on public.favoritos
  for insert to authenticated with check (usuario_id = (select auth.uid()));
create policy favoritos_delete_propios on public.favoritos
  for delete to authenticated using (usuario_id = (select auth.uid()));

-- CONFIGURACIÓN -------------------------------------------------------------
create policy configuracion_select on public.configuracion_tienda
  for select to anon, authenticated
  using (publica or (select public.is_admin()));
create policy configuracion_update_admin on public.configuracion_tienda
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));

-- ESTADOS (catálogo de solo lectura) ----------------------------------------
create policy estados_pedido_select on public.estados_pedido
  for select to anon, authenticated using (true);
create policy transiciones_select on public.transiciones_estado_pedido
  for select to anon, authenticated using (true);

-- CARRITO -------------------------------------------------------------------
create policy carritos_select_propio on public.carritos
  for select to authenticated using (usuario_id = (select auth.uid()));
create policy carritos_insert_propio on public.carritos
  for insert to authenticated
  with check (usuario_id = (select auth.uid()) and (select public.is_active_user()));
create policy carritos_delete_propio on public.carritos
  for delete to authenticated using (usuario_id = (select auth.uid()));

create policy carrito_detalle_select_propio on public.carrito_detalle
  for select to authenticated
  using (exists (select 1 from public.carritos c where c.id = carrito_id and c.usuario_id = (select auth.uid())));
create policy carrito_detalle_insert_propio on public.carrito_detalle
  for insert to authenticated
  with check (exists (select 1 from public.carritos c where c.id = carrito_id and c.usuario_id = (select auth.uid())));
create policy carrito_detalle_update_propio on public.carrito_detalle
  for update to authenticated
  using (exists (select 1 from public.carritos c where c.id = carrito_id and c.usuario_id = (select auth.uid())))
  with check (exists (select 1 from public.carritos c where c.id = carrito_id and c.usuario_id = (select auth.uid())));
create policy carrito_detalle_delete_propio on public.carrito_detalle
  for delete to authenticated
  using (exists (select 1 from public.carritos c where c.id = carrito_id and c.usuario_id = (select auth.uid())));

-- PEDIDOS -------------------------------------------------------------------
create policy pedidos_select_propios_o_admin on public.pedidos
  for select to authenticated
  using (usuario_id = (select auth.uid()) or (select public.is_admin()));
-- Sin INSERT/UPDATE/DELETE directos: crear_pedido(), cancelar_mi_pedido(),
-- admin_cambiar_estado_pedido() y admin_registrar_venta_pos().

create policy pedido_detalle_select on public.pedido_detalle
  for select to authenticated
  using (exists (select 1 from public.pedidos p
                  where p.id = pedido_id
                    and (p.usuario_id = (select auth.uid()) or (select public.is_admin()))));

create policy historial_estados_select on public.historial_estados_pedido
  for select to authenticated
  using (exists (select 1 from public.pedidos p
                  where p.id = pedido_id
                    and (p.usuario_id = (select auth.uid()) or (select public.is_admin()))));
