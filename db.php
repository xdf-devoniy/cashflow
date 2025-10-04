<?php
$servername = "localhost";
$username = "u180105715_db";
$password = "Rasul9898"; // Your database password
$dbname = "u180105715_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
