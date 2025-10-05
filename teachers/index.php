<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

require_once '../db.php';
require_once __DIR__ . '/bootstrap.php';

date_default_timezone_set('Asia/Tashkent');

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

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');
$rawStart = $_GET['start_date'] ?? $defaultStart;
$rawEnd = $_GET['end_date'] ?? $defaultEnd;

$rangeStart = DateTime::createFromFormat('Y-m-d', $rawStart);
$rangeEnd = DateTime::createFromFormat('Y-m-d', $rawEnd);

$filters = [
    'start_date' => $defaultStart,
    'end_date' => $defaultEnd,
];

$filterWarnings = [];

if ($rangeStart && $rangeStart->format('Y-m-d') === $rawStart) {
    $filters['start_date'] = $rangeStart->format('Y-m-d');
} else {
    $filterWarnings[] = "Boshlanish sanasi noto'g'ri. Standart oy boshiga sozlandi.";
}

if ($rangeEnd && $rangeEnd->format('Y-m-d') === $rawEnd) {
    $filters['end_date'] = $rangeEnd->format('Y-m-d');
} else {
    $filterWarnings[] = "Tugash sanasi noto'g'ri. Bugungi kun tanlandi.";
}

if (strtotime($filters['start_date']) > strtotime($filters['end_date'])) {
    $filters['start_date'] = $defaultStart;
    $filters['end_date'] = $defaultEnd;
    $filterWarnings[] = "Filtrlash uchun sana oralig'i noto'g'ri. Joriy oyga qaytarildi.";
}

$_SESSION['teachers_filters'] = $filters;

$rangeStartStr = $filters['start_date'];
$rangeEndStr = $filters['end_date'];

$teacherFilter = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : 0;
$teacherFilter = max($teacherFilter, 0);

$rangeSummary = [
    'total_amount' => 0,
    'total_share' => 0,
    'total_profit' => 0,
    'total_payout' => 0,
];

$teacherMetrics = [];
$sessionRows = [];
$sessionStudentsMap = [];
$payoutRows = [];
$monthlySessions = [];
$monthlyPayouts = [];

$monthStart = (new DateTime('first day of -5 month'))->format('Y-m-01');
$monthEnd = date('Y-m-t');

