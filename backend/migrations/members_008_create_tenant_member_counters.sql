CREATE TABLE tenant_member_counters (
    tenant_id  CHAR(36) NOT NULL,
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tmc_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_member_counters (tenant_id, next_value, updated_at)
SELECT
    t.id,
    COALESCE(MAX(CAST(u.member_number AS UNSIGNED)), 0) + 1,
    NOW()
FROM tenants t
LEFT JOIN user_tenants ut
       ON ut.tenant_id = t.id AND ut.role = 'member' AND ut.left_at IS NULL
LEFT JOIN users u
       ON u.id = ut.user_id
      AND u.member_number IS NOT NULL
      AND u.member_number REGEXP '^[0-9]+$'
GROUP BY t.id;
