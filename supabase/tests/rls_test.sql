-- =============================================================================
-- PRUEBAS DE SEGURIDAD (RLS, privilegios) Y REGLAS DE NEGOCIO EN LA BD
-- -----------------------------------------------------------------------------
-- Ejecutar sobre una BD con migraciones + seed aplicados:
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f supabase/tests/rls_test.sql
-- Todo ocurre dentro de una transacción que se revierte al final (ROLLBACK),
-- por lo que no deja datos. Cada prueba imprime "OK ..." o aborta con "FALLO".
-- Simula las peticiones de Supabase: rol anon/authenticated + claims del JWT.
-- =============================================================================
\set QUIET on
\set ON_ERROR_STOP on
set client_min_messages = notice;

begin;

-- ---------------------------------------------------------------------------
-- Preparación (como postgres): 3 usuarios de Auth → perfiles por trigger
-- ---------------------------------------------------------------------------
insert into auth.users (instance_id, id, aud, role, email, raw_user_meta_data, created_at, updated_at)
values
 ('00000000-0000-0000-0000-000000000000', 'aaaaaaaa-0000-0000-0000-00000000000a', 'authenticated', 'authenticated', 'test.a@firecat.test', '{"nombre":"Ana","apellido":"Cliente","rol":"admin"}', now(), now()),
 ('00000000-0000-0000-0000-000000000000', 'bbbbbbbb-0000-0000-0000-00000000000b', 'authenticated', 'authenticated', 'test.b@firecat.test', '{"nombre":"Beto","apellido":"Cliente"}', now(), now()),
 ('00000000-0000-0000-0000-000000000000', 'dddddddd-0000-0000-0000-00000000000d', 'authenticated', 'authenticated', 'test.admin@firecat.test', '{"nombre":"Admin","apellido":"Test"}', now(), now());

do $$ begin
  if (select rol from public.profiles where id = 'aaaaaaaa-0000-0000-0000-00000000000a') <> 'cliente' then
    raise exception 'FALLO: el registro aceptó rol desde metadatos';
  end if;
  raise notice 'OK 00 · El trigger crea perfiles con rol cliente (ignora "rol" enviado en el registro)';
end $$;

update public.profiles set rol = 'admin' where id = 'dddddddd-0000-0000-0000-00000000000d';

-- Producto borrador (no debe ser visible al público)
insert into public.productos (categoria_id, nombre, slug, precio, estado)
select id, 'Producto Borrador Test', 'producto-borrador-test', 50000, 'borrador' from public.categorias where slug = 'camisetas';

-- Variante de prueba con stock controlado = 2 unidades a precio 80.000
insert into public.productos (categoria_id, nombre, slug, precio, estado)
select id, 'Camiseta Oversize Test', 'camiseta-oversize-test', 80000, 'activo' from public.categorias where slug = 'camisetas';
insert into public.variantes_producto (producto_id, talla_id, color_id, sku, stock)
select p.id, t.id, c.id, 'TEST-OVR-M-NEG', 2
  from public.productos p, public.tallas t, public.colores c
 where p.slug = 'camiseta-oversize-test' and t.codigo = 'M' and c.nombre = 'Negro';
insert into public.variantes_producto (producto_id, talla_id, color_id, sku, stock)
select p.id, t.id, c.id, 'TEST-OVR-L-BLA', 0
  from public.productos p, public.tallas t, public.colores c
 where p.slug = 'camiseta-oversize-test' and t.codigo = 'L' and c.nombre = 'Blanco';

create temp table ids as
select (select id from public.variantes_producto where sku = 'TEST-OVR-M-NEG') as v_ok,
       (select id from public.variantes_producto where sku = 'TEST-OVR-L-BLA') as v_agotada,
       (select id from public.productos where slug = 'camiseta-oversize-test') as p_test;
grant select on ids to anon, authenticated;

