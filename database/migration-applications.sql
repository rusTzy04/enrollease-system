
USE enrollease_system;

CREATE TABLE IF NOT EXISTS enrollment_applications (
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

CREATE TABLE IF NOT EXISTS application_requirements (
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

CREATE TABLE IF NOT EXISTS application_payments (
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
