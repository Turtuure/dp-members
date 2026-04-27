CREATE TABLE admin_application_dismissals (
    id           CHAR(36) NOT NULL,
    admin_id     CHAR(36) NOT NULL,
    app_id       CHAR(36) NOT NULL,
    app_type     ENUM('member','supporter') NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_app (admin_id, app_id),
    KEY idx_aad_admin (admin_id),
    CONSTRAINT fk_aad_admin FOREIGN KEY (admin_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
