-- toutcuit.ch — Multi-tenant database schema
-- Run this on phpMyAdmin Infomaniak to create all tables

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Teachers
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schools
CREATE TABLE IF NOT EXISTS schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- N↔N teacher ↔ school
CREATE TABLE IF NOT EXISTS teacher_school (
    teacher_id INT NOT NULL,
    school_id INT NOT NULL,
    PRIMARY KEY (teacher_id, school_id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CERTs (fonds commun + privés)
CREATE TABLE IF NOT EXISTS certs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    title VARCHAR(500) NOT NULL,
    url VARCHAR(2000) NOT NULL,
    expert VARCHAR(200),
    cert_date DATE,
    descriptor1 VARCHAR(100),
    descriptor2 VARCHAR(100),
    reliability ENUM('good','mid','bad') NOT NULL,
    three_phrases TEXT,
    context TEXT,
    content TEXT,
    reliability_text TEXT,
    references_text TEXT,
    is_shared BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessions
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    school_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    is_open BOOLEAN DEFAULT TRUE,
    collector_open BOOLEAN DEFAULT FALSE,
    max_collect INT DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CERTs sélectionnés pour une séance
CREATE TABLE IF NOT EXISTS session_certs (
    session_id INT NOT NULL,
    cert_id INT NOT NULL,
    position INT DEFAULT 0,
    PRIMARY KEY (session_id, cert_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (cert_id) REFERENCES certs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réponses élèves
CREATE TABLE IF NOT EXISTS student_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    cert_id INT NOT NULL,
    first_label VARCHAR(100),
    last_label VARCHAR(100),
    reliability ENUM('good','mid','bad'),
    comment TEXT,
    dedup_key VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (dedup_key),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (cert_id) REFERENCES certs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Liens collectés par les élèves
CREATE TABLE IF NOT EXISTS collected_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    url VARCHAR(2000) NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
