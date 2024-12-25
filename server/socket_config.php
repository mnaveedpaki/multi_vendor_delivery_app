<?php
// Database configuration for socket server
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'delivery_app_db';

// Create connection
$socket_conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

// Check connection
if (!$socket_conn) {
    die("Socket Server Connection failed: " . mysqli_connect_error());
}

// Set charset
mysqli_set_charset($socket_conn, "utf8mb4");

// WebSocket Configuration
define('WS_PORT', 8080);
define('WS_HOST', '127.0.0.1');