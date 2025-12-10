CREATE TABLE support_ticket_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT
);


CREATE TABLE support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Open', 'Pending', 'Closed') DEFAULT 'Open',
    priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Medium',
    group_id INT,
    assigned_to INT,
    user_id INT,
    company_id INT,
    visibility_scope ENUM('private','company','public') DEFAULT 'private',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES support_ticket_groups(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);


CREATE TABLE support_ticket_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);


CREATE TABLE support_ticket_watchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);


CREATE TABLE canned_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    user_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);


CREATE TABLE user_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    signature TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);


ALTER TABLE support_ticket_groups ADD active TINYINT(1) DEFAULT 1;

CREATE TABLE `support_ticket_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

ALTER TABLE support_ticket_replies
ADD COLUMN attachment_path VARCHAR(255) NULL;

CREATE TABLE `support_ticket_attachments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reply_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255),
    `uploaded_at` DATETIME NOT NULL,
    FOREIGN KEY (`reply_id`) REFERENCES `support_ticket_replies`(`id`) ON DELETE CASCADE
);



-- Knowledge Base Categories
CREATE TABLE kb_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    parent_id INT DEFAULT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    color VARCHAR(7) DEFAULT '#6c757d',
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES kb_categories(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id),
    INDEX idx_active (is_active),
    INDEX idx_sort (sort_order)
);

-- Knowledge Base Articles
CREATE TABLE kb_articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    content LONGTEXT NOT NULL,
    excerpt TEXT,
    category_id INT NOT NULL,
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    visibility ENUM('public', 'authenticated', 'staff_only') DEFAULT 'public',
    author_id INT NOT NULL,
    view_count INT DEFAULT 0,
    helpful_count INT DEFAULT 0,
    not_helpful_count INT DEFAULT 0,
    featured BOOLEAN DEFAULT FALSE,
    meta_title VARCHAR(255),
    meta_description TEXT,
    search_keywords TEXT,
    last_reviewed_at TIMESTAMP NULL,
    last_reviewed_by INT NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES kb_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (last_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_visibility (visibility),
    INDEX idx_published (published_at),
    INDEX idx_featured (featured),
    FULLTEXT idx_search (title, content, search_keywords)
);

-- Knowledge Base Article Tags
CREATE TABLE kb_article_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    tag_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_article_tag (article_id, tag_name),
    INDEX idx_tag_name (tag_name)
);

-- Knowledge Base Article Attachments
CREATE TABLE kb_article_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_type ENUM('image', 'document', 'video', 'archive', 'other') DEFAULT 'other',
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_article (article_id),
    INDEX idx_file_type (file_type)
);

-- Knowledge Base Article Feedback
CREATE TABLE kb_article_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    user_id INT NULL,
    session_id VARCHAR(100) NULL,
    is_helpful BOOLEAN NOT NULL,
    feedback_text TEXT NULL,
    user_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_article (article_id),
    INDEX idx_helpful (is_helpful),
    INDEX idx_created (created_at)
);

-- Knowledge Base Settings
CREATE TABLE kb_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Knowledge Base Article Views (for analytics)
CREATE TABLE kb_article_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    user_id INT NULL,
    session_id VARCHAR(100) NULL,
    user_ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    referrer TEXT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_article (article_id),
    INDEX idx_viewed_at (viewed_at),
    INDEX idx_user (user_id)
);

-- Link KB articles to support tickets (for future integration)
CREATE TABLE kb_ticket_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    article_id INT NOT NULL,
    linked_by INT NOT NULL,
    link_type ENUM('suggested', 'referenced', 'resolved') DEFAULT 'suggested',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_ticket_article (ticket_id, article_id),
    INDEX idx_ticket (ticket_id),
    INDEX idx_article (article_id)
);

-- Insert default categories
INSERT INTO kb_categories (name, slug, description, icon, color, sort_order) VALUES
('Getting Started', 'getting-started', 'Basic guides and tutorials for new users', 'bi-play-circle', '#28a745', 1),
('Account Management', 'account-management', 'Managing your account, billing, and profile', 'bi-person-gear', '#007bff', 2),
('Technical Support', 'technical-support', 'Technical issues and troubleshooting', 'bi-tools', '#dc3545', 3),
('Features & Services', 'features-services', 'Learn about our features and services', 'bi-grid-3x3-gap', '#6f42c1', 4),
('Billing & Payments', 'billing-payments', 'Billing, invoices, and payment information', 'bi-credit-card', '#fd7e14', 5),
('Security', 'security', 'Security best practices and account protection', 'bi-shield-check', '#e83e8c', 6);

-- Insert default settings
INSERT INTO kb_settings (setting_key, setting_value) VALUES
('kb_title', 'Knowledge Base'),
('kb_description', 'Find answers to frequently asked questions and get help with our services'),
('articles_per_page', '10'),
('enable_public_access', '1'),
('enable_feedback', '1'),
('enable_search', '1'),
('enable_categories', '1'),
('enable_tags', '1'),
('require_review', '0'),
('auto_suggest_tickets', '1');
