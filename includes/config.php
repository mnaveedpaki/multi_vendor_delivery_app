<?php
session_start();

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'delivery_app_db';

// Create connection
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to handle special characters
mysqli_set_charset($conn, "utf8mb4");

// Define constants
define('SITE_URL', 'http://localhost/deliveryapp/');
define('SITE_NAME', 'MMTracker');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Authentication check function
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check user type
function isSuperAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Super Admin';
}

function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin';
}

function isRider() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Rider';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'index.php');
        exit();
    }
}

// Add this function to your existing config.php file

function validUser() {
    global $conn;
    
    // Check if user is logged in
    if (!isLoggedIn()) {
        return false;
    }
    
    // Prepare the SQL query to check user
    $query = "SELECT id, is_active FROM Users WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    
    // Close the statement
    mysqli_stmt_close($stmt);
    
    // Check if user exists in database
    if (!$user) {
        // User not found in database
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    // Check if user is active
    if ($user['is_active'] != 1) {
        // User is not active
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    // User is valid
    return true;
}

// Clean input data
function cleanInput($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}
?>