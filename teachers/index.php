<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

require_once '../db.php';
require_once __DIR__ . '/bootstrap.php';

date_default_timezone_set('Asia/Tashkent');

$flash = $_SESSION['teachers_flash'] ?? null;
unset($_SESSION['teachers_flash']);

$bootstrapError = null;
try {
    ensure_teacher_tables($conn);
} catch (Throwable $exception) {
    $bootstrapError = $exception->getMessage();
}

function format_money(float $value): string
{
    return number_format($value, 0, '.', ' ');
}

function uz_month_label(string $monthKey): string
{
    $date = DateTime::createFromFormat('Y-m', $monthKey);
    if (!$date) {
        return $monthKey;
    }

    $uzMonths = [
        1 => "yanvar",
        2 => "fevral",
        3 => "mart",
        4 => "aprel",
        5 => "may",
        6 => "iyun",
        7 => "iyul",
        8 => "avgust",
        9 => "sentyabr",
        10 => "oktyabr",
        11 => "noyabr",
        12 => "dekabr",
    ];

    $monthName = $uzMonths[(int) $date->format('n')] ?? $date->format('F');

    return $date->format('Y') . " yil " . ucfirst($monthName);
}

$cashTotalsSql = "SELECT
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN cash = 1 AND cash_in = 1 THEN payment END), 0) AS total_income_cash,
        COALESCE(SUM(CASE WHEN click = 1 AND cash_in = 1 THEN payment END), 0) AS total_income_click,
        COALESCE(SUM(CASE WHEN cash = 1 AND cash_out = 1 THEN payment END), 0) AS total_expense_cash,
        COALESCE(SUM(CASE WHEN click = 1 AND cash_out = 1 THEN payment END), 0) AS total_expense_click
    FROM transactions";

$cashTotalsResult = $conn->query($cashTotalsSql);
$cashTotals = $cashTotalsResult instanceof mysqli_result ? $cashTotalsResult->fetch_assoc() : null;
if ($cashTotalsResult instanceof mysqli_result) {
    $cashTotalsResult->free();
}
if (!$cashTotals) {
    $cashTotals = [
        'total_income' => 0,
        'total_expense' => 0,
        'total_income_cash' => 0,
        'total_income_click' => 0,
        'total_expense_cash' => 0,
        'total_expense_click' => 0,
    ];
}

$teacherMetrics = [];
$teacherMap = [];

$teacherSql = "SELECT t.id, t.name, t.percentage, t.phone, t.note,
        COALESCE(s.total_amount, 0) AS total_amount_all,
        COALESCE(s.total_share, 0) AS total_share_all,
        COALESCE(p.total_payout, 0) AS total_payout_all
    FROM teacher_profiles t
    LEFT JOIN (
        SELECT teacher_id, SUM(amount) AS total_amount, SUM(teacher_share) AS total_share
        FROM teacher_sessions
        GROUP BY teacher_id
    ) s ON s.teacher_id = t.id
    LEFT JOIN (
        SELECT teacher_id, SUM(amount) AS total_payout
        FROM teacher_payouts
        GROUP BY teacher_id
    ) p ON p.teacher_id = t.id
    ORDER BY t.name";

$teacherResult = $conn->query($teacherSql);
if ($teacherResult instanceof mysqli_result) {
    while ($row = $teacherResult->fetch_assoc()) {
        $row['total_amount_all'] = (float) ($row['total_amount_all'] ?? 0);
        $row['total_share_all'] = (float) ($row['total_share_all'] ?? 0);
        $row['total_payout_all'] = (float) ($row['total_payout_all'] ?? 0);
        $row['balance_all'] = $row['total_share_all'] - $row['total_payout_all'];
        $teacherMetrics[] = $row;
        $teacherMap[(int) $row['id']] = $row;
    }
    $teacherResult->free();
}

$selectedTeacherId = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : 0;
if ($selectedTeacherId <= 0 && $teacherMetrics) {
    $selectedTeacherId = (int) $teacherMetrics[0]['id'];
}
if ($selectedTeacherId > 0 && !isset($teacherMap[$selectedTeacherId])) {
    $selectedTeacherId = $teacherMetrics ? (int) $teacherMetrics[0]['id'] : 0;
}

$selectedTeacher = $selectedTeacherId > 0 ? ($teacherMap[$selectedTeacherId] ?? null) : null;

$monthKeys = [];
if ($selectedTeacher && !$bootstrapError) {
    $sessionMonthStmt = $conn->prepare("SELECT DISTINCT DATE_FORMAT(session_date, '%Y-%m') AS ym FROM teacher_sessions WHERE teacher_id = ? ORDER BY ym DESC");
    if ($sessionMonthStmt) {
        $sessionMonthStmt->bind_param('i', $selectedTeacherId);
        if ($sessionMonthStmt->execute()) {
            $result = $sessionMonthStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['ym'])) {
                    $monthKeys[] = $row['ym'];
                }
            }
        }
        $sessionMonthStmt->close();
    }

    $payoutMonthStmt = $conn->prepare("SELECT DISTINCT DATE_FORMAT(paid_at, '%Y-%m') AS ym FROM teacher_payouts WHERE teacher_id = ? ORDER BY ym DESC");
    if ($payoutMonthStmt) {
        $payoutMonthStmt->bind_param('i', $selectedTeacherId);
        if ($payoutMonthStmt->execute()) {
            $result = $payoutMonthStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['ym'])) {
                    $monthKeys[] = $row['ym'];
                }
            }
        }
        $payoutMonthStmt->close();
    }
}

