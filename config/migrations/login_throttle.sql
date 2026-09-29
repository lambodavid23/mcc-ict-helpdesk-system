-- Brute-force throttle for the login form.
--
-- auth/login.php records a row here for every failed attempt and refuses new
-- attempts once an IP has five failures inside a 15 minute window. Without
-- it, the default passwords shipped in baseline_data.sql were indefinitely
-- guessable, since nothing ever counted the attempts.
--
-- Applied in both database.sql and seed.php so a fresh install has it.
--
--   mysql -u root mcc_helpdesk < config/migrations/login_throttle.sql

CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45) NOT NULL,
    attempted_at  DATETIME NOT NULL,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Old rows are noise once the table exists; keep it from growing unbounded.
DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY);
