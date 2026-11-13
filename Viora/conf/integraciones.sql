-- Migración aditiva: no modifica pagos de alquiler/mantenimiento existentes.
CREATE TABLE IF NOT EXISTS suscripcion_pago (
    paypal_transaction_id VARCHAR(100) NOT NULL PRIMARY KEY,
    paypal_subscription_id VARCHAR(100) NOT NULL,
    usuario_id BIGINT NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    moneda CHAR(3) NOT NULL,
    fecha_pago DATETIME NOT NULL,
    estado VARCHAR(30) NOT NULL,
    INDEX (paypal_subscription_id),
    FOREIGN KEY (usuario_id) REFERENCES usuario(id_usuarios)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo identificadores de eventos verificados, sin cuerpo ni datos del comprador.
CREATE TABLE IF NOT EXISTS paypal_evento (
    paypal_event_id VARCHAR(100) NOT NULL PRIMARY KEY,
    tipo VARCHAR(100) NOT NULL,
    paypal_subscription_id VARCHAR(100) DEFAULT NULL,
    procesado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
