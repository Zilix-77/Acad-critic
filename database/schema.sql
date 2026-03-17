-- ============================================
-- AcadVerify Database Schema
-- Lab Work Verification System
-- ============================================

CREATE DATABASE IF NOT EXISTS acadverify
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE acadverify;

-- ============================================
-- 1. USERS
-- ============================================
CREATE TABLE users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)    NOT NULL,
    email       VARCHAR(255)    NOT NULL UNIQUE,
    password    VARCHAR(255)    NOT NULL,  -- bcrypt hash
    role        ENUM('main', 'assistant', 'student') NOT NULL,
    created_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- 2. SUBJECTS
-- ============================================
CREATE TABLE subjects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)    NOT NULL,
    code        VARCHAR(20)     NOT NULL UNIQUE,
    main_id     INT UNSIGNED    NOT NULL UNIQUE,  -- one professor per subject
    created_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_subjects_main
        FOREIGN KEY (main_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 3. SUBJECT_STUDENTS  (enrollment)
-- ============================================
CREATE TABLE subject_students (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED    NOT NULL,
    subject_id  INT UNSIGNED    NOT NULL,
    enrolled_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_student_subject (student_id, subject_id),

    CONSTRAINT fk_ss_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ss_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 4. ASSIGNMENTS
-- ============================================
CREATE TABLE assignments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id       INT UNSIGNED    NOT NULL,
    type             ENUM('experiment', 'assignment', 'class_exam', 'lab_exam') NOT NULL,
    title            VARCHAR(255)    NOT NULL,
    description      TEXT            NULL,
    due_date         DATE            NULL,
    total_marks      DECIMAL(5,2)    NOT NULL DEFAULT 0,
    pass_mark        DECIMAL(5,2)    NOT NULL DEFAULT 0,
    experiment_order INT UNSIGNED    NULL,      -- ordering for experiments
    created_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_assignments_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 5. CHECKLIST_ITEMS
-- ============================================
CREATE TABLE checklist_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED    NOT NULL,
    item_text     VARCHAR(500)    NOT NULL,

    CONSTRAINT fk_checklist_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 6. STUDENT_CHECKLIST  (student ticks)
-- ============================================
CREATE TABLE student_checklist (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id        INT UNSIGNED    NOT NULL,
    checklist_item_id INT UNSIGNED    NOT NULL,
    ticked_at         TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_student_checklist (student_id, checklist_item_id),

    CONSTRAINT fk_sc_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sc_checklist_item
        FOREIGN KEY (checklist_item_id) REFERENCES checklist_items(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 7. EXPERIMENT_STATUS
-- ============================================
CREATE TABLE experiment_status (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,
    assignment_id   INT UNSIGNED    NOT NULL,
    rough_status    ENUM('pending', 'signed')    NOT NULL DEFAULT 'pending',
    rough_signed_at TIMESTAMP       NULL,
    fair_status     ENUM('pending', 'submitted') NOT NULL DEFAULT 'pending',

    UNIQUE KEY uq_experiment_student (student_id, assignment_id),

    CONSTRAINT fk_es_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_es_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 8. SUBMISSIONS
-- ============================================
CREATE TABLE submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,
    assignment_id   INT UNSIGNED    NOT NULL,
    file_path       VARCHAR(500)    NOT NULL,
    mime_type       VARCHAR(100)    NOT NULL,
    status          ENUM('pending_assistant', 'pending_main', 'marked', 'approved', 'rejected') NOT NULL DEFAULT 'pending_assistant',
    attempt_count   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    assistant_notes TEXT            NULL,
    main_feedback   TEXT            NULL,
    final_mark      DECIMAL(5,2)    NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sub_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sub_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 9. EXAM_RESULTS
-- ============================================
CREATE TABLE exam_results (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED    NOT NULL,
    assignment_id INT UNSIGNED    NOT NULL,
    attendance    ENUM('present', 'absent') NOT NULL DEFAULT 'present',
    mark          DECIMAL(5,2)    NULL,
    pass_fail     ENUM('pass', 'fail') NULL,
    entered_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_exam_student (student_id, assignment_id),

    CONSTRAINT fk_er_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_er_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- 10. NOTIFICATIONS
-- ============================================
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED    NOT NULL,
    message     VARCHAR(500)    NOT NULL,
    is_read     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notif_user (user_id, is_read),

    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
