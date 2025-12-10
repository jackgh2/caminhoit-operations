-- Chat Sessions Table
CREATE TABLE chat_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    guest_name VARCHAR(100) NULL,
    guest_email VARCHAR(100) NULL,
    staff_id INT NULL,
    session_token VARCHAR(64) UNIQUE NOT NULL,
    status ENUM('waiting', 'active', 'ended', 'converted') DEFAULT 'waiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    ticket_id INT NULL,
    client_info JSON NULL,
    INDEX idx_session_token (session_token),
    INDEX idx_user_id (user_id),
    INDEX idx_staff_id (staff_id),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Chat Messages Table
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    sender_type ENUM('customer', 'staff', 'system') NOT NULL,
    sender_id INT NULL,
    message TEXT NOT NULL,
    message_type ENUM('text', 'file', 'system', 'emoji') DEFAULT 'text',
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE,
    metadata JSON NULL,
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Chat Templates for Quick Responses
CREATE TABLE chat_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'general',
    created_by INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default quick responses
INSERT INTO chat_templates (title, content, category, created_by) VALUES 
('Welcome', 'Hello! Welcome to CaminhoIT support. How can I help you today?', 'greeting', 1),
('Please Wait', 'Thank you for your message. Please give me a moment to look into this for you.', 'general', 1),
('Ticket Created', 'I\'ve created a support ticket for your request. You will receive updates via email.', 'general', 1),
('Chat Ending', 'Is there anything else I can help you with today?', 'closing', 1);