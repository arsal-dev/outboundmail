CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  sender VARCHAR(255),
  recipient VARCHAR(255),
  subject VARCHAR(255),
  spam_score DECIMAL(4,1),
  status VARCHAR(20) DEFAULT 'sent',
  message_id VARCHAR(255),
  INDEX idx_timestamp (timestamp),
  INDEX idx_sender (sender),
  INDEX idx_recipient (recipient),
  INDEX idx_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blocked_senders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  target VARCHAR(255) NOT NULL,
  block_type ENUM('sender','domain') NOT NULL DEFAULT 'sender',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_target_type (target, block_type),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
