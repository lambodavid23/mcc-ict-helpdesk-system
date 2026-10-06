<?php
/**
 * DESTRUCTIVE development seeder.
 *
 * This file DROPs the core tables and recreates them empty apart from a single
 * admin account, which destroys all live tickets, staff accounts and
 * resolutions. It must never be reachable from a browser.
 *
 * No sample users, technicians or tickets are created: the database comes back
 * up with one admin, the assignment rules and an empty knowledge base. Add
 * staff from the admin panel, and load the knowledge-base corpus with
 * config/migrations/baseline_data.sql if you want it.
 *
 * Run it from the command line only:
 *   php seed.php
 *
 * To run it you must also define SEED_ALLOW, so that an accidental web request
 * cannot trigger it:
 *   set SEED_ALLOW=1 && php seed.php   (Windows cmd)
 *   SEED_ALLOW=1 php seed.php           (Linux/macOS/Git Bash)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden: seed.php is a command-line tool and cannot be run over the web.\n";
    exit;
}

if (getenv('SEED_ALLOW') !== '1') {
    fwrite(STDERR, "Refusing to run. This DROPs tables and destroys live data.\n");
    fwrite(STDERR, "Set SEED_ALLOW=1 if you really mean it, e.g.:\n");
    fwrite(STDERR, "  set SEED_ALLOW=1 && php seed.php\n");
    exit(1);
}

require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();

fwrite(STDOUT, "WARNING: dropping and recreating core tables. Live data will be lost.\n");

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (['ticket_update_deletions', 'system_logs', 'fault_history', 'ticket_assignments', 'tickets', 'knowledge_base', 'technician_attendance', 'technicians', 'admins', 'users'] as $t) {
    $conn->query("DROP TABLE IF EXISTS $t");
}
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

$conn->query("
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(100) NOT NULL DEFAULT 'ICT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(100) NOT NULL,
    status ENUM('active', 'pending', 'rejected', 'suspended') NOT NULL DEFAULT 'active',
    pending_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_time (ip_address, attempted_at)
)");

$conn->query("CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'technician', 'user') NOT NULL,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    KEY idx_user (user_type, user_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'technician', 'user') NOT NULL,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token)
)");

$conn->query("
CREATE TABLE technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    specialization ENUM('network', 'hardware', 'software', 'general') NOT NULL,
    current_workload INT DEFAULT 0,
    status ENUM('available', 'busy', 'offline') DEFAULT 'available',
    phone VARCHAR(20),
    department VARCHAR(100) NOT NULL DEFAULT 'ICT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("
CREATE TABLE technician_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    work_date DATE NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    UNIQUE KEY uq_tech_date (technician_id, work_date),
    CONSTRAINT fk_attendance_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    department VARCHAR(100) NOT NULL,
    category ENUM('network', 'hardware', 'software', 'login') NOT NULL,
    priority ENUM('high', 'medium', 'low') NOT NULL,
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    created_by INT NOT NULL,
    assigned_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES technicians(id)
)");

$conn->query("
CREATE TABLE ticket_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    technician_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    previous_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    deleted_at TIMESTAMP NULL,
    deleted_by INT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE fault_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    problem TEXT NOT NULL,
    solution TEXT,
    resolved_by INT,
    resolved_at TIMESTAMP NULL,
    time_to_resolve INT,
    deleted_at TIMESTAMP NULL,
    deleted_by INT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES technicians(id)
)");

$conn->query("
CREATE TABLE ticket_update_deletions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    ticket_id INT NOT NULL,
    record_type ENUM('status_update', 'solution') NOT NULL,
    record_id INT NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_keyword VARCHAR(200) NOT NULL,
    category ENUM('network', 'hardware', 'software', 'login') NOT NULL,
    recommended_solution TEXT NOT NULL,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    user_type ENUM('admin', 'technician', 'user'),
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("
CREATE TABLE ticket_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'technician', 'user') NOT NULL DEFAULT 'user',
    comment TEXT NOT NULL,
    is_internal TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE ticket_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    filesize INT NOT NULL,
    mime_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'technician', 'user') NOT NULL,
    ticket_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
)");

echo "Tables created.\n";

// This is a development seeder: it DROPs and recreates the account table, so
// it deliberately leaves a known, trivial password behind so the admin can be
// logged into immediately. That is the opposite of what production wants,
// which is why this file is CLI-only and gated behind SEED_ALLOW.
//
// database.sql and config/migrations/baseline_data.sql, the files a real
// install uses, create their accounts with the LOCKED_PASSWORD_SENTINEL
// sentinel instead. Do not copy this password into them.
$adminPass = password_hash('admin123', PASSWORD_DEFAULT);

$admins = [
    ['Admin User', 'admin@mcc.co.zw', $adminPass, 'ICT'],
];

$stmt = $conn->prepare("INSERT INTO admins (name, email, password, department) VALUES (?, ?, ?, ?)");
foreach ($admins as $a) {
    $stmt->bind_param("ssss", $a[0], $a[1], $a[2], $a[3]);
    $stmt->execute();
}
echo "Admins inserted.\n";

// Assignment rules (technician_id NULL = auto-pick by specialization)
$conn->query("CREATE TABLE IF NOT EXISTS assignment_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    specialization VARCHAR(50) NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'any',
    technician_id INT NULL,
    auto_assign TINYINT(1) NOT NULL DEFAULT 1,
    priority_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL
)");

$conn->query("INSERT INTO assignment_rules (category, specialization, priority, technician_id, auto_assign, priority_order, is_active) VALUES
('network', 'network', 'any', NULL, 1, 1, 1),
('hardware', 'hardware', 'any', NULL, 1, 2, 1),
('software', 'software', 'any', NULL, 1, 3, 1),
('login', 'general', 'any', NULL, 1, 4, 1),
('all', 'general', 'any', NULL, 1, 10, 1),
('general', 'general', 'any', NULL, 1, 20, 1)");
echo "Assignment rules inserted.\n";

// Knowledge base corpus is deliberately not seeded: knowledge_base was just
// recreated empty above. Load the articles with the guarded, re-runnable
// config/migrations/baseline_data.sql, which also tops up the admin and the
// assignment rules without duplicating them.
echo "Knowledge base left empty - run config/migrations/baseline_data.sql for the corpus.\n";

// Indexes
$conn->query("CREATE INDEX idx_tickets_status ON tickets(status)");
$conn->query("CREATE INDEX idx_tickets_created_by ON tickets(created_by)");
$conn->query("CREATE INDEX idx_tickets_assigned_to ON tickets(assigned_to)");
$conn->query("CREATE INDEX idx_tickets_priority ON tickets(priority)");
$conn->query("CREATE INDEX idx_technicians_specialization ON technicians(specialization)");
$conn->query("CREATE INDEX idx_technicians_status ON technicians(status)");
$conn->query("CREATE INDEX idx_technicians_email ON technicians(email)");
$conn->query("CREATE INDEX idx_admins_email ON admins(email)");
$conn->query("CREATE INDEX idx_users_email ON users(email)");
$conn->query("CREATE INDEX idx_fault_history_ticket_id ON fault_history(ticket_id)");
$conn->query("CREATE INDEX idx_knowledge_base_keyword ON knowledge_base(issue_keyword)");
$conn->query("CREATE INDEX idx_system_logs_user_id ON system_logs(user_id)");
$conn->query("CREATE INDEX idx_system_logs_created_at ON system_logs(created_at)");

echo "Indexes created.\n\n";
echo "===== LOGIN CREDENTIALS =====\n";
echo "Admin:      admin@mcc.co.zw / admin123\n";
echo "Users, technicians and tickets: none - add them from the admin panel.\n";
echo "=============================\n";
