-- Migration 034: add decision metadata to member/supporter applications

ALTER TABLE member_applications
    ADD COLUMN decided_at    DATETIME NULL AFTER status,
    ADD COLUMN decided_by    CHAR(36) NULL AFTER decided_at,
    ADD COLUMN decision_note TEXT     NULL AFTER decided_by,
    ADD CONSTRAINT fk_member_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supporter_applications
    ADD COLUMN decided_at    DATETIME NULL AFTER status,
    ADD COLUMN decided_by    CHAR(36) NULL AFTER decided_at,
    ADD COLUMN decision_note TEXT     NULL AFTER decided_by,
    ADD CONSTRAINT fk_supporter_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;
