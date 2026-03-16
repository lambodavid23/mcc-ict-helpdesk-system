-- Smart ICT Helpdesk System Database
-- Mutare City Council ICT Department
-- MySQL Database Schema

-- Create database
CREATE DATABASE IF NOT EXISTS mcc_helpdesk;
USE mcc_helpdesk;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'technician', 'user') NOT NULL,
    department VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Technicians table
CREATE TABLE technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    name VARCHAR(100) NOT NULL,
    specialization ENUM('network', 'hardware', 'software', 'general') NOT NULL,
    current_workload INT DEFAULT 0,
    status ENUM('available', 'busy', 'offline') DEFAULT 'available',
    phone VARCHAR(20),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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

-- System logs table
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert sample data

-- Insert sample users
INSERT INTO users (name, email, password, role, department) VALUES
('Admin User', 'admin@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'ICT'),
('John Technician', 'john.tech@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician', 'ICT'),
('Mary Hardware', 'mary.hardware@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician', 'ICT'),
('Peter Network', 'peter.network@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician', 'ICT'),
('Sarah Software', 'sarah.software@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'technician', 'ICT'),
('Regular User', 'user@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 'Finance'),
('Employee One', 'emp1@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 'HR'),
('Employee Two', 'emp2@mcc.co.zw', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 'Administration');

-- Insert sample technicians
INSERT INTO technicians (user_id, name, specialization, current_workload, status, phone, email) VALUES
(2, 'John Technician', 'general', 2, 'available', '+263712345678', 'john.tech@mcc.co.zw'),
(3, 'Mary Hardware', 'hardware', 1, 'available', '+263712345679', 'mary.hardware@mcc.co.zw'),
(4, 'Peter Network', 'network', 3, 'busy', '+263712345680', 'peter.network@mcc.co.zw'),
(5, 'Sarah Software', 'software', 0, 'available', '+263712345681', 'sarah.software@mcc.co.zw');

-- Insert sample tickets
INSERT INTO tickets (title, description, department, category, priority, status, created_by, assigned_to) VALUES
('Cannot connect to network', 'My computer cannot connect to the office network. I have tried restarting the router but still no connection.', 'Finance', 'network', 'high', 'in_progress', 6, 4),
('Printer not working', 'The shared printer in the finance department is not printing documents. It shows offline status.', 'Finance', 'hardware', 'medium', 'open', 6, NULL),
('Login account locked', 'My account has been locked after multiple failed login attempts. Please help me reset my password.', 'HR', 'login', 'medium', 'resolved', 7, 2),
('Software installation issue', 'I need Microsoft Office installed on my new computer. The installation keeps failing.', 'Administration', 'software', 'low', 'open', 8, NULL),
('Email not sending', 'I can receive emails but cannot send any emails. Getting an error message about SMTP server.', 'Finance', 'software', 'high', 'in_progress', 6, 5),
('Computer running slow', 'My computer is extremely slow and takes a long time to open applications.', 'HR', 'hardware', 'medium', 'open', 7, NULL);

-- Insert sample ticket assignments
INSERT INTO ticket_assignments (ticket_id, technician_id, status, notes) VALUES
(1, 4, 'active', 'Working on network configuration issue'),
(3, 2, 'completed', 'Password reset successfully'),
(5, 5, 'active', 'Investigating email server settings');

-- Insert sample fault history
INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve) VALUES
(3, 'User account locked due to failed login attempts', 'Reset user password and unlocked account. Provided training on proper password management.', 2, '2024-01-15 14:30:00', 45);

-- Insert sample knowledge base
INSERT INTO knowledge_base (issue_keyword, category, recommended_solution, usage_count) VALUES
('cannot connect to network', 'network', '1. Check if network cable is properly connected\n2. Restart your computer\n3. Try connecting to a different network port\n4. Contact IT if issue persists', 15),
('printer not working', 'hardware', '1. Check if printer is turned on and connected\n2. Clear print queue\n3. Restart printer\n4. Update printer drivers\n5. Check paper and ink levels', 12),
('account locked', 'login', '1. Wait 15 minutes for automatic unlock\n2. Contact IT department for password reset\n3. Use password reset link if available\n4. Verify correct email/username', 8),
('software installation', 'software', '1. Ensure you have admin rights\n2. Disable antivirus temporarily\n3. Clear temporary files\n4. Download fresh installation files\n5. Run installer as administrator', 10),
('computer slow', 'hardware', '1. Restart your computer\n2. Clear browser cache and temporary files\n3. Check disk space\n4. Run virus scan\n5. Consider hardware upgrade if issue persists', 20);

-- Insert sample system logs
INSERT INTO system_logs (user_id, action, description, ip_address) VALUES
(1, 'LOGIN', 'Admin logged into system', '192.168.1.100'),
(2, 'TICKET_ASSIGNED', 'John assigned to ticket #1', '192.168.1.101'),
(6, 'TICKET_CREATED', 'User created new ticket', '192.168.1.102'),
(3, 'TICKET_RESOLVED', 'Mary resolved hardware issue', '192.168.1.103');

-- Create indexes for better performance
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_created_by ON tickets(created_by);
CREATE INDEX idx_tickets_assigned_to ON tickets(assigned_to);
CREATE INDEX idx_tickets_priority ON tickets(priority);
CREATE INDEX idx_technicians_specialization ON technicians(specialization);
CREATE INDEX idx_technicians_status ON technicians(status);
CREATE INDEX idx_fault_history_ticket_id ON fault_history(ticket_id);
CREATE INDEX idx_knowledge_base_keyword ON knowledge_base(issue_keyword);
CREATE INDEX idx_system_logs_user_id ON system_logs(user_id);
CREATE INDEX idx_system_logs_created_at ON system_logs(created_at);

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

-- Triggers for logging
DELIMITER //
CREATE TRIGGER tr_user_login
AFTER INSERT ON system_logs
FOR EACH ROW
BEGIN
    IF NEW.action = 'LOGIN' THEN
        UPDATE users SET updated_at = NOW() WHERE id = NEW.user_id;
    END IF;
END //

DELIMITER ;

-- Final setup
SET FOREIGN_KEY_CHECKS = 0;
SET FOREIGN_KEY_CHECKS = 1;

-- Display setup completion message
SELECT 'Smart ICT Helpdesk System database setup completed successfully!' as message;
