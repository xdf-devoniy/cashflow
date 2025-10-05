<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php?redirect=app');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

require_once '../db.php';

$filterWarnings = [];

function normalize_range(?string $rawStart, ?string $rawEnd, string $defaultStart, string $defaultEnd, array &$warnings, string $label): array
{
    $startInput = isset($rawStart) ? trim((string) $rawStart) : null;
    $endInput = isset($rawEnd) ? trim((string) $rawEnd) : null;

    $startProvided = $startInput !== null && $startInput !== '';
    $endProvided = $endInput !== null && $endInput !== '';

    $start = $startProvided ? DateTime::createFromFormat('Y-m-d', $startInput) ?: null : null;
    $end = $endProvided ? DateTime::createFromFormat('Y-m-d', $endInput) ?: null : null;

    if (!$start) {
        $start = new DateTime($defaultStart);
        if ($startProvided) {
            $warnings[] = $label . ' uchun boshlanish sanasi noto\'g\'ri. Standart sana qo\'llandi.';
        }
    }
    if (!$end) {
        $end = new DateTime($defaultEnd);
        if ($endProvided) {
            $warnings[] = $label . ' uchun tugash sanasi noto\'g\'ri. Standart sana qo\'llandi.';
        }
    }

    if ($start > $end) {
        [$start, $end] = [$end, $start];
        if ($startProvided || $endProvided) {
            $warnings[] = $label . ' uchun sanalar almashtirildi, chunki boshlanish sanasi keyinroq edi.';
        }
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

$reportDefaults = [
    'start' => (new DateTime('first day of -11 months'))->format('Y-m-01'),
    'end' => date('Y-m-d'),
];

$reportRange = normalize_range($_GET['start_date'] ?? null, $_GET['end_date'] ?? null, $reportDefaults['start'], $reportDefaults['end'], $filterWarnings, 'Hisobot filtri');

$incomeDefaults = [
    'start' => date('Y-m-01'),
    'end' => date('Y-m-d'),
];
$incomeRange = normalize_range($_GET['income_start'] ?? null, $_GET['income_end'] ?? null, $incomeDefaults['start'], $incomeDefaults['end'], $filterWarnings, 'Daromadlar filtri');

$expenseDefaults = [
    'start' => date('Y-m-01'),
    'end' => date('Y-m-d'),
];
$expenseRange = normalize_range($_GET['expense_start'] ?? null, $_GET['expense_end'] ?? null, $expenseDefaults['start'], $expenseDefaults['end'], $filterWarnings, 'Xarajatlar filtri');

$categoryDefaults = [
    'start' => date('Y-m-01'),
    'end' => date('Y-m-d'),
];
$categoryRange = normalize_range($_GET['category_start'] ?? null, $_GET['category_end'] ?? null, $categoryDefaults['start'], $categoryDefaults['end'], $filterWarnings, 'Turkumlar filtri');
$categoryRangeLabelStart = (new DateTime($categoryRange['start']))->format('d.m.Y');
$categoryRangeLabelEnd = (new DateTime($categoryRange['end']))->format('d.m.Y');
$selectedExpenseCategory = isset($_GET['expense_category']) ? (int) $_GET['expense_category'] : 0;

$tableCreationQueries = [
    "CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS transaction_categories (
        transaction_id INT NOT NULL PRIMARY KEY,
        category_id INT NOT NULL,
        CONSTRAINT fk_tc_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
        CONSTRAINT fk_tc_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tableCreationQueries as $sql) {
    if (!$conn->query($sql)) {
        $filterWarnings[] = 'Ma\'lumotlar bazasi jadvali yaratilmagan: ' . $conn->error;
    }
}

$categories = [];
$categoryStats = [];

$categoryResult = $conn->query('SELECT id, name FROM expense_categories ORDER BY name');
if ($categoryResult instanceof mysqli_result) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row;
    }
    $categoryResult->free();
}

$categoryStatsSql = "SELECT c.id, c.name,
        COUNT(DISTINCT CASE WHEN t.id IS NOT NULL THEN t.id END) AS expense_count,
        COALESCE(SUM(t.payment), 0) AS total_spent
    FROM expense_categories c
    LEFT JOIN transaction_categories tc ON tc.category_id = c.id
    LEFT JOIN transactions t ON t.id = tc.transaction_id AND t.cash_out = 1 AND t.date BETWEEN ? AND ?
    GROUP BY c.id, c.name
    ORDER BY c.name";
$categoryStatsStmt = $conn->prepare($categoryStatsSql);
if ($categoryStatsStmt) {
    $categoryStatsStmt->bind_param('ss', $categoryRange['start'], $categoryRange['end']);
    if ($categoryStatsStmt->execute()) {
        $categoryStatsResult = $categoryStatsStmt->get_result();
        if ($categoryStatsResult instanceof mysqli_result) {
            while ($row = $categoryStatsResult->fetch_assoc()) {
                $row['expense_count'] = (int) $row['expense_count'];
                $row['total_spent'] = (float) $row['total_spent'];
                $categoryStats[] = $row;
            }
            $categoryStatsResult->free();
        }
    } else {
        $filterWarnings[] = "Turkumlar statistikasi yuklanmadi: " . $categoryStatsStmt->error;
    }
    $categoryStatsStmt->close();
} else {
    $filterWarnings[] = 'Turkumlar statistikasi uchun so\'rov tayyorlanmadi: ' . $conn->error;
}

$totalsSql = "SELECT
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND cash = 1 THEN payment END), 0) AS total_income_cash,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND click = 1 THEN payment END), 0) AS total_income_click,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND cash = 1 THEN payment END), 0) AS total_expense_cash,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND click = 1 THEN payment END), 0) AS total_expense_click,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_expense
    FROM transactions";
$totalsResult = $conn->query($totalsSql);
$totals = $totalsResult instanceof mysqli_result ? $totalsResult->fetch_assoc() : null;
if ($totalsResult instanceof mysqli_result) {
    $totalsResult->free();
}
if (!$totals) {
    $totals = [
        'total_income' => 0,
        'total_expense' => 0,
        'total_income_cash' => 0,
        'total_income_click' => 0,
        'total_expense_cash' => 0,
        'total_expense_click' => 0,
        'monthly_income' => 0,
        'monthly_expense' => 0,
    ];
    $filterWarnings[] = 'Umumiy ko\'rsatkichlar yuklanmadi.';
}

$balance = $totals['total_income'] - $totals['total_expense'];
$monthlyBalance = $totals['monthly_income'] - $totals['monthly_expense'];

$now = new DateTime();
$currentDateLabel = $now->format('d.m.Y');
$currentTimeLabel = $now->format('H:i');

$incomeRecords = [];
$incomeTotals = [
    'total' => 0.0,
    'cash' => 0.0,
    'click' => 0.0,
];

$incomeStmt = $conn->prepare("SELECT id, date, payment, comment,
        CASE WHEN cash = 1 THEN 'Naqd' WHEN click = 1 THEN 'Click' ELSE 'Boshqa' END AS method,
        cash, click
    FROM transactions
    WHERE cash_in = 1 AND date BETWEEN ? AND ?
    ORDER BY date DESC, id DESC");
if ($incomeStmt) {
    $incomeStmt->bind_param('ss', $incomeRange['start'], $incomeRange['end']);
    if ($incomeStmt->execute()) {
        $result = $incomeStmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $row['payment'] = (float) $row['payment'];
                $incomeTotals['total'] += $row['payment'];
                if ((int) $row['cash'] === 1) {
                    $incomeTotals['cash'] += $row['payment'];
                }
                if ((int) $row['click'] === 1) {
                    $incomeTotals['click'] += $row['payment'];
                }
                $incomeRecords[] = $row;
            }
            $result->free();
        }
    }
    $incomeStmt->close();
}

