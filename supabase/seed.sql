-- =============================================================================
-- DATOS DE PRUEBA (catálogo)
-- -----------------------------------------------------------------------------
-- Supabase CLI ejecuta este archivo automáticamente tras las migraciones en
-- `supabase db reset`. NO debe ejecutarse en producción.
--
-- Las imágenes corresponden a las 20 fotografías del proyecto original
-- (carpeta IR/), ahora servidas desde public/assets/img/productos/.
-- Los usuarios de prueba se crean con scripts/seed_demo.php a través de la
-- API de administración de Supabase Auth (nunca insertando contraseñas).
-- =============================================================================

begin;

-- Categorías -----------------------------------------------------------------
insert into public.categorias (nombre, slug, descripcion, imagen_url, orden) values
  ('Camisetas',  'camisetas',  'Camisetas, camisas y polos con actitud urbana.',       '/assets/img/productos/17.jpg', 1),
  ('Pantalones', 'pantalones', 'Joggers, cargos y shorts para todos los días.',        '/assets/img/productos/20.jpg', 2),
  ('Sudaderas',  'sudaderas',  'Hoodies y buzos oversize de algodón perchado.',        '/assets/img/productos/10.jpg', 3),
  ('Chaquetas',  'chaquetas',  'Chaquetas con cremallera y diseños exclusivos.',       '/assets/img/productos/18.jpg', 4),
  ('Conjuntos',  'conjuntos',  'Sets coordinados de camisa + short listos para usar.', '/assets/img/productos/15.jpg', 5),
  ('Accesorios', 'accesorios', 'Gorros y complementos para cerrar el outfit.',         '/assets/img/productos/7.jpg',  6)
on conflict (slug) do nothing;

-- Tallas ---------------------------------------------------------------------
insert into public.tallas (codigo, nombre, orden) values
  ('XS', 'Extra pequeña', 1), ('S', 'Pequeña', 2), ('M', 'Mediana', 3), ('L', 'Grande', 4),
  ('XL', 'Extra grande', 5), ('XXL', 'Doble extra grande', 6),
  ('28', 'Cintura 28', 10), ('30', 'Cintura 30', 11), ('32', 'Cintura 32', 12),
  ('34', 'Cintura 34', 13), ('36', 'Cintura 36', 14),
  ('U', 'Talla única', 20)
on conflict (codigo) do nothing;

-- Colores --------------------------------------------------------------------
insert into public.colores (nombre, hex) values
  ('Negro', '#111111'), ('Blanco', '#F4F4F2'), ('Gris', '#8A8D91'), ('Beige', '#D8C3A5'),
  ('Rojo', '#C62828'), ('Naranja', '#E67E22'), ('Amarillo', '#FFE600'),
  ('Azul marino', '#1F2A44'), ('Verde oliva', '#556B2F')
on conflict (nombre) do nothing;

