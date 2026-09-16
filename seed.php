<?php
require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (['ticket_update_deletions', 'system_logs', 'fault_history', 'ticket_assignments', 'tickets', 'knowledge_base', 'technicians', 'admins', 'users'] as $t) {
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

$adminPass = password_hash('admin123', PASSWORD_DEFAULT);
$techPass = password_hash('tech123', PASSWORD_DEFAULT);
$userPass = password_hash('user123', PASSWORD_DEFAULT);

$admins = [
    ['Admin User', 'admin@mcc.co.zw', $adminPass, 'ICT'],
];

$stmt = $conn->prepare("INSERT INTO admins (name, email, password, department) VALUES (?, ?, ?, ?)");
foreach ($admins as $a) {
    $stmt->bind_param("ssss", $a[0], $a[1], $a[2], $a[3]);
    $stmt->execute();
}
echo "Admins inserted.\n";

$users = [
    ['Regular User',    'user@mcc.co.zw',       $userPass,  'Finance'],
    ['Alice HR',        'alice@mcc.co.zw',      $userPass,  'HR'],
    ['Bob Admin',       'bob@mcc.co.zw',        $userPass,  'Administration'],
];

$stmt = $conn->prepare("INSERT INTO users (name, email, password, department) VALUES (?, ?, ?, ?)");
foreach ($users as $u) {
    $stmt->bind_param("ssss", $u[0], $u[1], $u[2], $u[3]);
    $stmt->execute();
}
echo "Users inserted.\n";

// Technician IDs from auto-increment:
// John Technician -> id=1, Mary Hardware -> id=2, Peter Network -> id=3, Sarah Software -> id=4
$techs = [
    ['John Technician', 'john.tech@mcc.co.zw', $techPass, 'general',  2, 'available', '+263712345678'],
    ['Mary Hardware',   'mary.hardware@mcc.co.zw', $techPass, 'hardware', 1, 'available', '+263712345679'],
    ['Peter Network',   'peter.network@mcc.co.zw', $techPass, 'network',  3, 'busy',      '+263712345680'],
    ['Sarah Software',  'sarah.software@mcc.co.zw', $techPass, 'software', 0, 'available', '+263712345681'],
];

$stmt = $conn->prepare("INSERT INTO technicians (name, email, password, specialization, current_workload, status, phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ($techs as $t) {
    $stmt->bind_param("ssssiss", $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]);
    $stmt->execute();
}
echo "Technicians inserted.\n";

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

// Tickets - using correct user and technician IDs
// Users: Regular User -> id=1, Alice HR -> id=2, Bob Admin -> id=3
// Technicians: John -> 1, Mary -> 2, Peter -> 3, Sarah -> 4
$tickets = [
    ['Cannot connect to network', 'My computer cannot connect to the office network. I have tried restarting the router but still no connection.', 'Finance', 'network', 'high', 'in_progress', 1, 3],
    ['Printer not working', 'The shared printer in the finance department is not printing documents. It shows offline status.', 'Finance', 'hardware', 'medium', 'open', 1, null],
    ['Login account locked', 'My account has been locked after multiple failed login attempts. Please help me reset my password.', 'HR', 'login', 'medium', 'resolved', 2, 1],
    ['Software installation issue', 'I need Microsoft Office installed on my new computer. The installation keeps failing.', 'Administration', 'software', 'low', 'open', 3, null],
    ['Email not sending', 'I can receive emails but cannot send any emails. Getting an error message about SMTP server.', 'Finance', 'software', 'high', 'in_progress', 1, 4],
    ['Computer running slow', 'My computer is extremely slow and takes a long time to open applications.', 'HR', 'hardware', 'medium', 'open', 2, null],
];

$stmt = $conn->prepare("INSERT INTO tickets (title, description, department, category, priority, status, created_by, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($tickets as $t) {
    $stmt->bind_param("ssssssii", $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $t[7]);
    $stmt->execute();
}
echo "Tickets inserted.\n";

// Ticket assignments (ticket_id, technician_id)
$assignments = [
    [1, 3, 'active', 'Working on network configuration issue'],
    [3, 1, 'completed', 'Password reset successfully'],
    [5, 4, 'active', 'Investigating email server settings'],
];