$incomePercentages = [
    'cash' => percentage($incomeTotals['cash'], $incomeTotals['total']),
    'click' => percentage($incomeTotals['click'], $incomeTotals['total']),
];

$expenseRecords = [];
$expenseTotals = [
    'total' => 0.0,
    'cash' => 0.0,
    'click' => 0.0,
];

$expenseQuery = "SELECT t.id, t.date, t.payment, t.comment, t.cash, t.click,
        CASE WHEN t.cash = 1 THEN 'Naqd' WHEN t.click = 1 THEN 'Click' ELSE 'Boshqa' END AS method,
        COALESCE(c.name, 'Turkum tanlanmagan') AS category_name,
        COALESCE(c.id, 0) AS category_id
    FROM transactions t
    LEFT JOIN transaction_categories tc ON tc.transaction_id = t.id
    LEFT JOIN expense_categories c ON c.id = tc.category_id
    WHERE t.cash_out = 1 AND t.date BETWEEN ? AND ?";
$expenseTypes = 'ss';
$expenseParams = [$expenseRange['start'], $expenseRange['end']];
if ($selectedExpenseCategory > 0) {
    $expenseQuery .= ' AND COALESCE(c.id, 0) = ?';
    $expenseTypes .= 'i';
    $expenseParams[] = $selectedExpenseCategory;
}
$expenseQuery .= ' ORDER BY t.date DESC, t.id DESC';

$expenseStmt = $conn->prepare($expenseQuery);
if ($expenseStmt) {
    $expenseStmt->bind_param($expenseTypes, ...$expenseParams);
    if ($expenseStmt->execute()) {
        $result = $expenseStmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $row['payment'] = (float) $row['payment'];
                $expenseTotals['total'] += $row['payment'];
                if ((int) $row['cash'] === 1) {
                    $expenseTotals['cash'] += $row['payment'];
                }
                if ((int) $row['click'] === 1) {
                    $expenseTotals['click'] += $row['payment'];
                }
                $expenseRecords[] = $row;
            }
            $result->free();
        }
    }
    $expenseStmt->close();
}

$expensePercentages = [
    'cash' => percentage($expenseTotals['cash'], $expenseTotals['total']),
    'click' => percentage($expenseTotals['click'], $expenseTotals['total']),
];

$reportSql = "SELECT DATE_FORMAT(date, '%Y-%m') AS period,
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS expense
    FROM transactions
    WHERE date BETWEEN ? AND ?
    GROUP BY period
    ORDER BY period";
$reportLabels = [];
$reportIncome = [];
$reportExpense = [];
$reportBalance = [];
$reportTable = [];

$reportStmt = $conn->prepare($reportSql);
$monthlyMap = [];
if ($reportStmt) {
    $reportStmt->bind_param('ss', $reportRange['start'], $reportRange['end']);
    if ($reportStmt->execute()) {
        $result = $reportStmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $monthlyMap[$row['period']] = [
                    'income' => (float) $row['income'],
                    'expense' => (float) $row['expense'],
                ];
            }
            $result->free();
        }
    }
    $reportStmt->close();
}

$rangeSummary = [
    'income' => 0.0,
    'expense' => 0.0,
    'income_cash' => 0.0,
    'income_click' => 0.0,
    'expense_cash' => 0.0,
    'expense_click' => 0.0,
];

$summarySql = "SELECT
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS expense,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND cash = 1 THEN payment END), 0) AS income_cash,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND click = 1 THEN payment END), 0) AS income_click,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND cash = 1 THEN payment END), 0) AS expense_cash,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND click = 1 THEN payment END), 0) AS expense_click
    FROM transactions
    WHERE date BETWEEN ? AND ?";
$summaryStmt = $conn->prepare($summarySql);
if ($summaryStmt) {
    $summaryStmt->bind_param('ss', $reportRange['start'], $reportRange['end']);
    if ($summaryStmt->execute()) {
        $result = $summaryStmt->get_result();
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if ($row) {
                $rangeSummary = array_merge($rangeSummary, array_map('floatval', $row));
            }
            $result->free();
        }
    }
    $summaryStmt->close();
}
$rangeSummary['cash_balance'] = $rangeSummary['income_cash'] - $rangeSummary['expense_cash'];
$rangeSummary['click_balance'] = $rangeSummary['income_click'] - $rangeSummary['expense_click'];
$rangeSummary['balance'] = $rangeSummary['cash_balance'] + $rangeSummary['click_balance'];

$categoryBreakdown = [];
$categoryBreakdownSql = "SELECT COALESCE(c.name, 'Turkum tanlanmagan') AS name,
        COALESCE(SUM(t.payment), 0) AS total
    FROM transactions t
    LEFT JOIN transaction_categories tc ON tc.transaction_id = t.id
    LEFT JOIN expense_categories c ON c.id = tc.category_id
    WHERE t.cash_out = 1 AND t.date BETWEEN ? AND ?
    GROUP BY name
    ORDER BY total DESC";
$categoryBreakdownStmt = $conn->prepare($categoryBreakdownSql);
if ($categoryBreakdownStmt) {
    $categoryBreakdownStmt->bind_param('ss', $reportRange['start'], $reportRange['end']);
    if ($categoryBreakdownStmt->execute()) {
        $result = $categoryBreakdownStmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $categoryBreakdown[] = [
                    'name' => $row['name'],
                    'total' => (float) $row['total'],
                ];
            }
            $result->free();
        }
    }
    $categoryBreakdownStmt->close();
}
$topCategory = $categoryBreakdown[0] ?? ['name' => 'Ma\'lumot yo\'q', 'total' => 0.0];
$categoryChartLabels = array_column($categoryBreakdown, 'name');
$categoryChartTotals = array_map('floatval', array_column($categoryBreakdown, 'total'));
$categoryChartHasData = array_sum($categoryChartTotals) > 0;

$periodStart = (new DateTime($reportRange['start']))->modify('first day of this month');
$periodEnd = (new DateTime($reportRange['end']))->modify('first day of next month');
for ($cursor = clone $periodStart; $cursor < $periodEnd; $cursor->modify('+1 month')) {
    $periodKey = $cursor->format('Y-m');
    $incomeValue = $monthlyMap[$periodKey]['income'] ?? 0.0;
    $expenseValue = $monthlyMap[$periodKey]['expense'] ?? 0.0;
    $balanceValue = $incomeValue - $expenseValue;

    $reportLabels[] = $cursor->format('M Y');
    $reportIncome[] = $incomeValue;
    $reportExpense[] = $expenseValue;
    $reportBalance[] = $balanceValue;

    $reportTable[] = [
        'label' => $cursor->format('F Y'),
        'income' => $incomeValue,
        'expense' => $expenseValue,
        'balance' => $balanceValue,
    ];
}

$conn->close();

$allowedTabs = ['overview', 'income', 'expense', 'reports', 'categories'];
$activeTab = 'overview';
$explicitTab = null;
if (isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) {
    $explicitTab = $_GET['tab'];
    $activeTab = $explicitTab;
} elseif (isset($_SESSION['active_tab']) && in_array($_SESSION['active_tab'], $allowedTabs, true)) {
    $activeTab = $_SESSION['active_tab'];
}
unset($_SESSION['active_tab']);

if ($explicitTab === null) {
    if (!empty(array_intersect(array_keys($_GET), ['start_date', 'end_date']))) {
        $activeTab = 'reports';
    }
    if (!empty(array_intersect(array_keys($_GET), ['income_start', 'income_end']))) {
        $activeTab = 'income';
    }
    if (!empty(array_intersect(array_keys($_GET), ['expense_start', 'expense_end', 'expense_category']))) {
        $activeTab = 'expense';
    }
    if (!empty(array_intersect(array_keys($_GET), ['category_start', 'category_end']))) {
        $activeTab = 'categories';
    }
}