-- Productos ------------------------------------------------------------------
with datos (img, categoria, nombre, descripcion, precio, precio_anterior, destacado, colores, tallas) as (
  values
  (1,  'sudaderas',  'Hoodie Layered Urban',     'Hoodie efecto doble capa con mangas contrastantes, bolsillo canguro y cordones gruesos. Algodón perchado 320 g.', 149900, 179900, true,  array['Gris','Negro'],               array['S','M','L','XL']),
  (2,  'pantalones', 'Jogger Skull Print',       'Jogger con estampado de calavera en degradé, pretina elástica y puños ajustables.',                             119900, null,   false, array['Gris'],                       array['S','M','L','XL']),
  (3,  'sudaderas',  'Hoodie Smile Graffiti',    'Hoodie full print estilo graffiti con capucha forrada. Edición limitada.',                                       159900, null,   true,  array['Blanco'],                     array['S','M','L','XL']),
  (4,  'conjuntos',  'Conjunto Flame Summer',    'Set de camiseta y short con llamas negras. Tela fresca ideal para clima cálido.',                                129900, 149900, false, array['Blanco'],                     array['S','M','L']),
  (5,  'pantalones', 'Short Colorado Mesh',      'Short tipo basketball en malla transpirable con tipografía gótica.',                                             79900,  null,   false, array['Blanco','Negro'],             array['S','M','L','XL']),
  (6,  'camisetas',  'Camisa Béisbol NY',        'Camisa estilo béisbol con botones y ribetes en contraste. Corte relajado.',                                      109900, null,   true,  array['Blanco'],                     array['M','L','XL','XXL']),
  (7,  'accesorios', 'Gorro Stitch Logo',        'Gorro tejido con logo bordado. Disponible en tres colores.',                                                     49900,  null,   false, array['Negro','Blanco','Gris'],      array['U']),
  (8,  'accesorios', 'Gorro Alien Embroidery',   'Gorro de punto con bordado alien. Doblez ajustable.',                                                            45900,  null,   false, array['Blanco'],                     array['U']),
  (9,  'chaquetas',  'Chaqueta Bear Duo',        'Chaqueta bicolor con capucha de orejas y cremallera completa.',                                                  189900, null,   true,  array['Blanco'],                     array['S','M','L']),
  (10, 'sudaderas',  'Hoodie Fire Street',       'Hoodie bicolor rojo/negro con cintas estampadas en las mangas. La firma de FIRE CAT.',                           169900, 199900, true,  array['Rojo'],                       array['S','M','L','XL']),
  (11, 'camisetas',  'Camiseta Sakura Hood',     'Camiseta con capucha y mangas largas simuladas, estampado de flor de cerezo.',                                   89900,  null,   false, array['Blanco'],                     array['S','M','L','XL']),
  (12, 'conjuntos',  'Conjunto Texture White',   'Camisa y short en tejido texturizado tipo waffle. Elegante y fresco.',                                           159900, null,   false, array['Blanco'],                     array['S','M','L','XL']),
  (13, 'sudaderas',  'Buzo Essential Crew',      'Buzo cuello redondo minimalista con logo pequeño al pecho.',                                                     99900,  null,   false, array['Beige','Negro'],              array['XS','S','M','L','XL']),
  (14, 'conjuntos',  'Conjunto Tropical Leaf',   'Camisa de botones con hojas tropicales y short beige a juego.',                                                  139900, null,   false, array['Beige'],                      array['S','M','L','XL']),
  (15, 'conjuntos',  'Conjunto Geometric Orange','Set camisa + short con estampado geométrico naranja, negro y blanco.',                                            139900, 159900, true,  array['Naranja'],                    array['S','M','L','XL']),
  (16, 'camisetas',  'Camisa Block Color',       'Camisa manga corta con bloques de color y bordado en el pecho.',                                                 99900,  null,   false, array['Rojo'],                       array['S','M','L','XL']),
  (17, 'camisetas',  'Camiseta Hood K9',         'Camiseta con capucha en contraste y carita minimalista.',                                                        84900,  null,   false, array['Negro'],                      array['S','M','L','XL']),
  (18, 'chaquetas',  'Chaqueta Wings Zip',       'Chaqueta con cremallera y alas estampadas a dos tonos.',                                                         199900, null,   true,  array['Beige'],                      array['S','M','L','XL']),
  (19, 'camisetas',  'Polo Knit Ribbed',         'Polo en punto acanalado de manga corta. Look limpio y sofisticado.',                                             94900,  null,   false, array['Blanco','Negro'],             array['S','M','L','XL']),
  (20, 'pantalones', 'Pantalón Cargo Tactical',  'Cargo con múltiples bolsillos, cordón ajustable y bota elástica.',                                               139900, null,   false, array['Negro','Verde oliva'],        array['28','30','32','34','36'])
),
ins as (
  insert into public.productos (categoria_id, nombre, slug, descripcion, precio, precio_anterior, estado, destacado)
  select c.id, d.nombre, app_private.slugify(d.nombre), d.descripcion, d.precio, d.precio_anterior, 'activo', d.destacado
    from datos d join public.categorias c on c.slug = d.categoria
  on conflict (slug) do nothing
  returning id, slug
)
select count(*) from ins;

