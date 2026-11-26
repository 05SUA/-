CREATE TABLE IF NOT EXISTS auth_rate_limits (
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    window_start BIGINT UNSIGNED NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    KEY idx_auth_rate_limits_age (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