$preservedForm = $_SESSION['form_values'] ?? null;
unset($_SESSION['form_values']);

$reopenModal = null;
if ($preservedForm) {
    $preservedType = $preservedForm['transaction_type'] ?? '';
    if (in_array($preservedType, ['income', 'expense'], true)) {
        $reopenModal = $preservedType;
    }
}

$defaultFormState = [
    'amount' => '',
    'date' => date('Y-m-d'),
    'payment_method' => '',
    'comment' => '',
    'category_id' => '',
];
$incomeForm = $defaultFormState;
$expenseForm = $defaultFormState;
if ($preservedForm && ($preservedForm['transaction_type'] ?? '') === 'income') {
    $incomeForm = array_merge($incomeForm, array_intersect_key($preservedForm, $defaultFormState));
    $activeTab = 'income';
}
if ($preservedForm && ($preservedForm['transaction_type'] ?? '') === 'expense') {
    $expenseForm = array_merge($expenseForm, array_intersect_key($preservedForm, $defaultFormState));
    $activeTab = 'expense';
}

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function uzs(float $value): string
{
    return number_format($value, 0, '.', ' ') . ' so\'m';
}

function percentage(float $part, float $whole): float
{
    if ($whole <= 0.0) {
        return 0.0;
    }

    return round(($part / $whole) * 100, 1);
}

function balance_class(float $value): string
{
    if ($value > 0) {
        return 'text-emerald-600';
    }

    if ($value < 0) {
        return 'text-rose-500';
    }

    return 'text-slate-600';
}

