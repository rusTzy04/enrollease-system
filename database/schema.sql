
CREATE DATABASE IF NOT EXISTS enrollease_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE enrollease_system;

-- ---------------------------------------------------------------
-- USERS & ROLES
-- ---------------------------------------------------------------
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (role_name) VALUES
('admin'), ('academic_scheduler'), ('registrar'), ('cashier'), ('teacher'), ('student');

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    student_id VARCHAR(20) UNIQUE NULL,          
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    remember_token VARCHAR(255) NULL,
    reset_token VARCHAR(255) NULL,
    reset_token_expires DATETIME NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- CURRICULUM MANAGEMENT
-- ---------------------------------------------------------------
CREATE TABLE programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL UNIQUE,
    program_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE academic_years (
    academic_year_id INT AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(20) NOT NULL UNIQUE,       -- e.g. 2026-2027
    is_active TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE semesters (
    semester_id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    semester_name ENUM('1st Semester','2nd Semester','Summer') NOT NULL,
    enrollment_start DATETIME NULL,
    enrollment_end DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(academic_year_id)
) ENGINE=InnoDB;

CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    lecture_units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    lab_units DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE subject_prerequisites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    prerequisite_subject_id INT NOT NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (prerequisite_subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    UNIQUE KEY unique_prereq (subject_id, prerequisite_subject_id)
) ENGINE=InnoDB;

CREATE TABLE curriculum (
    curriculum_id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    subject_id INT NOT NULL,
    year_level TINYINT NOT NULL,           
    semester_name ENUM('1st Semester','2nd Semester','Summer') NOT NULL,
    subject_type ENUM('Required','Elective') NOT NULL DEFAULT 'Required',
    effective_academic_year_id INT NOT NULL,
    FOREIGN KEY (program_id) REFERENCES programs(program_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),
    FOREIGN KEY (effective_academic_year_id) REFERENCES academic_years(academic_year_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- SCHEDULE & SECTION MANAGEMENT
-- ---------------------------------------------------------------
CREATE TABLE rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(50) NOT NULL,
    capacity INT NOT NULL DEFAULT 40
) ENGINE=InnoDB;

CREATE TABLE sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(50) NOT NULL,     -- e.g. BSIT-1A
    program_id INT NOT NULL,
    year_level TINYINT NOT NULL,
    semester_id INT NOT NULL,
    max_slots INT NOT NULL DEFAULT 40,
    FOREIGN KEY (program_id) REFERENCES programs(program_id),
    FOREIGN KEY (semester_id) REFERENCES semesters(semester_id)
) ENGINE=InnoDB;

CREATE TABLE class_schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    section_id INT NOT NULL,
    teacher_id INT NULL,                  
    room_id INT NULL,
    day_of_week SET('Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slots_taken INT NOT NULL DEFAULT 0,
    status ENUM('Open','Closed','Cancelled') NOT NULL DEFAULT 'Open',
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),
    FOREIGN KEY (section_id) REFERENCES sections(section_id),
    FOREIGN KEY (teacher_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- STUDENT PROFILE (extends users for student-specific info)
-- ---------------------------------------------------------------
CREATE TABLE student_profiles (
    user_id INT PRIMARY KEY,
    program_id INT NULL,
    year_level TINYINT NOT NULL DEFAULT 1,
    address VARCHAR(255) NULL,
    birthdate DATE NULL,
    guardian_name VARCHAR(150) NULL,
    guardian_contact VARCHAR(20) NULL,
    academic_standing ENUM('Regular','Irregular','Probation','Dean''s Lister') DEFAULT 'Regular',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(program_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- ENROLLMENT
-- ---------------------------------------------------------------
CREATE TABLE enrollments (
    enrollment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    status ENUM(
        'Draft','Submitted','Requirements Pending','Requirements Verified',
        'Payment Pending','Payment Verified','Enrolled','Rejected','Cancelled'
    ) NOT NULL DEFAULT 'Draft',
    total_units DECIMAL(4,1) NOT NULL DEFAULT 0,
    total_tuition DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_fees DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount_due DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    submitted_at DATETIME NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    FOREIGN KEY (semester_id) REFERENCES semesters(semester_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY unique_enrollment_per_sem (student_id, semester_id)
) ENGINE=InnoDB;

CREATE TABLE enrollment_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    schedule_id INT NOT NULL,
    grade VARCHAR(5) NULL,
    remark ENUM('Passed','Failed','Withdrawn','Incomplete','In Progress') DEFAULT 'In Progress',
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES class_schedules(schedule_id)
) ENGINE=InnoDB;

CREATE TABLE enrollment_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    changed_by INT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- REQUIREMENTS
-- ---------------------------------------------------------------
CREATE TABLE requirement_types (
    requirement_type_id INT AUTO_INCREMENT PRIMARY KEY,
    requirement_name VARCHAR(150) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO requirement_types (requirement_name) VALUES
('Form 137 / Transcript of Records'), ('Certificate of Good Moral'), ('Birth Certificate (PSA)'),
('2x2 ID Picture'), ('Medical Certificate');

CREATE TABLE enrollment_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    requirement_type_id INT NOT NULL,
    status ENUM('Missing','Received','Verified') NOT NULL DEFAULT 'Missing',
    received_at DATETIME NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_type_id) REFERENCES requirement_types(requirement_type_id),
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- FEES / PAYMENTS
-- ---------------------------------------------------------------
CREATE TABLE fee_structure (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    fee_name VARCHAR(100) NOT NULL,        
    fee_type ENUM('Per Unit','Fixed') NOT NULL DEFAULT 'Fixed',
    amount DECIMAL(10,2) NOT NULL,
    program_id INT NULL,                   
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Card','Bank Transfer','Other') NOT NULL DEFAULT 'Cash',
    received_by INT NOT NULL,              -- cashier user_id
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_verified TINYINT(1) NOT NULL DEFAULT 1,
    remarks VARCHAR(255) NULL,
    is_refund TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id),
    FOREIGN KEY (received_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------------
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(150) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('Sent','Failed') NOT NULL DEFAULT 'Sent',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- AUDIT / ACTIVITY LOG
-- ---------------------------------------------------------------
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(150) NOT NULL,
    details VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO users (role_id, first_name, last_name, email, password_hash, is_active, email_verified)
VALUES (1, 'System', 'Administrator', 'admin@enrollease.edu.ph', '$2y$10$92C1s0Yc0m2E5wq0F0m1EOe0nQe2mR1z3F0x1nQe1S1lO1F0m1EOa', 1, 1),
       (2, 'Test', 'Academic', 'academic@enrollease.edu.ph', '$2y$10$92C1s0Yc0m2E5wq0F0m1EOe0nQe2mR1z3F0x1nQe1S1lO1F0m1EOa', 1, 1),
       (3, 'Test', 'Registrar', 'registrar@enrollease.edu.ph', '$2y$10$92C1s0Yc0m2E5wq0F0m1EOe0nQe2mR1z3F0x1nQe1S1lO1F0m1EOa', 1, 1),
       (4, 'Test', 'Cashier', 'cashier@enrollease.edu.ph', '$2y$10$92C1s0Yc0m2E5wq0F0m1EOe0nQe2mR1z3F0x1nQe1S1lO1F0m1EOa', 1, 1),
       (5, 'Test', 'Teacher', 'teacher@enrollease.edu.ph', '$2y$10$92C1s0Yc0m2E5wq0F0m1EOe0nQe2mR1z3F0x1nQe1S1lO1F0m1EOa', 1, 1);

INSERT INTO programs (program_code, program_name) VALUES
('BSIT', 'Bachelor of Science in Information Technology'),
('BSBA', 'Bachelor of Science in Business Administration');

INSERT INTO academic_years (year_label, is_active) VALUES ('2026-2027', 1);
INSERT INTO semesters (academic_year_id, semester_name, enrollment_start, enrollment_end, is_active)
VALUES (1, '1st Semester', '2026-10-01 00:00:00', '2026-12-18 23:59:59', 1);

INSERT INTO fee_structure (fee_name, fee_type, amount) VALUES
('Registration Fee', 'Fixed', 500.00),
('Miscellaneous Fee', 'Fixed', 1500.00),
('Laboratory Fee', 'Fixed', 800.00),
('Tuition per Unit', 'Per Unit', 450.00);


CREATE TABLE enrollment_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    program_id INT NOT NULL,
    address VARCHAR(255) NULL,
    birthdate DATE NULL,
    guardian_name VARCHAR(150) NULL,
    guardian_contact VARCHAR(20) NULL,
    semester_id INT NOT NULL,
    status ENUM('Requirements Pending','Requirements Verified','Payment Pending','Payment Verified','Approved','Rejected')
        NOT NULL DEFAULT 'Requirements Pending',
    total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    rejection_reason VARCHAR(255) NULL,
    converted_user_id INT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INT NULL,
    FOREIGN KEY (program_id) REFERENCES programs(program_id),
    FOREIGN KEY (semester_id) REFERENCES semesters(semester_id),
    FOREIGN KEY (converted_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE application_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    requirement_type_id INT NOT NULL,
    status ENUM('Missing','Received','Verified') NOT NULL DEFAULT 'Missing',
    received_at DATETIME NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (application_id) REFERENCES enrollment_applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_type_id) REFERENCES requirement_types(requirement_type_id),
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE application_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Card','Bank Transfer','Other') NOT NULL DEFAULT 'Cash',
    received_by INT NOT NULL,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    remarks VARCHAR(255) NULL,
    FOREIGN KEY (application_id) REFERENCES enrollment_applications(application_id),
    FOREIGN KEY (received_by) REFERENCES users(user_id)
) ENGINE=InnoDB;


INSERT INTO rooms (room_name, capacity) VALUES ('Room 101', 40), ('Room 102', 40), ('Computer Lab 1', 30);

INSERT INTO subjects (subject_code, subject_name, units, lecture_units, lab_units) VALUES
('IT101', 'Introduction to Computing', 3.0, 3.0, 0.0),
('IT102', 'Computer Programming 1', 3.0, 2.0, 1.0),
('GE101', 'Understanding the Self', 3.0, 3.0, 0.0),
('PE101', 'Physical Education 1', 2.0, 2.0, 0.0);

INSERT INTO curriculum (program_id, subject_id, year_level, semester_name, subject_type, effective_academic_year_id) VALUES
(1, 1, 1, '1st Semester', 'Required', 1),
(1, 2, 1, '1st Semester', 'Required', 1),
(1, 3, 1, '1st Semester', 'Required', 1),
(1, 4, 1, '1st Semester', 'Required', 1);

INSERT INTO sections (section_name, program_id, year_level, semester_id, max_slots) VALUES
('BSIT-1A', 1, 1, 1, 40);

INSERT INTO class_schedules (subject_id, section_id, teacher_id, room_id, day_of_week, start_time, end_time) VALUES
(1, 1, NULL, 1, 'Mon,Wed', '08:00:00', '09:30:00'),
(2, 1, NULL, 3, 'Tue,Thu', '09:30:00', '11:00:00'),
(3, 1, NULL, 2, 'Mon,Wed', '13:00:00', '14:30:00'),
(4, 1, NULL, 1, 'Fri', '08:00:00', '10:00:00');