-- =============================================================================
-- VISITANTE (anon)
-- =============================================================================
set local role anon;
select set_config('request.jwt.claims', '{"role":"anon"}', true) \g /dev/null

do $$ begin
  if exists (select 1 from public.productos where estado <> 'activo') then
    raise exception 'FALLO: anon ve productos no activos';
  end if;
  if (select count(*) from public.productos) < 20 then
    raise exception 'FALLO: anon no ve el catálogo activo';
  end if;
  raise notice 'OK 01 · Anónimo ve solo productos activos';
end $$;

do $$ begin
  perform 1 from public.pedidos;
  raise exception 'FALLO: anon puede leer pedidos';
exception when insufficient_privilege then
  raise notice 'OK 02 · Anónimo NO puede leer pedidos';
end $$;

do $$ begin
  perform 1 from public.profiles;
  raise exception 'FALLO: anon puede leer perfiles';
exception when insufficient_privilege then
  raise notice 'OK 03 · Anónimo NO puede leer perfiles/clientes';
end $$;

do $$ begin
  insert into public.categorias (nombre, slug) values ('Hack', 'hack');
  raise exception 'FALLO: anon creó una categoría';
exception when insufficient_privilege then
  raise notice 'OK 04 · Anónimo NO puede crear categorías/productos';
end $$;

do $$ begin
  perform public.crear_pedido(gen_random_uuid(), 'contra_entrega');
  raise exception 'FALLO: anon ejecutó crear_pedido';
exception when insufficient_privilege then
  raise notice 'OK 05 · Anónimo NO puede ejecutar crear_pedido';
end $$;

do $$ begin
  perform 1 from public.movimientos_inventario;
  raise exception 'FALLO: anon lee kardex';
exception when insufficient_privilege then
  raise notice 'OK 06 · Anónimo NO puede leer el inventario (kardex)';
end $$;

reset role;

-- =============================================================================
-- CLIENTE A
-- =============================================================================
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"aaaaaaaa-0000-0000-0000-00000000000a","role":"authenticated"}', true) \g /dev/null

do $$ begin
  if (select count(*) from public.profiles) <> 1 then
    raise exception 'FALLO: cliente ve perfiles ajenos';
  end if;
  raise notice 'OK 07 · Cliente solo ve su propio perfil';
end $$;

do $$ begin
  update public.profiles set rol = 'admin' where id = 'aaaaaaaa-0000-0000-0000-00000000000a';
  raise exception 'FALLO: cliente se autoasignó rol admin';
exception when insufficient_privilege then
  raise notice 'OK 08 · Cliente NO puede cambiarse el rol a admin';
end $$;

do $$ declare n int; begin
  update public.profiles set nombre = 'Hackeado' where id = 'bbbbbbbb-0000-0000-0000-00000000000b';
  get diagnostics n = row_count;
  if n <> 0 then raise exception 'FALLO: cliente modificó perfil ajeno'; end if;
  update public.profiles set nombre = 'Ana María' where id = 'aaaaaaaa-0000-0000-0000-00000000000a';
  get diagnostics n = row_count;
  if n <> 1 then raise exception 'FALLO: cliente no pudo editar su perfil'; end if;
  raise notice 'OK 09 · Cliente edita su perfil y NO el de otro usuario';
end $$;

do $$ begin
  insert into public.productos (categoria_id, nombre, slug, precio, estado) values (1, 'Hack', 'hack', 1, 'activo');
  raise exception 'FALLO: cliente creó producto';
exception when insufficient_privilege then
  raise notice 'OK 10 · Cliente NO puede crear productos';
end $$;

do $$ declare n int; begin
  update public.productos set precio = 1 where id = (select p_test from ids);
  get diagnostics n = row_count;
  if n <> 0 then raise exception 'FALLO: cliente modificó precio'; end if;
  delete from public.productos where id = (select p_test from ids);
  get diagnostics n = row_count;
  if n <> 0 then raise exception 'FALLO: cliente eliminó producto'; end if;
  raise notice 'OK 11 · Cliente NO puede modificar precios ni eliminar productos';