if ($bootstrapError === null) {
    $rangeStmt = $conn->prepare("SELECT IFNULL(SUM(amount), 0) AS total_amount, IFNULL(SUM(teacher_share), 0) AS total_share FROM teacher_sessions WHERE session_date BETWEEN ? AND ?");
    if ($rangeStmt) {
        $rangeStmt->bind_param('ss', $rangeStartStr, $rangeEndStr);
        if ($rangeStmt->execute()) {
            $rangeResult = $rangeStmt->get_result()->fetch_assoc();
            $rangeSummary['total_amount'] = (float) ($rangeResult['total_amount'] ?? 0);
            $rangeSummary['total_share'] = (float) ($rangeResult['total_share'] ?? 0);
            $rangeSummary['total_profit'] = $rangeSummary['total_amount'] - $rangeSummary['total_share'];
        }
        $rangeStmt->close();
    }

    $payoutSummaryStmt = $conn->prepare("SELECT IFNULL(SUM(amount), 0) AS total_payout FROM teacher_payouts WHERE paid_at BETWEEN ? AND ?");
    if ($payoutSummaryStmt) {
        $payoutSummaryStmt->bind_param('ss', $rangeStartStr, $rangeEndStr);
        if ($payoutSummaryStmt->execute()) {
            $payoutSummary = $payoutSummaryStmt->get_result()->fetch_assoc();
            $rangeSummary['total_payout'] = (float) ($payoutSummary['total_payout'] ?? 0);
        }
        $payoutSummaryStmt->close();
    }

    $teacherQuery = $conn->prepare(
        "SELECT t.id, t.name, t.percentage, t.phone, t.note,
                IFNULL(r.total_amount, 0) AS range_amount,
                IFNULL(r.total_share, 0) AS range_share,
                IFNULL(r.total_profit, 0) AS range_profit,
                IFNULL(p_range.total_payout, 0) AS range_payout,
                IFNULL(all_sessions.total_amount_all, 0) AS total_amount_all,
                IFNULL(all_sessions.total_share_all, 0) AS total_share_all,
                IFNULL(all_payouts.total_payout_all, 0) AS total_payout_all
         FROM teacher_profiles t
         LEFT JOIN (
            SELECT teacher_id, SUM(amount) AS total_amount, SUM(teacher_share) AS total_share, SUM(amount - teacher_share) AS total_profit
            FROM teacher_sessions
            WHERE session_date BETWEEN ? AND ?
            GROUP BY teacher_id
         ) AS r ON r.teacher_id = t.id
         LEFT JOIN (
            SELECT teacher_id, SUM(amount) AS total_payout
            FROM teacher_payouts
            WHERE paid_at BETWEEN ? AND ?
            GROUP BY teacher_id
         ) AS p_range ON p_range.teacher_id = t.id
         LEFT JOIN (
            SELECT teacher_id, SUM(amount) AS total_amount_all, SUM(teacher_share) AS total_share_all
            FROM teacher_sessions
            GROUP BY teacher_id
         ) AS all_sessions ON all_sessions.teacher_id = t.id
         LEFT JOIN (
            SELECT teacher_id, SUM(amount) AS total_payout_all
            FROM teacher_payouts
            GROUP BY teacher_id
         ) AS all_payouts ON all_payouts.teacher_id = t.id
         ORDER BY t.name"
    );

    if ($teacherQuery) {
        $teacherQuery->bind_param('ssss', $rangeStartStr, $rangeEndStr, $rangeStartStr, $rangeEndStr);
        if ($teacherQuery->execute()) {
            $teacherMetrics = $teacherQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $teacherQuery->close();
    }

    $sessionSql = "SELECT s.id, s.teacher_id, s.session_date, s.group_name, s.student_name, s.amount, s.teacher_percentage, s.teacher_share, t.name AS teacher_name
               FROM teacher_sessions s
               JOIN teacher_profiles t ON t.id = s.teacher_id
               WHERE s.session_date BETWEEN ? AND ?";
    $params = [$rangeStartStr, $rangeEndStr];
    $types = 'ss';

    if ($teacherFilter > 0) {
        $sessionSql .= " AND s.teacher_id = ?";
        $params[] = $teacherFilter;
        $types .= 'i';
    }

    $sessionSql .= " ORDER BY s.session_date DESC, s.id DESC";

    $sessionStmt = $conn->prepare($sessionSql);
    if ($sessionStmt) {
        $sessionStmt->bind_param($types, ...$params);
        if ($sessionStmt->execute()) {
            $sessionRows = $sessionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $sessionStmt->close();
    }

    if ($sessionRows) {
        $sessionIds = array_map('intval', array_column($sessionRows, 'id'));
        if ($sessionIds) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $studentSql = "SELECT session_id, student_name, amount FROM teacher_session_students WHERE session_id IN ($placeholders) ORDER BY id";
            $studentStmt = $conn->prepare($studentSql);
            if ($studentStmt) {
                $types = str_repeat('i', count($sessionIds));
                $bindParams = [$types];
                foreach ($sessionIds as $index => $sessionId) {
                    $bindParams[] = $sessionIds[$index];
                }
                $refParams = [];
                foreach ($bindParams as $key => $value) {
                    $refParams[$key] = &$bindParams[$key];
                }
                call_user_func_array([$studentStmt, 'bind_param'], $refParams);
                if ($studentStmt->execute()) {
                    $studentResult = $studentStmt->get_result();
                    while ($studentRow = $studentResult->fetch_assoc()) {
                        $sessionId = (int) $studentRow['session_id'];
                        if (!isset($sessionStudentsMap[$sessionId])) {
                            $sessionStudentsMap[$sessionId] = [];
                        }
                        $sessionStudentsMap[$sessionId][] = [
                            'student_name' => $studentRow['student_name'],
                            'amount' => (float) ($studentRow['amount'] ?? 0),
                        ];
                    }
                }
                $studentStmt->close();
            }
        }
    }

    $payoutSql = "SELECT p.id, p.teacher_id, p.paid_at, p.amount, p.payment_method, p.note, p.transaction_id, t.name AS teacher_name
               FROM teacher_payouts p
               JOIN teacher_profiles t ON t.id = p.teacher_id
               WHERE p.paid_at BETWEEN ? AND ?";
    $params = [$rangeStartStr, $rangeEndStr];
    $types = 'ss';
    if ($teacherFilter > 0) {
        $payoutSql .= " AND p.teacher_id = ?";
        $params[] = $teacherFilter;
        $types .= 'i';
    }
    $payoutSql .= " ORDER BY p.paid_at DESC, p.id DESC";

    $payoutStmt = $conn->prepare($payoutSql);
    if ($payoutStmt) {
        $payoutStmt->bind_param($types, ...$params);
        if ($payoutStmt->execute()) {
            $payoutRows = $payoutStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $payoutStmt->close();
    }

    $monthlyStmt = $conn->prepare("SELECT DATE_FORMAT(session_date, '%Y-%m') AS ym, SUM(amount) AS total_amount, SUM(teacher_share) AS total_share
        FROM teacher_sessions
        WHERE session_date BETWEEN ? AND ?
        GROUP BY ym
        ORDER BY ym");
    if ($monthlyStmt) {
        $monthlyStmt->bind_param('ss', $monthStart, $monthEnd);
        if ($monthlyStmt->execute()) {
            $monthlyResult = $monthlyStmt->get_result();
            while ($row = $monthlyResult->fetch_assoc()) {
                $monthlySessions[$row['ym']] = [
                    'amount' => (float) ($row['total_amount'] ?? 0),
                    'share' => (float) ($row['total_share'] ?? 0),
                ];
            }
        }
        $monthlyStmt->close();
    }

    $monthlyPayoutStmt = $conn->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total_amount
        FROM teacher_payouts
        WHERE paid_at BETWEEN ? AND ?
        GROUP BY ym
        ORDER BY ym");
    if ($monthlyPayoutStmt) {
        $monthlyPayoutStmt->bind_param('ss', $monthStart, $monthEnd);
        if ($monthlyPayoutStmt->execute()) {
            $payoutResult = $monthlyPayoutStmt->get_result();
            while ($row = $payoutResult->fetch_assoc()) {
                $monthlyPayouts[$row['ym']] = (float) ($row['total_amount'] ?? 0);
            }
        }
        $monthlyPayoutStmt->close();
    }
}

// Monthly analytics for charts (last 6 months including current)
$months = [];

for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i month"));
    $months[] = [
        'label' => date('M Y', strtotime("-$i month")),
        'key' => $monthKey,
        'amount' => $monthlySessions[$monthKey]['amount'] ?? 0,
        'share' => $monthlySessions[$monthKey]['share'] ?? 0,
        'profit' => ($monthlySessions[$monthKey]['amount'] ?? 0) - ($monthlySessions[$monthKey]['share'] ?? 0),
        'payout' => $monthlyPayouts[$monthKey] ?? 0,
    ];
}

$teacherSessionsById = [];

foreach ($sessionRows as $session) {
    $teacherId = (int) ($session['teacher_id'] ?? 0);
    $percentage = (float) ($session['teacher_percentage'] ?? 0);
    $totalAmount = (float) ($session['amount'] ?? 0);
    $teacherShare = (float) ($session['teacher_share'] ?? 0);

    if (!isset($teacherSessionsById[$teacherId])) {
        $teacherSessionsById[$teacherId] = [
            'info' => [
                'id' => $teacherId,
                'name' => $session['teacher_name'] ?? '',
                'percentage' => $percentage,
            ],
            'sessions' => [],
            'totals' => [
                'amount' => 0,
                'share' => 0,
                'profit' => 0,
            ],
        ];
    }

    $rawStudents = $sessionStudentsMap[(int) $session['id']] ?? [];
    if (!$rawStudents && !empty($session['student_name'])) {
        $rawStudents = [[
            'student_name' => $session['student_name'],
            'amount' => $totalAmount,
        ]];
    }

    $displayStudents = [];
    $formStudents = [];
    foreach ($rawStudents as $studentRow) {
        $studentName = $studentRow['student_name'] ?? ($studentRow['name'] ?? '');
        $studentAmount = (float) ($studentRow['amount'] ?? 0);
        if ($studentName === '' && $studentAmount <= 0) {
            continue;
        }
        $studentShare = round($studentAmount * ($percentage / 100), 2);
        $displayStudents[] = [
            'name' => $studentName !== '' ? $studentName : '—',
            'amount' => $studentAmount,
            'share' => $studentShare,
            'profit' => $studentAmount - $studentShare,
        ];
        $formStudents[] = [
            'name' => $studentName,
            'amount' => $studentAmount,
        ];
    }

    if (!$displayStudents) {
        $displayStudents[] = [
            'name' => !empty($session['student_name']) ? $session['student_name'] : '—',
            'amount' => $totalAmount,
            'share' => $teacherShare,
            'profit' => $totalAmount - $teacherShare,
        ];
        $formStudents[] = [
            'name' => $session['student_name'] ?? '',
            'amount' => $totalAmount,
        ];
    }

    $teacherSessionsById[$teacherId]['sessions'][] = [
        'id' => (int) $session['id'],
        'teacher_id' => $teacherId,
        'session_date' => $session['session_date'],
        'group_name' => $session['group_name'],
        'teacher_percentage' => $percentage,
        'total_amount' => $totalAmount,
        'total_share' => $teacherShare,
        'total_profit' => $totalAmount - $teacherShare,
        'students' => $displayStudents,
        'form_students' => $formStudents,
        'student_summary' => $session['student_name'] ?? '',
    ];

    $teacherSessionsById[$teacherId]['totals']['amount'] += $totalAmount;
    $teacherSessionsById[$teacherId]['totals']['share'] += $teacherShare;
    $teacherSessionsById[$teacherId]['totals']['profit'] += $totalAmount - $teacherShare;
}

$teacherLedgers = [];

foreach ($teacherMetrics as $teacherMetric) {
    $tid = (int) ($teacherMetric['id'] ?? 0);
    $ledger = $teacherSessionsById[$tid] ?? [
        'sessions' => [],
        'totals' => [
            'amount' => 0,
            'share' => 0,
            'profit' => 0,
        ],
    ];

    $teacherLedgers[$tid] = [
        'info' => $teacherMetric,
        'sessions' => $ledger['sessions'] ?? [],
        'totals' => $ledger['totals'] ?? [
            'amount' => 0,
            'share' => 0,
            'profit' => 0,
        ],
    ];
}

foreach ($teacherSessionsById as $tid => $ledger) {
    if (!isset($teacherLedgers[$tid])) {
        $teacherLedgers[$tid] = [
            'info' => $ledger['info'] + [
                'range_amount' => 0,
                'range_share' => 0,
                'range_profit' => 0,
                'range_payout' => 0,
                'total_amount_all' => 0,
                'total_share_all' => 0,
                'total_payout_all' => 0,
            ],
            'sessions' => $ledger['sessions'],
            'totals' => $ledger['totals'],
        ];
    }
}

$teacherLedgers = array_values($teacherLedgers);

$flash = $_SESSION['teachers_flash'] ?? null;
unset($_SESSION['teachers_flash']);

$redirectQuery = http_build_query($filters + ($teacherFilter ? ['teacher_id' => $teacherFilter] : []));

?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>O'qituvchilar oyligi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#2563eb',
                        accent: '#f97316',
                        slate: {
                            25: '#f8fafc'
                        }
                    },
                    boxShadow: {
                        'soft': '0 20px 45px -20px rgba(37, 99, 235, 0.35)'
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass { backdrop-filter: blur(16px); background: rgba(255, 255, 255, 0.7); }
        dialog::backdrop { background: rgba(15, 23, 42, 0.4); }
        dialog { border: 0; border-radius: 1rem; padding: 0; }
        @media print {
            body { background: #ffffff; color: #0f172a; }
            .no-print { display: none !important; }
            .glass { background: #ffffff !important; box-shadow: none !important; }
            table { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-slate-25 min-h-screen text-slate-700">
<div class="max-w-7xl mx-auto px-4 py-6 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900">O'qituvchilar moliyasi</h1>
            <p class="text-slate-500">Guruhlar tushumi, foizlar va maosh to'lovlarini nazorat qiling.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 no-print">
            <button data-open="teacherDialog" class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-white font-medium shadow-soft transition hover:bg-primary/90">
                <span class="text-lg">＋</span> Yangi o'qituvchi
            </button>
            <button data-open="sessionDialog" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-slate-700 font-medium shadow hover:shadow-lg transition">
                <span class="text-lg">＋</span> Dars tushumi
            </button>
            <button data-open="payoutDialog" class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-white font-medium shadow-lg transition hover:bg-accent/90">
                <span class="text-lg">＋</span> Maosh to'lovi
            </button>
            <a href="export.php<?= $redirectQuery ? '?' . htmlspecialchars($redirectQuery) : '' ?>" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 shadow transition hover:bg-slate-50">
                📄 Excelga eksport
            </a>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 shadow transition hover:bg-slate-50">
                🖨 Chop etish
            </button>
        </div>
    </div>

    <form class="mt-6 grid gap-3 rounded-2xl glass shadow-soft p-4 md:grid-cols-2 lg:grid-cols-4" method="get">
        <div class="flex flex-col">
            <label for="start_date" class="text-xs font-medium text-slate-500 uppercase">Boshlanish sanasi</label>
            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filters['start_date']) ?>" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
        </div>
        <div class="flex flex-col">
            <label for="end_date" class="text-xs font-medium text-slate-500 uppercase">Tugash sanasi</label>
            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filters['end_date']) ?>" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
        </div>
        <div class="flex flex-col">
            <label for="teacher_id" class="text-xs font-medium text-slate-500 uppercase">O'qituvchi</label>
            <select id="teacher_id" name="teacher_id" class="mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                <option value="0" <?= $teacherFilter === 0 ? 'selected' : '' ?>>Barchasi</option>
                <?php foreach ($teacherMetrics as $teacher): ?>
                    <option value="<?= $teacher['id'] ?>" <?= $teacherFilter === (int) $teacher['id'] ? 'selected' : '' ?>><?= htmlspecialchars($teacher['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end justify-end gap-2">
            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-white text-sm font-semibold shadow transition hover:bg-primary/90">Filtrlash</button>
            <a href="index.php" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-white">Tiklash</a>
        </div>
    </form>

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

    <?php if ($filterWarnings): ?>
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-700">
            <p class="font-semibold mb-1">Filtrlash ogohlantirishlari</p>
            <ul class="list-disc pl-5 space-y-1 text-sm">
                <?php foreach ($filterWarnings as $warn): ?>
                    <li><?= htmlspecialchars($warn) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="glass rounded-2xl p-5 shadow-soft">
            <p class="text-sm font-medium text-slate-500">Davr tushumi</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900"><?= format_money($rangeSummary['total_amount']) ?> so'm</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-soft">
            <p class="text-sm font-medium text-slate-500">O'qituvchi ulushi</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900"><?= format_money($rangeSummary['total_share']) ?> so'm</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-soft">
            <p class="text-sm font-medium text-slate-500">Markaz foydasi</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-600"><?= format_money($rangeSummary['total_profit']) ?> so'm</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-soft">
            <p class="text-sm font-medium text-slate-500">To'langan maosh</p>
            <p class="mt-2 text-3xl font-semibold text-primary"><?= format_money($rangeSummary['total_payout']) ?> so'm</p>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="glass rounded-2xl p-5 shadow-soft lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-900">O'qituvchilar kesimida</h2>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500">
                            <th class="py-2 pr-4">O'qituvchi</th>
                            <th class="py-2 pr-4">Davr tushumi</th>
                            <th class="py-2 pr-4">Davr ulushi</th>
                            <th class="py-2 pr-4">Davr foyda</th>
                            <th class="py-2 pr-4">Davrda to'landi</th>
                            <th class="py-2 pr-4">Balans (umumiy)</th>
                            <th class="py-2">Amal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($teacherMetrics as $teacher):
                            $balance = ($teacher['total_share_all'] ?? 0) - ($teacher['total_payout_all'] ?? 0);
                        ?>
                        <tr class="hover:bg-white/70">
                            <td class="py-3 pr-4">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($teacher['name']) ?></div>
                                <div class="text-xs text-slate-500">Standart ulush: <?= htmlspecialchars($teacher['percentage']) ?>%</div>
                            </td>
                            <td class="py-3 pr-4 font-medium text-slate-900"><?= format_money((float) $teacher['range_amount']) ?></td>
                            <td class="py-3 pr-4 text-slate-700"><?= format_money((float) $teacher['range_share']) ?></td>
                            <td class="py-3 pr-4 text-emerald-600 font-semibold"><?= format_money((float) $teacher['range_profit']) ?></td>
                            <td class="py-3 pr-4 text-primary font-medium"><?= format_money((float) $teacher['range_payout']) ?></td>
                            <td class="py-3 pr-4 <?= $balance >= 0 ? 'text-amber-600' : 'text-emerald-600' ?> font-semibold">
                                <?= format_money($balance) ?>
                                <span class="text-xs text-slate-500 block">(musbat - qarzdorlik)</span>
                            </td>
                            <td class="py-3">
                                <div class="flex flex-wrap gap-2">
                                    <button data-edit-teacher='<?= json_encode([
                                        'id' => (int) $teacher['id'],
                                        'name' => $teacher['name'],
                                        'percentage' => $teacher['percentage'],
                                        'phone' => $teacher['phone'],
                                        'note' => $teacher['note'],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Tahrirlash</button>
                                    <button data-open="sessionDialog" data-session-teacher="<?= (int) $teacher['id'] ?>" data-session-percentage="<?= htmlspecialchars($teacher['percentage']) ?>" class="rounded-lg border border-primary/40 px-3 py-1 text-xs font-semibold text-primary hover:bg-primary/10">Dars qo'shish</button>
                                    <button data-open="payoutDialog" data-payout-teacher="<?= (int) $teacher['id'] ?>" class="rounded-lg border border-emerald-300 px-3 py-1 text-xs font-semibold text-emerald-600 hover:bg-emerald-50">Maosh to'lovi</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$teacherMetrics): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-slate-500">Hali o'qituvchilar qo'shilmagan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="glass rounded-2xl p-5 shadow-soft">
            <h2 class="text-xl font-semibold text-slate-900">Oylik tahlil</h2>
            <div class="mt-6 h-72">
                <canvas id="teacherChart" class="h-full w-full"></canvas>
            </div>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)] print:grid-cols-1">
        <div class="space-y-6">
            <?php foreach ($teacherLedgers as $ledger): ?>
                <?php $teacher = $ledger['info']; ?>
                <?php $sessions = $ledger['sessions']; ?>
                <?php $totals = $ledger['totals']; ?>
                <section class="glass rounded-2xl p-5 shadow-soft">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($teacher['name'] ?? "Noma'lum o'qituvchi") ?></h2>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                                <span class="rounded-full bg-white/70 px-3 py-1">Standart ulush: <?= htmlspecialchars(number_format((float) ($teacher['percentage'] ?? 0), 2)) ?>%</span>
                                <?php if (!empty($teacher['phone'])): ?>
                                    <span class="rounded-full bg-white/70 px-3 py-1">Tel: <?= htmlspecialchars($teacher['phone']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($teacher['note'])): ?>
                                <p class="mt-2 text-xs text-slate-500 whitespace-pre-line"><?= htmlspecialchars($teacher['note']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-wrap gap-2 no-print">
                            <button data-edit-teacher='<?= json_encode([
                                'id' => (int) ($teacher['id'] ?? 0),
                                'name' => $teacher['name'] ?? '',
                                'percentage' => $teacher['percentage'] ?? '',
                                'phone' => $teacher['phone'] ?? '',
                                'note' => $teacher['note'] ?? '',
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Tahrirlash</button>
                            <button data-open="sessionDialog" data-session-teacher="<?= (int) ($teacher['id'] ?? 0) ?>" data-session-percentage="<?= htmlspecialchars($teacher['percentage'] ?? '') ?>" class="rounded-lg border border-primary/40 px-3 py-1 text-xs font-semibold text-primary hover:bg-primary/10">Dars qo'shish</button>
                            <button data-open="payoutDialog" data-payout-teacher="<?= (int) ($teacher['id'] ?? 0) ?>" class="rounded-lg border border-emerald-300 px-3 py-1 text-xs font-semibold text-emerald-600 hover:bg-emerald-50">Maosh to'lovi</button>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 text-xs text-slate-600 sm:grid-cols-3">
                        <div class="rounded-xl bg-white/70 px-3 py-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Davr tushumi</p>
                            <p class="mt-1 text-base font-semibold text-slate-900"><?= format_money((float) ($teacher['range_amount'] ?? $totals['amount'])) ?> so'm</p>
                        </div>
                        <div class="rounded-xl bg-white/70 px-3 py-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">O'qituvchi ulushi</p>
                            <p class="mt-1 text-base font-semibold text-primary"><?= format_money((float) ($teacher['range_share'] ?? $totals['share'])) ?> so'm</p>
                        </div>
                        <div class="rounded-xl bg-white/70 px-3 py-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Markaz foydasi</p>
                            <p class="mt-1 text-base font-semibold text-emerald-600"><?= format_money((float) ($teacher['range_profit'] ?? $totals['profit'])) ?> so'm</p>
                        </div>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-slate-500">
                                    <th class="py-2 pr-4">Sana</th>
                                    <th class="py-2 pr-4">Guruh</th>
                                    <th class="py-2 pr-4">Talaba</th>
                                    <th class="py-2 pr-4">Talaba to'lovi</th>
                                    <th class="py-2 pr-4">Ulush</th>
                                    <th class="py-2 pr-4">Foyda</th>
                                    <th class="py-2 pr-4">Jami</th>
                                    <th class="py-2">Amal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($sessions as $sessionData): ?>
                                    <?php $studentRows = $sessionData['students']; ?>
                                    <?php $rowspan = max(1, count($studentRows)); ?>
                                    <?php foreach ($studentRows as $index => $studentRow): ?>
                                        <tr class="align-top hover:bg-white/70">
                                            <?php if ($index === 0): ?>
                                                <td rowspan="<?= $rowspan ?>" class="py-3 pr-4 font-medium text-slate-900"><?= htmlspecialchars($sessionData['session_date']) ?></td>
                                                <td rowspan="<?= $rowspan ?>" class="py-3 pr-4">
                                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars($sessionData['group_name']) ?></div>
                                                    <div class="text-xs text-slate-500"><?= htmlspecialchars(number_format((float) $sessionData['teacher_percentage'], 2)) ?>%</div>
                                                </td>
                                            <?php endif; ?>
                                            <td class="py-3 pr-4 text-slate-700"><?= htmlspecialchars($studentRow['name']) ?></td>
                                            <td class="py-3 pr-4 font-semibold text-slate-900"><?= format_money((float) $studentRow['amount']) ?></td>
                                            <td class="py-3 pr-4 text-primary font-semibold"><?= format_money((float) $studentRow['share']) ?></td>
                                            <td class="py-3 pr-4 text-emerald-600 font-semibold"><?= format_money((float) $studentRow['profit']) ?></td>
                                            <?php if ($index === 0): ?>
                                                <td rowspan="<?= $rowspan ?>" class="py-3 pr-4 text-xs text-slate-500">
                                                    <div class="font-semibold text-slate-900"><?= format_money($sessionData['total_amount']) ?> so'm</div>
                                                    <div>Ulush: <?= format_money($sessionData['total_share']) ?> so'm</div>
                                                    <div class="text-emerald-600">Foyda: <?= format_money($sessionData['total_profit']) ?> so'm</div>
                                                </td>
                                                <td rowspan="<?= $rowspan ?>" class="py-3">
                                                    <div class="flex flex-col gap-2">
                                                        <button data-edit-session='<?= json_encode([
                                                            'id' => $sessionData['id'],
                                                            'teacher_id' => $sessionData['teacher_id'],
                                                            'session_date' => $sessionData['session_date'],
                                                            'group_name' => $sessionData['group_name'],
                                                            'teacher_percentage' => $sessionData['teacher_percentage'],
                                                            'students' => array_map(static function ($student) {
                                                                return [
                                                                    'name' => $student['name'],
                                                                    'amount' => $student['amount'],
                                                                ];
                                                            }, $sessionData['form_students'] ?? []),
                                                            'student_name' => $sessionData['student_summary'],
                                                            'amount' => $sessionData['total_amount'],
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Tahrirlash</button>
                                                        <form action="manage_session.php" method="post" onsubmit="return confirm('Ushbu dars yozuvini o\'chirasizmi?')">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="session_id" value="<?= (int) $sessionData['id'] ?>">
                                                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                                            <button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">O'chirish</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if (!$sessions): ?>
                                    <tr>
                                        <td colspan="8" class="py-6 text-center text-slate-500">Tanlangan oraliqda dars yozuvlari yo'q.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50 text-sm font-semibold text-slate-700">
                                    <td colspan="3" class="py-3 pr-4 text-right">O'qituvchi jami:</td>
                                    <td class="py-3 pr-4 text-slate-900"><?= format_money($totals['amount']) ?></td>
                                    <td class="py-3 pr-4 text-primary"><?= format_money($totals['share']) ?></td>
                                    <td class="py-3 pr-4 text-emerald-600"><?= format_money($totals['profit']) ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
            <?php if (!$teacherLedgers): ?>
                <section class="glass rounded-2xl p-8 text-center shadow-soft">
                    <h2 class="text-lg font-semibold text-slate-900">O'qituvchilar ro'yxati bo'sh</h2>
                    <p class="mt-2 text-sm text-slate-500">Avval o'qituvchi qo'shing va dars tushumlarini kiritishni boshlang.</p>
                    <button data-open="teacherDialog" class="no-print mt-4 inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">＋ O'qituvchi qo'shish</button>
                </section>
            <?php endif; ?>
        </div>
        <div class="glass rounded-2xl p-5 shadow-soft">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-900">Maosh to'lovlari</h2>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500">
                            <th class="py-2 pr-4">Sana</th>
                            <th class="py-2 pr-4">O'qituvchi</th>
                            <th class="py-2 pr-4">To'lov usuli</th>
                            <th class="py-2 pr-4">Summasi</th>
                            <th class="py-2 pr-4">Izoh</th>
                            <th class="py-2">Amal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($payoutRows as $payout): ?>
                        <tr class="hover:bg-white/70">
                            <td class="py-3 pr-4 font-medium text-slate-900"><?= htmlspecialchars($payout['paid_at']) ?></td>
                            <td class="py-3 pr-4 text-slate-700"><?= htmlspecialchars($payout['teacher_name']) ?></td>
                            <td class="py-3 pr-4 text-slate-600"><?= $payout['payment_method'] === 'click' ? 'Click' : 'Naqd' ?></td>
                            <td class="py-3 pr-4 font-semibold text-primary"><?= format_money((float) $payout['amount']) ?></td>
                            <td class="py-3 pr-4 text-slate-500"><?= htmlspecialchars($payout['note']) ?></td>
                            <td class="py-3">
                                <div class="flex gap-2">
                                    <button data-edit-payout='<?= json_encode([
                                        'id' => (int) $payout['id'],
                                        'teacher_id' => (int) $payout['teacher_id'],
                                        'paid_at' => $payout['paid_at'],
                                        'amount' => $payout['amount'],
                                        'payment_method' => $payout['payment_method'],
                                        'note' => $payout['note'],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Tahrirlash</button>
                                    <form action="manage_payout.php" method="post" onsubmit="return confirm('Ushbu maosh to\'lovini o\'chirasizmi?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="payout_id" value="<?= (int) $payout['id'] ?>">
                                        <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                        <button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">O'chirish</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$payoutRows): ?>
                            <tr>
                                <td colspan="6" class="py-6 text-center text-slate-500">Tanlangan oraliqda maosh to'lovlari yo'q.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<dialog id="teacherDialog">
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
        <div class="mt-6 flex justify-between">
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
            <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
            <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
        </div>
    </form>
</dialog>

<dialog id="sessionDialog">
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
                            <option value="<?= $teacher['id'] ?>" data-percentage="<?= htmlspecialchars($teacher['percentage']) ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Sana
                    <input type="date" name="session_date" value="<?= htmlspecialchars($filters['end_date']) ?>" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Guruh / davr nomi
                    <input type="text" name="group_name" placeholder="Masalan: 2024-yil may oyining 1-guruhi" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">O'qituvchi ulushi (%)
                    <input type="number" name="teacher_percentage" step="0.01" min="0" max="100" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
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
        <div class="mt-6 flex justify-between">
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
            <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
            <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
        </div>
    </form>
</dialog>

<dialog id="payoutDialog">
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
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">To'lov sanasi
                    <input type="date" name="paid_at" value="<?= htmlspecialchars($filters['end_date']) ?>" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">To'lov summasi (so'm)
                    <input type="number" name="amount" step="0.01" min="0" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                </label>
            </div>
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium text-slate-600">To'lov usuli</span>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input type="radio" name="payment_method" value="cash" checked class="h-4 w-4 text-primary focus:ring-primary"> Naqd
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input type="radio" name="payment_method" value="click" class="h-4 w-4 text-primary focus:ring-primary"> Click
                </label>
            </div>
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Izoh
                <textarea name="note" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30" placeholder="Masalan: aprel oyi darsi"></textarea>
            </label>
        </div>
        <div class="mt-6 flex justify-between">
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary/90">Saqlash</button>
            <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-close>Bekor qilish</button>
            <button type="submit" name="action" value="delete" class="hidden rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50" data-delete>O'chirish</button>
        </div>
    </form>
</dialog>

<script>
const formatNumber = value => new Intl.NumberFormat('uz-UZ').format(value);
const defaultDateValue = '<?= htmlspecialchars($filters['end_date']) ?>';

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
            recalcSessionTotals();
            return;
        }
        rowElement.remove();
        recalcSessionTotals();
    });
    sessionRowsContainer.appendChild(rowElement);
}

function resetSessionStudents(students = []) {
    if (!sessionRowsContainer) return;
    sessionRowsContainer.innerHTML = '';
    const payload = Array.isArray(students) && students.length ? students : [{ name: '', amount: '' }];
    payload.forEach(item => addStudentRow(item));
    recalcSessionTotals();
}

function resetDialog(dialog, trigger = null) {
    dialog.querySelectorAll('input[name], textarea[name], select[name]').forEach(el => {
        if (el.name === 'action') {
            el.value = 'create';
        } else if (['session_id', 'teacher_id', 'payout_id'].includes(el.name)) {
            if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else {
                el.value = '';
            }
        } else if (el.type === 'number') {
            el.value = '';
        } else if (el.type === 'date') {
            el.value = defaultDateValue;
        } else if (el.type === 'radio') {
            el.checked = el.value === 'cash';
        } else if (el.tagName === 'TEXTAREA') {
            el.value = '';
        } else {
            el.value = '';
        }
    });
    const deleteButton = dialog.querySelector('[data-delete]');
    if (deleteButton) {
        deleteButton.classList.add('hidden');
    }

    if (dialog === sessionDialog) {
        resetSessionStudents();
        if (sessionTeacherSelect) {
            if (trigger?.dataset.sessionTeacher) {
                sessionTeacherSelect.value = trigger.dataset.sessionTeacher;
            }
            const selectedOption = sessionTeacherSelect.selectedOptions[0];
            if (trigger?.dataset.sessionPercentage) {
                sessionPercentageInput.value = trigger.dataset.sessionPercentage;
            } else if (selectedOption?.dataset.percentage) {
                sessionPercentageInput.value = selectedOption.dataset.percentage;
            } else if (sessionPercentageInput) {
                sessionPercentageInput.value = '';
            }
        }
        recalcSessionTotals();
    }

    if (dialog === payoutDialog && trigger?.dataset.payoutTeacher) {
        const payoutTeacherSelect = payoutDialog.querySelector('select[name="teacher_id"]');
        if (payoutTeacherSelect) {
            payoutTeacherSelect.value = trigger.dataset.payoutTeacher;
        }
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
        const deleteButton = teacherDialog.querySelector('[data-delete]');
        deleteButton?.classList.remove('hidden');
        teacherDialog.showModal();
    });
});

