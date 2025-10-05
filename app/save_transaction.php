<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php?redirect=app');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

require_once '../db.php';

$transactionType = $_POST['transaction_type'] ?? '';
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$date = $_POST['date'] ?? date('Y-m-d');
$paymentMethod = $_POST['payment_method'] ?? '';
$comment = trim($_POST['comment'] ?? '');
$categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

$formValues = [
    'transaction_type' => $transactionType,
    'amount' => $_POST['amount'] ?? '',
    'date' => $_POST['date'] ?? date('Y-m-d'),
    'payment_method' => $paymentMethod,
    'comment' => $comment,
    'category_id' => $_POST['category_id'] ?? '',
];
$_SESSION['form_values'] = $formValues;

if (in_array($transactionType, ['income', 'expense'], true)) {
    $_SESSION['active_tab'] = $transactionType;
} else {
    $_SESSION['active_tab'] = 'overview';
}

$validType = in_array($transactionType, ['income', 'expense'], true);
$validMethod = in_array($paymentMethod, ['cash', 'click'], true);
$validDateTime = DateTime::createFromFormat('Y-m-d', $date);
$validDate = $validDateTime && $validDateTime->format('Y-m-d') === $date;

if (!$validType || !$validMethod || !$validDate || $amount <= 0) {
    $_SESSION['flash_message'] = 'Tranzaksiya ma\'lumotlari noto\'g\'ri kiritildi.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: index.php');
    exit();
}

$cash = $paymentMethod === 'cash' ? 1 : 0;
$click = $paymentMethod === 'click' ? 1 : 0;

if ($transactionType === 'income') {
    $cashIn = 1;
    $cashOut = 0;
    $xarajat = 0;
} else {
    $cashIn = 0;
    $cashOut = 1;
    $xarajat = 1;
}

$stmt = $conn->prepare("INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

if (!$stmt) {
    $_SESSION['flash_message'] = 'Ma\'lumotlar bazasi so\'rovi tayyorlanmadi: ' . $conn->error;
    $_SESSION['flash_type'] = 'danger';
    header('Location: index.php');
    exit();
}

$stmt->bind_param('diiiiiss', $amount, $cash, $click, $cashIn, $cashOut, $xarajat, $comment, $date);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    if ($transactionType === 'expense') {
        if ($categoryId > 0) {
            $linkStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
            if ($linkStmt) {
                $linkStmt->bind_param('ii', $newId, $categoryId);
                $linkStmt->execute();
                $linkStmt->close();
            }
        }
    }
    if ($transactionType === 'income') {
        require_once __DIR__ . '/../telegram/helpers.php';
        $botToken = resolve_bot_token();
        if ($botToken) {
            $chatIds = fetch_notification_chat_ids($conn);
            if (!empty($chatIds)) {
                $amountFormatted = format_currency($amount);
                $methodLabels = [
                    'cash' => 'Naqd',
                    'click' => 'Click',
                ];
                $methodLabel = $methodLabels[$paymentMethod] ?? ucfirst($paymentMethod);
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
    $_SESSION['flash_message'] = $transactionType === 'income' ? 'Daromad muvaffaqiyatli saqlandi!' : 'Xarajat muvaffaqiyatli saqlandi!';
    $_SESSION['flash_type'] = 'success';
    unset($_SESSION['form_values']);
} else {
    $_SESSION['flash_message'] = 'Tranzaksiyani saqlashda xatolik: ' . $stmt->error;
    $_SESSION['flash_type'] = 'danger';
}

$stmt->close();
$conn->close();

header('Location: index.php');
exit();
