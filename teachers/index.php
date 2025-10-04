<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

require_once '../db.php';

date_default_timezone_set('Asia/Tashkent');

// Ensure teacher tables exist
$conn->query("CREATE TABLE IF NOT EXISTS teacher_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    phone VARCHAR(100) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS teacher_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    session_date DATE NOT NULL,
    group_name VARCHAR(255) NOT NULL,
    student_name VARCHAR(255) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    teacher_percentage DECIMAL(5,2) NOT NULL,
    teacher_share DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_teacher_sessions_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS teacher_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    paid_at DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    note VARCHAR(255) DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_teacher_payouts_teacher FOREIGN KEY (teacher_id) REFERENCES teacher_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

$teacherMetrics = [];
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

$sessionRows = [];
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

$payoutRows = [];
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

// Monthly analytics for charts (last 6 months including current)
$months = [];
$monthStart = (new DateTime('first day of -5 month'))->format('Y-m-01');
$monthEnd = date('Y-m-t');

$monthlySessions = [];
$monthlyStmt = $conn->prepare("SELECT DATE_FORMAT(session_date, '%Y-%m') AS ym, SUM(amount) AS total_amount, SUM(teacher_share) AS total_share
    FROM teacher_sessions
    WHERE session_date BETWEEN ? AND ?
    GROUP BY ym
    ORDER BY ym");
if ($monthlyStmt) {
    $monthlyStmt->bind_param('ss', $monthStart, $monthEnd);
    if ($monthlyStmt->execute()) {
        while ($row = $monthlyStmt->get_result()->fetch_assoc()) {
            $monthlySessions[$row['ym']] = [
                'amount' => (float) ($row['total_amount'] ?? 0),
                'share' => (float) ($row['total_share'] ?? 0),
            ];
        }
    }
    $monthlyStmt->close();
}

$monthlyPayouts = [];
$monthlyPayoutStmt = $conn->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total_amount
    FROM teacher_payouts
    WHERE paid_at BETWEEN ? AND ?
    GROUP BY ym
    ORDER BY ym");
if ($monthlyPayoutStmt) {
    $monthlyPayoutStmt->bind_param('ss', $monthStart, $monthEnd);
    if ($monthlyPayoutStmt->execute()) {
        while ($row = $monthlyPayoutStmt->get_result()->fetch_assoc()) {
            $monthlyPayouts[$row['ym']] = (float) ($row['total_amount'] ?? 0);
        }
    }
    $monthlyPayoutStmt->close();
}

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
    </style>
</head>
<body class="bg-slate-25 min-h-screen text-slate-700">
<div class="max-w-7xl mx-auto px-4 py-6 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900">O'qituvchilar moliyasi</h1>
            <p class="text-slate-500">Guruhlar tushumi, foizlar va maosh to'lovlarini nazorat qiling.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button data-open="teacherDialog" class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-white font-medium shadow-soft transition hover:bg-primary/90">
                <span class="text-lg">＋</span> Yangi o'qituvchi
            </button>
            <button data-open="sessionDialog" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-slate-700 font-medium shadow hover:shadow-lg transition">
                <span class="text-lg">＋</span> Dars tushumi
            </button>
            <button data-open="payoutDialog" class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-white font-medium shadow-lg transition hover:bg-accent/90">
                <span class="text-lg">＋</span> Maosh to'lovi
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
            <canvas id="teacherChart" class="mt-6"></canvas>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="glass rounded-2xl p-5 shadow-soft">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-900">Dars tushumlari</h2>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500">
                            <th class="py-2 pr-4">Sana</th>
                            <th class="py-2 pr-4">O'qituvchi</th>
                            <th class="py-2 pr-4">Guruh / Talaba</th>
                            <th class="py-2 pr-4">To'lov</th>
                            <th class="py-2 pr-4">Foiz</th>
                            <th class="py-2 pr-4">Ulash</th>
                            <th class="py-2">Amal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($sessionRows as $session): ?>
                        <tr class="hover:bg-white/70">
                            <td class="py-3 pr-4 font-medium text-slate-900"><?= htmlspecialchars($session['session_date']) ?></td>
                            <td class="py-3 pr-4 text-slate-700"><?= htmlspecialchars($session['teacher_name']) ?></td>
                            <td class="py-3 pr-4">
                                <div class="text-slate-900 font-semibold"><?= htmlspecialchars($session['group_name']) ?></div>
                                <?php if ($session['student_name']): ?>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars($session['student_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pr-4 font-semibold text-slate-900"><?= format_money((float) $session['amount']) ?></td>
                            <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars($session['teacher_percentage']) ?>%</td>
                            <td class="py-3 pr-4 text-primary font-semibold"><?= format_money((float) $session['teacher_share']) ?></td>
                            <td class="py-3">
                                <div class="flex gap-2">
                                    <button data-edit-session='<?= json_encode([
                                        'id' => (int) $session['id'],
                                        'teacher_id' => (int) $session['teacher_id'],
                                        'session_date' => $session['session_date'],
                                        'group_name' => $session['group_name'],
                                        'student_name' => $session['student_name'],
                                        'amount' => $session['amount'],
                                        'teacher_percentage' => $session['teacher_percentage'],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Tahrirlash</button>
                                    <form action="manage_session.php" method="post" onsubmit="return confirm('Ushbu dars yozuvini o\'chirasizmi?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                        <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                        <button type="submit" class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">O'chirish</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$sessionRows): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-slate-500">Tanlangan oraliqda dars yozuvlari topilmadi.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
        <div class="mt-4 grid gap-4 md:grid-cols-2">
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
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Guruh nomi
                <input type="text" name="group_name" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">Talaba (ixtiyoriy)
                <input type="text" name="student_name" class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">To'lov summasi (so'm)
                <input type="number" name="amount" step="0.01" min="0" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">O'qituvchi ulushi (%)
                <input type="number" name="teacher_percentage" step="0.01" min="0" max="100" required class="rounded-lg border border-slate-200 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
            </label>
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
const dialogs = document.querySelectorAll('dialog');
const formatNumber = value => new Intl.NumberFormat('uz-UZ').format(value);

document.querySelectorAll('[data-open]').forEach(button => {
    button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-open');
        const dialog = document.getElementById(targetId);
        if (!dialog) return;
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
                el.value = '<?= htmlspecialchars($filters['end_date']) ?>';
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
        if (targetId === 'sessionDialog' && button.dataset.sessionTeacher) {
            const select = dialog.querySelector('select[name="teacher_id"]');
            select.value = button.dataset.sessionTeacher;
            const percentageInput = dialog.querySelector('input[name="teacher_percentage"]');
            if (button.dataset.sessionPercentage) {
                percentageInput.value = button.dataset.sessionPercentage;
            }
        }
        if (targetId === 'payoutDialog' && button.dataset.payoutTeacher) {
            const select = dialog.querySelector('select[name="teacher_id"]');
            select.value = button.dataset.payoutTeacher;
        }
        dialog.showModal();
    });
});

document.querySelectorAll('[data-close]').forEach(button => {
    button.addEventListener('click', () => {
        const dialog = button.closest('dialog');
        if (dialog) dialog.close();
    });
});

const sessionDialog = document.getElementById('sessionDialog');
if (sessionDialog) {
    const teacherSelect = sessionDialog.querySelector('select[name="teacher_id"]');
    const percentageInput = sessionDialog.querySelector('input[name="teacher_percentage"]');
    const actionInput = sessionDialog.querySelector('input[name="action"]');
    teacherSelect?.addEventListener('change', () => {
        const option = teacherSelect.selectedOptions[0];
        if (!option) return;
        const defaultPercentage = option.dataset.percentage;
        if (!defaultPercentage) return;
        if ((actionInput?.value ?? 'create') !== 'update' || percentageInput.value === '') {
            percentageInput.value = defaultPercentage;
        }
    });
}

document.querySelectorAll('[data-edit-teacher]').forEach(button => {
    button.addEventListener('click', () => {
        const data = JSON.parse(button.getAttribute('data-edit-teacher'));
        const dialog = document.getElementById('teacherDialog');
        dialog.showModal();
        dialog.querySelector('input[name="action"]').value = 'update';
        dialog.querySelector('input[name="teacher_id"]').value = data.id;
        dialog.querySelector('input[name="name"]').value = data.name || '';
        dialog.querySelector('input[name="percentage"]').value = data.percentage || '';
        dialog.querySelector('input[name="phone"]').value = data.phone || '';
        dialog.querySelector('textarea[name="note"]').value = data.note || '';
        const deleteButton = dialog.querySelector('[data-delete]');
        if (deleteButton) {
            deleteButton.classList.remove('hidden');
        }
    });
});

document.querySelectorAll('[data-edit-session]').forEach(button => {
    button.addEventListener('click', () => {
        const data = JSON.parse(button.getAttribute('data-edit-session'));
        const dialog = document.getElementById('sessionDialog');
        dialog.showModal();
        dialog.querySelector('input[name="action"]').value = 'update';
        dialog.querySelector('input[name="session_id"]').value = data.id;
        dialog.querySelector('select[name="teacher_id"]').value = data.teacher_id;
        dialog.querySelector('input[name="session_date"]').value = data.session_date;
        dialog.querySelector('input[name="group_name"]').value = data.group_name;
        dialog.querySelector('input[name="student_name"]').value = data.student_name || '';
        dialog.querySelector('input[name="amount"]').value = data.amount;
        dialog.querySelector('input[name="teacher_percentage"]').value = data.teacher_percentage;
        const deleteButton = dialog.querySelector('[data-delete]');
        if (deleteButton) {
            deleteButton.classList.remove('hidden');
        }
    });
});

document.querySelectorAll('[data-edit-payout]').forEach(button => {
    button.addEventListener('click', () => {
        const data = JSON.parse(button.getAttribute('data-edit-payout'));
        const dialog = document.getElementById('payoutDialog');
        dialog.showModal();
        dialog.querySelector('input[name="action"]').value = 'update';
        dialog.querySelector('input[name="payout_id"]').value = data.id;
        dialog.querySelector('select[name="teacher_id"]').value = data.teacher_id;
        dialog.querySelector('input[name="paid_at"]').value = data.paid_at;
        dialog.querySelector('input[name="amount"]').value = data.amount;
        dialog.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.checked = radio.value === data.payment_method;
        });
        dialog.querySelector('textarea[name="note"]').value = data.note || '';
        const deleteButton = dialog.querySelector('[data-delete]');
        if (deleteButton) {
            deleteButton.classList.remove('hidden');
        }
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