end $$;

do $$ begin
  update public.variantes_producto set stock = 999 where id = (select v_ok from ids);
  raise exception 'FALLO: cliente modificó stock';
exception when insufficient_privilege then
  raise notice 'OK 12 · Cliente NO puede modificar el inventario (columna stock)';
end $$;

do $$ begin
  insert into public.movimientos_inventario (variante_id, tipo, cantidad, stock_anterior, stock_resultante)
  values ((select v_ok from ids), 'entrada', 100, 2, 102);
  raise exception 'FALLO: cliente escribió en kardex';
exception when insufficient_privilege then
  raise notice 'OK 13 · Cliente NO puede escribir movimientos de inventario';
end $$;

do $$ begin
  perform public.admin_ajustar_inventario((select v_ok from ids), 50, 'entrada', 'hack');
  raise exception 'FALLO: cliente ajustó inventario';
exception when insufficient_privilege then
  raise notice 'OK 14 · Cliente NO puede usar funciones administrativas de inventario';
end $$;

do $$ begin
  perform public.admin_resumen_dashboard();
  raise exception 'FALLO: cliente leyó estadísticas';
exception when insufficient_privilege then
  raise notice 'OK 15 · Cliente NO puede consultar estadísticas administrativas';
end $$;

-- Carrito
insert into public.carritos default values;

do $$ begin
  insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
  values ((select id from public.carritos), (select v_ok from ids), 5);
  raise exception 'FALLO: se agregó más cantidad que el stock';
exception when raise_exception then
  if sqlerrm <> 'STOCK_INSUFICIENTE' then raise; end if;
  raise notice 'OK 16 · No se puede agregar al carrito más que el stock disponible';
end $$;

do $$ begin
  insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
  values ((select id from public.carritos), (select v_agotada from ids), 1);
  raise exception 'FALLO: se agregó variante agotada';
exception when raise_exception then
  if sqlerrm <> 'STOCK_INSUFICIENTE' then raise; end if;
  raise notice 'OK 17 · No se puede agregar una variante sin stock';
end $$;

do $$ begin
  insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
  values ((select id from public.carritos), (select v_ok from ids), -3);
  raise exception 'FALLO: se aceptó cantidad negativa';
exception when check_violation then
  raise notice 'OK 18 · Cantidades negativas o cero son rechazadas';
end $$;

insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
values ((select id from public.carritos), (select v_ok from ids), 1);

do $$ begin
  insert into public.direcciones (usuario_id, destinatario, telefono, direccion, ciudad, departamento)
  values ('bbbbbbbb-0000-0000-0000-00000000000b', 'X', '3001234567', 'Calle falsa 123', 'Bogotá', 'Cundinamarca');
  raise exception 'FALLO: cliente creó dirección a nombre de otro';
exception when insufficient_privilege or check_violation then
  raise notice 'OK 19 · Cliente NO puede suplantar el usuario_id (manipulación de ID)';
end $$;

insert into public.direcciones (destinatario, telefono, direccion, ciudad, departamento, es_principal)
values ('Ana Cliente', '3001234567', 'Calle 10 # 20-30', 'Medellín', 'Antioquia', true);

do $$ begin
  insert into public.pedidos (usuario_id, metodo_pago, subtotal, total) values ('aaaaaaaa-0000-0000-0000-00000000000a', 'contra_entrega', 1, 1);
  raise exception 'FALLO: cliente insertó pedido directo';
exception when insufficient_privilege then
  raise notice 'OK 20 · Cliente NO puede crear pedidos saltándose el checkout';
end $$;

