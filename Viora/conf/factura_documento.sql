-- Migración aditiva: los PDF sobreviven a reinicios y despliegues de Cloud Run.
CREATE TABLE IF NOT EXISTS factura_documento (
    tipo VARCHAR(10) NOT NULL,
    documento_id BIGINT NOT NULL,
    factura_id BIGINT NOT NULL,
    contenido MEDIUMBLOB NOT NULL,
    sha256 CHAR(64) NOT NULL,
    actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tipo, documento_id),
    KEY idx_documento_factura (factura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
