<?php
// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "ina_lms";

// Create connection
$conn = new mysqli($host, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS $database";
if ($conn->query($sql) === TRUE) {
    // Database created successfully or already exists
} else {
    echo "Error creating database: " . $conn->error;
}

// Select database
$conn->select_db($database);

// Create users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    role ENUM('student', 'teacher', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create classrooms table
$sql = "CREATE TABLE IF NOT EXISTS classrooms (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    teacher_id INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id)
)";
$conn->query($sql);

// Create classroom_students table (for many-to-many relationship)
$sql = "CREATE TABLE IF NOT EXISTS classroom_students (
    classroom_id INT(11) NOT NULL,
    student_id INT(11) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (classroom_id, student_id),
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($sql);

// Create lessons table
$sql = "CREATE TABLE IF NOT EXISTS lessons (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(255),
    classroom_id INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
)";
$conn->query($sql);

// Create submissions table
$sql = "CREATE TABLE IF NOT EXISTS submissions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT(11) NOT NULL,
    student_id INT(11) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    grade DECIMAL(5,2) DEFAULT NULL,
    feedback TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($sql);
?>