-- Checkout correcto
do $$ declare v_ped uuid; v_det record; v_stock int; begin
  v_ped := public.crear_pedido((select id from public.direcciones limit 1), 'contra_entrega', 'Test', 'test-idem-0001');
  select precio_unitario, cantidad into v_det from public.pedido_detalle where pedido_id = v_ped;
  if v_det.precio_unitario <> 80000 or v_det.cantidad <> 1 then
    raise exception 'FALLO: precio/cantidad del pedido incorrectos';
  end if;
  if exists (select 1 from public.carrito_detalle) then
    raise exception 'FALLO: carrito no se vació';
  end if;
  select stock into v_stock from public.variantes_producto where id = (select v_ok from ids);
  if v_stock <> 1 then raise exception 'FALLO: stock no descontado (%).', v_stock; end if;
  if (select total from public.pedidos where id = v_ped) <> 80000 + 12000 then
    raise exception 'FALLO: total mal calculado';
  end if;
  -- Idempotencia: la misma clave devuelve el mismo pedido.
  if public.crear_pedido((select id from public.direcciones limit 1), 'contra_entrega', 'Test', 'test-idem-0001') <> v_ped then
    raise exception 'FALLO: idempotencia';
  end if;
  raise notice 'OK 21 · Checkout atómico: precio real de BD, stock descontado, carrito vaciado, envío calculado, idempotente';
end $$;

do $$ begin
  update public.pedidos set total = 1;
  raise exception 'FALLO: cliente modificó total';
exception when insufficient_privilege then
  raise notice 'OK 22 · Cliente NO puede modificar el total ni el propietario del pedido';
end $$;

do $$ begin
  perform public.crear_pedido((select id from public.direcciones limit 1), 'contra_entrega');
  raise exception 'FALLO: pedido con carrito vacío';
exception when raise_exception then
  if sqlerrm <> 'CARRITO_VACIO' then raise; end if;
  raise notice 'OK 23 · No se puede confirmar un pedido con el carrito vacío';
end $$;

reset role;

-- =============================================================================
-- CLIENTE B
-- =============================================================================
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"bbbbbbbb-0000-0000-0000-00000000000b","role":"authenticated"}', true) \g /dev/null

do $$ begin
  if exists (select 1 from public.pedidos) or exists (select 1 from public.pedido_detalle)
     or exists (select 1 from public.direcciones) then
    raise exception 'FALLO: cliente B ve datos de A';
  end if;
  raise notice 'OK 24 · Cliente B NO ve pedidos, detalles ni direcciones de A';
end $$;

reset role;
create temp table pedido_a as select id from public.pedidos where usuario_id = 'aaaaaaaa-0000-0000-0000-00000000000a';
grant select on pedido_a to authenticated;
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"bbbbbbbb-0000-0000-0000-00000000000b","role":"authenticated"}', true) \g /dev/null

do $$ begin
  perform public.cancelar_mi_pedido((select id from pedido_a));
  raise exception 'FALLO: B canceló pedido de A';
exception when raise_exception then
  if sqlerrm <> 'PEDIDO_NO_ENCONTRADO' then raise; end if;
  raise notice 'OK 25 · Cliente B NO puede cancelar/modificar el pedido de A (aunque conozca su id)';
end $$;

-- B pone en su carrito la última unidad; luego alguien la compra antes.
insert into public.carritos default values;
insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
values ((select id from public.carritos), (select v_ok from ids), 1);
insert into public.direcciones (destinatario, telefono, direccion, ciudad, departamento)
values ('Beto', '3109876543', 'Carrera 5 # 1-2', 'Cali', 'Valle del Cauca');

reset role;
update public.variantes_producto set stock = 0 where id = (select v_ok from ids);  -- venta concurrente simulada
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"bbbbbbbb-0000-0000-0000-00000000000b","role":"authenticated"}', true) \g /dev/null

do $$ begin
  perform public.crear_pedido((select id from public.direcciones limit 1), 'contra_entrega');
  raise exception 'FALLO: se compró sin stock';
exception when raise_exception then
  if sqlerrm <> 'STOCK_INSUFICIENTE' then raise; end if;
  raise notice 'OK 26 · El stock se revalida al confirmar: no se puede comprar sin stock';
