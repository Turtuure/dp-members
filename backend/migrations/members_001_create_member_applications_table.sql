CREATE TABLE IF NOT EXISTS member_applications (
    id            CHAR(36)     NOT NULL,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    date_of_birth DATE         NOT NULL,
    country       VARCHAR(100) NULL,
    motivation    TEXT         NOT NULL,
    how_heard     VARCHAR(50)  NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY member_apps_email (email),
    KEY member_apps_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
