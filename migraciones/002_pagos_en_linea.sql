-- =============================================================================
--  Migración: pagos en línea (Wompi sandbox: Web Checkout y PSE)
--  Úsala SÓLO si ya tienes la base `tienda_ropa` con datos propios y no quieres
--  volver a importar database.sql. Es segura de ejecutar más de una vez (MariaDB 10.4+).
--  phpMyAdmin → SELECCIONA tu base (tienda_ropa en XAMPP, if0_…_tienda en InfinityFree)
--  → pestaña Importar (o SQL) → ejecutar este archivo. No contiene USE a propósito.
-- =============================================================================

ALTER TABLE pedidos
    MODIFY metodo_pago ENUM('contra_entrega','pedido_prueba','wompi','pse') NOT NULL DEFAULT 'contra_entrega',
    ADD COLUMN IF NOT EXISTS estado_pago ENUM('no_aplica','pendiente','aprobado','rechazado','anulado','error') NOT NULL DEFAULT 'no_aplica'
        COMMENT 'Sólo pagos en línea; "aprobado" únicamente tras verificación del backend' AFTER metodo_pago,
    ADD COLUMN IF NOT EXISTS token_checkout CHAR(32) NULL COMMENT 'Identificador único del intento de compra' AFTER estado_pago,
    ADD UNIQUE KEY IF NOT EXISTS uq_pedidos_token_checkout (token_checkout);

-- Intentos de pago en línea (Wompi Web Checkout o PSE vía Wompi). Un pedido puede tener
-- varios intentos (p. ej. un pago rechazado y luego un reintento); cada uno con referencia única.
CREATE TABLE IF NOT EXISTS pagos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pedido_id           INT UNSIGNED  NOT NULL,
    proveedor           ENUM('wompi') NOT NULL DEFAULT 'wompi',
    metodo              ENUM('wompi','pse') NOT NULL,
    ambiente            ENUM('sandbox') NOT NULL DEFAULT 'sandbox',
    referencia          VARCHAR(60)   NOT NULL,
    monto_centavos      BIGINT UNSIGNED NOT NULL,
    moneda              CHAR(3)       NOT NULL DEFAULT 'COP',
    estado              ENUM('CREADO','PENDING','APPROVED','DECLINED','VOIDED','ERROR') NOT NULL DEFAULT 'CREADO',
    transaccion_id      VARCHAR(64)   NULL COMMENT 'Id de la transacción en Wompi (sólo si el proveedor lo confirma)',
    medio_wompi         VARCHAR(40)   NULL COMMENT 'payment_method_type reportado por Wompi (CARD, NEQUI, PSE…)',
    banco_pse           VARCHAR(120)  NULL,
    url_pago            VARCHAR(1500) NULL COMMENT 'URL del checkout externo generada por el servidor',
    mensaje             VARCHAR(255)  NULL,
    verificado_en       DATETIME      NULL,
    fuente_verificacion ENUM('webhook','api') NULL,
    ultima_consulta     DATETIME      NULL,
    fecha_creacion      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pagos_referencia (referencia),
    UNIQUE KEY uq_pagos_transaccion (transaccion_id),
    KEY idx_pagos_pedido (pedido_id, id),
    CONSTRAINT fk_pagos_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de eventos recibidos del proveedor (webhooks), válidos o no
CREATE TABLE IF NOT EXISTS pagos_eventos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pago_id        INT UNSIGNED NULL,
    proveedor      VARCHAR(20)  NOT NULL DEFAULT 'wompi',
    tipo_evento    VARCHAR(60)  NULL,
    transaccion_id VARCHAR(64)  NULL,
    estado         VARCHAR(20)  NULL,
    firma_valida   TINYINT(1)   NOT NULL DEFAULT 0,
    resultado      VARCHAR(255) NULL,
    payload        MEDIUMTEXT   NULL,
    ip             VARCHAR(45)  NULL,
    fecha          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pagos_eventos_pago (pago_id),
    CONSTRAINT fk_pagos_eventos_pago FOREIGN KEY (pago_id)
        REFERENCES pagos (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

