-- E31 (crédito fiscal) y E34 (nota de crédito).
-- Ejecutar una sola vez sobre la base de datos de VIORA.

ALTER TABLE factura
    ADD COLUMN IF NOT EXISTS rnc_comprador VARCHAR(11) NULL AFTER observaciones,
    ADD COLUMN IF NOT EXISTS razon_social_comprador VARCHAR(150) NULL AFTER rnc_comprador;

-- Una factura puede tener varias notas de crédito (anulación
-- o correcciones parciales), por eso no comparte la tabla factura_ecf.
CREATE TABLE IF NOT EXISTS factura_ecf_nota (
    id_factura_ecf_nota INT AUTO_INCREMENT PRIMARY KEY,
    factura_id BIGINT NOT NULL,
    codigo_modificacion TINYINT NOT NULL,
    encf_modificado VARCHAR(20) NOT NULL,
    fecha_encf_modificado DATE NOT NULL,
    monto_centavos BIGINT NOT NULL,
    lineas_json TEXT NOT NULL,
    usuario_id INT NULL,
    ecf_api_id VARCHAR(64) NULL,
    encf VARCHAR(20) NULL,
    estado VARCHAR(60) NOT NULL,
    codigo_seguridad VARCHAR(20) NULL,
    dgii_url TEXT NULL,
    error_mensaje TEXT NULL,
    intentos INT NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL,
    fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_factura_ecf_nota_factura (factura_id),
    CONSTRAINT fk_factura_ecf_nota_factura
        FOREIGN KEY (factura_id) REFERENCES factura (id_facturas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fecha de firma con la que se envió el E31 a DGII; la E34 debe citarla.
ALTER TABLE factura_ecf
    ADD COLUMN IF NOT EXISTS fecha_emision_ecf DATE NULL AFTER encf;