$stmt = $conn->prepare("INSERT INTO ticket_assignments (ticket_id, technician_id, status, notes) VALUES (?, ?, ?, ?)");
foreach ($assignments as $a) {
    $stmt->bind_param("iiss", $a[0], $a[1], $a[2], $a[3]);
    $stmt->execute();
}
echo "Ticket assignments inserted.\n";

// Fault history
$conn->query("INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve) VALUES (3, 'User account locked due to failed login attempts', 'Reset user password and unlocked account. Provided training on proper password management.', 1, '2024-01-15 14:30:00', 45)");
echo "Fault history inserted.\n";

// Knowledge base
$kbArticles = [
    ['cannot connect to network', 'network', "1. Check if network cable is properly connected\n2. Restart your computer\n3. Try connecting to a different network port\n4. Contact IT if issue persists", 15],
    ['printer not working', 'hardware', "1. Check if printer is turned on and connected\n2. Clear print queue\n3. Restart printer\n4. Update printer drivers\n5. Check paper and ink levels", 12],
    ['account locked', 'login', "1. Wait 15 minutes for automatic unlock\n2. Contact IT department for password reset\n3. Use password reset link if available\n4. Verify correct email/username", 8],
    ['software installation', 'software', "1. Ensure you have admin rights\n2. Disable antivirus temporarily\n3. Clear temporary files\n4. Download fresh installation files\n5. Run installer as administrator", 10],
    ['computer slow', 'hardware', "1. Restart your computer\n2. Clear browser cache and temporary files\n3. Check disk space\n4. Run virus scan\n5. Consider hardware upgrade if issue persists", 20],
];

$stmt = $conn->prepare("INSERT INTO knowledge_base (issue_keyword, category, recommended_solution, usage_count) VALUES (?, ?, ?, ?)");
foreach ($kbArticles as $k) {
    $stmt->bind_param("sssi", $k[0], $k[1], $k[2], $k[3]);
    $stmt->execute();
}
echo "Knowledge base inserted.\n";

// Sample ticket comment (ticket 1, user 1)
$stmt = $conn->prepare("INSERT INTO ticket_comments (ticket_id, user_id, user_type, comment, is_internal) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iissi", $ticketId, $userId, $userType, $commentText, $isInternal);
foreach ([
    [1, 1, 'user', 'Please keep me updated on the progress. My department needs the network urgently.', 0],
    [1, 3, 'technician', 'Testing a different network port and checking switch configuration.', 1],
] as $c) {
    $ticketId = $c[0]; $userId = $c[1]; $userType = $c[2]; $commentText = $c[3]; $isInternal = $c[4];
    $stmt->execute();
}
echo "Sample comments inserted.\n";

// System logs
$logs = [
    [1, 'admin', 'LOGIN', 'Admin logged into system', '192.168.1.100'],
    [1, 'technician', 'TICKET_ASSIGNED', 'John assigned to ticket #1', '192.168.1.101'],
    [1, 'user', 'TICKET_CREATED', 'User created new ticket', '192.168.1.102'],
    [2, 'technician', 'TICKET_RESOLVED', 'Mary resolved hardware issue', '192.168.1.103'],
];

$stmt = $conn->prepare("INSERT INTO system_logs (user_id, user_type, action, description, ip_address) VALUES (?, ?, ?, ?, ?)");
foreach ($logs as $l) {
    $stmt->bind_param("issss", $l[0], $l[1], $l[2], $l[3], $l[4]);
    $stmt->execute();
}
echo "System logs inserted.\n";

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
echo "Technician: john.tech@mcc.co.zw / tech123\n";
echo "            mary.hardware@mcc.co.zw / tech123\n";
echo "            peter.network@mcc.co.zw / tech123\n";
echo "            sarah.software@mcc.co.zw / tech123\n";
echo "User:       user@mcc.co.zw / user123\n";
echo "            alice@mcc.co.zw / user123\n";
echo "            bob@mcc.co.zw / user123\n";
echo "=============================\n";