$currentMonthKey = date('Y-m');
if (!in_array($currentMonthKey, $monthKeys, true)) {
    $monthKeys[] = $currentMonthKey;
}

$monthKeys = array_values(array_unique($monthKeys));
rsort($monthKeys);

$selectedMonthKey = isset($_GET['month']) ? (string) $_GET['month'] : '';
if ($selectedMonthKey && !preg_match('/^\\d{4}-\\d{2}$/', $selectedMonthKey)) {
    $selectedMonthKey = '';
}
if ($selectedMonthKey && !in_array($selectedMonthKey, $monthKeys, true)) {
    $selectedMonthKey = '';
}
if ($selectedMonthKey === '' && $monthKeys) {
    $selectedMonthKey = $monthKeys[0];
}
if ($selectedMonthKey === '') {
    $selectedMonthKey = $currentMonthKey;
}

$selectedMonth = DateTime::createFromFormat('Y-m', $selectedMonthKey) ?: new DateTime(date('Y-m-01'));
$monthStart = $selectedMonth->format('Y-m-01');
$monthEnd = $selectedMonth->format('Y-m-t');
$selectedMonthLabel = uz_month_label($selectedMonthKey);

$today = new DateTime();
$defaultDate = clone $selectedMonth;
if ($today < new DateTime($monthStart)) {
    $defaultDateValue = $monthStart;
} elseif ($today > new DateTime($monthEnd)) {
    $defaultDateValue = $monthEnd;
} else {
    $defaultDateValue = $today->format('Y-m-d');
}

$redirectQuery = '';
if ($selectedTeacherId > 0) {
    $redirectQuery = http_build_query([
        'teacher_id' => $selectedTeacherId,
        'month' => $selectedMonthKey,
    ]);
}

$availableMonths = array_map(static function (string $key) {
    return [
        'value' => $key,
        'label' => uz_month_label($key),
    ];
}, $monthKeys);

$monthlySummary = [
    'total_amount' => 0.0,
    'teacher_share' => 0.0,
    'profit' => 0.0,
    'payout' => 0.0,
    'outstanding' => 0.0,
];

$sessionRows = [];
$sessionStudentsMap = [];
$payoutRows = [];

$chartMonths = [];
$chartStart = (clone $selectedMonth)->modify('-11 months');
$chartCursor = clone $chartStart;
for ($i = 0; $i < 12; $i++) {
    $key = $chartCursor->format('Y-m');
    $chartMonths[$key] = [
        'label' => uz_month_label($key),
        'amount' => 0.0,
        'share' => 0.0,
        'profit' => 0.0,
        'payout' => 0.0,
    ];
    $chartCursor->modify('+1 month');
}

