<?php
// Read credentials from Render Environment Variables, or default to local XAMPP
$host     = getenv('DB_HOST')     ?: 'localhost';
$user     = getenv('DB_USER')     ?: 'root';
$password = getenv('DB_PASS')     ?: '';
$database = getenv('DB_NAME')     ?: 'complaint_system';
$port     = getenv('DB_PORT')     ?: 3306;

// Create connection
$conn = new mysqli($host, $user, $password, $database, (int)$port);

// Check connection
if ($conn->connect_error) {
    // Log real error (for developer debugging)
    error_log("Database Connection Error: " . $conn->connect_error);

    // Show friendly message to user
    die("⚠️ Sorry, something went wrong. Please try again later.");
}
?>