-- Add role column to teachers + promote the three initial editors.
-- Run once on the production DB (phpMyAdmin Infomaniak) before deploying the role-aware code.

ALTER TABLE teachers
    ADD COLUMN role ENUM('expert','editor') NOT NULL DEFAULT 'expert' AFTER name;

UPDATE teachers SET role = 'editor'
    WHERE LOWER(email) IN (
        'ale@certify.community',
        'basile@certify.community',
        'stephane.hermenier@edu.ge.ch'
    );