end $$;

do $$ begin
  if (select count(*) from public.pedidos) <> 0 then
    raise exception 'FALLO: quedó un pedido parcial';
  end if;
  raise notice 'OK 27 · Atomicidad: el pedido fallido no dejó registros parciales';
end $$;

do $$ begin
  perform public.crear_pedido((select id from public.direcciones limit 1), 'efectivo_gratis');
  raise exception 'FALLO: método de pago arbitrario aceptado';
exception when raise_exception then
  raise notice 'OK 28 · Parámetros manipulados (método de pago) son rechazados';
end $$;

reset role;
update public.variantes_producto set stock = 1 where id = (select v_ok from ids);

-- Usuario bloqueado no puede comprar (operación de backend: sin claims de usuario)
select set_config('request.jwt.claims', '', true) \g /dev/null
update public.profiles set estado = 'bloqueado' where id = 'bbbbbbbb-0000-0000-0000-00000000000b';
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"bbbbbbbb-0000-0000-0000-00000000000b","role":"authenticated"}', true) \g /dev/null
do $$ begin
  perform public.crear_pedido((select id from public.direcciones limit 1), 'contra_entrega');
  raise exception 'FALLO: usuario bloqueado compró';
exception when insufficient_privilege then
  raise notice 'OK 29 · Un usuario bloqueado no puede realizar pedidos';
end $$;
reset role;

-- =============================================================================
-- ADMINISTRADOR
-- =============================================================================
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"dddddddd-0000-0000-0000-00000000000d","role":"authenticated"}', true) \g /dev/null

do $$ begin
  if (select count(*) from public.profiles) < 3 then raise exception 'FALLO: admin no ve clientes'; end if;
  if (select count(*) from public.pedidos) < 1 then raise exception 'FALLO: admin no ve pedidos'; end if;
  if not exists (select 1 from public.productos where estado = 'borrador') then raise exception 'FALLO: admin no ve borradores'; end if;
  raise notice 'OK 30 · Admin ve clientes, todos los pedidos y productos en borrador';
end $$;

-- Precio histórico: el admin sube el precio; el pedido conserva 80.000
update public.productos set precio = 95000 where slug = 'camiseta-oversize-test';
do $$ begin
  if (select precio_unitario from public.pedido_detalle where pedido_id = (select id from pedido_a)) <> 80000 then
    raise exception 'FALLO: el pedido perdió su precio histórico';
  end if;
  raise notice 'OK 31 · Precio histórico: el pedido conserva $80.000 tras subir el producto a $95.000';
end $$;

do $$ begin
  perform public.admin_cambiar_estado_pedido((select id from pedido_a), 'entregado');
  raise exception 'FALLO: transición inválida aceptada';
exception when raise_exception then
  if sqlerrm <> 'TRANSICION_INVALIDA' then raise; end if;
  raise notice 'OK 32 · Transiciones de estado inválidas son rechazadas (pendiente → entregado)';
end $$;

do $$ declare v_stock int; begin
  perform public.admin_cambiar_estado_pedido((select id from pedido_a), 'confirmado', 'Pago recibido');
  perform public.admin_cambiar_estado_pedido((select id from pedido_a), 'cancelado', 'Cliente desistió');
  select stock into v_stock from public.variantes_producto where id = (select v_ok from ids);
  if v_stock <> 2 then raise exception 'FALLO: cancelación no repuso stock (%).', v_stock; end if;
  if (select count(*) from public.historial_estados_pedido where pedido_id = (select id from pedido_a)) <> 3 then
    raise exception 'FALLO: historial incompleto';
  end if;
  raise notice 'OK 33 · Cancelar un pedido repone el inventario y queda en el historial';
end $$;