document.querySelectorAll('[data-edit-session]').forEach(button => {
    button.addEventListener('click', () => {
        if (!sessionDialog) return;
        const data = JSON.parse(button.getAttribute('data-edit-session'));
        resetDialog(sessionDialog);
        sessionDialog.querySelector('input[name="action"]').value = 'update';
        sessionDialog.querySelector('input[name="session_id"]').value = data.id;
        if (sessionTeacherSelect) {
            sessionTeacherSelect.value = data.teacher_id;
        }
        sessionDialog.querySelector('input[name="session_date"]').value = data.session_date;
        sessionDialog.querySelector('input[name="group_name"]').value = data.group_name || '';
        if (sessionPercentageInput) {
            sessionPercentageInput.value = data.teacher_percentage ?? '';
        }
        const studentPayload = Array.isArray(data.students) && data.students.length
            ? data.students
            : (data.student_name || data.amount
                ? [{ name: data.student_name || '', amount: data.amount }]
                : []);
        resetSessionStudents(studentPayload);
        if (sessionActionInput) {
            sessionActionInput.value = 'update';
        }
        const deleteButton = sessionDialog.querySelector('[data-delete]');
        deleteButton?.classList.remove('hidden');
        recalcSessionTotals();
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
        payoutDialog.querySelector('input[name="paid_at"]').value = data.paid_at;
        payoutDialog.querySelector('input[name="amount"]').value = data.amount;
        payoutDialog.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.checked = radio.value === data.payment_method;
        });
        payoutDialog.querySelector('textarea[name="note"]').value = data.note || '';
        const deleteButton = payoutDialog.querySelector('[data-delete]');
        deleteButton?.classList.remove('hidden');
        payoutDialog.showModal();
    });
});

