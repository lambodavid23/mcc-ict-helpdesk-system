-- Feature Addition Migration
-- Smart ICT Helpdesk System

USE mcc_helpdesk;

-- 1. Ticket Comments Table
CREATE TABLE IF NOT EXISTS ticket_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type ENUM('user', 'technician', 'admin') NOT NULL,
    comment TEXT NOT NULL,
    is_internal TINYINT(1) DEFAULT 0 COMMENT '1=internal note (techs/admins only), 0=public reply',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 2. Ticket Attachments Table
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    filesize INT NOT NULL,
    mime_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('user', 'technician', 'admin') NOT NULL,
    ticket_id INT,
    type ENUM('ticket_created', 'ticket_assigned', 'ticket_updated', 'ticket_resolved', 'comment_added', 'mention') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    is_read TINYINT(1) DEFAULT 0,
    email_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
);

-- 4. Assignment Rules Table
CREATE TABLE IF NOT EXISTS assignment_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    specialization VARCHAR(50) NOT NULL,
    priority VARCHAR(20) DEFAULT 'any',
    technician_id INT,
    auto_assign TINYINT(1) DEFAULT 1,
    priority_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL
);

-- 5. SLA Levels Table
CREATE TABLE IF NOT EXISTS sla_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    priority ENUM('high', 'medium', 'low') NOT NULL,
    response_time_hours INT NOT NULL,
    resolution_time_hours INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default SLA levels
INSERT INTO sla_levels (name, priority, response_time_hours, resolution_time_hours) VALUES
('Critical', 'high', 1, 4),
('Standard', 'medium', 8, 24),
('Low', 'low', 24, 72);

-- Insert default assignment rules
INSERT INTO assignment_rules (category, specialization, priority, auto_assign, priority_order) VALUES
('network', 'network', 'any', 1, 1),
('hardware', 'hardware', 'any', 1, 2),
('software', 'software', 'any', 1, 3),
('login', 'general', 'any', 1, 4),
('general', 'general', 'any', 1, 5);

-- Create indexes for new tables
CREATE INDEX idx_comments_ticket_id ON ticket_comments(ticket_id);
CREATE INDEX idx_comments_user_id ON ticket_comments(user_id);
CREATE INDEX idx_comments_created ON ticket_comments(created_at);
CREATE INDEX idx_attachments_ticket_id ON ticket_attachments(ticket_id);
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_read ON notifications(is_read);
CREATE INDEX idx_notifications_ticket ON notifications(ticket_id);
CREATE INDEX idx_rules_category ON assignment_rules(category);
CREATE INDEX idx_rules_active ON assignment_rules(is_active);

-- Create uploads directory indicator
-- Note: Create this directory manually: mkdir -p ../uploads/tickets
