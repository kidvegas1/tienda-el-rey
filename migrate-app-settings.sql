USE tienda_el_rey;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('fincen_global_limit', '3000')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('fincen_period', 'month')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'app_settings migration complete' AS result;