if ($selectedTeacher && !$bootstrapError) {
    $summaryStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_amount, COALESCE(SUM(teacher_share), 0) AS total_share FROM teacher_sessions WHERE teacher_id = ? AND session_date BETWEEN ? AND ?");
    if ($summaryStmt) {
        $summaryStmt->bind_param('iss', $selectedTeacherId, $monthStart, $monthEnd);
        if ($summaryStmt->execute()) {
            $summaryRow = $summaryStmt->get_result()->fetch_assoc();
            $monthlySummary['total_amount'] = (float) ($summaryRow['total_amount'] ?? 0);
            $monthlySummary['teacher_share'] = (float) ($summaryRow['total_share'] ?? 0);
            $monthlySummary['profit'] = $monthlySummary['total_amount'] - $monthlySummary['teacher_share'];
        }
        $summaryStmt->close();
    }

    $payoutSummaryStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_payout FROM teacher_payouts WHERE teacher_id = ? AND paid_at BETWEEN ? AND ?");
    if ($payoutSummaryStmt) {
        $payoutSummaryStmt->bind_param('iss', $selectedTeacherId, $monthStart, $monthEnd);
        if ($payoutSummaryStmt->execute()) {
            $payoutRow = $payoutSummaryStmt->get_result()->fetch_assoc();
            $monthlySummary['payout'] = (float) ($payoutRow['total_payout'] ?? 0);
        }
        $payoutSummaryStmt->close();
    }

    $monthlySummary['outstanding'] = $monthlySummary['teacher_share'] - $monthlySummary['payout'];

    $sessionSql = "SELECT s.id, s.teacher_id, s.session_date, s.group_name, s.student_name, s.amount, s.teacher_percentage, s.teacher_share,
            t.name AS teacher_name
        FROM teacher_sessions s
        JOIN teacher_profiles t ON t.id = s.teacher_id
        WHERE s.teacher_id = ? AND s.session_date BETWEEN ? AND ?
        ORDER BY s.session_date DESC, s.id DESC";
    $sessionStmt = $conn->prepare($sessionSql);
    if ($sessionStmt) {
        $sessionStmt->bind_param('iss', $selectedTeacherId, $monthStart, $monthEnd);
        if ($sessionStmt->execute()) {
            $sessionRows = $sessionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $sessionStmt->close();
    }

    if ($sessionRows) {
        $sessionIds = array_map(static fn($row) => (int) $row['id'], $sessionRows);
        if ($sessionIds) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $studentSql = "SELECT session_id, student_name, amount FROM teacher_session_students WHERE session_id IN ($placeholders) ORDER BY id";
            $studentStmt = $conn->prepare($studentSql);
            if ($studentStmt) {
                $types = str_repeat('i', count($sessionIds));
                $bindParams = [$types];
                foreach ($sessionIds as $sessionId) {
                    $bindParams[] = $sessionId;
                }
                $ref = [];
                foreach ($bindParams as $key => $value) {
                    $ref[$key] = &$bindParams[$key];
                }
                call_user_func_array([$studentStmt, 'bind_param'], $ref);
                if ($studentStmt->execute()) {
                    $studentResult = $studentStmt->get_result();
                    while ($studentRow = $studentResult->fetch_assoc()) {
                        $sid = (int) $studentRow['session_id'];
                        if (!isset($sessionStudentsMap[$sid])) {
                            $sessionStudentsMap[$sid] = [];
                        }
                        $sessionStudentsMap[$sid][] = [
                            'student_name' => $studentRow['student_name'] ?? '',
                            'amount' => (float) ($studentRow['amount'] ?? 0),
                        ];
                    }
                }
                $studentStmt->close();
            }
        }
    }

    $payoutSql = "SELECT id, teacher_id, paid_at, amount, payment_method, note FROM teacher_payouts WHERE teacher_id = ? AND paid_at BETWEEN ? AND ? ORDER BY paid_at DESC, id DESC";
    $payoutStmt = $conn->prepare($payoutSql);
    if ($payoutStmt) {
        $payoutStmt->bind_param('iss', $selectedTeacherId, $monthStart, $monthEnd);
        if ($payoutStmt->execute()) {
            $payoutRows = $payoutStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $payoutStmt->close();
    }

    $chartSessionStmt = $conn->prepare("SELECT DATE_FORMAT(session_date, '%Y-%m') AS ym, SUM(amount) AS total_amount, SUM(teacher_share) AS total_share
        FROM teacher_sessions
        WHERE teacher_id = ? AND session_date BETWEEN ? AND ?
        GROUP BY ym");
    $chartSessionStart = $chartStart->format('Y-m-01');
    $chartSessionEnd = $selectedMonth->format('Y-m-t');
    if ($chartSessionStmt) {
        $chartSessionStmt->bind_param('iss', $selectedTeacherId, $chartSessionStart, $chartSessionEnd);
        if ($chartSessionStmt->execute()) {
            $chartSessionResult = $chartSessionStmt->get_result();
            while ($row = $chartSessionResult->fetch_assoc()) {
                $key = $row['ym'];
                if (isset($chartMonths[$key])) {
                    $chartMonths[$key]['amount'] = (float) ($row['total_amount'] ?? 0);
                    $chartMonths[$key]['share'] = (float) ($row['total_share'] ?? 0);
                    $chartMonths[$key]['profit'] = $chartMonths[$key]['amount'] - $chartMonths[$key]['share'];
                }
            }
        }
        $chartSessionStmt->close();
    }

    $chartPayoutStmt = $conn->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total_payout FROM teacher_payouts WHERE teacher_id = ? AND paid_at BETWEEN ? AND ? GROUP BY ym");
    if ($chartPayoutStmt) {
        $chartPayoutStmt->bind_param('iss', $selectedTeacherId, $chartSessionStart, $chartSessionEnd);
        if ($chartPayoutStmt->execute()) {
            $chartPayoutResult = $chartPayoutStmt->get_result();
            while ($row = $chartPayoutResult->fetch_assoc()) {
                $key = $row['ym'];
                if (isset($chartMonths[$key])) {
                    $chartMonths[$key]['payout'] = (float) ($row['total_payout'] ?? 0);
                }
            }
        }
        $chartPayoutStmt->close();
    }
}

$chartSeries = [
    'labels' => array_map(static fn($data) => $data['label'], $chartMonths),
    'amount' => array_map(static fn($data) => round($data['amount'], 2), $chartMonths),
    'share' => array_map(static fn($data) => round($data['share'], 2), $chartMonths),
    'profit' => array_map(static fn($data) => round($data['profit'], 2), $chartMonths),
    'payout' => array_map(static fn($data) => round($data['payout'], 2), $chartMonths),
];

?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>O'qituvchilar oyligi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            DEFAULT: '#2563eb',
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                        },
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    boxShadow: {
                        soft: '0 20px 50px -20px rgba(30, 64, 175, 0.25)',
                    },
                },
            },
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold text-slate-900">O'qituvchilar boshqaruvi</h1>
            <div class="flex gap-3 text-sm text-slate-500">
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-2 shadow-sm">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Naqd balans</p>
                    <p class="text-base font-semibold text-emerald-600"><?= format_money($cashTotals['total_income_cash'] - $cashTotals['total_expense_cash']) ?> so'm</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-2 shadow-sm">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Click balans</p>
                    <p class="text-base font-semibold text-indigo-600"><?= format_money($cashTotals['total_income_click'] - $cashTotals['total_expense_click']) ?> so'm</p>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mt-4 rounded-xl border <?= $flash['type'] === 'danger' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> px-4 py-3">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($bootstrapError): ?>
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-700">
                <?= htmlspecialchars($bootstrapError) ?>
            </div>
        <?php endif; ?>

        <div class="mt-6 grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900">O'qituvchilar</h2>
                    <button data-open="teacherDialog" class="inline-flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white shadow hover:bg-primary/90">＋ Qo'shish</button>
                </div>
                <div class="space-y-3">
                    <?php foreach ($teacherMetrics as $teacher):
                        $isActive = (int) $teacher['id'] === $selectedTeacherId;
                        $balance = (float) $teacher['balance_all'];
                    ?>
                        <a href="index.php?teacher_id=<?= (int) $teacher['id'] ?>" class="block rounded-2xl border <?= $isActive ? 'border-primary-500 bg-white shadow-soft' : 'border-slate-200 bg-white/70 shadow-sm hover:border-primary-200' ?> p-4 transition">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-slate-900"><?= htmlspecialchars($teacher['name']) ?></p>
                                    <p class="text-xs text-slate-500">Standart ulush: <?= htmlspecialchars($teacher['percentage']) ?>%</p>
                                </div>
                                <span class="rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-600"><?= $balance >= 0 ? 'Qarzdorlik' : 'To‘langan' ?></span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
                                <div class="rounded-lg bg-slate-50 px-3 py-2">
                                    <p class="font-medium text-slate-600">Jami ulush</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-900"><?= format_money($teacher['total_share_all']) ?> so'm</p>
                                </div>
                                <div class="rounded-lg bg-slate-50 px-3 py-2">
                                    <p class="font-medium text-slate-600">To'langan</p>
                                    <p class="mt-1 text-sm font-semibold text-primary-600"><?= format_money($teacher['total_payout_all']) ?> so'm</p>
                                </div>
                                <div class="rounded-lg bg-slate-50 px-3 py-2 col-span-2">
                                    <p class="font-medium text-slate-600">Qoldiq</p>
                                    <p class="mt-1 text-sm font-semibold <?= $balance >= 0 ? 'text-amber-600' : 'text-emerald-600' ?>"><?= format_money($balance) ?> so'm</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" data-edit-teacher='<?= json_encode([
                                    'id' => (int) $teacher['id'],
                                    'name' => $teacher['name'],
                                    'percentage' => $teacher['percentage'],
                                    'phone' => $teacher['phone'],
                                    'note' => $teacher['note'],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>' class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50">Tahrirlash</button>
                                <button type="button" data-open="sessionDialog" data-session-teacher="<?= (int) $teacher['id'] ?>" data-session-percentage="<?= htmlspecialchars($teacher['percentage']) ?>" class="inline-flex items-center rounded-lg border border-primary/30 px-3 py-1 text-xs font-semibold text-primary hover:bg-primary/10">Dars qo'shish</button>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$teacherMetrics): ?>
                        <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">Hali o'qituvchilar qo'shilmagan. "Qo'shish" tugmasi orqali yangi o'qituvchi yarating.</p>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="space-y-6">
                <?php if (!$selectedTeacher): ?>
                    <div class="rounded-3xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
                        <h2 class="text-xl font-semibold text-slate-900">O'qituvchi tanlanmagan</h2>
                        <p class="mt-2 text-sm text-slate-500">Boshlash uchun chap tomondagi ro'yxatdan o'qituvchini tanlang yoki yangisini qo'shing.</p>
                    </div>
                <?php else: ?>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-sm uppercase tracking-wider text-slate-400">Tanlangan o'qituvchi</p>
                                <h2 class="text-2xl font-semibold text-slate-900"><?= htmlspecialchars($selectedTeacher['name']) ?></h2>
                                <p class="text-sm text-slate-500">Standart ulush: <?= htmlspecialchars($selectedTeacher['percentage']) ?>%</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" data-open="sessionDialog" data-session-teacher="<?= $selectedTeacherId ?>" data-session-percentage="<?= htmlspecialchars($selectedTeacher['percentage']) ?>" class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">＋ Dars yozuvi</button>
                                <button type="button" data-open="payoutDialog" data-payout-teacher="<?= $selectedTeacherId ?>" class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-5 py-2 text-sm font-semibold text-emerald-600 hover:bg-emerald-100">⚡ Maosh to'lovi</button>
                                <a href="export.php?teacher_id=<?= $selectedTeacherId ?>&start_date=<?= $monthStart ?>&end_date=<?= $monthEnd ?>" class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">⬇️ Excel</a>
                                <button type="button" data-print class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">🖨 Chop etish</button>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <?php foreach ($availableMonths as $month):
                                $isActive = $month['value'] === $selectedMonthKey;
                                $link = 'index.php?teacher_id=' . $selectedTeacherId . '&month=' . urlencode($month['value']);
                            ?>
                                <a href="<?= $link ?>" class="inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold transition <?= $isActive ? 'bg-primary text-white shadow-soft' : 'bg-slate-100 text-slate-600 hover:bg-primary/10 hover:text-primary-600' ?>">
                                    <?= htmlspecialchars($month['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs uppercase tracking-wide text-slate-400">Oy tushumi</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900"><?= format_money($monthlySummary['total_amount']) ?> so'm</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs uppercase tracking-wide text-slate-400">O'qituvchi ulushi</p>
                            <p class="mt-2 text-3xl font-semibold text-primary-600"><?= format_money($monthlySummary['teacher_share']) ?> so'm</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs uppercase tracking-wide text-slate-400">Markaz foydasi</p>
                            <p class="mt-2 text-3xl font-semibold text-emerald-600"><?= format_money($monthlySummary['profit']) ?> so'm</p>
                        </div>
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs uppercase tracking-wide text-slate-400">To'langan oyda</p>
                            <p class="mt-2 text-3xl font-semibold text-amber-600"><?= format_money($monthlySummary['payout']) ?> so'm</p>
                            <p class="mt-1 text-xs text-slate-500">Qoldiq: <?= format_money($monthlySummary['outstanding']) ?> so'm</p>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Oylik tahlil (so'nggi 12 oy)</h3>
                                <p class="text-sm text-slate-500">Tushum, ulush, foyda va maosh to'lovlari</p>
                            </div>
                        </div>
                        <div class="mt-4 h-72">
                            <canvas id="teacherChart" class="h-full w-full"></canvas>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" id="print-area">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($selectedMonthLabel) ?> — dars tushumlari</h3>
                            <p class="text-sm text-slate-500">Talaba to'lovlari va ulushlar jadvali</p>
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50">
                                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-4 py-3">Sana</th>
                                        <th class="px-4 py-3">Guruh / davr</th>
                                        <th class="px-4 py-3">Talabalar</th>
                                        <th class="px-4 py-3">Jami tushum</th>
                                        <th class="px-4 py-3">O'qituvchi ulushi</th>
                                        <th class="px-4 py-3">Markaz foydasi</th>
                                        <th class="px-4 py-3">Amallar</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($sessionRows as $session):
                                        $sessionId = (int) $session['id'];
                                        $students = $sessionStudentsMap[$sessionId] ?? [];
                                        if (!$students && !empty($session['student_name'])) {
                                            $students = [[
                                                'student_name' => $session['student_name'],
                                                'amount' => (float) $session['amount'],
                                            ]];
                                        }
                                        $studentsPayload = array_map(static fn($row) => [
                                            'name' => $row['student_name'],
                                            'amount' => $row['amount'],
                                        ], $students);
                                        $sessionPayload = [
                                            'id' => $sessionId,
                                            'teacher_id' => (int) $session['teacher_id'],
                                            'session_date' => $session['session_date'],
                                            'group_name' => $session['group_name'],
                                            'teacher_percentage' => (float) $session['teacher_percentage'],
                                            'student_name' => $session['student_name'],
                                            'amount' => (float) $session['amount'],
                                            'students' => $studentsPayload,
                                        ];
                                    ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($session['session_date']) ?></td>
                                            <td class="px-4 py-3 text-slate-700">
                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($session['group_name']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600">
                                                <ul class="space-y-1 text-xs">
                                                    <?php foreach ($students as $student): ?>
                                                        <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-100 px-3 py-1.5">
                                                            <span><?= htmlspecialchars($student['student_name']) ?></span>
                                                            <span class="font-semibold text-slate-900"><?= format_money((float) $student['amount']) ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </td>
                                            <td class="px-4 py-3 font-semibold text-slate-900"><?= format_money((float) $session['amount']) ?></td>
                                            <td class="px-4 py-3 font-semibold text-primary-600"><?= format_money((float) $session['teacher_share']) ?></td>
                                            <td class="px-4 py-3 font-semibold text-emerald-600"><?= format_money((float) $session['amount'] - (float) $session['teacher_share']) ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" data-edit-session='<?= json_encode($sessionPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50">Tahrirlash</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$sessionRows): ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">Tanlangan oy uchun dars tushumlari kiritilmagan.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($selectedMonthLabel) ?> — maosh to'lovlari</h3>
                            <p class="text-sm text-slate-500">Naqd va Click bo'yicha amalga oshirilgan to'lovlar</p>
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50">
                                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-4 py-3">Sana</th>
                                        <th class="px-4 py-3">Usul</th>
                                        <th class="px-4 py-3">Summasi</th>
                                        <th class="px-4 py-3">Izoh</th>
                                        <th class="px-4 py-3">Amallar</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($payoutRows as $payout):
                                        $payoutPayload = [
                                            'id' => (int) $payout['id'],
                                            'teacher_id' => (int) $payout['teacher_id'],
                                            'paid_at' => $payout['paid_at'],
                                            'amount' => (float) $payout['amount'],
                                            'payment_method' => $payout['payment_method'],
                                            'note' => $payout['note'],
                                        ];
                                    ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($payout['paid_at']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= $payout['payment_method'] === 'click' ? 'Click' : 'Naqd' ?></td>
                                            <td class="px-4 py-3 font-semibold text-primary-600"><?= format_money((float) $payout['amount']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($payout['note'] ?? '') ?></td>
                                            <td class="px-4 py-3">
                                                <button type="button" data-edit-payout='<?= json_encode($payoutPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50">Tahrirlash</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$payoutRows): ?>
                                        <tr>
                                            <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Bu oyda maosh to'lovlari hali kiritilmagan.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <dialog id="teacherDialog" class="backdrop:bg-slate-900/40">
        <form action="manage_teacher.php" method="post" class="w-full max-w-lg rounded-2xl bg-white p-6">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="teacher_id" value="">
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-slate-900">O'qituvchi ma'lumoti</h3>
                <button type="button" class="text-slate-400 transition hover:text-slate-600" data-close>✕</button>
            </div>
            <div class="mt-4 grid gap-4">
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Ism familiya
                    <input type="text" name="name" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Standart ulush (%)
                    <input type="number" name="percentage" step="0.01" min="0" max="100" value="40" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Telefon
                    <input type="text" name="phone" class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Izoh
                    <textarea name="note" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"></textarea>
                </label>
            </div>
            <div class="mt-6 flex items-center justify-between">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
                    <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
                </div>
            </div>
        </form>
    </dialog>

    <dialog id="sessionDialog" class="backdrop:bg-slate-900/40">
        <form action="manage_session.php" method="post" class="w-full max-w-2xl rounded-2xl bg-white p-6">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="session_id" value="">
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-slate-900">Dars tushumini kiritish</h3>
                <button type="button" class="text-slate-400 transition hover:text-slate-600" data-close>✕</button>
            </div>
            <div class="mt-4 grid gap-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">O'qituvchi
                        <select name="teacher_id" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                            <option value="">Tanlang</option>
                            <?php foreach ($teacherMetrics as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>" data-percentage="<?= htmlspecialchars($teacher['percentage']) ?>" <?= (int) $teacher['id'] === $selectedTeacherId ? 'selected' : '' ?>><?= htmlspecialchars($teacher['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Sana
                        <input type="date" name="session_date" value="<?= htmlspecialchars($defaultDateValue) ?>" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </label>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Guruh / davr nomi
                        <input type="text" name="group_name" placeholder="Masalan: 2024-yil may guruhi" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">O'qituvchi ulushi (%)
                        <input type="number" name="teacher_percentage" step="0.01" min="0" max="100" value="<?= htmlspecialchars($selectedTeacher['percentage'] ?? '') ?>" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </label>
                </div>
                <div class="md:col-span-2">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-slate-600">Talaba to'lovlari</p>
                        <button type="button" data-add-student class="inline-flex items-center gap-2 rounded-lg border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary/10">＋ Talaba qo'shish</button>
                    </div>
                    <div class="mt-3 space-y-3" data-student-rows></div>
                    <p class="mt-3 text-xs text-slate-500" data-student-summary>Jami to'lov: 0 so'm · O'qituvchi ulushi: 0 so'm</p>
                    <template id="studentRowTemplate">
                        <div class="grid items-end gap-2 md:grid-cols-[minmax(0,1fr)_minmax(0,160px)_auto]" data-student-row>
                            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">Talaba ismi
                                <input type="text" name="students[name][]" data-student-name class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30" placeholder="Masalan: Dilshod" required>
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">To'lov (so'm)
                                <input type="number" name="students[amount][]" data-student-amount step="0.01" min="0" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30" placeholder="0" required>
                            </label>
                            <button type="button" data-remove-row class="h-10 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-500 transition hover:bg-red-50 hover:text-red-500">✕</button>
                        </div>
                    </template>
                </div>
            </div>
            <div class="mt-6 flex items-center justify-between">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
                    <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
                </div>
            </div>
        </form>
    </dialog>

    <dialog id="payoutDialog" class="backdrop:bg-slate-900/40">
        <form action="manage_payout.php" method="post" class="w-full max-w-xl rounded-2xl bg-white p-6">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="payout_id" value="">
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-slate-900">Maosh to'lovini kiritish</h3>
                <button type="button" class="text-slate-400 transition hover:text-slate-600" data-close>✕</button>
            </div>
            <div class="mt-4 grid gap-4">
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">O'qituvchi
                    <select name="teacher_id" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                        <option value="">Tanlang</option>
                        <?php foreach ($teacherMetrics as $teacher): ?>
                            <option value="<?= (int) $teacher['id'] ?>" <?= (int) $teacher['id'] === $selectedTeacherId ? 'selected' : '' ?>><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">To'lov sanasi
                        <input type="date" name="paid_at" value="<?= htmlspecialchars($defaultDateValue) ?>" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">To'lov summasi (so'm)
                        <input type="number" name="amount" step="0.01" min="0" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </label>
                </div>
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-slate-600">To'lov usuli</span>
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                        <input type="radio" name="payment_method" value="cash" class="h-4 w-4 text-primary focus:ring-primary" checked> Naqd
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                        <input type="radio" name="payment_method" value="click" class="h-4 w-4 text-primary focus:ring-primary"> Click
                    </label>
                </div>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Izoh
                    <textarea name="note" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30" placeholder="Masalan: may oyi maoshi"></textarea>
                </label>
            </div>
            <div class="mt-6 flex items-center justify-between">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
                    <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
                </div>
            </div>
        </form>
    </dialog>

    <script>
    const formatNumber = value => new Intl.NumberFormat('uz-UZ').format(value);
    const defaultDateValue = '<?= htmlspecialchars($defaultDateValue) ?>';

    const teacherDialog = document.getElementById('teacherDialog');
    const sessionDialog = document.getElementById('sessionDialog');
    const payoutDialog = document.getElementById('payoutDialog');

    let sessionTeacherSelect = null;
    let sessionPercentageInput = null;
    let sessionActionInput = null;
    let sessionRowsContainer = null;
    let sessionSummary = null;
    let sessionTemplate = null;
    let sessionAddButton = null;

    if (sessionDialog) {
        sessionTeacherSelect = sessionDialog.querySelector('select[name="teacher_id"]');
        sessionPercentageInput = sessionDialog.querySelector('input[name="teacher_percentage"]');
        sessionActionInput = sessionDialog.querySelector('input[name="action"]');
        sessionRowsContainer = sessionDialog.querySelector('[data-student-rows]');
        sessionSummary = sessionDialog.querySelector('[data-student-summary]');
        sessionTemplate = sessionDialog.querySelector('#studentRowTemplate');
        sessionAddButton = sessionDialog.querySelector('[data-add-student]');
    }

    function recalcSessionTotals() {
        if (!sessionRowsContainer || !sessionSummary) return;
        let total = 0;
        sessionRowsContainer.querySelectorAll('[data-student-row]').forEach(row => {
            const amountInput = row.querySelector('[data-student-amount]');
            const value = parseFloat(amountInput?.value || '0');
            if (!Number.isNaN(value)) {
                total += value;
            }
        });
        const percentage = parseFloat(sessionPercentageInput?.value || '0');
        const share = total * (percentage / 100);
        sessionSummary.textContent = `Jami to'lov: ${formatNumber(Math.round(total))} so'm · O'qituvchi ulushi: ${formatNumber(Math.round(share))} so'm`;
    }

    function addStudentRow(student = { name: '', amount: '' }) {
        if (!sessionRowsContainer || !sessionTemplate) return;
        const rowElement = sessionTemplate.content.firstElementChild.cloneNode(true);
        const nameInput = rowElement.querySelector('[data-student-name]');
        const amountInput = rowElement.querySelector('[data-student-amount]');
        const removeButton = rowElement.querySelector('[data-remove-row]');
        if (nameInput) {
            nameInput.value = student.name ?? '';
        }
        if (amountInput) {
            amountInput.value = student.amount !== undefined && student.amount !== null && student.amount !== '' ? student.amount : '';
            amountInput.addEventListener('input', recalcSessionTotals);
        }
        removeButton?.addEventListener('click', () => {
            if (!sessionRowsContainer) return;
            const rows = sessionRowsContainer.querySelectorAll('[data-student-row]');
            if (rows.length <= 1) {
                if (nameInput) nameInput.value = '';
                if (amountInput) amountInput.value = '';
            } else {
                rowElement.remove();
            }
            recalcSessionTotals();
        });
        sessionRowsContainer.appendChild(rowElement);
    }

    function resetSessionStudents(students = []) {
        if (!sessionRowsContainer) return;
        sessionRowsContainer.innerHTML = '';
        if (!students.length) {
            addStudentRow();
        } else {
            students.forEach(student => addStudentRow(student));
        }
        recalcSessionTotals();
    }

    function resetDialog(dialog, trigger = null) {
        const form = dialog?.querySelector('form');
        if (!form) return;
        form.reset();
        const redirectInput = form.querySelector('input[name="redirect_query"]');
        if (redirectInput) {
            redirectInput.value = '<?= htmlspecialchars($redirectQuery) ?>';
        }
        form.querySelectorAll('input[name="action"]').forEach(input => {
            input.value = 'create';
        });
        form.querySelectorAll('[data-delete]').forEach(button => {
            button.classList.add('hidden');
        });
        if (form.contains(sessionDialog)) {
            resetSessionStudents();
        }
        const sessionDateInput = form.querySelector('input[name="session_date"]');
        if (sessionDateInput && !sessionDateInput.value) {
            sessionDateInput.value = defaultDateValue;
        }
        const payoutDateInput = form.querySelector('input[name="paid_at"]');
        if (payoutDateInput && !payoutDateInput.value) {
            payoutDateInput.value = defaultDateValue;
        }
        if (trigger?.dataset.sessionTeacher && sessionTeacherSelect) {
            sessionTeacherSelect.value = trigger.dataset.sessionTeacher;
            const defaultPercentage = trigger.dataset.sessionPercentage;
            if (defaultPercentage && sessionPercentageInput) {
                sessionPercentageInput.value = defaultPercentage;
            }
        }
        if (trigger?.dataset.payoutTeacher) {
            const payoutSelect = payoutDialog?.querySelector('select[name="teacher_id"]');
            if (payoutSelect) {
                payoutSelect.value = trigger.dataset.payoutTeacher;
            }
        }
        if (sessionRowsContainer) {
            resetSessionStudents();
        }
    }

    document.querySelectorAll('[data-open]').forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-open');
            const dialog = document.getElementById(targetId);
            if (!dialog) return;
            resetDialog(dialog, button);
            dialog.showModal();
        });
    });

    document.querySelectorAll('[data-close]').forEach(button => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            dialog?.close();
        });
    });

    if (sessionTeacherSelect) {
        sessionTeacherSelect.addEventListener('change', () => {
            const option = sessionTeacherSelect.selectedOptions[0];
            if (!option) return;
            const defaultPercentage = option.dataset.percentage;
            if (!defaultPercentage) return;
            if ((sessionActionInput?.value ?? 'create') !== 'update' || sessionPercentageInput.value === '') {
                sessionPercentageInput.value = defaultPercentage;
            }
            recalcSessionTotals();
        });
    }

    sessionPercentageInput?.addEventListener('input', recalcSessionTotals);
    sessionAddButton?.addEventListener('click', () => {
        addStudentRow();
        recalcSessionTotals();
    });

    document.querySelectorAll('[data-edit-teacher]').forEach(button => {
        button.addEventListener('click', () => {
            if (!teacherDialog) return;
            resetDialog(teacherDialog);
            const data = JSON.parse(button.getAttribute('data-edit-teacher'));
            teacherDialog.querySelector('input[name="action"]').value = 'update';
            teacherDialog.querySelector('input[name="teacher_id"]').value = data.id;
            teacherDialog.querySelector('input[name="name"]').value = data.name || '';
            teacherDialog.querySelector('input[name="percentage"]').value = data.percentage || '';
            teacherDialog.querySelector('input[name="phone"]').value = data.phone || '';
            teacherDialog.querySelector('textarea[name="note"]').value = data.note || '';
            teacherDialog.querySelector('[data-delete]')?.classList.remove('hidden');
            teacherDialog.showModal();
        });
    });

    document.querySelectorAll('[data-edit-session]').forEach(button => {
        button.addEventListener('click', () => {
            if (!sessionDialog) return;
            resetDialog(sessionDialog);
            const data = JSON.parse(button.getAttribute('data-edit-session'));
            sessionDialog.querySelector('input[name="action"]').value = 'update';
            sessionDialog.querySelector('input[name="session_id"]').value = data.id;
            if (sessionTeacherSelect) {
                sessionTeacherSelect.value = data.teacher_id;
                const option = sessionTeacherSelect.selectedOptions[0];
                if (option && sessionPercentageInput && (sessionPercentageInput.value === '' || sessionPercentageInput.value === option.dataset.percentage)) {
                    sessionPercentageInput.value = option.dataset.percentage || data.teacher_percentage || '';
                }
            }
            sessionDialog.querySelector('input[name="session_date"]').value = data.session_date || defaultDateValue;
            sessionDialog.querySelector('input[name="group_name"]').value = data.group_name || '';
            if (sessionPercentageInput) {
                sessionPercentageInput.value = data.teacher_percentage ?? sessionPercentageInput.value;
            }
            const studentPayload = Array.isArray(data.students) && data.students.length
                ? data.students
                : (data.student_name || data.amount
                    ? [{ name: data.student_name || '', amount: data.amount }]
                    : []);
            resetSessionStudents(studentPayload);
            sessionActionInput.value = 'update';
            sessionDialog.querySelector('[data-delete]')?.classList.remove('hidden');
            sessionDialog.showModal();
        });
    });

    document.querySelectorAll('[data-edit-payout]').forEach(button => {
        button.addEventListener('click', () => {
            if (!payoutDialog) return;
            resetDialog(payoutDialog);
            const data = JSON.parse(button.getAttribute('data-edit-payout'));
            payoutDialog.querySelector('input[name="action"]').value = 'update';
            payoutDialog.querySelector('input[name="payout_id"]').value = data.id;
            payoutDialog.querySelector('select[name="teacher_id"]').value = data.teacher_id;
            payoutDialog.querySelector('input[name="paid_at"]').value = data.paid_at || defaultDateValue;
            payoutDialog.querySelector('input[name="amount"]').value = data.amount;
            payoutDialog.querySelectorAll('input[name="payment_method"]').forEach(radio => {
                radio.checked = radio.value === data.payment_method;
            });
            payoutDialog.querySelector('textarea[name="note"]').value = data.note || '';
            payoutDialog.querySelector('[data-delete]')?.classList.remove('hidden');
            payoutDialog.showModal();
        });
    });

    document.querySelectorAll('[data-print]').forEach(button => {
        button.addEventListener('click', () => {
            window.print();
        });
    });

    const ctx = document.getElementById('teacherChart');
    const chartData = <?= json_encode($chartSeries) ?>;
    if (ctx && chartData.labels.length) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: "Tushum",
                        data: chartData.amount,
                        backgroundColor: 'rgba(59, 130, 246, 0.35)',
                        borderRadius: 8,
                    },
                    {
                        type: 'bar',
                        label: "O'qituvchi ulushi",
                        data: chartData.share,
                        backgroundColor: 'rgba(79, 70, 229, 0.35)',
                        borderRadius: 8,
                    },
                    {
                        type: 'line',
                        label: "Markaz foydasi",
                        data: chartData.profit,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.2)',
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'y',
                    },
                    {
                        type: 'line',
                        label: "To'langan maosh",
                        data: chartData.payout,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.2)',
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'y',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => formatNumber(value),
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.15)',
                        },
                    },
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: context => `${context.dataset.label}: ${formatNumber(context.parsed.y || context.parsed)} so'm`,
                        },
                    },
                },
            },
        });
    }
    </script>
</body>
</html>
