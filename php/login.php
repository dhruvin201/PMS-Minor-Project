<?php
session_start();
require_once 'db_connect.php'; // Adjust path if necessary

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize input
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = urlencode("Please enter both username and password.");
        header("Location: ../login.html?error=$error");
        exit();
    }

    // Prepare and execute query to get user data
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($userId, $userUsername, $hashedPassword);
        $stmt->fetch();

        // Verify password (assuming password hashed with password_hash)
        if (password_verify($password, $hashedPassword)) {
            // Login success: set session variables
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $userUsername;

            // Redirect to dashboard
            header("Location: ./dashboard.php");
            exit();
        } else {
            // Password incorrect
            $error = urlencode("Invalid password.");
            header("Location: ../login.html?error=$error");
            exit();
        }
    } else {
        // Username not found
        $error = urlencode("Invalid username.");
        header("Location: ../login.html?error=$error");
        exit();
    }

    $stmt->close();
} else {
    // If this script is accessed directly without POST data, redirect to login form
    header("Location: ../login.html");
    exit();
}
?>
