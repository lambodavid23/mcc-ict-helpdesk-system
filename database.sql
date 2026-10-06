-- Smart ICT Helpdesk System Database
-- Mutare City Council ICT Department
-- MySQL Database Schema
-- Each role has its own separate table: admins, users, technicians
--
-- This file deliberately contains NO "CREATE DATABASE" and NO "USE". It used to
-- hardcode "USE mcc_helpdesk;", which meant that pointing the client at any
-- other database - a scratch copy, a staging server, a restored dump - silently
-- wrote to the live one instead, where it would then fail partway through on
-- tables that already exist.
--
-- Select the target database yourself before importing:
--   mysql -u root -e "CREATE DATABASE mcc_helpdesk"
--   mysql -u root mcc_helpdesk < database.sql

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

-- Failed login attempts, used to throttle brute force on the login form.
-- See config/migrations/login_throttle.sql.
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_time (ip_address, attempted_at)
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

-- Technician daily attendance (clock in / clock out)
-- Technicians must clock in each day to be eligible for auto-assignment
CREATE TABLE technician_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    work_date DATE NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    UNIQUE KEY uq_tech_date (technician_id, work_date),
    CONSTRAINT fk_attendance_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
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
    previous_status VARCHAR(50) DEFAULT NULL COMMENT 'Status before the change (for status updates)',
    new_status VARCHAR(50) DEFAULT NULL COMMENT 'Status after the change (for status updates)',
    deleted_at TIMESTAMP NULL COMMENT 'Soft-delete marker',
    deleted_by INT NULL COMMENT 'Technician who soft-deleted this record',
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
    deleted_at TIMESTAMP NULL COMMENT 'Soft-delete marker',
    deleted_by INT NULL COMMENT 'Technician who soft-deleted this record',
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES technicians(id)
);

-- Ticket update deletion quota/audit log
CREATE TABLE ticket_update_deletions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    ticket_id INT NOT NULL,
    record_type ENUM('status_update', 'solution') NOT NULL,
    record_id INT NOT NULL,
    details TEXT COMMENT 'Snapshot of deleted content for the audit trail',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
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

-- The only account this file creates is the admin below. No sample users,
-- technicians or tickets are seeded: a fresh install starts empty apart from
-- this admin, and staff are added from the admin panel afterwards.
--
-- It is created LOCKED, with the sentinel password '!locked' rather than a
-- real hash. '!locked' is not a valid bcrypt hash, so password_verify()
-- rejects every guess against it and nobody can sign in until a password is
-- set.
--
-- It used to carry the bcrypt hash of the literal string "password". This
-- file is in a public repository, so that made the account reachable by
-- anyone who had read the source.
--
-- Set a real password from the command line after importing:
--   php tools/create_admin.php            (new admin, password generated)
--   php tools/set_password.php admin@mcc.co.zw

INSERT INTO admins (name, email, password, department) VALUES
('System Administrator', 'admin@mcc.co.zw', '!locked', 'ICT');

-- Knowledge base, ticket data and staff are NOT seeded here. The knowledge-base
-- corpus lives in config/migrations/baseline_data.sql; run it after importing
-- this file if you want the AI assistant to have articles to retrieve.

-- Create indexes for better performance
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_created_by ON tickets(created_by);
CREATE INDEX idx_tickets_assigned_to ON tickets(assigned_to);
CREATE INDEX idx_tickets_priority ON tickets(priority);
CREATE INDEX idx_technicians_specialization ON technicians(specialization);
CREATE INDEX idx_technicians_status ON technicians(status);
CREATE INDEX idx_technicians_email ON technicians(email);
CREATE INDEX idx_attendance_technician_date ON technician_attendance(technician_id, work_date);
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