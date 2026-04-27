-- Migration 033: create member_register_audit with tenant_id

CREATE TABLE member_register_audit (
    id             CHAR(36)     NOT NULL,
    tenant_id      CHAR(36)     NOT NULL,
    application_id CHAR(36)     NOT NULL,
    action         VARCHAR(50)  NOT NULL,
    performed_by   CHAR(36)     NOT NULL,
    note           TEXT         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY member_register_audit_tenant_idx (tenant_id, created_at),
    CONSTRAINT fk_member_register_audit_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_register_audit_application
        FOREIGN KEY (application_id) REFERENCES member_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_register_audit_performer
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