const ctx = document.getElementById('teacherChart');
if (ctx) {
    const months = <?= json_encode(array_map(fn($m) => $m['label'], $months)) ?>;
    const revenueData = <?= json_encode(array_map(fn($m) => round($m['amount'], 2), $months)) ?>;
    const shareData = <?= json_encode(array_map(fn($m) => round($m['share'], 2), $months)) ?>;
    const profitData = <?= json_encode(array_map(fn($m) => round($m['profit'], 2), $months)) ?>;
    const payoutData = <?= json_encode(array_map(fn($m) => round($m['payout'], 2), $months)) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [
                {
                    type: 'bar',
                    label: "Tushum",
                    data: revenueData,
                    backgroundColor: 'rgba(37, 99, 235, 0.6)',
                    borderRadius: 10
                },
                {
                    type: 'bar',
                    label: "O'qituvchi ulushi",
                    data: shareData,
                    backgroundColor: 'rgba(249, 115, 22, 0.5)',
                    borderRadius: 10
                },
                {
                    type: 'line',
                    label: "Markaz foydasi",
                    data: profitData,
                    borderColor: '#10b981',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: '#10b981'
                },
                {
                    type: 'line',
                    label: "To'langan maosh",
                    data: payoutData,
                    borderColor: '#facc15',
                    tension: 0.4,
                    fill: false,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointBackgroundColor: '#facc15',
                    borderDash: [6, 6]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxHeight: 8
                    }
                },
                tooltip: {
                    callbacks: {
                        label: context => `${context.dataset.label}: ${formatNumber(context.parsed.y ?? 0)} so'm`
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            scales: {
                y: {
                    ticks: {
                        callback: value => `${formatNumber(value)} so'm`
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.2)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>
</body>
</html>