do $$ declare v int; begin
  v := public.admin_ajustar_inventario((select v_ok from ids), 10, 'entrada', 'Compra a proveedor');
  if v <> 12 then raise exception 'FALLO: ajuste de inventario'; end if;
  if not exists (select 1 from public.movimientos_inventario where variante_id = (select v_ok from ids) and motivo = 'Compra a proveedor') then
    raise exception 'FALLO: kardex';
  end if;
  begin
    perform public.admin_ajustar_inventario((select v_ok from ids), -100, 'salida', 'Salida imposible');
    raise exception 'FALLO: stock negativo permitido';
  exception when raise_exception then
    if sqlerrm <> 'STOCK_INSUFICIENTE' then raise; end if;
  end;
  raise notice 'OK 34 · Admin ajusta inventario con kardex; nunca queda stock negativo';
end $$;

do $$ declare v_ped uuid; begin
  begin
    perform public.admin_registrar_venta_pos(
      jsonb_build_array(jsonb_build_object('variante_id', (select v_ok from ids), 'cantidad', 2)), 1000);
    raise exception 'FALLO: POS con pago insuficiente';
  exception when raise_exception then
    if sqlerrm <> 'PAGO_INSUFICIENTE' then raise; end if;
  end;
  if (select stock from public.variantes_producto where id = (select v_ok from ids)) <> 12 then
    raise exception 'FALLO: POS fallida alteró stock';
  end if;
  v_ped := public.admin_registrar_venta_pos(
    jsonb_build_array(jsonb_build_object('variante_id', (select v_ok from ids), 'cantidad', 2)), 200000, 'efectivo', 'Cliente mostrador');
  if (select cambio from public.pedidos where id = v_ped) <> 200000 - 2 * 95000 then
    raise exception 'FALLO: cambio POS mal calculado';
  end if;
  raise notice 'OK 35 · Venta POS (caja original): cambio calculado en servidor, pago insuficiente rechazado';
end $$;

do $$ begin
  perform public.admin_registrar_venta_pos(
    jsonb_build_array(jsonb_build_object('variante_id', (select v_ok from ids), 'cantidad', 5),
                      jsonb_build_object('variante_id', (select v_ok from ids), 'cantidad', -5)), 999999);
  raise exception 'FALLO: cantidad negativa compensatoria aceptada';
exception when raise_exception then
  if sqlerrm <> 'CANTIDAD_INVALIDA' then raise; end if;
  raise notice 'OK 36 · Líneas con cantidades negativas no pueden "compensar" otras';
end $$;

do $$ begin
  update public.profiles set rol = 'cliente' where id = 'dddddddd-0000-0000-0000-00000000000d';
  raise exception 'FALLO: admin se degradó a sí mismo';
exception when insufficient_privilege then
  raise notice 'OK 37 · Un admin no puede cambiar su propio rol/estado';
end $$;

do $$ begin
  perform public.admin_resumen_dashboard();
  perform * from public.admin_reporte_ventas(current_date - 30, current_date, 'day');
  perform * from public.admin_reporte_productos_mas_vendidos(current_date - 30, current_date, 5);
  perform * from public.admin_reporte_categorias_mas_vendidas(current_date - 30, current_date);
  perform * from public.admin_reporte_pedidos_por_estado(current_date - 30, current_date);
  perform * from public.admin_reporte_inventario_bajo(null);
  perform * from public.admin_reporte_clientes(current_date - 365, current_date, 'month');
  raise notice 'OK 38 · Reportes administrativos disponibles para el admin';
end $$;

do $$ begin
  perform app_private.mover_stock((select v_ok from ids), 1000, 'entrada', 'x', null, null);
  raise exception 'FALLO: función interna invocable';
exception when insufficient_privilege then
  raise notice 'OK 39 · Las funciones internas (app_private) no son invocables directamente';
end $$;

reset role;

do $$ begin
  raise notice '==============================================';
  raise notice ' TODAS LAS PRUEBAS RLS / NEGOCIO PASARON (39)';
  raise notice '==============================================';
end $$;

rollback;
