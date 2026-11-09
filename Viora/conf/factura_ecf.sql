CREATE TABLE IF NOT EXISTS factura_ecf (
    id_factura_ecf INT AUTO_INCREMENT PRIMARY KEY,
    factura_id BIGINT NOT NULL,
    ecf_api_id VARCHAR(64) NULL,
    encf VARCHAR(20) NULL,
    tipo_ecf VARCHAR(5) NOT NULL DEFAULT 'E32',
    estado VARCHAR(60) NOT NULL,
    codigo_seguridad VARCHAR(20) NULL,
    dgii_url TEXT NULL,
    error_mensaje TEXT NULL,
    intentos INT NOT NULL DEFAULT 0,
    fecha_envio DATETIME NULL,
    fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_factura_ecf_factura (factura_id),
    CONSTRAINT fk_factura_ecf_factura
        FOREIGN KEY (factura_id) REFERENCES factura (id_facturas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
