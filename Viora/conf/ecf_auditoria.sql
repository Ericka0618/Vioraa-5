-- Aditiva. Aplicar primero en QA; requiere autorización y respaldo para el destino.
CREATE TABLE IF NOT EXISTS ecf_envio (
    id_envio BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factura_id BIGINT NOT NULL,
    nota_id INT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    estado VARCHAR(60) NOT NULL,
    http_codigo SMALLINT NULL,
    respuesta_json MEDIUMTEXT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    respondido_en DATETIME NULL,
    INDEX (factura_id),
    INDEX (nota_id),
    FOREIGN KEY (factura_id) REFERENCES factura(id_facturas),
    FOREIGN KEY (nota_id) REFERENCES factura_ecf_nota(id_factura_ecf_nota)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecf_nota_operacion (
    operacion CHAR(64) NOT NULL PRIMARY KEY,
    factura_id BIGINT NOT NULL,
    nota_id INT NOT NULL,
    solicitud_sha256 CHAR(64) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (factura_id) REFERENCES factura(id_facturas),
    FOREIGN KEY (nota_id) REFERENCES factura_ecf_nota(id_factura_ecf_nota)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
