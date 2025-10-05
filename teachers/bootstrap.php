<?php

declare(strict_types=1);

if (!function_exists('ensure_teacher_tables')) {
    /**
     * Ensure teacher-related tables exist before processing any request.
     *
     * @throws Exception when a DDL statement fails.
     */
    function ensure_teacher_tables(mysqli $conn): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS teacher_profiles (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                name VARCHAR(255) NOT NULL,\n                percentage DECIMAL(5,2) NOT NULL DEFAULT 0,\n                phone VARCHAR(100) DEFAULT NULL,\n                note TEXT DEFAULT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS teacher_sessions (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                teacher_id INT NOT NULL,\n                session_date DATE NOT NULL,\n                group_name VARCHAR(255) NOT NULL,\n                student_name VARCHAR(255) DEFAULT NULL,\n                amount DECIMAL(12,2) NOT NULL,\n                teacher_percentage DECIMAL(5,2) NOT NULL,\n                teacher_share DECIMAL(12,2) NOT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL,\n                CONSTRAINT fk_teacher_sessions_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS teacher_payouts (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                teacher_id INT NOT NULL,\n                paid_at DATE NOT NULL,\n                amount DECIMAL(12,2) NOT NULL,\n                payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',\n                note VARCHAR(255) DEFAULT NULL,\n                transaction_id INT DEFAULT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL,\n                CONSTRAINT fk_teacher_payouts_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS teacher_session_students (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                session_id INT NOT NULL,\n                student_name VARCHAR(255) NOT NULL,\n                amount DECIMAL(12,2) NOT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL,\n                CONSTRAINT fk_session_student_session FOREIGN KEY (session_id) REFERENCES teacher_sessions(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($queries as $sql) {
            if ($conn->query($sql) === false) {
                throw new Exception("Bazani tayyorlashda xatolik: " . $conn->error);
            }
        }
    }
}
