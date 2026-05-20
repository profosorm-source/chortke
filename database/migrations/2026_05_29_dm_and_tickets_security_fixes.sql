-- Migration: Direct Message and Ticket Security Enhancements
-- Date: 2026-05-19

CREATE TABLE IF NOT EXISTS ticket_assignment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    old_admin_id INT NULL,
    new_admin_id INT NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    old_status VARCHAR(50) NOT NULL,
    new_status VARCHAR(50) NOT NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure user_conversations has correct constraints
ALTER TABLE user_conversations DROP INDEX IF EXISTS unique_conversation;
ALTER TABLE user_conversations ADD UNIQUE KEY unique_conversation (user1_id, user2_id);
