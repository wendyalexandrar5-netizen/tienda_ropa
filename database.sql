-- =============================================================================
--  FIRE CAT · Tienda online de ropa
--  Script completo de base de datos (MySQL 5.7+ / MariaDB 10.4+ · XAMPP)
--
--  Importación: phpMyAdmin → pestaña "Importar" → seleccionar este archivo → Continuar.
--  El script crea la base de datos `tienda_ropa`, todas las tablas, relaciones,
--  índices, una vista y los datos de prueba.
--
--  ATENCIÓN: el script ELIMINA la base de datos `tienda_ropa` si ya existe.
--
--  Credenciales de DESARROLLO (las contraseñas sólo se guardan como hash bcrypt
--  generado con password_hash(); nunca en texto plano):
--    Administrador : admin@firecat.com    / Admin123*    (se obliga a cambiarla
--                                                         en el primer inicio de sesión)
--    Cliente       : cliente@firecat.com  / Cliente123*
--    Clientes extra: andres@example.com, camila@example.com / Cliente123*
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '-05:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS tienda_ropa;
CREATE DATABASE tienda_ropa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tienda_ropa;

-- -----------------------------------------------------------------------------
-- 1. USUARIOS (clientes y administradores)
-- -----------------------------------------------------------------------------
CREATE TABLE usuarios (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre                VARCHAR(60)  NOT NULL,
    apellido              VARCHAR(60)  NOT NULL,
    email                 VARCHAR(120) NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    telefono              VARCHAR(20)  NULL,
    direccion             VARCHAR(160) NULL,
    ciudad                VARCHAR(80)  NULL,
    rol                   ENUM('administrador','cliente') NOT NULL DEFAULT 'cliente',
    estado                ENUM('activo','inactivo')       NOT NULL DEFAULT 'activo',
    debe_cambiar_password TINYINT(1)   NOT NULL DEFAULT 0,
    ultimo_acceso         DATETIME     NULL,
    fecha_registro        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_rol_estado (rol, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de recuperación de contraseña (se guarda sólo el hash SHA-256 del token)
CREATE TABLE password_resets (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id     INT UNSIGNED NOT NULL,
    token_hash     CHAR(64)     NOT NULL,
    expira         DATETIME     NOT NULL,
    usado          TINYINT(1)   NOT NULL DEFAULT 0,
    fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_usuario (usuario_id),
    CONSTRAINT fk_password_resets_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de intentos de inicio de sesión (limitación de fuerza bruta)
CREATE TABLE intentos_login (
    id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email  VARCHAR(120) NOT NULL,
    ip     VARCHAR(45)  NOT NULL,
    exito  TINYINT(1)   NOT NULL DEFAULT 0,
    fecha  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intentos_email_fecha (email, fecha),
    KEY idx_intentos_ip_fecha (ip, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. CATÁLOGO
-- -----------------------------------------------------------------------------
CREATE TABLE categorias (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(60)  NOT NULL,
    descripcion    VARCHAR(255) NULL,
    imagen         VARCHAR(255) NULL,
    estado         ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogos de atributos (normalización: una talla / un color se define una sola vez)
CREATE TABLE tallas (
    id     TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(10)      NOT NULL,
    orden  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tallas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE colores (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(30)      NOT NULL,
    codigo_hex  CHAR(7)          NOT NULL DEFAULT '#000000',
    PRIMARY KEY (id),
    UNIQUE KEY uq_colores_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    categoria_id        INT UNSIGNED  NOT NULL,
    nombre              VARCHAR(120)  NOT NULL,
    descripcion         TEXT          NULL,
    precio              DECIMAL(10,2) NOT NULL,
    imagen              VARCHAR(255)  NULL,
    destacado           TINYINT(1)    NOT NULL DEFAULT 0,
    estado              ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_categoria (categoria_id),
    KEY idx_productos_estado (estado, destacado),
    KEY idx_productos_nombre (nombre),
    CONSTRAINT chk_productos_precio CHECK (precio > 0),
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id)
        REFERENCES categorias (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada combinación producto + talla + color es una variante con su propio stock
CREATE TABLE variantes_producto (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    producto_id         INT UNSIGNED     NOT NULL,
    talla_id            TINYINT UNSIGNED NOT NULL,
    color_id            TINYINT UNSIGNED NOT NULL,
    sku                 VARCHAR(40)      NOT NULL,
    stock               INT UNSIGNED     NOT NULL DEFAULT 0,
    estado              ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variantes_combinacion (producto_id, talla_id, color_id),
    UNIQUE KEY uq_variantes_sku (sku),
    KEY idx_variantes_stock (stock),
    KEY idx_variantes_talla (talla_id),
    KEY idx_variantes_color (color_id),
    CONSTRAINT chk_variantes_stock CHECK (stock >= 0),
    CONSTRAINT fk_variantes_producto FOREIGN KEY (producto_id)
        REFERENCES productos (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_variantes_talla FOREIGN KEY (talla_id)
        REFERENCES tallas (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_variantes_color FOREIGN KEY (color_id)
        REFERENCES colores (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. CARRITO (uno por usuario)
-- -----------------------------------------------------------------------------
CREATE TABLE carritos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id          INT UNSIGNED NOT NULL,
    fecha_creacion      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carritos_usuario (usuario_id),
    CONSTRAINT fk_carritos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- precio_unitario es sólo referencial: el servidor SIEMPRE recalcula con productos.precio
CREATE TABLE carrito_detalle (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    carrito_id      INT UNSIGNED      NOT NULL,
    producto_id     INT UNSIGNED      NOT NULL,
    variante_id     INT UNSIGNED      NOT NULL,
    cantidad        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2)     NOT NULL,
    fecha_agregado  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carrito_variante (carrito_id, variante_id),
    KEY idx_carrito_detalle_producto (producto_id),
    KEY idx_carrito_detalle_variante (variante_id),
    CONSTRAINT chk_carrito_cantidad CHECK (cantidad > 0),
    CONSTRAINT fk_carrito_detalle_carrito FOREIGN KEY (carrito_id)
        REFERENCES carritos (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_carrito_detalle_producto FOREIGN KEY (producto_id)
        REFERENCES productos (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_carrito_detalle_variante FOREIGN KEY (variante_id)
        REFERENCES variantes_producto (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. PEDIDOS
-- -----------------------------------------------------------------------------
CREATE TABLE pedidos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo              VARCHAR(20)   NULL,
    usuario_id          INT UNSIGNED  NOT NULL,
    subtotal            DECIMAL(12,2) NOT NULL,
    costo_envio         DECIMAL(10,2) NOT NULL DEFAULT 0,
    total               DECIMAL(12,2) NOT NULL,
    estado              ENUM('pendiente','confirmado','preparado','enviado','entregado','cancelado')
                        NOT NULL DEFAULT 'pendiente',
    metodo_pago         ENUM('contra_entrega','pedido_prueba') NOT NULL DEFAULT 'contra_entrega',
    pago_con            DECIMAL(12,2) NULL COMMENT 'Efectivo con el que pagará (para calcular el cambio)',
    nombre_destinatario VARCHAR(120)  NOT NULL,
    telefono_entrega    VARCHAR(20)   NOT NULL,
    direccion_entrega   VARCHAR(160)  NOT NULL,
    ciudad_entrega      VARCHAR(80)   NOT NULL,
    notas               VARCHAR(255)  NULL,
    fecha_pedido        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedidos_codigo (codigo),
    KEY idx_pedidos_usuario (usuario_id),
    KEY idx_pedidos_estado_fecha (estado, fecha_pedido),
    KEY idx_pedidos_fecha (fecha_pedido),
    CONSTRAINT chk_pedidos_totales CHECK (subtotal >= 0 AND total >= 0 AND costo_envio >= 0),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guarda una "foto" del producto al momento de la compra (precio histórico,
-- nombre, talla y color) para que cambios futuros no alteren pedidos antiguos.
CREATE TABLE pedido_detalle (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    pedido_id       INT UNSIGNED      NOT NULL,
    producto_id     INT UNSIGNED      NOT NULL,
    variante_id     INT UNSIGNED      NOT NULL,
    nombre_producto VARCHAR(120)      NOT NULL,
    talla           VARCHAR(10)       NOT NULL,
    color           VARCHAR(30)       NOT NULL,
    cantidad        SMALLINT UNSIGNED NOT NULL,
    precio_unitario DECIMAL(10,2)     NOT NULL,
    subtotal        DECIMAL(12,2)     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pedido_detalle_pedido (pedido_id),
    KEY idx_pedido_detalle_producto (producto_id),
    KEY idx_pedido_detalle_variante (variante_id),
    CONSTRAINT chk_pedido_detalle CHECK (cantidad > 0 AND precio_unitario >= 0),
    CONSTRAINT fk_pedido_detalle_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pedido_detalle_producto FOREIGN KEY (producto_id)
        REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pedido_detalle_variante FOREIGN KEY (variante_id)
        REFERENCES variantes_producto (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trazabilidad de cambios de estado de cada pedido
CREATE TABLE pedido_historial (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pedido_id       INT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(20)  NULL,
    estado_nuevo    VARCHAR(20)  NOT NULL,
    usuario_id      INT UNSIGNED NULL COMMENT 'Quién realizó el cambio',
    comentario      VARCHAR(255) NULL,
    fecha           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pedido_historial_pedido (pedido_id),
    CONSTRAINT fk_pedido_historial_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pedido_historial_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. INVENTARIO: kardex de movimientos por variante
-- -----------------------------------------------------------------------------
CREATE TABLE movimientos_inventario (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    variante_id      INT UNSIGNED NOT NULL,
    tipo             ENUM('entrada','salida','ajuste','venta','devolucion') NOT NULL,
    cantidad         INT          NOT NULL COMMENT 'Positivo suma stock, negativo resta',
    stock_resultante INT UNSIGNED NOT NULL,
    motivo           VARCHAR(255) NULL,
    usuario_id       INT UNSIGNED NULL,
    pedido_id        INT UNSIGNED NULL,
    fecha            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_movimientos_variante_fecha (variante_id, fecha),
    KEY idx_movimientos_pedido (pedido_id),
    CONSTRAINT fk_movimientos_variante FOREIGN KEY (variante_id)
        REFERENCES variantes_producto (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_movimientos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_movimientos_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. CONFIGURACIÓN de la tienda (clave → valor)
-- -----------------------------------------------------------------------------
CREATE TABLE configuracion (
    clave       VARCHAR(50)  NOT NULL,
    valor       VARCHAR(255) NOT NULL,
    descripcion VARCHAR(255) NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. VISTA de apoyo: stock total por producto
-- -----------------------------------------------------------------------------
CREATE VIEW vista_stock_productos AS
SELECT p.id                          AS producto_id,
       p.nombre                      AS producto,
       c.nombre                      AS categoria,
       p.precio,
       p.estado,
       COUNT(v.id)                   AS total_variantes,
       COALESCE(SUM(v.stock), 0)     AS stock_total
FROM productos p
JOIN categorias c           ON c.id = p.categoria_id
LEFT JOIN variantes_producto v ON v.producto_id = p.id AND v.estado = 'activo'
GROUP BY p.id, p.nombre, c.nombre, p.precio, p.estado;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  DATOS DE PRUEBA
-- =============================================================================

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('nombre_tienda',       'FIRE CAT',                          'Nombre comercial de la tienda'),
('eslogan',             'La mejor calidad en venta de ropa y textiles', 'Texto de la portada'),
('email_contacto',      'hola@firecat.com',                  'Correo de contacto público'),
('telefono_contacto',   '+57 300 000 0000',                  'Teléfono / WhatsApp de contacto'),
('costo_envio',         '12000',                             'Costo de envío en COP'),
('envio_gratis_desde',  '200000',                            'Subtotal a partir del cual el envío es gratis (0 = nunca)'),
('umbral_stock_bajo',   '5',                                 'Unidades a partir de las cuales una variante se considera con poco stock');

-- Usuarios (hash bcrypt generado con password_hash(); ver credenciales arriba)
INSERT INTO usuarios (id, nombre, apellido, email, password_hash, telefono, direccion, ciudad, rol, estado, debe_cambiar_password, fecha_registro) VALUES
(1, 'Admin',  'FIRE CAT', 'admin@firecat.com',   '$2y$12$2TPawIm2dF4e7A.g75QToeFUO.0XftOuWRKQdekv9sFMWwsr5.E3m', '3000000000', 'Oficina principal', 'Bogotá',   'administrador', 'activo', 1, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 'Laura',  'Gómez',    'cliente@firecat.com', '$2y$12$W6iM4y7XB3RkGImt9tkDW.QZfy3QW3Z44PjT7GWSBXKJphEYVSSN2', '3001234567', 'Calle 45 # 12-30, Apto 402', 'Bogotá',   'cliente', 'activo', 0, DATE_SUB(NOW(), INTERVAL 20 DAY)),
(3, 'Andrés', 'Martínez', 'andres@example.com',  '$2y$12$W6iM4y7XB3RkGImt9tkDW.QZfy3QW3Z44PjT7GWSBXKJphEYVSSN2', '3109876543', 'Carrera 7 # 80-15',          'Medellín', 'cliente', 'activo', 0, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(4, 'Camila', 'Rojas',    'camila@example.com',  '$2y$12$W6iM4y7XB3RkGImt9tkDW.QZfy3QW3Z44PjT7GWSBXKJphEYVSSN2', '3205551234', 'Avenida 6N # 23-40',         'Cali',     'cliente', 'activo', 0, DATE_SUB(NOW(), INTERVAL 12 DAY));

INSERT INTO categorias (id, nombre, descripcion, imagen, estado) VALUES
(1, 'Camisetas',  'Camisetas, polos y camisas para el día a día.',          'assets/img/productos/11.jpg', 'activa'),
(2, 'Pantalones', 'Cargos, joggers y shorts con estilo urbano.',             'assets/img/productos/20.jpg', 'activa'),
(3, 'Sudaderas',  'Hoodies y buzos cómodos con estampados exclusivos.',     'assets/img/productos/1.jpg',  'activa'),
(4, 'Chaquetas',  'Chaquetas con cremallera para cualquier clima.',         'assets/img/productos/18.jpg', 'activa'),
(5, 'Conjuntos',  'Sets coordinados de camiseta/camisa y short.',           'assets/img/productos/15.jpg', 'activa'),
(6, 'Accesorios', 'Gorros y complementos para completar tu outfit.',        'assets/img/productos/7.jpg',  'activa');

INSERT INTO tallas (id, nombre, orden) VALUES
(1, 'XS', 1), (2, 'S', 2), (3, 'M', 3), (4, 'L', 4), (5, 'XL', 5), (6, 'XXL', 6), (7, 'Única', 7);

INSERT INTO colores (id, nombre, codigo_hex) VALUES
(1, 'Negro', '#111111'), (2, 'Blanco', '#FFFFFF'), (3, 'Gris', '#8E8E8E'), (4, 'Beige', '#D8C3A5'),
(5, 'Rojo', '#C62828'),  (6, 'Amarillo', '#FFE500'), (7, 'Naranja', '#E07B24'), (8, 'Azul marino', '#1F2A44');

INSERT INTO productos (id, categoria_id, nombre, descripcion, precio, imagen, destacado, estado, fecha_creacion) VALUES
(1,  1, 'Camiseta básica',            'Camiseta de algodón peinado 180 g con capucha ligera y estampado minimalista. Corte regular, ideal para el día a día.', 54900,  'assets/img/productos/17.jpg', 1, 'activo', DATE_SUB(NOW(), INTERVAL 40 DAY)),
(2,  1, 'Camiseta oversize',          'Camiseta oversize con estampado de flor de cerezo, mangas contrastantes y capucha. Algodón 100 %.',                  64900,  'assets/img/productos/11.jpg', 1, 'activo', DATE_SUB(NOW(), INTERVAL 38 DAY)),
(3,  1, 'Camiseta béisbol NY',        'Camiseta estilo jersey de béisbol con botones frontales, ribetes negros y bordado NY.',                             79900,  'assets/img/productos/6.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 36 DAY)),
(4,  1, 'Polo acanalado',             'Polo de punto acanalado con cuello sin botones. Tejido elástico que se adapta al cuerpo.',                          69900,  'assets/img/productos/19.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 34 DAY)),
(5,  1, 'Camisa bicolor',             'Camisa manga corta con bloques de color negro, gris y vino. Botones a presión y bordado en el pecho.',               74900,  'assets/img/productos/16.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 32 DAY)),
(6,  2, 'Pantalón cargo',             'Pantalón cargo tipo jogger con bolsillos laterales, cordón ajustable y puños elásticos.',                            119900, 'assets/img/productos/20.jpg', 1, 'activo', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(7,  2, 'Jogger Skull',               'Jogger con estampado degradado de calavera, cintura elástica y cordones largos.',                                   99900,  'assets/img/productos/2.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 28 DAY)),
(8,  2, 'Short deportivo Colorado',   'Short deportivo de malla con estampado gótico y franjas laterales. Secado rápido.',                                  59900,  'assets/img/productos/5.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 26 DAY)),
(9,  3, 'Sudadera clásica',           'Sudadera con capucha efecto capas, bolsillo canguro y cintas en los puños. Felpa perchada.',                         129900, 'assets/img/productos/1.jpg',  1, 'activo', DATE_SUB(NOW(), INTERVAL 24 DAY)),
(10, 3, 'Sudadera Smile Graffiti',    'Hoodie full print con diseño graffiti en blanco y negro. Interior afelpado.',                                        139900, 'assets/img/productos/3.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 22 DAY)),
(11, 3, 'Sudadera bicolor Fanstore',  'Hoodie rojo y negro con cintas estampadas en las mangas y letrero frontal.',                                          149900, 'assets/img/productos/10.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(12, 3, 'Buzo cuello redondo',        'Buzo básico de cuello redondo en felpa suave, con logo bordado discreto.',                                           109900, 'assets/img/productos/13.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 18 DAY)),
(13, 4, 'Chaqueta urbana',            'Chaqueta con capucha y cremallera, bicolor beige/negro con estampado de alas.',                                      169900, 'assets/img/productos/18.jpg', 1, 'activo', DATE_SUB(NOW(), INTERVAL 16 DAY)),
(14, 4, 'Chaqueta Oso bicolor',       'Chaqueta con cremallera y capucha con orejas, diseño bicolor blanco y negro.',                                        159900, 'assets/img/productos/9.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 14 DAY)),
(15, 5, 'Conjunto deportivo',         'Conjunto de camiseta y short con estampado de llamas. Tela fresca y ligera.',                                        119900, 'assets/img/productos/4.jpg',  1, 'activo', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(16, 5, 'Conjunto texturizado',       'Camisa y short en tela texturizada tipo waffle, color blanco hueso.',                                                139900, 'assets/img/productos/12.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(17, 5, 'Conjunto tropical',          'Camisa estampada de hojas y short beige con cordón. Perfecto para clima cálido.',                                    129900, 'assets/img/productos/14.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(18, 5, 'Conjunto geométrico',        'Camisa de estampado geométrico y short naranja a juego.',                                                            134900, 'assets/img/productos/15.jpg', 0, 'activo', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(19, 6, 'Gorro Street',               'Gorro tejido doble capa con bordado frontal. Talla única.',                                                          39900,  'assets/img/productos/7.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(20, 6, 'Gorro Alien',                'Gorro tejido blanco con parche bordado de alien. Talla única.',                                                      34900,  'assets/img/productos/8.jpg',  0, 'activo', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- Variantes (id, producto, talla, color, SKU, stock). Incluye variantes agotadas
-- (stock 0) y con poco inventario para demostrar las validaciones y alertas.
INSERT INTO variantes_producto (id, producto_id, talla_id, color_id, sku, stock) VALUES
(1, 1, 2, 1, 'FC-001-S-NEG', 6),
(2, 1, 3, 1, 'FC-001-M-NEG', 9),
(3, 1, 4, 1, 'FC-001-L-NEG', 12),
(4, 1, 5, 1, 'FC-001-XL-NEG', 15),
(5, 1, 2, 2, 'FC-001-S-BLA', 11),
(6, 1, 3, 2, 'FC-001-M-BLA', 14),
(7, 1, 4, 2, 'FC-001-L-BLA', 17),
(8, 1, 5, 2, 'FC-001-XL-BLA', 0),
(9, 2, 3, 2, 'FC-002-M-BLA', 21),
(10, 2, 4, 2, 'FC-002-L-BLA', 6),
(11, 2, 5, 2, 'FC-002-XL-BLA', 9),
(12, 2, 6, 2, 'FC-002-XXL-BLA', 12),
(13, 2, 3, 1, 'FC-002-M-NEG', 16),
(14, 2, 4, 1, 'FC-002-L-NEG', 19),
(15, 2, 5, 1, 'FC-002-XL-NEG', 22),
(16, 2, 6, 1, 'FC-002-XXL-NEG', 7),
(17, 3, 2, 2, 'FC-003-S-BLA', 7),
(18, 3, 3, 2, 'FC-003-M-BLA', 10),
(19, 3, 4, 2, 'FC-003-L-BLA', 13),
(20, 4, 2, 2, 'FC-004-S-BLA', 14),
(21, 4, 3, 2, 'FC-004-M-BLA', 17),
(22, 4, 4, 2, 'FC-004-L-BLA', 20),
(23, 4, 5, 2, 'FC-004-XL-BLA', 23),
(24, 4, 2, 4, 'FC-004-S-BEI', 6),
(25, 4, 3, 4, 'FC-004-M-BEI', 9),
(26, 4, 4, 4, 'FC-004-L-BEI', 12),
(27, 4, 5, 4, 'FC-004-XL-BEI', 15),
(28, 4, 2, 1, 'FC-004-S-NEG', 9),
(29, 4, 3, 1, 'FC-004-M-NEG', 12),
(30, 4, 4, 1, 'FC-004-L-NEG', 15),
(31, 4, 5, 1, 'FC-004-XL-NEG', 18),
(32, 5, 3, 1, 'FC-005-M-NEG', 19),
(33, 5, 4, 1, 'FC-005-L-NEG', 22),
(34, 5, 5, 1, 'FC-005-XL-NEG', 7),
(35, 6, 2, 1, 'FC-006-S-NEG', 23),
(36, 6, 3, 1, 'FC-006-M-NEG', 8),
(37, 6, 4, 1, 'FC-006-L-NEG', 11),
(38, 6, 5, 1, 'FC-006-XL-NEG', 14),
(39, 6, 2, 3, 'FC-006-S-GRI', 15),
(40, 6, 3, 3, 'FC-006-M-GRI', 18),
(41, 6, 4, 3, 'FC-006-L-GRI', 21),
(42, 6, 5, 3, 'FC-006-XL-GRI', 6),
(43, 7, 2, 3, 'FC-007-S-GRI', 22),
(44, 7, 3, 3, 'FC-007-M-GRI', 7),
(45, 7, 4, 3, 'FC-007-L-GRI', 10),
(46, 8, 2, 2, 'FC-008-S-BLA', 6),
(47, 8, 3, 2, 'FC-008-M-BLA', 9),
(48, 8, 4, 2, 'FC-008-L-BLA', 12),
(49, 8, 5, 2, 'FC-008-XL-BLA', 15),
(50, 9, 2, 3, 'FC-009-S-GRI', 18),
(51, 9, 3, 3, 'FC-009-M-GRI', 21),
(52, 9, 4, 3, 'FC-009-L-GRI', 6),
(53, 9, 5, 3, 'FC-009-XL-GRI', 9),
(54, 9, 2, 1, 'FC-009-S-NEG', 8),
(55, 9, 3, 1, 'FC-009-M-NEG', 11),
(56, 9, 4, 1, 'FC-009-L-NEG', 3),
(57, 9, 5, 1, 'FC-009-XL-NEG', 17),
(58, 10, 2, 2, 'FC-010-S-BLA', 20),
(59, 10, 3, 2, 'FC-010-M-BLA', 23),
(60, 10, 4, 2, 'FC-010-L-BLA', 8),
(61, 11, 3, 5, 'FC-011-M-ROJ', 9),
(62, 11, 4, 5, 'FC-011-L-ROJ', 12),
(63, 11, 5, 5, 'FC-011-XL-ROJ', 5),
(64, 12, 1, 4, 'FC-012-XS-BEI', 23),
(65, 12, 2, 4, 'FC-012-S-BEI', 8),
(66, 12, 3, 4, 'FC-012-M-BEI', 11),
(67, 12, 4, 4, 'FC-012-L-BEI', 14),
(68, 12, 1, 3, 'FC-012-XS-GRI', 18),
(69, 12, 2, 3, 'FC-012-S-GRI', 21),
(70, 12, 3, 3, 'FC-012-M-GRI', 6),
(71, 12, 4, 3, 'FC-012-L-GRI', 9),
(72, 12, 1, 1, 'FC-012-XS-NEG', 8),
(73, 12, 2, 1, 'FC-012-S-NEG', 11),
(74, 12, 3, 1, 'FC-012-M-NEG', 14),
(75, 12, 4, 1, 'FC-012-L-NEG', 17),
(76, 13, 3, 4, 'FC-013-M-BEI', 2),
(77, 13, 4, 4, 'FC-013-L-BEI', 21),
(78, 13, 5, 4, 'FC-013-XL-BEI', 6),
(79, 14, 2, 2, 'FC-014-S-BLA', 12),
(80, 14, 3, 2, 'FC-014-M-BLA', 15),
(81, 14, 4, 2, 'FC-014-L-BLA', 18),
(82, 15, 2, 2, 'FC-015-S-BLA', 19),
(83, 15, 3, 2, 'FC-015-M-BLA', 22),
(84, 15, 4, 2, 'FC-015-L-BLA', 7),
(85, 15, 5, 2, 'FC-015-XL-BLA', 10),
(86, 16, 3, 2, 'FC-016-M-BLA', 11),
(87, 16, 4, 2, 'FC-016-L-BLA', 14),
(88, 16, 5, 2, 'FC-016-XL-BLA', 17),
(89, 17, 2, 4, 'FC-017-S-BEI', 7),
(90, 17, 3, 4, 'FC-017-M-BEI', 10),
(91, 17, 4, 4, 'FC-017-L-BEI', 13),
(92, 18, 3, 7, 'FC-018-M-NAR', 14),
(93, 18, 4, 7, 'FC-018-L-NAR', 0),
(94, 19, 7, 1, 'FC-019-UN-NEG', 21),
(95, 19, 7, 2, 'FC-019-UN-BLA', 8),
(96, 19, 7, 3, 'FC-019-UN-GRI', 13),
(97, 20, 7, 2, 'FC-020-UN-BLA', 4);

-- Pedidos de demostración. El pedido 1 se compró a $49.900 (precio anterior) y hoy la
-- "Camiseta básica" cuesta $54.900: el detalle conserva el precio histórico.
INSERT INTO pedidos (id, codigo, usuario_id, subtotal, costo_envio, total, estado, metodo_pago, pago_con, nombre_destinatario, telefono_entrega, direccion_entrega, ciudad_entrega, notas, fecha_pedido) VALUES
(1, 'FC-000001', 2, 229800, 0,     229800, 'entregado',  'contra_entrega', 250000, 'Laura Gómez',     '3001234567', 'Calle 45 # 12-30, Apto 402', 'Bogotá',   NULL,                         DATE_SUB(NOW(), INTERVAL 13 DAY)),
(2, 'FC-000002', 3, 119900, 12000, 131900, 'entregado',  'contra_entrega', NULL,   'Andrés Martínez', '3109876543', 'Carrera 7 # 80-15',          'Medellín', 'Dejar en portería',          DATE_SUB(NOW(), INTERVAL 11 DAY)),
(3, 'FC-000003', 4, 159800, 12000, 171800, 'entregado',  'pedido_prueba',  NULL,   'Camila Rojas',    '3205551234', 'Avenida 6N # 23-40',         'Cali',     NULL,                         DATE_SUB(NOW(), INTERVAL 9 DAY)),
(4, 'FC-000004', 2, 159900, 12000, 171900, 'enviado',    'contra_entrega', 200000, 'Laura Gómez',     '3001234567', 'Calle 45 # 12-30, Apto 402', 'Bogotá',   NULL,                         DATE_SUB(NOW(), INTERVAL 7 DAY)),
(5, 'FC-000005', 3, 139900, 12000, 151900, 'cancelado',  'contra_entrega', NULL,   'Andrés Martínez', '3109876543', 'Carrera 7 # 80-15',          'Medellín', NULL,                         DATE_SUB(NOW(), INTERVAL 5 DAY)),
(6, 'FC-000006', 4, 159800, 12000, 171800, 'preparado',  'contra_entrega', 180000, 'Camila Rojas',    '3205551234', 'Avenida 6N # 23-40',         'Cali',     'Llamar antes de entregar',   DATE_SUB(NOW(), INTERVAL 3 DAY)),
(7, 'FC-000007', 2, 179800, 12000, 191800, 'confirmado', 'pedido_prueba',  NULL,   'Laura Gómez',     '3001234567', 'Calle 45 # 12-30, Apto 402', 'Bogotá',   NULL,                         DATE_SUB(NOW(), INTERVAL 1 DAY)),
(8, 'FC-000008', 3, 234800, 0,     234800, 'pendiente',  'contra_entrega', 250000, 'Andrés Martínez', '3109876543', 'Carrera 7 # 80-15',          'Medellín', NULL,                         DATE_SUB(NOW(), INTERVAL 2 HOUR));

INSERT INTO pedido_detalle (pedido_id, producto_id, variante_id, nombre_producto, talla, color, cantidad, precio_unitario, subtotal) VALUES
(1, 1,  2,  'Camiseta básica',     'M',     'Negro',  2, 49900,  99800),
(1, 9,  51, 'Sudadera clásica',    'M',     'Gris',   1, 129900, 129900),
(2, 6,  36, 'Pantalón cargo',      'M',     'Negro',  1, 119900, 119900),
(3, 15, 83, 'Conjunto deportivo',  'M',     'Blanco', 1, 119900, 119900),
(3, 19, 94, 'Gorro Street',        'Única', 'Negro',  1, 39900,  39900),
(4, 14, 80, 'Chaqueta Oso bicolor','M',     'Blanco', 1, 159900, 159900),
(5, 10, 59, 'Sudadera Smile Graffiti','M',  'Blanco', 1, 139900, 139900),
(6, 3,  18, 'Camiseta béisbol NY', 'M',     'Blanco', 2, 79900,  159800),
(7, 12, 66, 'Buzo cuello redondo', 'M',     'Beige',  1, 109900, 109900),
(7, 4,  22, 'Polo acanalado',      'L',     'Blanco', 1, 69900,  69900),
(8, 13, 77, 'Chaqueta urbana',     'L',     'Beige',  1, 169900, 169900),
(8, 2,  13, 'Camiseta oversize',   'M',     'Negro',  1, 64900,  64900);

INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario, fecha) VALUES
(1, NULL, 'pendiente', 2, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 13 DAY)),
(1, 'pendiente', 'confirmado', 1, NULL, DATE_SUB(NOW(), INTERVAL 13 DAY) + INTERVAL 2 HOUR),
(1, 'confirmado', 'preparado', 1, NULL, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(1, 'preparado', 'enviado', 1, 'Guía 7001', DATE_SUB(NOW(), INTERVAL 12 DAY) + INTERVAL 5 HOUR),
(1, 'enviado', 'entregado', 1, NULL, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(2, NULL, 'pendiente', 3, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 11 DAY)),
(2, 'pendiente', 'entregado', 1, 'Entrega express', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(3, NULL, 'pendiente', 4, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(3, 'pendiente', 'entregado', 1, NULL, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(4, NULL, 'pendiente', 2, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(4, 'pendiente', 'confirmado', 1, NULL, DATE_SUB(NOW(), INTERVAL 7 DAY) + INTERVAL 1 HOUR),
(4, 'confirmado', 'preparado', 1, NULL, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 'preparado', 'enviado', 1, 'Guía 7002', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, NULL, 'pendiente', 3, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 'pendiente', 'cancelado', 3, 'Cancelado por el cliente', DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 3 HOUR),
(6, NULL, 'pendiente', 4, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(6, 'pendiente', 'confirmado', 1, NULL, DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 1 HOUR),
(6, 'confirmado', 'preparado', 1, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(7, NULL, 'pendiente', 2, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(7, 'pendiente', 'confirmado', 1, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 2 HOUR),
(8, NULL, 'pendiente', 3, 'Pedido creado por el cliente', DATE_SUB(NOW(), INTERVAL 2 HOUR));

-- Kardex: entrada inicial de todas las variantes (stock actual + unidades vendidas)
INSERT INTO movimientos_inventario (variante_id, tipo, cantidad, stock_resultante, motivo, usuario_id, fecha)
SELECT v.id, 'entrada',
       v.stock + COALESCE(s.vendidas, 0),
       v.stock + COALESCE(s.vendidas, 0),
       'Inventario inicial', 1, DATE_SUB(NOW(), INTERVAL 30 DAY)
FROM variantes_producto v
LEFT JOIN (
    SELECT d.variante_id, SUM(d.cantidad) AS vendidas
    FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
    WHERE p.estado <> 'cancelado'
    GROUP BY d.variante_id
) s ON s.variante_id = v.id;

-- Kardex: salidas por venta de los pedidos (y la devolución del pedido cancelado)
INSERT INTO movimientos_inventario (variante_id, tipo, cantidad, stock_resultante, motivo, usuario_id, pedido_id, fecha)
SELECT d.variante_id, 'venta', -d.cantidad, v.stock - IF(p.estado = 'cancelado', d.cantidad, 0),
       CONCAT('Venta pedido ', p.codigo), p.usuario_id, p.id, p.fecha_pedido
FROM pedido_detalle d
JOIN pedidos p ON p.id = d.pedido_id
JOIN variantes_producto v ON v.id = d.variante_id;

INSERT INTO movimientos_inventario (variante_id, tipo, cantidad, stock_resultante, motivo, usuario_id, pedido_id, fecha)
SELECT d.variante_id, 'devolucion', d.cantidad, v.stock, CONCAT('Cancelación pedido ', p.codigo), p.usuario_id, p.id,
       p.fecha_pedido + INTERVAL 3 HOUR
FROM pedido_detalle d
JOIN pedidos p ON p.id = d.pedido_id
JOIN variantes_producto v ON v.id = d.variante_id
WHERE p.estado = 'cancelado';

-- Carrito de ejemplo de la clienta de prueba
INSERT INTO carritos (id, usuario_id) VALUES (1, 2);
INSERT INTO carrito_detalle (carrito_id, producto_id, variante_id, cantidad, precio_unitario) VALUES
(1, 1, 7, 1, 54900);

ALTER TABLE usuarios           AUTO_INCREMENT = 5;
ALTER TABLE pedidos            AUTO_INCREMENT = 9;
