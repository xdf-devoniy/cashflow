<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

require_once '../db.php';

$action = $_POST['action'] ?? '';
$transactionId = isset($_POST['transaction_id']) ? (int) $_POST['transaction_id'] : 0;
$type = $_POST['transaction_type'] ?? '';

function redirect_with_flash(string $tab, string $message, string $type = 'success'): void
{
    $_SESSION['active_tab'] = $tab;
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: index.php');
    exit();
}

if ($transactionId <= 0) {
    redirect_with_flash('overview', 'Tranzaksiya topilmadi.', 'danger');
}

$validActions = ['update', 'delete'];
if (!in_array($action, $validActions, true) || !in_array($type, ['income', 'expense'], true)) {
    redirect_with_flash('overview', 'Noto\'g\'ri amal bajarildi.', 'danger');
}

$checkStmt = $conn->prepare('SELECT id, cash_in, cash_out FROM transactions WHERE id = ?');
if (!$checkStmt) {
    redirect_with_flash('overview', 'Ma\'lumotlar bazasi xatosi: ' . $conn->error, 'danger');
}
$checkStmt->bind_param('i', $transactionId);
$checkStmt->execute();
$result = $checkStmt->get_result();
$transaction = $result ? $result->fetch_assoc() : null;
$checkStmt->close();

if (!$transaction) {
    redirect_with_flash('overview', 'Tranzaksiya topilmadi.', 'danger');
}

$isIncome = $type === 'income';
if ($isIncome && (int) $transaction['cash_in'] !== 1) {
    redirect_with_flash('overview', 'Tanlangan tranzaksiya daromad emas.', 'danger');
}
if (!$isIncome && (int) $transaction['cash_out'] !== 1) {
    redirect_with_flash('overview', 'Tanlangan tranzaksiya xarajat emas.', 'danger');
}

if ($action === 'delete') {
    $cleanup = $conn->prepare('DELETE FROM transaction_categories WHERE transaction_id = ?');
    if ($cleanup) {
        $cleanup->bind_param('i', $transactionId);
        $cleanup->execute();
        $cleanup->close();
    }
    $deleteStmt = $conn->prepare('DELETE FROM transactions WHERE id = ?');
    if (!$deleteStmt) {
        redirect_with_flash($type, 'Tranzaksiyani o\'chirishda xatolik: ' . $conn->error, 'danger');
    }
    $deleteStmt->bind_param('i', $transactionId);
    if ($deleteStmt->execute()) {
        $deleteStmt->close();
        redirect_with_flash($type, 'Tranzaksiya o\'chirildi.');
    }
    $error = $deleteStmt->error;
    $deleteStmt->close();
    redirect_with_flash($type, 'Tranzaksiyani o\'chirib bo\'lmadi: ' . $error, 'danger');
}

$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$date = $_POST['date'] ?? '';
$method = $_POST['payment_method'] ?? '';
$comment = trim($_POST['comment'] ?? '');
$categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

$dateObj = DateTime::createFromFormat('Y-m-d', $date);
$validDate = $dateObj && $dateObj->format('Y-m-d') === $date;

if ($amount <= 0 || !$validDate || !in_array($method, ['cash', 'click'], true)) {
    redirect_with_flash($type, 'Ma\'lumotlar to\'liq emas yoki noto\'g\'ri.', 'danger');
}

$cash = $method === 'cash' ? 1 : 0;
$click = $method === 'click' ? 1 : 0;
$cashIn = $isIncome ? 1 : 0;
$cashOut = $isIncome ? 0 : 1;
$xarajat = $isIncome ? 0 : 1;

$updateStmt = $conn->prepare('UPDATE transactions SET payment = ?, cash = ?, click = ?, comment = ?, date = ?, cash_in = ?, cash_out = ?, xarajat = ? WHERE id = ?');
if (!$updateStmt) {
    redirect_with_flash($type, 'Yangilashda xatolik: ' . $conn->error, 'danger');
}

$updateStmt->bind_param('diissiiii', $amount, $cash, $click, $comment, $date, $cashIn, $cashOut, $xarajat, $transactionId);

if (!$updateStmt->execute()) {
    $error = $updateStmt->error;
    $updateStmt->close();
    redirect_with_flash($type, 'Tranzaksiyani yangilab bo\'lmadi: ' . $error, 'danger');
}
$updateStmt->close();

if ($isIncome) {
    redirect_with_flash('income', 'Daromad yangilandi.');
}

// expense category mapping
if ($categoryId > 0) {
    $linkStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
    if ($linkStmt) {
        $linkStmt->bind_param('ii', $transactionId, $categoryId);
        $linkStmt->execute();
        $linkStmt->close();
    }
} else {
    $cleanupStmt = $conn->prepare('DELETE FROM transaction_categories WHERE transaction_id = ?');
    if ($cleanupStmt) {
        $cleanupStmt->bind_param('i', $transactionId);
        $cleanupStmt->execute();
        $cleanupStmt->close();
    }
}

redirect_with_flash('expense', 'Xarajat yangilandi.');
