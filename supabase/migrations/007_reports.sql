-- =============================================================================
-- 007 · Reportes y estadísticas (calculados al vuelo, no almacenados)
-- -----------------------------------------------------------------------------
-- Reemplaza "caja.html" (ganancia por día calculada en el navegador).
-- Todas las funciones son SECURITY INVOKER (respetan RLS) y además exigen
-- rol administrador explícitamente. Las ventas excluyen pedidos cancelados.
-- =============================================================================

insert into public.configuracion_tienda (clave, valor, descripcion, publica)
values ('zona_horaria', '"America/Bogota"', 'Zona horaria para agrupar reportes por día', false)
on conflict (clave) do nothing;

create or replace function app_private.zona_horaria()
returns text
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((select valor #>> '{}' from public.configuracion_tienda where clave = 'zona_horaria'), 'America/Bogota')
$$;

create or replace function app_private.exigir_admin()
returns void
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if not public.is_admin() then
    raise exception 'ACCESO_DENEGADO' using detail = 'Se requiere rol administrador.', errcode = '42501';
  end if;
end;
$$;

-- ---------------------------------------------------------------------------
-- Resumen del dashboard
-- ---------------------------------------------------------------------------
create or replace function public.admin_resumen_dashboard()
returns jsonb
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz  text := app_private.zona_horaria();
  v_hoy date := (now() at time zone v_tz)::date;
  v_res jsonb;
begin
  perform app_private.exigir_admin();

  select jsonb_build_object(
    'ventas_hoy',          coalesce(sum(total) filter (where (created_at at time zone v_tz)::date = v_hoy and estado <> 'cancelado'), 0),
    'ventas_mes',          coalesce(sum(total) filter (where date_trunc('month', created_at at time zone v_tz) = date_trunc('month', v_hoy::timestamp) and estado <> 'cancelado'), 0),
    'ventas_totales',      coalesce(sum(total) filter (where estado <> 'cancelado'), 0),
    'pedidos_totales',     count(*),
    'pedidos_mes',         count(*) filter (where date_trunc('month', created_at at time zone v_tz) = date_trunc('month', v_hoy::timestamp)),
    'pedidos_pendientes',  count(*) filter (where estado = 'pendiente'),
    'pedidos_en_proceso',  count(*) filter (where estado in ('confirmado', 'preparando', 'enviado')),
    'ticket_promedio',     coalesce(round(avg(total) filter (where estado <> 'cancelado'), 2), 0)
  ) into v_res
  from public.pedidos;

  v_res := v_res || (
    select jsonb_build_object(
      'clientes_totales', count(*) filter (where rol = 'cliente'),
      'clientes_nuevos_mes', count(*) filter (where rol = 'cliente' and date_trunc('month', fecha_registro at time zone v_tz) = date_trunc('month', v_hoy::timestamp))
    ) from public.profiles
  );

  v_res := v_res || (
    select jsonb_build_object(
      'productos_activos', count(*) filter (where estado = 'activo'),
      'productos_totales', count(*)
    ) from public.productos
  );

  v_res := v_res || (
    select jsonb_build_object(
      'unidades_inventario', coalesce(sum(stock) filter (where activa), 0),
      'valor_inventario',    coalesce(sum(v.stock * coalesce(v.precio, p.precio)) filter (where v.activa), 0),
      'variantes_stock_bajo', count(*) filter (where v.activa and v.stock <= v.stock_minimo),
      'variantes_agotadas',   count(*) filter (where v.activa and v.stock = 0)
    ) from public.variantes_producto v join public.productos p on p.id = v.producto_id
  );

  return v_res;
end;
$$;

-- ---------------------------------------------------------------------------
-- Ventas por período (día | semana | mes), con días sin ventas en cero
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_ventas(
  p_desde      date,
  p_hasta      date,
  p_agrupacion text default 'day'
)
returns table (periodo date, pedidos bigint, unidades bigint, total numeric, web numeric, pos numeric)
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz text := app_private.zona_horaria();
begin
  perform app_private.exigir_admin();
  if p_agrupacion not in ('day', 'week', 'month') then
    raise exception 'DATOS_INVALIDOS' using detail = 'Agrupación inválida (day, week, month).';
  end if;
  if p_hasta < p_desde or p_hasta - p_desde > 3660 then
    raise exception 'DATOS_INVALIDOS' using detail = 'Rango de fechas inválido.';
  end if;

  return query
  with periodos as (
    select generate_series(date_trunc(p_agrupacion, p_desde::timestamp),
                           date_trunc(p_agrupacion, p_hasta::timestamp),
                           ('1 ' || p_agrupacion)::interval)::date as periodo
  ),
  ped as (
    select date_trunc(p_agrupacion, (pe.created_at at time zone v_tz))::date as periodo,
           pe.id, pe.total, pe.canal
      from public.pedidos pe
     where pe.estado <> 'cancelado'
       and (pe.created_at at time zone v_tz)::date between p_desde and p_hasta
  ),
  uni as (
    select ped.periodo, sum(d.cantidad) as unidades
      from ped join public.pedido_detalle d on d.pedido_id = ped.id
     group by ped.periodo
  )
  select pr.periodo,
         count(ped.id)                                                 as pedidos,
         coalesce(max(uni.unidades), 0)::bigint                        as unidades,
         coalesce(sum(ped.total), 0)                                   as total,
         coalesce(sum(ped.total) filter (where ped.canal <> 'pos'), 0) as web,
         coalesce(sum(ped.total) filter (where ped.canal = 'pos'), 0)  as pos
    from periodos pr
    left join ped on ped.periodo = pr.periodo
    left join uni on uni.periodo = pr.periodo
   group by pr.periodo
   order by pr.periodo;
end;
$$;

-- ---------------------------------------------------------------------------
-- Productos más vendidos
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_productos_mas_vendidos(
  p_desde  date,
  p_hasta  date,
  p_limite integer default 10
)
returns table (producto_id bigint, producto_nombre text, unidades bigint, total numeric)
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz text := app_private.zona_horaria();
begin
  perform app_private.exigir_admin();
  return query
  select d.producto_id, max(d.producto_nombre), sum(d.cantidad)::bigint, sum(d.subtotal)
    from public.pedido_detalle d
    join public.pedidos pe on pe.id = d.pedido_id
   where pe.estado <> 'cancelado'
     and (pe.created_at at time zone v_tz)::date between p_desde and p_hasta
   group by d.producto_id
   order by 3 desc, 4 desc
   limit least(greatest(coalesce(p_limite, 10), 1), 100);
end;
$$;

-- ---------------------------------------------------------------------------
-- Categorías más vendidas
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_categorias_mas_vendidas(p_desde date, p_hasta date)
returns table (categoria_id bigint, categoria_nombre text, unidades bigint, total numeric)
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz text := app_private.zona_horaria();
begin
  perform app_private.exigir_admin();
  return query
  select c.id, c.nombre, sum(d.cantidad)::bigint, sum(d.subtotal)
    from public.pedido_detalle d
    join public.pedidos pe   on pe.id = d.pedido_id
    join public.productos p  on p.id = d.producto_id
    join public.categorias c on c.id = p.categoria_id
   where pe.estado <> 'cancelado'
     and (pe.created_at at time zone v_tz)::date between p_desde and p_hasta
   group by c.id, c.nombre
   order by 4 desc;
end;
$$;

-- ---------------------------------------------------------------------------
-- Pedidos por estado
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_pedidos_por_estado(p_desde date, p_hasta date)
returns table (estado text, nombre text, color text, pedidos bigint, total numeric)
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz text := app_private.zona_horaria();
begin
  perform app_private.exigir_admin();
  return query
  select e.codigo, e.nombre, e.color, count(pe.id), coalesce(sum(pe.total), 0)
    from public.estados_pedido e
    left join public.pedidos pe
           on pe.estado = e.codigo
          and (pe.created_at at time zone v_tz)::date between p_desde and p_hasta
   group by e.codigo, e.nombre, e.color, e.orden
   order by e.orden;
end;
$$;

-- ---------------------------------------------------------------------------
-- Inventario bajo
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_inventario_bajo(p_umbral integer default null)
returns table (variante_id bigint, sku text, producto_id bigint, producto_nombre text,
               talla text, color text, stock integer, stock_minimo integer)
language plpgsql
stable
security invoker
set search_path = ''
as $$
begin
  perform app_private.exigir_admin();
  return query
  select v.id, v.sku, p.id, p.nombre, t.codigo, c.nombre, v.stock, v.stock_minimo
    from public.variantes_producto v
    join public.productos p on p.id = v.producto_id
    join public.tallas t    on t.id = v.talla_id
    join public.colores c   on c.id = v.color_id
   where v.activa
     and p.estado <> 'inactivo'
     and v.stock <= coalesce(p_umbral, v.stock_minimo)
   order by v.stock, p.nombre, t.orden;
end;
$$;

-- ---------------------------------------------------------------------------
-- Clientes registrados por período (nuevos y acumulado)
-- ---------------------------------------------------------------------------
create or replace function public.admin_reporte_clientes(
  p_desde      date,
  p_hasta      date,
  p_agrupacion text default 'month'
)
returns table (periodo date, nuevos bigint, acumulado bigint)
language plpgsql
stable
security invoker
set search_path = ''
as $$
declare
  v_tz text := app_private.zona_horaria();
begin
  perform app_private.exigir_admin();
  if p_agrupacion not in ('day', 'week', 'month') then
    raise exception 'DATOS_INVALIDOS' using detail = 'Agrupación inválida (day, week, month).';
  end if;
  if p_hasta < p_desde or p_hasta - p_desde > 3660 then
    raise exception 'DATOS_INVALIDOS' using detail = 'Rango de fechas inválido.';
  end if;

  return query
  with periodos as (
    select generate_series(date_trunc(p_agrupacion, p_desde::timestamp),
                           date_trunc(p_agrupacion, p_hasta::timestamp),
                           ('1 ' || p_agrupacion)::interval)::date as periodo
  ),
  nuevos as (
    select date_trunc(p_agrupacion, fecha_registro at time zone v_tz)::date as periodo, count(*) as n
      from public.profiles
     where rol = 'cliente'
     group by 1
  )
  select pr.periodo,
         coalesce(n.n, 0)::bigint,
         (select count(*) from public.profiles pf
           where pf.rol = 'cliente'
             and date_trunc(p_agrupacion, pf.fecha_registro at time zone v_tz)::date <= pr.periodo)::bigint
    from periodos pr
    left join nuevos n on n.periodo = pr.periodo
   order by pr.periodo;
end;
$$;

revoke execute on function public.admin_resumen_dashboard()                                 from public, anon;
revoke execute on function public.admin_reporte_ventas(date, date, text)                    from public, anon;
revoke execute on function public.admin_reporte_productos_mas_vendidos(date, date, integer) from public, anon;
revoke execute on function public.admin_reporte_categorias_mas_vendidas(date, date)         from public, anon;
revoke execute on function public.admin_reporte_pedidos_por_estado(date, date)              from public, anon;
revoke execute on function public.admin_reporte_inventario_bajo(integer)                    from public, anon;
revoke execute on function public.admin_reporte_clientes(date, date, text)                  from public, anon;

grant execute on function public.admin_resumen_dashboard()                                 to authenticated;
grant execute on function public.admin_reporte_ventas(date, date, text)                    to authenticated;
grant execute on function public.admin_reporte_productos_mas_vendidos(date, date, integer) to authenticated;
grant execute on function public.admin_reporte_categorias_mas_vendidas(date, date)         to authenticated;
grant execute on function public.admin_reporte_pedidos_por_estado(date, date)              to authenticated;
grant execute on function public.admin_reporte_inventario_bajo(integer)                    to authenticated;
grant execute on function public.admin_reporte_clientes(date, date, text)                  to authenticated;

-- ---------------------------------------------------------------------------
-- Endurecimiento del esquema privado: ninguna función interna (mover_stock,
-- procesar_lineas_pedido, ...) puede invocarse directamente. Solo se permiten
-- los dos helpers que usan los reportes SECURITY INVOKER.
-- ---------------------------------------------------------------------------
revoke execute on all functions in schema app_private from public, anon, authenticated;
grant usage on schema app_private to authenticated;
grant execute on function app_private.zona_horaria() to authenticated;
grant execute on function app_private.exigir_admin() to authenticated;
grant execute on function app_private.slugify(text)    to authenticated;