function renderComment(?string $comment): string
{
    if ($comment === null) {
        $comment = '';
    }

    $comment = trim($comment);
    if ($comment === '') {
        return '<span class="text-slate-400">—</span>';
    }

    $pattern = '/https?:\/\/[^\s]+/i';
    $result = '';
    $offset = 0;

    while (preg_match($pattern, $comment, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $url = $match[0][0];
        $position = $match[0][1];

        $before = substr($comment, $offset, $position - $offset);
        if ($before !== '') {
            $result .= htmlspecialchars($before, ENT_QUOTES);
        }

        $safeUrl = htmlspecialchars($url, ENT_QUOTES);
        $result .= '<span class="underline decoration-dotted text-primary-600">' . $safeUrl . '</span>';
        $result .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer" class="ml-1 inline-flex items-center text-primary-500 hover:text-primary-600" aria-label="Havolani yangi oynada ochish">↗</a>';

        $offset = $position + strlen($url);
    }

    if ($offset < strlen($comment)) {
        $result .= htmlspecialchars(substr($comment, $offset), ENT_QUOTES);
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Naqd oqim boshqaruvi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#f0f5ff',
                            100: '#e0ecff',
                            500: '#2563eb',
                            600: '#1d4ed8',
                        },
                        accent: {
                            400: '#38bdf8',
                            500: '#0ea5e9',
                        },
                    },
                    boxShadow: {
                        glow: '0 40px 60px -45px rgba(37, 99, 235, 0.65)',
                    },
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-b from-primary-50 via-white to-white text-slate-900">
    <div class="absolute inset-x-0 top-0 -z-10 h-40 bg-gradient-to-b from-white/0 via-white/60 to-white"></div>
    <header class="sticky top-0 z-30 border-b border-white/60 bg-white/80 backdrop-blur">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-500 to-accent-500 text-lg font-semibold text-white shadow-lg shadow-primary-500/40">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="h-6 w-6">
                            <path fill="currentColor" d="M4.75 4A1.75 1.75 0 0 0 3 5.75v12.5A1.75 1.75 0 0 0 4.75 20h6.5A1.75 1.75 0 0 0 13 18.25V14h6.25A1.75 1.75 0 0 0 21 12.25v-4.5A1.75 1.75 0 0 0 19.25 6H13V5.75A1.75 1.75 0 0 0 11.25 4h-6.5Zm0 1.5h6.5a.25.25 0 0 1 .25.25v12.5a.25.25 0 0 1-.25.25h-6.5a.25.25 0 0 1-.25-.25V5.75a.25.25 0 0 1 .25-.25Zm8 1.5H19.25a.25.25 0 0 1 .25.25v4.5a.25.25 0 0 1-.25.25H12.75V7Z" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.32em] text-primary-500">Naqd oqim</p>
                        <h1 class="text-xl font-semibold text-slate-900 sm:text-2xl">Moliyaviy panel</h1>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs sm:text-sm">
                    <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50/80 px-3 py-1.5 font-semibold text-emerald-700 shadow-sm shadow-emerald-100/80">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        <span>Balans: <?= uzs((float) $balance) ?></span>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/80 px-3 py-1.5 text-slate-600 shadow-sm">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="h-4 w-4 text-primary-500">
                            <path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm0 18.25A8.25 8.25 0 1 1 20.25 12 8.259 8.259 0 0 1 12 20.25Zm.75-13a.75.75 0 0 0-1.5 0v5a.75.75 0 0 0 .336.625l3 2a.75.75 0 1 0 .828-1.25L12.75 11.7Z" />
                        </svg>
                        <span>Yangilanish: <?= htmlspecialchars($currentTimeLabel, ENT_QUOTES) ?></span>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/80 px-3 py-1.5 text-slate-600 shadow-sm">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="h-4 w-4 text-primary-500">
                            <path fill="currentColor" d="M7 2.75a.75.75 0 0 0-1.5 0V4H4.75A1.75 1.75 0 0 0 3 5.75v12.5A1.75 1.75 0 0 0 4.75 20h14.5A1.75 1.75 0 0 0 21 18.25V5.75A1.75 1.75 0 0 0 19.25 4H18.5V2.75a.75.75 0 0 0-1.5 0V4H7Zm12.25 4v11.5a.25.25 0 0 1-.25.25H4.75a.25.25 0 0 1-.25-.25V6.75a.25.25 0 0 1 .25-.25h14.5a.25.25 0 0 1 .25.25ZM8 9.25A.75.75 0 0 1 8.75 8.5h6.5a.75.75 0 0 1 0 1.5h-6.5A.75.75 0 0 1 8 9.25Zm0 3a.75.75 0 0 1 .75-.75h6.5a.75.75 0 0 1 0 1.5h-6.5A.75.75 0 0 1 8 12.25Zm0 3a.75.75 0 0 1 .75-.75h4.5a.75.75 0 0 1 0 1.5h-4.5A.75.75 0 0 1 8 15.25Z" />
                        </svg>
                        <span>Bugun: <?= htmlspecialchars($currentDateLabel, ENT_QUOTES) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 pb-16 pt-10 sm:px-6 lg:px-8">

        <?php if ($flashMessage): ?>
            <?php
            $flashThemes = [
                'success' => 'border-emerald-200/70 bg-emerald-50/90 text-emerald-900 shadow-emerald-100/80',
                'danger' => 'border-rose-200/70 bg-rose-50/90 text-rose-900 shadow-rose-100/80',
            ];
            $theme = $flashThemes[$flashType] ?? $flashThemes['success'];
            ?>
            <div class="mt-6 flex items-start gap-3 rounded-3xl border px-5 py-4 shadow-sm backdrop-blur <?php echo htmlspecialchars($theme, ENT_QUOTES); ?>">
                <div class="mt-0.5 text-lg">
                    <?php if ($flashType === 'danger'): ?>
                        ⚠️
                    <?php else: ?>
                        ✅
                    <?php endif; ?>
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold"><?= htmlspecialchars($flashType === 'danger' ? 'Diqqat' : 'Muvaffaqiyat', ENT_QUOTES) ?></p>
                    <p class="text-sm leading-relaxed text-slate-700"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($filterWarnings)): ?>
            <div class="mt-6 space-y-2 rounded-3xl border border-amber-200/70 bg-amber-50/90 px-5 py-4 text-amber-900 shadow-sm shadow-amber-100/80 backdrop-blur">
                <p class="text-sm font-semibold">Filtrlash ogohlantirishlari</p>
                <ul class="list-disc space-y-1 pl-5 text-sm text-amber-700">
                    <?php foreach ($filterWarnings as $warning): ?>
                        <li><?= htmlspecialchars($warning, ENT_QUOTES) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <nav class="mt-5 flex flex-wrap items-center gap-2 rounded-2xl border border-primary-100/70 bg-gradient-to-r from-primary-50/90 via-white/70 to-accent-50/80 p-2 shadow-md shadow-primary-100/70 backdrop-blur">
            <?php
            $tabs = [
                'overview' => 'Umumiy',
                'income' => 'Daromadlar',
                'expense' => 'Xarajatlar',
                'reports' => 'Hisobotlar',
                'categories' => 'Turkumlar',
            ];
            foreach ($tabs as $key => $label):
                $active = $activeTab === $key;
            ?>
                <button data-tab-target="<?= htmlspecialchars($key, ENT_QUOTES) ?>" class="tab-trigger inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 <?php echo $active ? 'bg-primary-500 text-white shadow-md shadow-primary-500/30' : 'text-slate-600 hover:bg-slate-100'; ?>">
                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <section class="mt-8 space-y-10">
            <div data-tab-panel="overview" class="tab-panel <?= $activeTab === 'overview' ? '' : 'hidden' ?> space-y-8">
                <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <p class="text-sm text-slate-500">Umumiy daromad</p>
                        <p class="mt-2 text-2xl font-semibold text-primary-600"><?= uzs((float) $totals['total_income']) ?></p>
                        <p class="mt-4 text-xs text-slate-400">Naqd: <?= uzs((float) $totals['total_income_cash']) ?> · Click: <?= uzs((float) $totals['total_income_click']) ?></p>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <p class="text-sm text-slate-500">Umumiy xarajat</p>
                        <p class="mt-2 text-2xl font-semibold text-rose-500"><?= uzs((float) $totals['total_expense']) ?></p>
                        <p class="mt-4 text-xs text-slate-400">Naqd: <?= uzs((float) $totals['total_expense_cash']) ?> · Click: <?= uzs((float) $totals['total_expense_click']) ?></p>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <p class="text-sm text-slate-500">Joriy oy daromadi</p>
                        <p class="mt-2 text-2xl font-semibold text-emerald-600"><?= uzs((float) $totals['monthly_income']) ?></p>
                        <p class="mt-4 text-xs text-slate-400">Balans: <?= uzs((float) $monthlyBalance) ?></p>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <p class="text-sm text-slate-500">Umumiy balans</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900"><?= uzs((float) $balance) ?></p>
                        <p class="mt-4 text-xs text-slate-400">Eng ko'p xarajat turi: <?= htmlspecialchars($topCategory['name'], ENT_QUOTES) ?></p>
                    </div>
                </div>
                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <h2 class="text-lg font-semibold text-slate-900">Daromadlar taqsimoti</h2>
                        <p class="text-sm text-slate-500">Naqd va Click ulushi</p>
                        <canvas id="incomeMethodChart" class="mt-6 h-56 w-full"></canvas>
                        <div class="mt-5 space-y-2 text-sm text-slate-500">
                            <div class="flex items-center justify-between">
                                <span>Naqd</span>
                                <span class="font-semibold text-slate-900">
                                    <?= uzs($incomeTotals['cash']) ?>
                                    <span class="ml-1 text-xs text-slate-400"><?= number_format($incomePercentages['cash'], 1, ',', ' ') ?>%</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Click</span>
                                <span class="font-semibold text-slate-900">
                                    <?= uzs($incomeTotals['click']) ?>
                                    <span class="ml-1 text-xs text-slate-400"><?= number_format($incomePercentages['click'], 1, ',', ' ') ?>%</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-slate-600">
                                <span>Jami</span>
                                <span class="font-semibold text-slate-900"><?= uzs($incomeTotals['total']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <h2 class="text-lg font-semibold text-slate-900">Xarajatlar taqsimoti</h2>
                        <p class="text-sm text-slate-500">Naqd va Click ulushi</p>
                        <canvas id="expenseMethodChart" class="mt-6 h-56 w-full"></canvas>
                        <div class="mt-5 space-y-2 text-sm text-slate-500">
                            <div class="flex items-center justify-between">
                                <span>Naqd</span>
                                <span class="font-semibold text-slate-900">
                                    <?= uzs($expenseTotals['cash']) ?>
                                    <span class="ml-1 text-xs text-slate-400"><?= number_format($expensePercentages['cash'], 1, ',', ' ') ?>%</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Click</span>
                                <span class="font-semibold text-slate-900">
                                    <?= uzs($expenseTotals['click']) ?>
                                    <span class="ml-1 text-xs text-slate-400"><?= number_format($expensePercentages['click'], 1, ',', ' ') ?>%</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-slate-600">
                                <span>Jami</span>
                                <span class="font-semibold text-slate-900"><?= uzs($expenseTotals['total']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                        <h2 class="text-lg font-semibold text-slate-900">Turkumlar kesimi</h2>
                        <p class="text-sm text-slate-500">Qaysi yo'nalishlarga ko'proq xarajat qilinyapti</p>
                        <canvas id="expenseCategoryChart" data-has-data="<?= $categoryChartHasData ? '1' : '0' ?>" class="mt-6 h-56 w-full <?= $categoryChartHasData ? '' : 'hidden' ?>"></canvas>
                        <?php if (!$categoryChartHasData): ?>
                            <p class="mt-6 text-sm text-slate-500">Hozircha turkumlar bo'yicha xarajat ma'lumotlari mavjud emas.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div data-tab-panel="income" class="tab-panel <?= $activeTab === 'income' ? '' : 'hidden' ?> space-y-8">
                <div class="rounded-3xl border border-primary-100/70 bg-primary-50/80 p-6 shadow-lg shadow-primary-200/40">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-primary-700">Daromadlar oynasi</h2>
                            <p class="text-sm text-primary-600/80">Tanlangan davr bo'yicha daromadlar va to'lov usullari</p>
                        </div>
                        <div class="text-sm text-primary-700/80">
                            <p>Jami: <span class="font-semibold text-primary-800"><?= uzs($incomeTotals['total']) ?></span></p>
                            <p class="mt-1">Naqd: <?= uzs($incomeTotals['cash']) ?> · Click: <?= uzs($incomeTotals['click']) ?></p>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70 space-y-6">
                    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Daromadlar ro'yxati</h2>
                            <p class="text-sm text-slate-500">Tanlangan sana oralig'i uchun barcha daromadlar</p>
                        </div>
                        <form method="get" class="grid gap-3 text-sm md:grid-cols-4">
                            <input type="hidden" name="tab" value="income">
                            <div>
                                <label class="text-slate-600" for="income-start">Boshlanish</label>
                                <input id="income-start" type="date" name="income_start" value="<?= htmlspecialchars($incomeRange['start'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div>
                                <label class="text-slate-600" for="income-end">Tugash</label>
                                <input id="income-end" type="date" name="income_end" value="<?= htmlspecialchars($incomeRange['end'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div class="md:col-span-2 flex items-end gap-3">
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-600 transition hover:bg-slate-100">Filtrlash</button>
                                <a href="index.php?tab=income" class="inline-flex items-center justify-center rounded-xl border border-transparent bg-slate-100 px-4 py-2 font-semibold text-slate-500 transition hover:bg-slate-200">Tozalash</a>
                            </div>
                        </form>
                    </div>

                    <div class="flex flex-wrap gap-6">
                        <div class="rounded-2xl border border-primary-100 bg-primary-50/80 px-5 py-4 text-primary-700">
                            <p class="text-xs uppercase tracking-wide">Jami</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($incomeTotals['total']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/80 px-5 py-4 text-emerald-700">
                            <p class="text-xs uppercase tracking-wide">Naqd</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($incomeTotals['cash']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-sky-100 bg-sky-50/80 px-5 py-4 text-sky-700">
                            <p class="text-xs uppercase tracking-wide">Click</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($incomeTotals['click']) ?></p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Sana</th>
                                    <th class="px-4 py-3">Summa</th>
                                    <th class="px-4 py-3">Usul</th>
                                    <th class="px-4 py-3">Izoh</th>
                                    <th class="px-4 py-3 text-right">Amallar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/70">
                                <?php if (empty($incomeRecords)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Ushbu davr uchun daromad topilmadi.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($incomeRecords as $income): ?>
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-slate-700"><?= htmlspecialchars($income['date'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-primary-600 font-semibold"><?= uzs($income['payment']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($income['method'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-slate-500"><?= renderComment($income['comment'] ?? '') ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="edit-income inline-flex items-center rounded-lg border border-primary-200 px-3 py-1.5 text-xs font-semibold text-primary-600 transition hover:bg-primary-50" data-transaction='<?= json_encode([
                                                        'id' => $income['id'],
                                                        'amount' => $income['payment'],
                                                        'date' => $income['date'],
                                                        'method' => $income['cash'] == 1 ? 'cash' : 'click',
                                                        'comment' => $income['comment'] ?? '',
                                                    ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?>'>Tahrirlash</button>
                                                    <form method="post" action="manage_transaction.php" onsubmit="return confirm('Daromadni o\'chirmoqchimisiz?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="transaction_type" value="income">
                                                        <input type="hidden" name="transaction_id" value="<?= (int) $income['id'] ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-500 transition hover:bg-rose-50">O'chirish</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="button" data-open-modal="#incomeCreateModal" class="open-income-create fixed bottom-24 right-6 z-30 inline-flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-accent-500 text-2xl font-semibold text-white shadow-lg shadow-primary-500/40 transition hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                    <span aria-hidden="true">+</span>
                    <span class="sr-only">Yangi daromad qo'shish</span>
                </button>
            </div>

            <div data-tab-panel="expense" class="tab-panel <?= $activeTab === 'expense' ? '' : 'hidden' ?> space-y-8">
                <div class="rounded-3xl border border-rose-100/70 bg-rose-50/80 p-6 shadow-lg shadow-rose-200/40">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-rose-600">Xarajatlar oynasi</h2>
                            <p class="text-sm text-rose-500/80">Davr ichida qilingan xarajatlar va turkumlar</p>
                        </div>
                        <div class="text-sm text-rose-600/80">
                            <p>Jami: <span class="font-semibold text-rose-700"><?= uzs($expenseTotals['total']) ?></span></p>
                            <p class="mt-1">Naqd: <?= uzs($expenseTotals['cash']) ?> · Click: <?= uzs($expenseTotals['click']) ?></p>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70 space-y-6">
                    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Xarajatlar ro'yxati</h2>
                            <p class="text-sm text-slate-500">Tanlangan sana oralig'i va turkum bo'yicha xarajatlar</p>
                        </div>
                        <form method="get" class="grid gap-3 text-sm md:grid-cols-5">
                            <input type="hidden" name="tab" value="expense">
                            <div>
                                <label class="text-slate-600" for="expense-start">Boshlanish</label>
                                <input id="expense-start" type="date" name="expense_start" value="<?= htmlspecialchars($expenseRange['start'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div>
                                <label class="text-slate-600" for="expense-end">Tugash</label>
                                <input id="expense-end" type="date" name="expense_end" value="<?= htmlspecialchars($expenseRange['end'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div>
                                <label class="text-slate-600" for="expense-cat-filter">Turkum</label>
                                <select id="expense-cat-filter" name="expense_category" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                                    <option value="0">Barchasi</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int) $category['id'] ?>" <?= $selectedExpenseCategory === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2 flex items-end gap-3">
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-600 transition hover:bg-slate-100">Filtrlash</button>
                                <a href="index.php?tab=expense" class="inline-flex items-center justify-center rounded-xl border border-transparent bg-slate-100 px-4 py-2 font-semibold text-slate-500 transition hover:bg-slate-200">Tozalash</a>
                            </div>
                        </form>
                    </div>

                    <div class="flex flex-wrap gap-6">
                        <div class="rounded-2xl border border-rose-100 bg-rose-50/80 px-5 py-4 text-rose-600">
                            <p class="text-xs uppercase tracking-wide">Jami</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($expenseTotals['total']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50/80 px-5 py-4 text-amber-700">
                            <p class="text-xs uppercase tracking-wide">Naqd</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($expenseTotals['cash']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-sky-100 bg-sky-50/80 px-5 py-4 text-sky-700">
                            <p class="text-xs uppercase tracking-wide">Click</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($expenseTotals['click']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-5 py-4 text-slate-700">
                            <p class="text-xs uppercase tracking-wide">Eng ko'p xarajat</p>
                            <p class="mt-1 text-lg font-semibold"><?= htmlspecialchars($topCategory['name'], ENT_QUOTES) ?></p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Sana</th>
                                    <th class="px-4 py-3">Summa</th>
                                    <th class="px-4 py-3">Turkum</th>
                                    <th class="px-4 py-3">Usul</th>
                                    <th class="px-4 py-3">Izoh</th>
                                    <th class="px-4 py-3 text-right">Amallar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/70">
                                <?php if (empty($expenseRecords)): ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">Ushbu davr uchun xarajat topilmadi.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($expenseRecords as $expense): ?>
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-slate-700"><?= htmlspecialchars($expense['date'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-rose-500 font-semibold"><?= uzs($expense['payment']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($expense['category_name'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($expense['method'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-slate-500"><?= renderComment($expense['comment'] ?? '') ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="edit-expense inline-flex items-center rounded-lg border border-primary-200 px-3 py-1.5 text-xs font-semibold text-primary-600 transition hover:bg-primary-50" data-transaction='<?= json_encode([
                                                        'id' => $expense['id'],
                                                        'amount' => $expense['payment'],
                                                        'date' => $expense['date'],
                                                        'method' => $expense['cash'] == 1 ? 'cash' : 'click',
                                                        'comment' => $expense['comment'] ?? '',
                                                        'category_id' => $expense['category_id'],
                                                    ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?>'>Tahrirlash</button>
                                                    <form method="post" action="manage_transaction.php" onsubmit="return confirm('Xarajatni o\'chirmoqchimisiz?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="transaction_type" value="expense">
                                                        <input type="hidden" name="transaction_id" value="<?= (int) $expense['id'] ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-500 transition hover:bg-rose-50">O'chirish</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="button" data-open-modal="#expenseCreateModal" class="open-expense-create fixed bottom-24 right-6 z-30 inline-flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-rose-500 to-primary-500 text-2xl font-semibold text-white shadow-lg shadow-rose-400/40 transition hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500">
                    <span aria-hidden="true">+</span>
                    <span class="sr-only">Yangi xarajat qo'shish</span>
                </button>
            </div>

            <div data-tab-panel="reports" class="tab-panel <?= $activeTab === 'reports' ? '' : 'hidden' ?> space-y-8">
                <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70 space-y-6">
                    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Davr bo'yicha hisobot</h2>
                            <p class="text-sm text-slate-500">Oyma-oy balans va to'lov usullari tahlili</p>
                        </div>
                        <form method="get" class="grid gap-3 text-sm md:grid-cols-5">
                            <input type="hidden" name="tab" value="reports">
                            <div>
                                <label class="text-slate-600" for="report-start">Boshlanish</label>
                                <input id="report-start" type="date" name="start_date" value="<?= htmlspecialchars($reportRange['start'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div>
                                <label class="text-slate-600" for="report-end">Tugash</label>
                                <input id="report-end" type="date" name="end_date" value="<?= htmlspecialchars($reportRange['end'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            </div>
                            <div class="md:col-span-2 flex items-end gap-3">
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-600 transition hover:bg-slate-100">Filtrlash</button>
                                <a href="index.php?tab=reports" class="inline-flex items-center justify-center rounded-xl border border-transparent bg-slate-100 px-4 py-2 font-semibold text-slate-500 transition hover:bg-slate-200">Tozalash</a>
                            </div>
                            <div class="flex items-end justify-end gap-2">
                                <button type="button" data-range="month" class="quick-range rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-600 transition hover:bg-primary-100">Joriy oy</button>
                                <button type="button" data-range="quarter" class="quick-range rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-600 transition hover:bg-primary-100">Oxirgi 3 oy</button>
                                <button type="button" data-range="year" class="quick-range rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-600 transition hover:bg-primary-100">Yil boshidan</button>
                            </div>
                        </form>
                    </div>

                    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-5">
                        <div class="rounded-2xl border border-primary-100 bg-primary-50/80 px-5 py-4 text-primary-700">
                            <p class="text-xs uppercase tracking-wide">Davr daromadi</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($rangeSummary['income']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-rose-100 bg-rose-50/80 px-5 py-4 text-rose-600">
                            <p class="text-xs uppercase tracking-wide">Davr xarajati</p>
                            <p class="mt-1 text-lg font-semibold"><?= uzs($rangeSummary['expense']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-emerald-100/70 bg-emerald-50/80 px-5 py-4">
                            <p class="text-xs uppercase tracking-wide text-emerald-600">Naqd qoldiq</p>
                            <p class="mt-1 text-lg font-semibold <?= balance_class($rangeSummary['cash_balance']) ?>"><?= uzs($rangeSummary['cash_balance']) ?></p>
                            <p class="mt-2 text-xs text-slate-500">Daromad: <?= uzs($rangeSummary['income_cash']) ?> · Xarajat: <?= uzs($rangeSummary['expense_cash']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-sky-100/70 bg-sky-50/80 px-5 py-4">
                            <p class="text-xs uppercase tracking-wide text-sky-600">Click qoldiq</p>
                            <p class="mt-1 text-lg font-semibold <?= balance_class($rangeSummary['click_balance']) ?>"><?= uzs($rangeSummary['click_balance']) ?></p>
                            <p class="mt-2 text-xs text-slate-500">Daromad: <?= uzs($rangeSummary['income_click']) ?> · Xarajat: <?= uzs($rangeSummary['expense_click']) ?></p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white/80 px-5 py-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Umumiy balans</p>
                            <p class="mt-1 text-lg font-semibold <?= balance_class($rangeSummary['balance']) ?>"><?= uzs($rangeSummary['balance']) ?></p>
                            <p class="mt-2 text-xs text-slate-500">Naqd + Click qoldiqlari yig'indisi</p>
                        </div>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                            <h3 class="text-base font-semibold text-slate-900">Oylik balans</h3>
                            <canvas id="balanceTrendChart" class="mt-6 h-64 w-full"></canvas>
                        </div>
                        <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70">
                            <h3 class="text-base font-semibold text-slate-900">Turkumlar bo'yicha xarajat</h3>
                            <p class="text-sm text-slate-500">Eng katta xarajat: <?= htmlspecialchars($topCategory['name'], ENT_QUOTES) ?> (<?= uzs($topCategory['total']) ?>)</p>
                            <canvas id="categoryChart" class="mt-6 h-64 w-full"></canvas>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Oy</th>
                                    <th class="px-4 py-3">Daromad</th>
                                    <th class="px-4 py-3">Xarajat</th>
                                    <th class="px-4 py-3">Balans</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/70">
                                <?php foreach (array_reverse($reportTable) as $row): ?>
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-700"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></td>
                                        <td class="px-4 py-3 text-primary-600 font-semibold"><?= uzs($row['income']) ?></td>
                                        <td class="px-4 py-3 text-rose-500 font-semibold"><?= uzs($row['expense']) ?></td>
                                        <td class="px-4 py-3 text-slate-600 font-semibold"><?= uzs($row['balance']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div data-tab-panel="categories" class="tab-panel <?= $activeTab === 'categories' ? '' : 'hidden' ?> space-y-8">
                <div class="rounded-3xl border border-white/80 bg-white/90 p-6 shadow-lg shadow-slate-200/70 space-y-6">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-slate-900">Xarajat turlari</h2>
                        <p class="text-sm text-slate-500">Turkumlar bo'yicha xarajatlaringizni boshqaring</p>
                    </div>
                    <form method="get" class="grid gap-3 text-sm md:grid-cols-5">
                        <input type="hidden" name="tab" value="categories">
                        <div>
                            <label class="text-slate-600" for="category-start">Boshlanish</label>
                            <input id="category-start" type="date" name="category_start" value="<?= htmlspecialchars($categoryRange['start'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                        </div>
                        <div>
                            <label class="text-slate-600" for="category-end">Tugash</label>
                            <input id="category-end" type="date" name="category_end" value="<?= htmlspecialchars($categoryRange['end'], ENT_QUOTES) ?>" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                        </div>
                        <div class="md:col-span-2 flex items-end gap-3">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-600 transition hover:bg-slate-100">Filtrlash</button>
                            <a href="index.php?tab=categories" class="inline-flex items-center justify-center rounded-xl border border-transparent bg-slate-100 px-4 py-2 font-semibold text-slate-500 transition hover:bg-slate-200">Tozalash</a>
                        </div>
                        <div class="md:col-span-1 flex items-end text-xs text-slate-400 md:justify-end">
                            <span>Davr: <?= htmlspecialchars($categoryRangeLabelStart, ENT_QUOTES) ?> — <?= htmlspecialchars($categoryRangeLabelEnd, ENT_QUOTES) ?></span>
                        </div>
                    </form>
                    <form action="manage_category.php" method="post" class="grid gap-3 md:grid-cols-12">
                        <input type="hidden" name="action" value="create">
                        <div class="md:col-span-9">
                            <label class="text-sm font-medium text-slate-600" for="new-category">Yangi turkum nomi</label>
                            <input id="new-category" name="name" required class="mt-1 block w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" placeholder="Masalan, Transport">
                        </div>
                        <div class="md:col-span-3 flex items-end">
                            <button type="submit" class="w-full rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 transition hover:bg-primary-600">Qo'shish</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Nomi</th>
                                    <th class="px-4 py-3">Xarajatlar soni</th>
                                    <th class="px-4 py-3">
                                        <span>Jami xarajat</span>
                                        <span class="mt-1 block text-[0.65rem] font-normal uppercase tracking-wide text-slate-400">(<?= htmlspecialchars($categoryRangeLabelStart, ENT_QUOTES) ?> — <?= htmlspecialchars($categoryRangeLabelEnd, ENT_QUOTES) ?>)</span>
                                    </th>
                                    <th class="px-4 py-3 text-right">Amallar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/70">
                                <?php if (empty($categoryStats)): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">Hali turkumlar yaratilmagan.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categoryStats as $category): ?>
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-slate-700"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= (int) $category['expense_count'] ?></td>
                                            <td class="px-4 py-3 text-slate-600 font-semibold"><?= uzs($category['total_spent']) ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="edit-category inline-flex items-center rounded-lg border border-primary-200 px-3 py-1.5 text-xs font-semibold text-primary-600 transition hover:bg-primary-50" data-category='<?= json_encode([
                                                        'id' => $category['id'],
                                                        'name' => $category['name'],
                                                    ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?>'>Tahrirlash</button>
                                                    <form action="manage_category.php" method="post" onsubmit="return confirm('Turkumni o\'chirmoqchimisiz?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-500 transition hover:bg-rose-50">O'chirish</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <div id="incomeCreateModal" class="modal fixed inset-0 z-40 hidden flex items-center justify-center bg-slate-900/40 px-4 py-8">
        <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Yangi daromad</h3>
                <button type="button" class="close-modal text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="post" action="save_transaction.php" class="mt-4 space-y-4" data-currency-form>
                <input type="hidden" name="transaction_type" value="income">
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-create-amount">Summa</label>
                    <input id="income-create-amount" name="amount" type="text" inputmode="numeric" autocomplete="off" value="<?= htmlspecialchars($incomeForm['amount'], ENT_QUOTES) ?>" required data-currency-input class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-create-date">Sana</label>
                    <input id="income-create-date" name="date" type="date" value="<?= htmlspecialchars($incomeForm['date'], ENT_QUOTES) ?>" required class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div class="flex gap-4">
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="cash" <?= $incomeForm['payment_method'] === 'cash' ? 'checked' : '' ?> required class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Naqd
                    </label>
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="click" <?= $incomeForm['payment_method'] === 'click' ? 'checked' : '' ?> required class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Click
                    </label>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-create-comment">Izoh</label>
                    <textarea id="income-create-comment" name="comment" rows="2" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" placeholder="Masalan, xizmat uchun to'lov"><?= htmlspecialchars($incomeForm['comment'], ENT_QUOTES) ?></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="close-modal inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="incomeModal" class="modal fixed inset-0 z-40 hidden flex items-center justify-center bg-slate-900/40 px-4 py-8">
        <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Daromadni tahrirlash</h3>
                <button type="button" class="close-modal text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="post" action="manage_transaction.php" class="mt-4 space-y-4" data-currency-form>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="transaction_type" value="income">
                <input type="hidden" name="transaction_id" id="income-edit-id">
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-edit-amount">Summa</label>
                    <input id="income-edit-amount" name="amount" type="text" inputmode="numeric" required data-currency-input class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-edit-date">Sana</label>
                    <input id="income-edit-date" name="date" type="date" required class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div class="flex gap-4">
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="cash" class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Naqd
                    </label>
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="click" class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Click
                    </label>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="income-edit-comment">Izoh</label>
                    <textarea id="income-edit-comment" name="comment" rows="2" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="close-modal inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="expenseCreateModal" class="modal fixed inset-0 z-40 hidden flex items-center justify-center bg-slate-900/40 px-4 py-8">
        <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Yangi xarajat</h3>
                <button type="button" class="close-modal text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="post" action="save_transaction.php" class="mt-4 space-y-4" data-currency-form>
                <input type="hidden" name="transaction_type" value="expense">
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-create-amount">Summa</label>
                    <input id="expense-create-amount" name="amount" type="text" inputmode="numeric" autocomplete="off" value="<?= htmlspecialchars($expenseForm['amount'], ENT_QUOTES) ?>" required data-currency-input class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-create-date">Sana</label>
                    <input id="expense-create-date" name="date" type="date" value="<?= htmlspecialchars($expenseForm['date'], ENT_QUOTES) ?>" required class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div class="flex gap-4">
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="cash" <?= $expenseForm['payment_method'] === 'cash' ? 'checked' : '' ?> required class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Naqd
                    </label>
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="click" <?= $expenseForm['payment_method'] === 'click' ? 'checked' : '' ?> required class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Click
                    </label>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-create-category">Turkum</label>
                    <div class="mt-1 flex gap-2">
                        <select id="expense-create-category" name="category_id" class="w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                            <option value="">— Tanlanmagan —</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= $expenseForm['category_id'] == $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="open-category-modal inline-flex items-center justify-center rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 text-sm font-semibold text-primary-600 transition hover:bg-primary-100" data-close-parent="true">+</button>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-create-comment">Izoh</label>
                    <textarea id="expense-create-comment" name="comment" rows="2" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" placeholder="Masalan, ofis uchun sarf"><?= htmlspecialchars($expenseForm['comment'], ENT_QUOTES) ?></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="close-modal inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="expenseModal" class="modal fixed inset-0 z-40 hidden flex items-center justify-center bg-slate-900/40 px-4 py-8">
        <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Xarajatni tahrirlash</h3>
                <button type="button" class="close-modal text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="post" action="manage_transaction.php" class="mt-4 space-y-4" data-currency-form>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="transaction_type" value="expense">
                <input type="hidden" name="transaction_id" id="expense-edit-id">
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-edit-amount">Summa</label>
                    <input id="expense-edit-amount" name="amount" type="text" inputmode="numeric" required data-currency-input class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-edit-date">Sana</label>
                    <input id="expense-edit-date" name="date" type="date" required class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div class="flex gap-4">
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="cash" class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Naqd
                    </label>
                    <label class="inline-flex flex-1 items-center gap-2 text-sm text-slate-600">
                        <input type="radio" name="payment_method" value="click" class="rounded border-slate-300 text-primary-500 focus:ring-primary-500"> Click
                    </label>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-edit-category">Turkum</label>
                    <select id="expense-edit-category" name="category_id" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                        <option value="0">— Tanlanmagan —</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-600" for="expense-edit-comment">Izoh</label>
                    <textarea id="expense-edit-comment" name="comment" rows="2" class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="close-modal inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <div id="categoryModal" class="modal fixed inset-0 z-40 hidden flex items-center justify-center bg-slate-900/40 px-4 py-8">
        <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 id="category-modal-title" class="text-lg font-semibold text-slate-900">Turkumni tahrirlash</h3>
                <button type="button" class="close-modal text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="post" action="manage_category.php" class="mt-4 space-y-4">
                <input type="hidden" name="action" id="category-modal-action" value="update">
                <input type="hidden" name="category_id" id="category-edit-id">
                <div>
                    <label class="text-sm font-medium text-slate-600" for="category-edit-name">Turkum nomi</label>
                    <input id="category-edit-name" name="name" required class="mt-1 w-full rounded-xl border-slate-200 bg-white/80 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="close-modal inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">Bekor qilish</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-primary-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600">Saqlash</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const formatCurrencyValue = (value) => {
            const digits = String(value ?? '').replace(/[^\d]/g, '');
            if (!digits) {
                return '';
            }
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        };

        const normalizeCurrencyValue = (value) => String(value ?? '').replace(/[^\d]/g, '');

        const applyCurrencyMask = (input) => {
            const update = () => {
                input.value = formatCurrencyValue(input.value);
            };
            update();
            input.addEventListener('input', update);
            input.addEventListener('blur', update);
        };

        const showModal = (selector) => {
            if (!selector) {
                return;
            }
            const modal = document.querySelector(selector);
            if (!modal) {
                return;
            }
            modal.classList.remove('hidden');
            const focusTarget = modal.querySelector('[data-currency-input], input:not([type="hidden"]), textarea, select');
            if (focusTarget) {
                setTimeout(() => focusTarget.focus(), 0);
            }
        };

        const tabButtons = document.querySelectorAll('[data-tab-target]');
        const filterMap = {
            reports: ['start_date', 'end_date'],
            income: ['income_start', 'income_end'],
            expense: ['expense_start', 'expense_end', 'expense_category'],
        };
        const allFilterKeys = Array.from(new Set(Object.values(filterMap).flat()));
        tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-tab-target');
                const url = new URL(window.location);
                url.searchParams.set('tab', target);
                const allowedFilters = new Set(filterMap[target] ?? []);
                allFilterKeys.forEach((key) => {
                    if (!allowedFilters.has(key)) {
                        url.searchParams.delete(key);
                    }
                });
                window.location.href = url.toString();
            });
        });

        document.querySelectorAll('[data-open-modal]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetSelector = btn.getAttribute('data-open-modal');
                if (btn.dataset.closeParent === 'true') {
                    const parentModal = btn.closest('.modal');
                    if (parentModal) {
                        parentModal.classList.add('hidden');
                    }
                }
                showModal(targetSelector);
            });
        });

        const modals = document.querySelectorAll('.modal');
        modals.forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                }
            });
        });

        document.querySelectorAll('.close-modal').forEach((btn) => {
            btn.addEventListener('click', () => {
                btn.closest('.modal').classList.add('hidden');
            });
        });

        document.querySelectorAll('[data-currency-input]').forEach(applyCurrencyMask);
        document.querySelectorAll('form[data-currency-form]').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('[data-currency-input]').forEach((input) => {
                    input.value = normalizeCurrencyValue(input.value);
                });
            });
        });

        const reopenModal = '<?= $reopenModal ?? '' ?>';
        if (reopenModal === 'income') {
            showModal('#incomeCreateModal');
        } else if (reopenModal === 'expense') {
            showModal('#expenseCreateModal');
        }

        document.querySelectorAll('.edit-income').forEach((btn) => {
            btn.addEventListener('click', () => {
                const data = JSON.parse(btn.getAttribute('data-transaction'));
                document.getElementById('income-edit-id').value = data.id;
                const amountInput = document.getElementById('income-edit-amount');
                amountInput.value = formatCurrencyValue(data.amount);
                document.getElementById('income-edit-date').value = data.date;
                document.getElementById('income-edit-comment').value = data.comment || '';
                document.querySelectorAll('#incomeModal input[name="payment_method"]').forEach((input) => {
                    input.checked = input.value === data.method;
                });
                showModal('#incomeModal');
            });
        });

        document.querySelectorAll('.edit-expense').forEach((btn) => {
            btn.addEventListener('click', () => {
                const data = JSON.parse(btn.getAttribute('data-transaction'));
                document.getElementById('expense-edit-id').value = data.id;
                const amountInput = document.getElementById('expense-edit-amount');
                amountInput.value = formatCurrencyValue(data.amount);
                document.getElementById('expense-edit-date').value = data.date;
                document.getElementById('expense-edit-comment').value = data.comment || '';
                document.querySelectorAll('#expenseModal input[name="payment_method"]').forEach((input) => {
                    input.checked = input.value === data.method;
                });
                const categorySelect = document.getElementById('expense-edit-category');
                if (categorySelect) {
                    categorySelect.value = data.category_id ? data.category_id : '0';
                }
                showModal('#expenseModal');
            });
        });

        const categoryModalAction = document.getElementById('category-modal-action');
        const categoryModalTitle = document.getElementById('category-modal-title');

        document.querySelectorAll('.edit-category').forEach((btn) => {
            btn.addEventListener('click', () => {
                const data = JSON.parse(btn.getAttribute('data-category'));
                document.getElementById('category-edit-id').value = data.id;
                document.getElementById('category-edit-name').value = data.name;
                if (categoryModalAction) {
                    categoryModalAction.value = 'update';
                }
                if (categoryModalTitle) {
                    categoryModalTitle.textContent = 'Turkumni tahrirlash';
                }
                showModal('#categoryModal');
            });
        });

        document.querySelectorAll('.open-category-modal').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.getElementById('category-edit-id').value = '';
                document.getElementById('category-edit-name').value = '';
                if (categoryModalAction) {
                    categoryModalAction.value = 'create';
                }
                if (categoryModalTitle) {
                    categoryModalTitle.textContent = 'Yangi turkum qo\'shish';
                }
                if (btn.dataset.closeParent === 'true') {
                    const parentModal = btn.closest('.modal');
                    if (parentModal) {
                        parentModal.classList.add('hidden');
                    }
                }
                showModal('#categoryModal');
            });
        });

        const quickRangeButtons = document.querySelectorAll('.quick-range');
        quickRangeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const range = btn.getAttribute('data-range');
                const startInput = document.getElementById('report-start');
                const endInput = document.getElementById('report-end');
                const today = new Date();
                let start = new Date();

                if (range === 'month') {
                    start = new Date(today.getFullYear(), today.getMonth(), 1);
                } else if (range === 'quarter') {
                    start = new Date(today.getFullYear(), today.getMonth() - 2, 1);
                } else if (range === 'year') {
                    start = new Date(today.getFullYear(), 0, 1);
                }

                const format = (date) => date.toISOString().slice(0, 10);
                startInput.value = format(start);
                endInput.value = format(today);
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal').forEach((modal) => modal.classList.add('hidden'));
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const incomeMethodCtx = document.getElementById('incomeMethodChart');
            if (incomeMethodCtx) {
                new Chart(incomeMethodCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Naqd', 'Click'],
                        datasets: [{
                            data: [<?= (float) $incomeTotals['cash'] ?>, <?= (float) $incomeTotals['click'] ?>],
                            backgroundColor: ['#38bdf8', '#2563eb'],
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                        },
                    },
                });
            }

            const expenseMethodCtx = document.getElementById('expenseMethodChart');
            if (expenseMethodCtx) {
                new Chart(expenseMethodCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Naqd', 'Click'],
                        datasets: [{
                            data: [<?= (float) $expenseTotals['cash'] ?>, <?= (float) $expenseTotals['click'] ?>],
                            backgroundColor: ['#f59e0b', '#f97316'],
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                        },
                    },
                });
            }

            const expenseCategoryCtx = document.getElementById('expenseCategoryChart');
            if (expenseCategoryCtx && expenseCategoryCtx.dataset.hasData === '1') {
                const categoryLabels = <?= json_encode($categoryChartLabels, JSON_UNESCAPED_UNICODE) ?>;
                const categoryValues = <?= json_encode($categoryChartTotals, JSON_UNESCAPED_UNICODE) ?>;
                const palette = ['#2563eb', '#38bdf8', '#f97316', '#f59e0b', '#16a34a', '#f43f5e', '#8b5cf6', '#ec4899', '#14b8a6', '#6366f1'];
                const backgroundColors = categoryLabels.map((_, index) => palette[index % palette.length]);
                new Chart(expenseCategoryCtx, {
                    type: 'pie',
                    data: {
                        labels: categoryLabels,
                        datasets: [{
                            data: categoryValues,
                            backgroundColor: backgroundColors,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                        },
                    },
                });
            }

            const balanceCtx = document.getElementById('balanceTrendChart');
            if (balanceCtx) {
                new Chart(balanceCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($reportLabels) ?>,
                        datasets: [
                            {
                                label: 'Daromad',
                                data: <?= json_encode($reportIncome) ?>,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37, 99, 235, 0.15)',
                                tension: 0.4,
                                fill: true,
                            },
                            {
                                label: 'Xarajat',
                                data: <?= json_encode($reportExpense) ?>,
                                borderColor: '#f97316',
                                backgroundColor: 'rgba(249, 115, 22, 0.15)',
                                tension: 0.4,
                                fill: true,
                            },
                            {
                                label: 'Balans',
                                data: <?= json_encode($reportBalance) ?>,
                                borderColor: '#16a34a',
                                backgroundColor: 'rgba(22, 163, 74, 0.12)',
                                tension: 0.4,
                                fill: true,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (value) => value.toLocaleString('uz-UZ'),
                                },
                            },
                        },
                    },
                });
            }

            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_column($categoryBreakdown, 'name')) ?>,
                        datasets: [{
                            label: 'Xarajat',
                            data: <?= json_encode(array_column($categoryBreakdown, 'total')) ?>,
                            backgroundColor: '#2563eb',
                            borderRadius: 8,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (value) => value.toLocaleString('uz-UZ'),
                                },
                            },
                        },
                    },
                });
            }
        });
    </script>
</body>
</html>
