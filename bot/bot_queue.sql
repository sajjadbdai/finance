CREATE TABLE IF NOT EXISTS bot_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    update_id    BIGINT NOT NULL,
    payload      MEDIUMTEXT NOT NULL,
    status       VARCHAR(20) DEFAULT 'pending',
    note         VARCHAR(255) NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME NULL,
    processed_at DATETIME NULL,
    UNIQUE KEY uniq_update (update_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
