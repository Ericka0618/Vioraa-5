CREATE TABLE IF NOT EXISTS viora_runtime_sessions (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    payload MEDIUMBLOB NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB;
