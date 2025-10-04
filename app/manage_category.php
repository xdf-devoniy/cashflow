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

require_once '../db.php';

date_default_timezone_set('Asia/Tashkent');

$action = $_POST['action'] ?? '';
$name = trim($_POST['name'] ?? '');
$categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

$_SESSION['active_tab'] = 'categories';

function redirect_with_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: index.php');
    exit();
}

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    redirect_with_flash('Noto\'g\'ri amal tanlandi.', 'danger');
}

if ($action !== 'delete' && $name === '') {
    redirect_with_flash('Turkum nomi bo\'sh bo\'lishi mumkin emas.', 'danger');
}

if ($action === 'create') {
    $stmt = $conn->prepare('INSERT INTO expense_categories (name) VALUES (?)');
    if (!$stmt) {
        redirect_with_flash('Turkumni yaratib bo\'lmadi: ' . $conn->error, 'danger');
    }

    $stmt->bind_param('s', $name);
    if ($stmt->execute()) {
        redirect_with_flash('Turkum muvaffaqiyatli qo\'shildi.');
    }

    $error = $stmt->error;
    $stmt->close();
    redirect_with_flash('Turkumni qo\'shishda xatolik: ' . $error, 'danger');
}

if ($categoryId <= 0) {
    redirect_with_flash('Turkum topilmadi.', 'danger');
}

if ($action === 'update') {
    $stmt = $conn->prepare('UPDATE expense_categories SET name = ? WHERE id = ?');
    if (!$stmt) {
        redirect_with_flash('Turkumni yangilashda xatolik: ' . $conn->error, 'danger');
    }
    $stmt->bind_param('si', $name, $categoryId);
    if ($stmt->execute()) {
        $stmt->close();
        redirect_with_flash('Turkum muvaffaqiyatli yangilandi.');
    }
    $error = $stmt->error;
    $stmt->close();
    redirect_with_flash('Turkumni yangilab bo\'lmadi: ' . $error, 'danger');
}

// delete
$conn->query('DELETE FROM transaction_categories WHERE category_id = ' . $categoryId);

$stmt = $conn->prepare('DELETE FROM expense_categories WHERE id = ?');
if (!$stmt) {
    redirect_with_flash('Turkumni o\'chirishda xatolik: ' . $conn->error, 'danger');
}
$stmt->bind_param('i', $categoryId);
if ($stmt->execute()) {
    $stmt->close();
    redirect_with_flash('Turkum o\'chirildi.');
}
$error = $stmt->error;
$stmt->close();
redirect_with_flash('Turkumni o\'chirib bo\'lmadi: ' . $error, 'danger');
