<?php
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: login.php');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

include 'db.php';

$payment = $_POST['payment'];
$cash = isset($_POST['cash']) ? 1 : 0;
$click = isset($_POST['click']) ? 1 : 0;
$cash_in = isset($_POST['cash_in']) ? 1 : 0;
$cash_out = isset($_POST['cash_out']) ? 1 : 0;
$xarajat = isset($_POST['xarajat']) ? 1 : 0;
$comment = $_POST['comment'];
$date = date('Y-m-d');

$sql = "INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES ('$payment', '$cash', '$click', '$cash_in', '$cash_out', '$xarajat', '$comment', '$date')";

if ($conn->query($sql) === TRUE) {
    header("Location: index.php");
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();
?>
