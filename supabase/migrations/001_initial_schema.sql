-- =============================================================================
-- 001 · Esquema inicial
-- -----------------------------------------------------------------------------
-- Extensiones, esquema privado y funciones utilitarias compartidas.
--
-- Convenciones del proyecto:
--   * Tablas y columnas en español, snake_case (consistentes con el dominio).
--   * Identificadores:  bigint identity para catálogo;  uuid para entidades
--     que se exponen a clientes (pedidos, direcciones, carritos) y así evitar
--     IDs secuenciales adivinables.
--   * Dinero: numeric(12,2)  (moneda por defecto: COP).
--   * Todas las tablas del esquema public tienen RLS (ver 005_rls.sql).
--   * El esquema app_private NO se expone por la Data API de Supabase.
-- =============================================================================

create extension if not exists pgcrypto with schema extensions;
create extension if not exists pg_trgm  with schema extensions;

-- Esquema privado: tablas y funciones internas que nunca deben ser accesibles
-- mediante la API pública (PostgREST) ni por los roles anon / authenticated.
create schema if not exists app_private;
revoke all on schema app_private from public;
revoke all on schema app_private from anon, authenticated;

-- ---------------------------------------------------------------------------
-- Trigger genérico para mantener updated_at
-- ---------------------------------------------------------------------------
create or replace function app_private.set_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

-- ---------------------------------------------------------------------------
-- Slug a partir de un texto (sin tildes, minúsculas, guiones)
-- ---------------------------------------------------------------------------
create or replace function app_private.slugify(p_texto text)
returns text
language sql
immutable
set search_path = ''
as $$
  select trim(both '-' from regexp_replace(
           lower(translate(coalesce(p_texto, ''),
             'ÁÀÄÂÃáàäâãÉÈËÊéèëêÍÌÏÎíìïîÓÒÖÔÕóòöôõÚÙÜÛúùüûÑñÇç',
             'AAAAAaaaaaEEEEeeeeIIIIiiiiOOOOOoooooUUUUuuuuNnCc')),
           '[^a-z0-9]+', '-', 'g'))
$$;

-- ---------------------------------------------------------------------------
-- Rate limiting (ventana fija) usado por el backend para login, registro,
-- recuperación de contraseña y checkout.
-- ---------------------------------------------------------------------------
create table if not exists app_private.rate_limits (
  clave          text primary key,
  ventana_inicio timestamptz not null default now(),
  conteo         integer     not null default 0
);

create or replace function app_private.rate_limit_hit(
  p_clave text,
  p_maximo integer,
  p_ventana_segundos integer
)
returns boolean           -- true = permitido, false = límite excedido
language plpgsql
set search_path = ''
as $$
declare
  v_conteo integer;
begin
  insert into app_private.rate_limits as r (clave, ventana_inicio, conteo)
  values (p_clave, now(), 1)
  on conflict (clave) do update
    set conteo = case
                   when r.ventana_inicio < now() - make_interval(secs => p_ventana_segundos) then 1
                   else r.conteo + 1
                 end,
        ventana_inicio = case
                   when r.ventana_inicio < now() - make_interval(secs => p_ventana_segundos) then now()
                   else r.ventana_inicio
                 end
  returning conteo into v_conteo;

  return v_conteo <= p_maximo;
end;
$$;

revoke all on all tables    in schema app_private from public, anon, authenticated;
revoke all on all functions in schema app_private from public, anon, authenticated;
