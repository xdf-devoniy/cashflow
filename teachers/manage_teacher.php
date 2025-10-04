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
$redirectQuery = trim($_POST['redirect_query'] ?? '');
$redirectPath = 'index.php' . ($redirectQuery ? '?' . $redirectQuery : '');

function flash_and_redirect(string $message, string $type = 'success', string $redirectPath = 'index.php'): void
{
    $_SESSION['teachers_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
    header('Location: ' . $redirectPath);
    exit();
}

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    flash_and_redirect("Noto'g'ri amal tanlandi.", 'danger', $redirectPath);
}

$teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;

if ($action === 'delete') {
    if ($teacherId <= 0) {
        flash_and_redirect("O'qituvchi topilmadi.", 'danger', $redirectPath);
    }
    $stmt = $conn->prepare('DELETE FROM teacher_profiles WHERE id = ?');
    if (!$stmt) {
        flash_and_redirect("Ma'lumotlar bazasi xatosi: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $stmt->close();
        flash_and_redirect("O'qituvchi muvaffaqiyatli o'chirildi.", 'success', $redirectPath);
    }
    $error = $stmt->error;
    $stmt->close();
    flash_and_redirect("O'qituvchini o'chirishda xatolik: " . $error, 'danger', $redirectPath);
}

$name = trim($_POST['name'] ?? '');
$percentage = isset($_POST['percentage']) ? (float) $_POST['percentage'] : 0;
$phone = trim($_POST['phone'] ?? '');
$note = trim($_POST['note'] ?? '');

if ($name === '') {
    flash_and_redirect("Ism familiya maydoni bo'sh bo'lmasligi kerak.", 'danger', $redirectPath);
}

if ($percentage < 0 || $percentage > 100) {
    flash_and_redirect("Foiz 0 va 100 oralig'ida bo'lishi kerak.", 'danger', $redirectPath);
}

if ($action === 'create') {
    $stmt = $conn->prepare('INSERT INTO teacher_profiles (name, percentage, phone, note) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        flash_and_redirect("O'qituvchini yaratishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('sdss', $name, $percentage, $phone, $note);
    if ($stmt->execute()) {
        $stmt->close();
        flash_and_redirect("O'qituvchi muvaffaqiyatli qo'shildi.", 'success', $redirectPath);
    }
    $error = $stmt->error;
    $stmt->close();
    flash_and_redirect("O'qituvchini saqlashda xatolik: " . $error, 'danger', $redirectPath);
}

if ($teacherId <= 0) {
    flash_and_redirect("O'qituvchi topilmadi.", 'danger', $redirectPath);
}

$stmt = $conn->prepare('UPDATE teacher_profiles SET name = ?, percentage = ?, phone = ?, note = ?, updated_at = NOW() WHERE id = ?');
if (!$stmt) {
    flash_and_redirect("O'qituvchini yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$stmt->bind_param('sdssi', $name, $percentage, $phone, $note, $teacherId);
if ($stmt->execute()) {
    $stmt->close();
    flash_and_redirect("O'qituvchi ma'lumotlari yangilandi.", 'success', $redirectPath);
}
$error = $stmt->error;
$stmt->close();
flash_and_redirect("O'zgarishlarni saqlab bo'lmadi: " . $error, 'danger', $redirectPath);
