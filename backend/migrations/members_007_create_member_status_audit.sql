-- Migration 035: create member_status_audit

CREATE TABLE member_status_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    previous_status VARCHAR(30)  NULL,
    new_status      VARCHAR(30)  NOT NULL,
    reason          TEXT         NOT NULL,
    performed_by    CHAR(36)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY member_status_audit_tenant_idx (tenant_id, created_at),
    KEY member_status_audit_user_idx (user_id, created_at),
    CONSTRAINT fk_member_status_audit_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_status_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_status_audit_performer
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
