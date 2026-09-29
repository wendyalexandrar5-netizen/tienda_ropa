-- =============================================================================
-- 002 · Perfiles, roles y direcciones
-- -----------------------------------------------------------------------------
-- Las credenciales (email + contraseña cifrada) viven EXCLUSIVAMENTE en
-- auth.users, administrada por Supabase Auth. Aquí solo se guardan datos de
-- perfil asociados 1:1 al usuario autenticado.
--
-- Sustituye al almacenamiento original del proyecto, que guardaba la
-- contraseña en texto plano en /usuarios/<email>/data.json.
-- =============================================================================

create table public.profiles (
  id             uuid primary key references auth.users (id) on delete cascade,
  email          text        not null,
  nombre         text        not null default '' check (char_length(nombre)   <= 80),
  apellido       text        not null default '' check (char_length(apellido) <= 80),
  telefono       text                 check (telefono is null or telefono ~ '^[0-9+() -]{7,20}$'),
  rol            text        not null default 'cliente' check (rol in ('cliente', 'admin')),
  estado         text        not null default 'activo'  check (estado in ('activo', 'bloqueado')),
  fecha_registro timestamptz not null default now(),
  updated_at     timestamptz not null default now()
);

comment on table  public.profiles is 'Perfil de cada usuario de Supabase Auth (1:1 con auth.users).';
comment on column public.profiles.rol is 'cliente | admin. Solo modificable por un administrador.';
comment on column public.profiles.estado is 'activo | bloqueado. Un usuario bloqueado no puede comprar ni iniciar sesión en el backend.';

create index profiles_rol_idx on public.profiles (rol);
create index profiles_fecha_registro_idx on public.profiles (fecha_registro);

create trigger profiles_set_updated_at
  before update on public.profiles
  for each row execute function app_private.set_updated_at();

-- ---------------------------------------------------------------------------
-- Helpers de autorización (usados por las políticas RLS y las funciones).
-- SECURITY DEFINER para poder leer profiles sin provocar recursión de RLS.
-- ---------------------------------------------------------------------------
create or replace function public.is_admin()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
      from public.profiles p
     where p.id = (select auth.uid())
       and p.rol = 'admin'
       and p.estado = 'activo'
  );
$$;

create or replace function public.is_active_user()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
      from public.profiles p
     where p.id = (select auth.uid())
       and p.estado = 'activo'
  );
$$;

-- ---------------------------------------------------------------------------
-- Creación automática del perfil al registrarse en Supabase Auth.
-- El rol SIEMPRE es 'cliente': nunca se toma de los metadatos enviados por
-- el cliente (evita escalamiento de privilegios en el registro).
-- ---------------------------------------------------------------------------
create or replace function app_private.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  insert into public.profiles (id, email, nombre, apellido, telefono)
  values (
    new.id,
    coalesce(new.email, ''),
    left(coalesce(trim(new.raw_user_meta_data ->> 'nombre'), ''), 80),
    left(coalesce(trim(new.raw_user_meta_data ->> 'apellido'), ''), 80),
    nullif(regexp_replace(coalesce(new.raw_user_meta_data ->> 'telefono', ''), '[^0-9+() -]', '', 'g'), '')
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function app_private.handle_new_user();

-- Mantener el email del perfil sincronizado con auth.users.
create or replace function app_private.handle_user_email_change()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.email is distinct from old.email then
    update public.profiles set email = coalesce(new.email, '') where id = new.id;
  end if;
  return new;
end;
$$;

create trigger on_auth_user_email_changed
  after update of email on auth.users
  for each row execute function app_private.handle_user_email_change();

-- ---------------------------------------------------------------------------
-- Protección de columnas sensibles del perfil.
-- Además de los GRANT por columna (005_rls.sql), este trigger impide que un
-- cliente cambie su rol, estado o email, y que un admin se degrade a sí mismo.
-- ---------------------------------------------------------------------------
create or replace function app_private.profiles_guard()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_uid uuid := (select auth.uid());
begin
  -- Procesos de backend sin sesión de usuario (service_role / postgres).
  if v_uid is null then
    return new;
  end if;

  if new.id <> old.id then
    raise exception 'OPERACION_NO_PERMITIDA' using detail = 'No se puede cambiar el identificador del perfil.';
  end if;

  if not public.is_admin() then
    if new.rol <> old.rol or new.estado <> old.estado or new.email <> old.email
       or new.fecha_registro <> old.fecha_registro then
      raise exception 'OPERACION_NO_PERMITIDA' using detail = 'No tienes permiso para modificar estos datos del perfil.', errcode = '42501';
    end if;
  elsif new.id = v_uid and (new.rol <> old.rol or new.estado <> old.estado) then
    raise exception 'OPERACION_NO_PERMITIDA' using detail = 'Un administrador no puede cambiar su propio rol o estado.', errcode = '42501';
  end if;

  return new;
end;
$$;

create trigger profiles_guard
  before update on public.profiles
  for each row execute function app_private.profiles_guard();

-- ---------------------------------------------------------------------------
-- Direcciones de envío del cliente
-- ---------------------------------------------------------------------------
create table public.direcciones (
  id            uuid primary key default gen_random_uuid(),
  usuario_id    uuid not null default auth.uid() references public.profiles (id) on delete cascade,
  alias         text not null default 'Casa'  check (char_length(alias) between 1 and 40),
  destinatario  text not null check (char_length(destinatario) between 2 and 120),
  telefono      text not null check (telefono ~ '^[0-9+() -]{7,20}$'),
  direccion     text not null check (char_length(direccion) between 5 and 200),
  detalle       text          check (detalle is null or char_length(detalle) <= 200),
  ciudad        text not null check (char_length(ciudad) between 2 and 80),
  departamento  text not null check (char_length(departamento) between 2 and 80),
  codigo_postal text          check (codigo_postal is null or codigo_postal ~ '^[0-9A-Za-z -]{3,12}$'),
  pais          text not null default 'Colombia' check (char_length(pais) between 2 and 60),
  es_principal  boolean not null default false,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index direcciones_usuario_idx on public.direcciones (usuario_id);
create unique index direcciones_una_principal_idx
  on public.direcciones (usuario_id) where es_principal;

create trigger direcciones_set_updated_at
  before update on public.direcciones
  for each row execute function app_private.set_updated_at();

-- Al marcar una dirección como principal, desmarcar las demás del usuario.
create or replace function app_private.direcciones_unica_principal()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.es_principal then
    update public.direcciones
       set es_principal = false
     where usuario_id = new.usuario_id
       and id <> new.id
       and es_principal;
  end if;
  return new;
end;
$$;

create trigger direcciones_unica_principal
  before insert or update of es_principal on public.direcciones
  for each row execute function app_private.direcciones_unica_principal();
