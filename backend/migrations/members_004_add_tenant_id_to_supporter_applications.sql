-- Migration 029: add tenant_id to supporter_applications

ALTER TABLE supporter_applications ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE supporter_applications
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE supporter_applications
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_supporter_applications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX supporter_applications_tenant_idx (tenant_id, status);
