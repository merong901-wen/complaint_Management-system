<?php
// Read credentials from Render Environment Variables, or default to local 127.0.0.1 (forces TCP/IP)
$host     = getenv('DB_HOST')     ?: '127.0.0.1';
$user     = getenv('DB_USER')     ?: 'root';
$password = getenv('DB_PASSWORD');
if ($password === false) {
    $password = getenv('DB_PASS') ?: '';
}
$database = getenv('DB_NAME')     ?: 'complaint_system';
$port     = getenv('DB_PORT')     ?: 3306;

try {
    $conn = new mysqli($host, $user, $password, $database, (int)$port);
} catch (mysqli_sql_exception $exception) {
    error_log('Database Connection Error: ' . $exception->getMessage());
    http_response_code(503);
    exit('The application database is unavailable. Check the database connection settings.');
}

if ($conn->connect_error) {
    error_log('Database Connection Error: ' . $conn->connect_error);
    http_response_code(503);
    exit('The application database is unavailable. Check the database connection settings.');
}

$conn->set_charset('utf8mb4');
?>