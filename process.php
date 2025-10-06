<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: login.php');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db.php';

$rawAmount = trim($_POST['payment'] ?? '');
$normalizedAmount = preg_replace('/[^\d.,-]/', '', $rawAmount);
$normalizedAmount = str_replace([',', ' '], '', $normalizedAmount ?? '');
$amount = $normalizedAmount !== '' ? (float) $normalizedAmount : 0.0;

$cash = isset($_POST['cash']) ? 1 : 0;
$click = isset($_POST['click']) ? 1 : 0;
$cashIn = isset($_POST['cash_in']) ? 1 : 0;
$cashOut = isset($_POST['cash_out']) ? 1 : 0;
$xarajat = isset($_POST['xarajat']) ? 1 : 0;
$comment = trim($_POST['comment'] ?? '');

$dateInput = trim($_POST['date'] ?? '');
$date = date('Y-m-d');

if ($dateInput !== '') {
    $dateTime = DateTime::createFromFormat('Y-m-d', $dateInput);
    if ($dateTime instanceof DateTime && $dateTime->format('Y-m-d') === $dateInput) {
        $date = $dateInput;
    }
}

$stmt = $conn->prepare('INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

if (!$stmt) {
    $conn->close();
    echo 'Error: ' . $conn->error;
    exit();
}

$stmt->bind_param('diiiiiss', $amount, $cash, $click, $cashIn, $cashOut, $xarajat, $comment, $date);

if ($stmt->execute()) {
    if ($cashIn === 1) {
        require_once __DIR__ . '/telegram/helpers.php';

        $botToken = resolve_bot_token();
        if ($botToken) {
            $chatIds = fetch_notification_chat_ids($conn);
            if (!empty($chatIds)) {
                $amountFormatted = format_currency($amount);

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

                if ($comment !== '') {
                    $lines[] = 'Izoh: ' . $comment;
                }

                $message = implode("\n", $lines);

                foreach ($chatIds as $chatId) {
                    send_message($botToken, $chatId, $message);
                }
            }
        }
    }

    $stmt->close();
    $conn->close();

    header('Location: index.php');
    exit();
}

$error = $stmt->error;
$stmt->close();
$conn->close();

echo 'Error: ' . $error;
exit();
?>
