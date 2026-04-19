-- Activity log for the editor "Activité" tool.
-- - teachers.last_seen_at : updated on every authenticated API call (see getTeacherId()).
-- - teacher_activity      : append-only event log (one row per mutation worth tracking).
-- Additive migration, no downtime. Run once on the production DB via phpMyAdmin Infomaniak
-- BEFORE deploying the code that reads/writes these.

SET NAMES utf8mb4;

ALTER TABLE teachers
    ADD COLUMN last_seen_at DATETIME NULL AFTER role,
    ADD INDEX idx_last_seen (last_seen_at);

CREATE TABLE IF NOT EXISTS teacher_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,        -- e.g. auth.login, cert.save, review.request
    target_type VARCHAR(32) NULL,       -- e.g. cert, session, teacher, review
    target_id INT NULL,
    meta JSON NULL,                     -- free-form context (title, old/new role, etc.)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_teacher_created (teacher_id, created_at),
    INDEX idx_action (action),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