-- Imágenes (una principal por producto, tomada de las fotos originales)
insert into public.imagenes_producto (producto_id, url, alt, orden, es_principal)
select p.id, '/assets/img/productos/' || d.img || '.jpg', p.nombre, 0, true
  from (values
    (1,'hoodie-layered-urban'),(2,'jogger-skull-print'),(3,'hoodie-smile-graffiti'),(4,'conjunto-flame-summer'),
    (5,'short-colorado-mesh'),(6,'camisa-beisbol-ny'),(7,'gorro-stitch-logo'),(8,'gorro-alien-embroidery'),
    (9,'chaqueta-bear-duo'),(10,'hoodie-fire-street'),(11,'camiseta-sakura-hood'),(12,'conjunto-texture-white'),
    (13,'buzo-essential-crew'),(14,'conjunto-tropical-leaf'),(15,'conjunto-geometric-orange'),(16,'camisa-block-color'),
    (17,'camiseta-hood-k9'),(18,'chaqueta-wings-zip'),(19,'polo-knit-ribbed'),(20,'pantalon-cargo-tactical')
  ) as d(img, slug)
  join public.productos p on p.slug = d.slug
 where not exists (select 1 from public.imagenes_producto i where i.producto_id = p.id);

-- Variantes: talla × color con cantidades variadas (algunas agotadas o bajas)
with conf (slug, colores, tallas) as (
  values
  ('hoodie-layered-urban',      array['Gris','Negro'],          array['S','M','L','XL']),
  ('jogger-skull-print',        array['Gris'],                  array['S','M','L','XL']),
  ('hoodie-smile-graffiti',     array['Blanco'],                array['S','M','L','XL']),
  ('conjunto-flame-summer',     array['Blanco'],                array['S','M','L']),
  ('short-colorado-mesh',       array['Blanco','Negro'],        array['S','M','L','XL']),
  ('camisa-beisbol-ny',         array['Blanco'],                array['M','L','XL','XXL']),
  ('gorro-stitch-logo',         array['Negro','Blanco','Gris'], array['U']),
  ('gorro-alien-embroidery',    array['Blanco'],                array['U']),
  ('chaqueta-bear-duo',         array['Blanco'],                array['S','M','L']),
  ('hoodie-fire-street',        array['Rojo'],                  array['S','M','L','XL']),
  ('camiseta-sakura-hood',      array['Blanco'],                array['S','M','L','XL']),
  ('conjunto-texture-white',    array['Blanco'],                array['S','M','L','XL']),
  ('buzo-essential-crew',       array['Beige','Negro'],         array['XS','S','M','L','XL']),
  ('conjunto-tropical-leaf',    array['Beige'],                 array['S','M','L','XL']),
  ('conjunto-geometric-orange', array['Naranja'],               array['S','M','L','XL']),
  ('camisa-block-color',        array['Rojo'],                  array['S','M','L','XL']),
  ('camiseta-hood-k9',          array['Negro'],                 array['S','M','L','XL']),
  ('chaqueta-wings-zip',        array['Beige'],                 array['S','M','L','XL']),
  ('polo-knit-ribbed',          array['Blanco','Negro'],        array['S','M','L','XL']),
  ('pantalon-cargo-tactical',   array['Negro','Verde oliva'],   array['28','30','32','34','36'])
)
insert into public.variantes_producto (producto_id, talla_id, color_id, sku, stock, stock_minimo)
select p.id, t.id, c.id,
       upper('FC-' || lpad(p.id::text, 3, '0') || '-' || t.codigo || '-' || left(regexp_replace(app_private.slugify(c.nombre), '-', '', 'g'), 3)),
       -- Cantidades deterministas y variadas (0..24): hay agotados y stock bajo.
       ((p.id * 7 + t.orden * 5 + c.id * 3) % 25),
       3
  from conf
  join public.productos p on p.slug = conf.slug
  cross join lateral unnest(conf.colores) as col(nombre)
  cross join lateral unnest(conf.tallas)  as tal(codigo)
  join public.colores c on c.nombre = col.nombre
  join public.tallas  t on t.codigo = tal.codigo
on conflict (producto_id, talla_id, color_id) do nothing;

-- Kardex: inventario inicial
insert into public.movimientos_inventario (variante_id, tipo, cantidad, stock_anterior, stock_resultante, motivo)
select v.id, 'entrada', v.stock, 0, v.stock, 'Inventario inicial (datos de prueba)'
  from public.variantes_producto v
 where v.stock > 0
   and not exists (select 1 from public.movimientos_inventario m where m.variante_id = v.id);

commit;
