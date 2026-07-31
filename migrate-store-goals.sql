-- Monthly store goals (envíos / cambio de cheques) — MySQL local

CREATE TABLE IF NOT EXISTS store_goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    metric_type ENUM('envios_count','envios_volume','cambio_count','cambio_volume') NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    target_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_store_goal_period (store_id, metric_type, period_year, period_month),
    INDEX idx_store_goals_store_period (store_id, period_year, period_month)
) ENGINE=InnoDB;

SELECT 'store_goals migration complete' AS result;
