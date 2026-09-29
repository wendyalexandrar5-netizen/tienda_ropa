-- =============================================================================
-- 006 · Supabase Storage: bucket de imágenes de productos
-- -----------------------------------------------------------------------------
-- Reemplaza el almacenamiento original de imágenes como base64 dentro de
-- localStorage (agregar.html), que no escalaba y aceptaba cualquier archivo.
--
--   * Bucket público de solo lectura (las fotos del catálogo son públicas).
--   * Límite de 5 MB y solo image/jpeg, image/png, image/webp (validado por
--     Supabase Storage además de la validación del backend PHP).
--   * Solo administradores pueden subir, reemplazar o borrar.
--   * Extensión restringida a jpg/jpeg/png/webp → imposible subir .php, .js,
--     .html, .svg (vector de XSS) u otros ejecutables.
-- =============================================================================

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('productos', 'productos', true, 5242880, array['image/jpeg', 'image/png', 'image/webp'])
on conflict (id) do update
  set public             = excluded.public,
      file_size_limit    = excluded.file_size_limit,
      allowed_mime_types = excluded.allowed_mime_types;

drop policy if exists "productos: lectura publica"  on storage.objects;
drop policy if exists "productos: admin sube"       on storage.objects;
drop policy if exists "productos: admin actualiza"  on storage.objects;
drop policy if exists "productos: admin elimina"    on storage.objects;

create policy "productos: lectura publica" on storage.objects
  for select to anon, authenticated
  using (bucket_id = 'productos');

create policy "productos: admin sube" on storage.objects
  for insert to authenticated
  with check (
    bucket_id = 'productos'
    and (select public.is_admin())
    and lower(storage.extension(name)) in ('jpg', 'jpeg', 'png', 'webp')
    and (storage.foldername(name))[1] = 'productos'
  );

create policy "productos: admin actualiza" on storage.objects
  for update to authenticated
  using (bucket_id = 'productos' and (select public.is_admin()))
  with check (
    bucket_id = 'productos'
    and (select public.is_admin())
    and lower(storage.extension(name)) in ('jpg', 'jpeg', 'png', 'webp')
  );

create policy "productos: admin elimina" on storage.objects
  for delete to authenticated
  using (bucket_id = 'productos' and (select public.is_admin()));
