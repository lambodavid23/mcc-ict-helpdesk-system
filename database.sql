-- Smart ICT Helpdesk System Database
-- Mutare City Council ICT Department
-- MySQL Database Schema
-- Each role has its own separate table: admins, users, technicians

-- Create database
CREATE DATABASE IF NOT EXISTS mcc_helpdesk;
USE mcc_helpdesk;

-- Admins table
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(100) NOT NULL DEFAULT 'ICT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users table (regular users only)
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
);

-- Remember me tokens (for auto-login when "Remember" is checked)
CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'technician', 'user') NOT NULL,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    KEY idx_user (user_type, user_id)
);

-- Password reset tokens (forgot password flow)
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'technician', 'user') NOT NULL,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token)
);

-- Technicians table
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
);

-- Tickets table
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
);

-- Ticket assignments table
CREATE TABLE ticket_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    technician_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
);

-- Fault history table
CREATE TABLE fault_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    problem TEXT NOT NULL,
    solution TEXT,
    resolved_by INT,
    resolved_at TIMESTAMP NULL,
    time_to_resolve INT COMMENT 'Time in minutes',
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES technicians(id)
);

-- Knowledge base table
CREATE TABLE knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_keyword VARCHAR(200) NOT NULL,
    category ENUM('network', 'hardware', 'software', 'login') NOT NULL,
    recommended_solution TEXT NOT NULL,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Ticket comments table
CREATE TABLE ticket_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'technician', 'user') NOT NULL DEFAULT 'user',
    comment TEXT NOT NULL,
    is_internal TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

-- Ticket attachments table
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
);

-- Notifications table (user_type identifies which table user_id belongs to)
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
);

-- Assignment rules table (routes tickets to technicians by category/specialization)
CREATE TABLE assignment_rules (
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
);

-- Insert sample assignment rules
INSERT INTO assignment_rules (category, specialization, priority, technician_id, auto_assign, priority_order, is_active) VALUES
('network', 'network', 'any', NULL, 1, 1, 1),
('hardware', 'hardware', 'any', NULL, 1, 2, 1),
('software', 'software', 'any', NULL, 1, 3, 1),
('login', 'general', 'any', NULL, 1, 4, 1),
('all', 'general', 'any', NULL, 1, 10, 1),
('general', 'general', 'any', NULL, 1, 20, 1);

-- System logs table (user_type identifies which table user_id belongs to)
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    user_type ENUM('admin', 'technician', 'user'),
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample admins
INSERT INTO admins (name, email, password, department) VALUES
('Admin User', 'admin@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ICT');

