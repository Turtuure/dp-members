CREATE TABLE IF NOT EXISTS supporter_applications (
    id             CHAR(36)     NOT NULL,
    org_name       VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NOT NULL,
    reg_no         VARCHAR(100) NULL,
    email          VARCHAR(255) NOT NULL,
    country        VARCHAR(100) NULL,
    motivation     TEXT         NOT NULL,
    how_heard      VARCHAR(50)  NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY supporter_apps_email (email),
    KEY supporter_apps_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
