-- CERT review-request cycle: expert asks an editor to review, editor validates/redirects/returns.
-- Each cycle = one row. Latest row per cert_id represents the current state; older rows form the history.
-- Run once on the production DB (phpMyAdmin Infomaniak) before deploying the review-request endpoints.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cert_review_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cert_id INT NOT NULL,
    editor_id INT NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','done','returned') NOT NULL DEFAULT 'pending',
    note TEXT NULL,                     -- briefing from expert to editor (optional)
    editor_comment TEXT NULL,           -- required when status='returned', optional on 'done'
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    expert_ack_at DATETIME NULL,        -- NULL = expert has not yet acknowledged the done/returned notification
    INDEX idx_cert_latest (cert_id, id),
    INDEX idx_editor_pending (editor_id, status),
    FOREIGN KEY (cert_id) REFERENCES certs(id) ON DELETE CASCADE,
    FOREIGN KEY (editor_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
