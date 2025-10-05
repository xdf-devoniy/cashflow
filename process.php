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
    if ($cash_in === 1) {
        require_once __DIR__ . '/telegram/helpers.php';

        $botToken = resolve_bot_token();
        if ($botToken) {
            $chatIds = fetch_notification_chat_ids($conn);
            if (!empty($chatIds)) {
                $amountValue = is_numeric($payment) ? (float) $payment : null;
                $amountFormatted = $amountValue !== null ? format_currency($amountValue) : trim((string) $payment);

                if ($amountFormatted === '') {
                    $amountFormatted = '0';
                }

                $methodLabel = "Noma'lum";
                if ($cash === 1) {
                    $methodLabel = 'Naqd';
                } elseif ($click === 1) {
                    $methodLabel = 'Click';
                }

                $lines = [
                    'Sizda yangi daromad summasi: ' . $amountFormatted . " so'm",
                    "To'lov usuli: " . $methodLabel,
                    'Sana: ' . $date,
                ];

                if (!empty($comment)) {
                    $lines[] = 'Izoh: ' . $comment;
                }

                $message = implode("\n", $lines);

                foreach ($chatIds as $chatId) {
                    send_message($botToken, $chatId, $message);
                }
            }
        }
    }

    header("Location: index.php");
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();
?>
