-- Módulo de reparaciones y mantenimiento mensual de áreas comunes.

-- Suplidor asignado por el administrador a una solicitud de reparación.
ALTER TABLE solicitud_mantenimiento
    ADD COLUMN suplidor_id BIGINT(20) NULL AFTER persona_id,
    ADD KEY suplidor_id (suplidor_id),
    ADD CONSTRAINT fk_solicitud_mantenimiento_suplidor
        FOREIGN KEY (suplidor_id) REFERENCES suplidor (id_suplidores);

-- 'Mensual' + periodo 'AAAA-MM' identifica el mantenimiento de áreas comunes de cada mes.
-- La clave única impide crear dos veces el mismo mes para un condominio.
ALTER TABLE mantenimiento
    ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'Puntual' AFTER suplidor_id,
    ADD COLUMN periodo CHAR(7) NULL AFTER tipo,
    ADD UNIQUE KEY uq_mantenimiento_mensual (condominio_id, tipo, periodo);