-- Insert sample users
INSERT INTO users (name, email, password, department) VALUES
('Regular User', 'user@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Finance'),
('Employee One', 'emp1@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'HR'),
('Employee Two', 'emp2@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administration');

-- Insert sample technicians
INSERT INTO technicians (name, email, password, specialization, current_workload, status, phone, department) VALUES
('John Technician', 'john.tech@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'general', 2, 'available', '+263712345678', 'ICT'),
('Mary Hardware', 'mary.hardware@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hardware', 1, 'available', '+263712345679', 'ICT'),
('Peter Network', 'peter.network@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'network', 3, 'busy', '+263712345680', 'ICT'),
('Sarah Software', 'sarah.software@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'software', 0, 'available', '+263712345681', 'ICT');

-- Insert sample tickets
INSERT INTO tickets (title, description, department, category, priority, status, created_by, assigned_to) VALUES
('Cannot connect to network', 'My computer cannot connect to the office network. I have tried restarting the router but still no connection.', 'Finance', 'network', 'high', 'in_progress', 1, 3),
('Printer not working', 'The shared printer in the finance department is not printing documents. It shows offline status.', 'Finance', 'hardware', 'medium', 'open', 1, NULL),
('Login account locked', 'My account has been locked after multiple failed login attempts. Please help me reset my password.', 'HR', 'login', 'medium', 'resolved', 2, 1),
('Software installation issue', 'I need Microsoft Office installed on my new computer. The installation keeps failing.', 'Administration', 'software', 'low', 'open', 3, NULL),
('Email not sending', 'I can receive emails but cannot send any emails. Getting an error message about SMTP server.', 'Finance', 'software', 'high', 'in_progress', 1, 4),
('Computer running slow', 'My computer is extremely slow and takes a long time to open applications.', 'HR', 'hardware', 'medium', 'open', 2, NULL);

-- Insert sample ticket assignments
INSERT INTO ticket_assignments (ticket_id, technician_id, status, notes) VALUES
(1, 3, 'active', 'Working on network configuration issue'),
(3, 1, 'completed', 'Password reset successfully'),
(5, 4, 'active', 'Investigating email server settings');

-- Insert sample fault history
INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve) VALUES
(3, 'User account locked due to failed login attempts', 'Reset user password and unlocked account. Provided training on proper password management.', 1, '2024-01-15 14:30:00', 45);

-- Insert sample knowledge base
INSERT INTO knowledge_base (issue_keyword, category, recommended_solution, usage_count) VALUES
('cannot connect to network', 'network', '1. Check if network cable is properly connected\n2. Restart your computer\n3. Try connecting to a different network port\n4. Contact IT if issue persists', 15),
('printer not working', 'hardware', '1. Check if printer is turned on and connected\n2. Clear print queue\n3. Restart printer\n4. Update printer drivers\n5. Check paper and ink levels', 12),
('account locked', 'login', '1. Wait 15 minutes for automatic unlock\n2. Contact IT department for password reset\n3. Use password reset link if available\n4. Verify correct email/username', 8),
('software installation', 'software', '1. Ensure you have admin rights\n2. Disable antivirus temporarily\n3. Clear temporary files\n4. Download fresh installation files\n5. Run installer as administrator', 10),
('computer slow', 'hardware', '1. Restart your computer\n2. Clear browser cache and temporary files\n3. Check disk space\n4. Run virus scan\n5. Consider hardware upgrade if issue persists', 20);

-- Insert sample system logs
INSERT INTO system_logs (user_id, user_type, action, description, ip_address) VALUES
(1, 'admin', 'LOGIN', 'Admin logged into system', '192.168.1.100'),
(1, 'technician', 'TICKET_ASSIGNED', 'John assigned to ticket #1', '192.168.1.101'),
(1, 'user', 'TICKET_CREATED', 'User created new ticket', '192.168.1.102'),
(2, 'technician', 'TICKET_RESOLVED', 'Mary resolved hardware issue', '192.168.1.103');

-- Create indexes for better performance
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_created_by ON tickets(created_by);
CREATE INDEX idx_tickets_assigned_to ON tickets(assigned_to);
CREATE INDEX idx_tickets_priority ON tickets(priority);
CREATE INDEX idx_technicians_specialization ON technicians(specialization);
CREATE INDEX idx_technicians_status ON technicians(status);
CREATE INDEX idx_technicians_email ON technicians(email);
CREATE INDEX idx_admins_email ON admins(email);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_fault_history_ticket_id ON fault_history(ticket_id);
CREATE INDEX idx_knowledge_base_keyword ON knowledge_base(issue_keyword);
CREATE INDEX idx_system_logs_user_id ON system_logs(user_id);
CREATE INDEX idx_system_logs_created_at ON system_logs(created_at);
CREATE INDEX idx_ticket_comments_ticket_id ON ticket_comments(ticket_id);
CREATE INDEX idx_ticket_attachments_ticket_id ON ticket_attachments(ticket_id);
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_is_read ON notifications(is_read);
CREATE INDEX idx_assignment_rules_category ON assignment_rules(category);
CREATE INDEX idx_assignment_rules_is_active ON assignment_rules(is_active);

-- Create views for common queries
CREATE VIEW ticket_summary AS
SELECT 
    t.id,
    t.title,
    t.status,
    t.priority,
    t.category,
    u.name as created_by_name,
    tech.name as assigned_to_name,
    t.created_at
FROM tickets t
LEFT JOIN users u ON t.created_by = u.id
LEFT JOIN technicians tech ON t.assigned_to = tech.id;

CREATE VIEW technician_workload AS
SELECT 
    tech.id,
    tech.name,
    tech.specialization,
    tech.status,
    COUNT(t.id) as active_tickets,
    tech.current_workload
FROM technicians tech
LEFT JOIN tickets t ON tech.id = t.assigned_to AND t.status IN ('open', 'in_progress')
GROUP BY tech.id, tech.name, tech.specialization, tech.status, tech.current_workload;

-- Stored procedures for common operations
DELIMITER //

CREATE PROCEDURE sp_assign_technician(IN ticket_id INT, IN category VARCHAR(50))
BEGIN
    DECLARE tech_id INT;
    
    -- Find available technician with lowest workload in the relevant specialization
    SELECT id INTO tech_id
    FROM technicians 
    WHERE status = 'available' 
    AND (specialization = category OR specialization = 'general')
    ORDER BY current_workload ASC, specialization = category DESC
    LIMIT 1;
    
    -- Update ticket with assigned technician
    IF tech_id IS NOT NULL THEN
        UPDATE tickets 
        SET assigned_to = tech_id, status = 'in_progress'
        WHERE id = ticket_id;
        
        -- Update technician workload
        UPDATE technicians 
        SET current_workload = current_workload + 1, status = 'busy'
        WHERE id = tech_id;
        
        -- Create assignment record
        INSERT INTO ticket_assignments (ticket_id, technician_id, status)
        VALUES (ticket_id, tech_id, 'active');
    END IF;
END //

CREATE PROCEDURE sp_resolve_ticket(IN ticket_id INT, IN technician_id INT, IN solution TEXT)
BEGIN
    DECLARE resolution_time INT;
    
    -- Calculate resolution time in minutes
    SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) INTO resolution_time
    FROM tickets WHERE id = ticket_id;
    
    -- Update ticket status
    UPDATE tickets 
    SET status = 'resolved'
    WHERE id = ticket_id;
    
    -- Update technician workload
    UPDATE technicians 
    SET current_workload = GREATEST(current_workload - 1, 0),
        status = CASE WHEN current_workload <= 1 THEN 'available' ELSE 'busy' END
    WHERE id = technician_id;
    
    -- Add to fault history
    INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve)
    SELECT id, title, solution, technician_id, NOW(), resolution_time
    FROM tickets WHERE id = ticket_id;
    
    -- Update ticket assignment
    UPDATE ticket_assignments 
    SET status = 'completed'
    WHERE ticket_id = ticket_id AND technician_id = technician_id;
END //

DELIMITER ;

-- Final setup
SET FOREIGN_KEY_CHECKS = 0;
SET FOREIGN_KEY_CHECKS = 1;

-- Display setup completion message
SELECT 'Smart ICT Helpdesk System database setup completed successfully!' as message;