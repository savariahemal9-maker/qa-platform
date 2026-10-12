-- QA Platform Database Schema
-- Run this on your MySQL/RDS instance

CREATE DATABASE IF NOT EXISTS qa_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qa_platform;

-- Companies (tenants)
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    plan ENUM('trial','starter','pro','enterprise') DEFAULT 'trial',
    plan_expires_at DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','tester','viewer') DEFAULT 'tester',
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Projects
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Test Suites
CREATE TABLE test_suites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Test Cases
CREATE TABLE test_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    suite_id INT NOT NULL,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    steps JSON NOT NULL,
    expected_result TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (suite_id) REFERENCES test_suites(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Test Runs
CREATE TABLE test_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    project_id INT NOT NULL,
    suite_id INT NOT NULL,
    triggered_by INT NOT NULL,
    status ENUM('queued','running','passed','failed','error') DEFAULT 'queued',
    total_cases INT DEFAULT 0,
    passed_cases INT DEFAULT 0,
    failed_cases INT DEFAULT 0,
    duration_ms INT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- Test Results (per case)
CREATE TABLE test_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    case_id INT NOT NULL,
    company_id INT NOT NULL,
    status ENUM('passed','failed','skipped','error') DEFAULT 'skipped',
    error_message TEXT NULL,
    screenshot_path VARCHAR(500) NULL,
    duration_ms INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES test_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES test_cases(id) ON DELETE CASCADE
);

-- API Keys (for CI/CD integration)
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    last_used DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Seed: Demo company + admin user (password: Admin@123)
INSERT INTO companies (name, slug, plan, plan_expires_at) VALUES
('Demo Company', 'demo', 'pro', DATE_ADD(NOW(), INTERVAL 30 DAY));

INSERT INTO users (company_id, name, email, password, role) VALUES
(1, 'Admin User', 'admin@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
-- Default password: password (change this!)
