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
require_once __DIR__ . '/bootstrap.php';

date_default_timezone_set('Asia/Tashkent');

try {
    ensure_teacher_tables($conn);
} catch (Throwable $exception) {
    flash_redirect("Bazani tayyorlashda xatolik: " . $exception->getMessage(), 'danger', $redirectPath);
}

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
$studentsInput = $_POST['students'] ?? [];
$studentNames = isset($studentsInput['name']) && is_array($studentsInput['name']) ? $studentsInput['name'] : [];
$studentAmounts = isset($studentsInput['amount']) && is_array($studentsInput['amount']) ? $studentsInput['amount'] : [];
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

if ($teacherPercentage < 0 || $teacherPercentage > 100) {
    flash_redirect("O'qituvchi ulushi 0 va 100 oralig'ida bo'lishi kerak.", 'danger', $redirectPath);
}

$lineItems = [];
$maxCount = max(count($studentNames), count($studentAmounts));
for ($i = 0; $i < $maxCount; $i++) {
    $name = isset($studentNames[$i]) ? trim((string) $studentNames[$i]) : '';
    $value = isset($studentAmounts[$i]) ? (float) $studentAmounts[$i] : 0;

    if ($name === '' && $value <= 0) {
        continue;
    }

    if ($name === '') {
        flash_redirect("Talaba ismi bo'sh bo'lishi kerak.", 'danger', $redirectPath);
    }

    if ($value <= 0) {
        flash_redirect("Talaba to'lovi 0 dan katta bo'lishi kerak.", 'danger', $redirectPath);
    }

    $lineItems[] = [
        'name' => $name,
        'amount' => round($value, 2),
    ];
}

if (!$lineItems) {
    flash_redirect("Hech bo'lmaganda bitta talaba to'lovi kiritilishi kerak.", 'danger', $redirectPath);
}

$totalAmount = array_reduce($lineItems, static function ($carry, $item) {
    return $carry + ($item['amount'] ?? 0);
}, 0.0);

if ($totalAmount <= 0) {
    flash_redirect("Umumiy to'lov summasi 0 dan katta bo'lishi kerak.", 'danger', $redirectPath);
}

$teacherShare = round($totalAmount * ($teacherPercentage / 100), 2);
$studentSummary = $lineItems[0]['name'];
if (count($lineItems) > 1) {
    $studentSummary .= ' +' . (count($lineItems) - 1) . ' ta talaba';
}

if ($action === 'create') {
    $conn->begin_transaction();

    $stmt = $conn->prepare('INSERT INTO teacher_sessions (teacher_id, session_date, group_name, student_name, amount, teacher_percentage, teacher_share) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        $conn->rollback();
        flash_redirect("Dars yozuvini qo'shishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('isssddd', $teacherId, $sessionDate, $groupName, $studentSummary, $totalAmount, $teacherPercentage, $teacherShare);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->rollback();
        flash_redirect("Ma'lumotni saqlashda xatolik: " . $error, 'danger', $redirectPath);
    }
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();

    $studentStmt = $conn->prepare('INSERT INTO teacher_session_students (session_id, student_name, amount) VALUES (?, ?, ?)');
    if (!$studentStmt) {
        $conn->rollback();
        flash_redirect("Talaba to'lovlarini saqlashda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $sessionIdParam = $sessionId;
    $studentNameParam = '';
    $studentAmountParam = 0.0;
    $studentStmt->bind_param('isd', $sessionIdParam, $studentNameParam, $studentAmountParam);
    foreach ($lineItems as $item) {
        $studentNameParam = $item['name'];
        $studentAmountParam = $item['amount'];
        if (!$studentStmt->execute()) {
            $error = $studentStmt->error;
            $studentStmt->close();
            $conn->rollback();
            flash_redirect("Talaba to'lovlarini saqlashda xatolik: " . $error, 'danger', $redirectPath);
        }
    }
    $studentStmt->close();

    $conn->commit();
    flash_redirect("Dars tushumi qo'shildi.", 'success', $redirectPath);
}

if ($sessionId <= 0) {
    flash_redirect("Dars yozuvi topilmadi.", 'danger', $redirectPath);
}

$conn->begin_transaction();

$stmt = $conn->prepare('UPDATE teacher_sessions SET teacher_id = ?, session_date = ?, group_name = ?, student_name = ?, amount = ?, teacher_percentage = ?, teacher_share = ?, updated_at = NOW() WHERE id = ?');
if (!$stmt) {
    $conn->rollback();
    flash_redirect("Dars yozuvini yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$stmt->bind_param('isssdddi', $teacherId, $sessionDate, $groupName, $studentSummary, $totalAmount, $teacherPercentage, $teacherShare, $sessionId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->rollback();
    flash_redirect("Yangilashda xatolik: " . $error, 'danger', $redirectPath);
}
$stmt->close();

$deleteStudents = $conn->prepare('DELETE FROM teacher_session_students WHERE session_id = ?');
if (!$deleteStudents) {
    $conn->rollback();
    flash_redirect("Talabalarni yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$deleteStudents->bind_param('i', $sessionId);
$deleteStudents->execute();
$deleteError = $deleteStudents->error;
$deleteStudents->close();
if ($deleteError) {
    $conn->rollback();
    flash_redirect("Talabalarni yangilashda xatolik: " . $deleteError, 'danger', $redirectPath);
}

$studentStmt = $conn->prepare('INSERT INTO teacher_session_students (session_id, student_name, amount) VALUES (?, ?, ?)');
if (!$studentStmt) {
    $conn->rollback();
    flash_redirect("Talaba to'lovlarini saqlashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$sessionIdParam = $sessionId;
$studentNameParam = '';
$studentAmountParam = 0.0;
$studentStmt->bind_param('isd', $sessionIdParam, $studentNameParam, $studentAmountParam);
foreach ($lineItems as $item) {
    $studentNameParam = $item['name'];
    $studentAmountParam = $item['amount'];
    if (!$studentStmt->execute()) {
        $error = $studentStmt->error;
        $studentStmt->close();
        $conn->rollback();
        flash_redirect("Talaba to'lovlarini saqlashda xatolik: " . $error, 'danger', $redirectPath);
    }
}
$studentStmt->close();

$conn->commit();
flash_redirect("Dars yozuvi yangilandi.", 'success', $redirectPath);
