-- Migration 028: add tenant_id to member_applications

ALTER TABLE member_applications ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE member_applications
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE member_applications
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_member_applications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX member_applications_tenant_idx (tenant_id, status);
