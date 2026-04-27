CREATE TABLE tenant_supporter_counters (
    tenant_id  CHAR(36) NOT NULL,
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tsc_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_supporter_counters (tenant_id, next_value, updated_at)
SELECT t.id, 1, NOW() FROM tenants t;
