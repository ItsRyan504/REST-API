CREATE DATABASE IF NOT EXISTS school_grading_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE school_grading_system;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(30) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'teacher',
    created_at INT UNSIGNED NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    year_level VARCHAR(50) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at INT UNSIGNED NOT NULL,
    CONSTRAINT fk_students_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    grade DECIMAL(4,2) NOT NULL,
    label VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_subject_per_student (student_id, name),
    CONSTRAINT fk_subjects_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
);
