<?php
$host = "localhost";         // or IP address if remote
$port = 3306;                // your MySQL port, often 3306
$dbUsername = "root";   // your MySQL username
$dbPassword = "password";   // your MySQL password
$dbName = "portfolio_db_2";    // your database name

// Create connection with port specified
$conn = new mysqli($host, $dbUsername, $dbPassword, $dbName, $port);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
