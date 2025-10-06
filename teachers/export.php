<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

require_once '../db.php';
require_once __DIR__ . '/bootstrap.php';

date_default_timezone_set('Asia/Tashkent');

try {
    ensure_teacher_tables($conn);
} catch (Throwable $exception) {
    $_SESSION['teachers_flash'] = [
        'message' => "Bazani tayyorlashda xatolik: " . $exception->getMessage(),
        'type' => 'danger',
    ];
    header('Location: index.php');
    exit();
}

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');
$rawStart = $_GET['start_date'] ?? $defaultStart;
$rawEnd = $_GET['end_date'] ?? $defaultEnd;

$startDate = DateTime::createFromFormat('Y-m-d', $rawStart);
$endDate = DateTime::createFromFormat('Y-m-d', $rawEnd);

if (!$startDate) {
    $startDate = new DateTime($defaultStart);
}
if (!$endDate) {
    $endDate = new DateTime($defaultEnd);
}
if ($startDate > $endDate) {
    $startDate = new DateTime($defaultStart);
    $endDate = new DateTime($defaultEnd);
}

$startStr = $startDate->format('Y-m-d');
$endStr = $endDate->format('Y-m-d');

$teacherFilter = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : 0;
$teacherName = '';

if ($teacherFilter > 0) {
    $teacherStmt = $conn->prepare('SELECT name FROM teacher_profiles WHERE id = ?');
    if ($teacherStmt) {
        $teacherStmt->bind_param('i', $teacherFilter);
        if ($teacherStmt->execute()) {
            $teacherRow = $teacherStmt->get_result()->fetch_assoc();
            if ($teacherRow) {
                $teacherName = $teacherRow['name'];
            }
        }
        $teacherStmt->close();
    }
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="oqituvchilar_oyligi_' . date('Ymd_His') . '.csv"');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility

$output = fopen('php://output', 'w');

$metaRow = ["Hisobot sanalari", $startStr, $endStr];
if ($teacherName !== '') {
    $metaRow[] = "O'qituvchi: " . $teacherName;
}
fputcsv($output, $metaRow);

fputcsv($output, []);

$sessionSql = "SELECT s.id, s.session_date, s.group_name, s.amount, s.teacher_percentage, s.teacher_share, t.name AS teacher_name,"
    . " ss.student_name, ss.amount AS student_amount"
    . " FROM teacher_sessions s"
    . " JOIN teacher_profiles t ON t.id = s.teacher_id"
    . " LEFT JOIN teacher_session_students ss ON ss.session_id = s.id"
    . " WHERE s.session_date BETWEEN ? AND ?";
if ($teacherFilter > 0) {
    $sessionSql .= " AND s.teacher_id = ?";
}
$sessionSql .= " ORDER BY s.session_date, s.id, ss.id";

$sessionStmt = $conn->prepare($sessionSql);
if ($sessionStmt) {
    if ($teacherFilter > 0) {
        $sessionStmt->bind_param('ssi', $startStr, $endStr, $teacherFilter);
    } else {
        $sessionStmt->bind_param('ss', $startStr, $endStr);
    }
    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();
} else {
    $sessionResult = false;
}

fputcsv($output, ["Sana", "O'qituvchi", "Guruh", "Talaba", "Talaba to'lovi (so'm)", "O'qituvchi ulushi (so'm)", "Markaz foydasi (so'm)"]);

$totalSessionAmount = 0.0;
$totalSessionShare = 0.0;
$totalSessionProfit = 0.0;
$sessionCounted = [];

$formatAmount = static function (float $value): string {
    return number_format($value, 2, '.', '');
};

if ($sessionResult) {
    while ($row = $sessionResult->fetch_assoc()) {
        $sessionId = (int) $row['id'];
        $teacher = $row['teacher_name'];
        $group = $row['group_name'];
        $sessionAmount = (float) ($row['amount'] ?? 0);
        $sessionShare = (float) ($row['teacher_share'] ?? 0);
        $percentage = (float) ($row['teacher_percentage'] ?? 0);
        $studentName = $row['student_name'];
        $studentAmount = $row['student_amount'] !== null ? (float) $row['student_amount'] : null;

        if (!isset($sessionCounted[$sessionId])) {
            $totalSessionAmount += $sessionAmount;
            $totalSessionShare += $sessionShare;
            $totalSessionProfit += $sessionAmount - $sessionShare;
            $sessionCounted[$sessionId] = true;
        }

        if ($studentAmount === null) {
            $studentAmount = $sessionAmount;
            $studentShare = $sessionShare;
        } else {
            $studentShare = round($studentAmount * ($percentage / 100), 2);
        }
        $profit = $studentAmount - $studentShare;

        fputcsv($output, [
            $row['session_date'],
            $teacher,
            $group,
            $studentName ?: '-',
            $formatAmount($studentAmount),
            $formatAmount($studentShare),
            $formatAmount($profit),
        ]);
    }
    $sessionStmt->close();
}

fputcsv($output, []);

fputcsv($output, [
    'Jami tushum',
    '',
    '',
    '',
    $formatAmount($totalSessionAmount),
    $formatAmount($totalSessionShare),
    $formatAmount($totalSessionProfit),
]);

fputcsv($output, []);

$payoutSql = "SELECT p.paid_at, t.name AS teacher_name, p.amount, p.payment_method, p.note"
    . " FROM teacher_payouts p"
    . " JOIN teacher_profiles t ON t.id = p.teacher_id"
    . " WHERE p.paid_at BETWEEN ? AND ?";
if ($teacherFilter > 0) {
    $payoutSql .= " AND p.teacher_id = ?";
}
$payoutSql .= " ORDER BY p.paid_at, p.id";

$payoutStmt = $conn->prepare($payoutSql);
if ($payoutStmt) {
    if ($teacherFilter > 0) {
        $payoutStmt->bind_param('ssi', $startStr, $endStr, $teacherFilter);
    } else {
        $payoutStmt->bind_param('ss', $startStr, $endStr);
    }
    $payoutStmt->execute();
    $payoutResult = $payoutStmt->get_result();
} else {
    $payoutResult = false;
}

fputcsv($output, ["Sana", "O'qituvchi", "To'lov usuli", "Summasi (so'm)", "Izoh"]);

$totalPayout = 0.0;
if ($payoutResult) {
    while ($row = $payoutResult->fetch_assoc()) {
        $amount = (float) ($row['amount'] ?? 0);
        $totalPayout += $amount;
        fputcsv($output, [
            $row['paid_at'],
            $row['teacher_name'],
            $row['payment_method'] === 'click' ? 'Click' : 'Naqd',
            $formatAmount($amount),
            $row['note'],
        ]);
    }
    $payoutStmt->close();
}

fputcsv($output, []);

fputcsv($output, [
    "Jami maosh to'lovi",
    '',
    '',
    $formatAmount($totalPayout),
    '',
]);

$netProfit = $totalSessionProfit - $totalPayout;
fputcsv($output, [
    "Sof foyda (foyda - maosh)",
    '',
    '',
    $formatAmount($netProfit),
    '',
]);

fclose($output);
exit();
