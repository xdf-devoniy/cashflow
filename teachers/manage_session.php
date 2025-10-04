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

function flash_redirect(string $message, string $type, string $redirectPath): void
{
    $_SESSION['teachers_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
    header('Location: ' . $redirectPath);
    exit();
}

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    flash_redirect("Noto'g'ri amal tanlandi.", 'danger', $redirectPath);
}

$sessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;

if ($action === 'delete') {
    if ($sessionId <= 0) {
        flash_redirect("Dars yozuvi topilmadi.", 'danger', $redirectPath);
    }
    $stmt = $conn->prepare('DELETE FROM teacher_sessions WHERE id = ?');
    if (!$stmt) {
        flash_redirect("Dars yozuvini o'chirishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('i', $sessionId);
    if ($stmt->execute()) {
        $stmt->close();
        flash_redirect("Dars yozuvi o'chirildi.", 'success', $redirectPath);
    }
    $error = $stmt->error;
    $stmt->close();
    flash_redirect("Darsni o'chirishda xatolik: " . $error, 'danger', $redirectPath);
}

$teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;
if ($teacherId <= 0) {
    flash_redirect("O'qituvchi tanlanmadi.", 'danger', $redirectPath);
}

$teacherStmt = $conn->prepare('SELECT percentage FROM teacher_profiles WHERE id = ?');
if (!$teacherStmt) {
    flash_redirect("O'qituvchini topishda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$teacherStmt->bind_param('i', $teacherId);
$teacherStmt->execute();
$teacherResult = $teacherStmt->get_result()->fetch_assoc();
$teacherStmt->close();

if (!$teacherResult) {
    flash_redirect("O'qituvchi topilmadi.", 'danger', $redirectPath);
}

$sessionDate = $_POST['session_date'] ?? '';
$groupName = trim($_POST['group_name'] ?? '');
$studentName = trim($_POST['student_name'] ?? '');
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$teacherPercentage = isset($_POST['teacher_percentage']) && $_POST['teacher_percentage'] !== ''
    ? (float) $_POST['teacher_percentage']
    : (float) $teacherResult['percentage'];

$dateValid = DateTime::createFromFormat('Y-m-d', $sessionDate);
if (!$dateValid || $dateValid->format('Y-m-d') !== $sessionDate) {
    flash_redirect("Sana noto'g'ri ko'rsatildi.", 'danger', $redirectPath);
}

if ($groupName === '') {
    flash_redirect("Guruh nomi bo'sh bo'lmasligi kerak.", 'danger', $redirectPath);
}

if ($amount <= 0) {
    flash_redirect("To'lov summasi 0 dan katta bo'lishi kerak.", 'danger', $redirectPath);
}

if ($teacherPercentage < 0 || $teacherPercentage > 100) {
    flash_redirect("O'qituvchi ulushi 0 va 100 oralig'ida bo'lishi kerak.", 'danger', $redirectPath);
}

$teacherShare = round($amount * ($teacherPercentage / 100), 2);

if ($action === 'create') {
    $stmt = $conn->prepare('INSERT INTO teacher_sessions (teacher_id, session_date, group_name, student_name, amount, teacher_percentage, teacher_share) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        flash_redirect("Dars yozuvini qo'shishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('isssddd', $teacherId, $sessionDate, $groupName, $studentName, $amount, $teacherPercentage, $teacherShare);
    if ($stmt->execute()) {
        $stmt->close();
        flash_redirect("Dars tushumi qo'shildi.", 'success', $redirectPath);
    }
    $error = $stmt->error;
    $stmt->close();
    flash_redirect("Ma'lumotni saqlashda xatolik: " . $error, 'danger', $redirectPath);
}

if ($sessionId <= 0) {
    flash_redirect("Dars yozuvi topilmadi.", 'danger', $redirectPath);
}

$stmt = $conn->prepare('UPDATE teacher_sessions SET teacher_id = ?, session_date = ?, group_name = ?, student_name = ?, amount = ?, teacher_percentage = ?, teacher_share = ?, updated_at = NOW() WHERE id = ?');
if (!$stmt) {
    flash_redirect("Dars yozuvini yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$stmt->bind_param('isssdddi', $teacherId, $sessionDate, $groupName, $studentName, $amount, $teacherPercentage, $teacherShare, $sessionId);
if ($stmt->execute()) {
    $stmt->close();
    flash_redirect("Dars yozuvi yangilandi.", 'success', $redirectPath);
}
$error = $stmt->error;
$stmt->close();
flash_redirect("Yangilashda xatolik: " . $error, 'danger', $redirectPath);
