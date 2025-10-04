<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

require_once '../db.php';

$filterWarnings = [];

$defaultStartDate = (new DateTime('first day of -11 months'))->format('Y-m-d');
$defaultEndDate = date('Y-m-d');

$rawStart = $_GET['start_date'] ?? $defaultStartDate;
$rawEnd = $_GET['end_date'] ?? $defaultEndDate;

$startDateTime = DateTime::createFromFormat('Y-m-d', $rawStart) ?: null;
if (!$startDateTime) {
    $filterWarnings[] = 'The start date was invalid, so the default range has been applied.';
    $startDateTime = new DateTime($defaultStartDate);
    $rawStart = $startDateTime->format('Y-m-d');
}

$endDateTime = DateTime::createFromFormat('Y-m-d', $rawEnd) ?: null;
if (!$endDateTime) {
    $filterWarnings[] = 'The end date was invalid, so today is used instead.';
    $endDateTime = new DateTime($defaultEndDate);
    $rawEnd = $endDateTime->format('Y-m-d');
}

if ($startDateTime > $endDateTime) {
    $filterWarnings[] = 'The start date was later than the end date, so the values were swapped.';
    [$startDateTime, $endDateTime] = [$endDateTime, $startDateTime];
    $rawStart = $startDateTime->format('Y-m-d');
    $rawEnd = $endDateTime->format('Y-m-d');
}

$reportStart = $startDateTime->format('Y-m-d');
$reportEnd = $endDateTime->format('Y-m-d');
$reportRangeLabel = $startDateTime->format('M j, Y') . ' – ' . $endDateTime->format('M j, Y');
if ($startDateTime->format('Y-m-d') === $endDateTime->format('Y-m-d')) {
    $reportRangeLabel = $startDateTime->format('M j, Y');
}

$reportStartInput = $rawStart;
$reportEndInput = $rawEnd;

$quickRanges = [
    [
        'label' => 'This Month',
        'start' => date('Y-m-01'),
        'end' => date('Y-m-d'),
    ],
    [
        'label' => 'Last 3 Months',
        'start' => (new DateTime('first day of -2 months'))->format('Y-m-01'),
        'end' => date('Y-m-d'),
    ],
    [
        'label' => 'Year to Date',
        'start' => date('Y-01-01'),
        'end' => date('Y-m-d'),
    ],
    [
        'label' => 'Last 12 Months',
        'start' => (new DateTime('first day of -11 months'))->format('Y-m-01'),
        'end' => date('Y-m-d'),
    ],
];

$dbErrors = [];

// Fetch aggregated totals
$totalsSql = "SELECT
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND cash = 1 THEN payment END), 0) AS total_income_cash,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND click = 1 THEN payment END), 0) AS total_income_click,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND cash = 1 THEN payment END), 0) AS total_expense_cash,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND click = 1 THEN payment END), 0) AS total_expense_click,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_expense,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND cash = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_income_cash,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND click = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_income_click,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND cash = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_expense_cash,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND click = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_expense_click
    FROM transactions";
$totalsResult = $conn->query($totalsSql);
if ($totalsResult instanceof mysqli_result) {
    $totals = $totalsResult->fetch_assoc();
    $totalsResult->free();
} else {
    $totals = [
        'total_income' => 0,
        'total_expense' => 0,
        'total_income_cash' => 0,
        'total_income_click' => 0,
        'total_expense_cash' => 0,
        'total_expense_click' => 0,
        'monthly_income' => 0,
        'monthly_expense' => 0,
        'monthly_income_cash' => 0,
        'monthly_income_click' => 0,
        'monthly_expense_cash' => 0,
        'monthly_expense_click' => 0,
    ];
    $dbErrors[] = 'Totals could not be loaded. Please check the database connection.';
}

$balance = $totals['total_income'] - $totals['total_expense'];
$monthlyBalance = $totals['monthly_income'] - $totals['monthly_expense'];

// Fetch recent income and expense transactions
$recentIncome = [];
$recentExpense = [];

$incomeQuery = "SELECT id, date, payment, comment,
        CASE
            WHEN cash = 1 THEN 'Cash'
            WHEN click = 1 THEN 'Click'
            ELSE 'Other'
        END AS method
    FROM transactions
    WHERE cash_in = 1
    ORDER BY date DESC, id DESC
    LIMIT 10";

$incomeResult = $conn->query($incomeQuery);
if ($incomeResult instanceof mysqli_result) {
    while ($row = $incomeResult->fetch_assoc()) {
        $recentIncome[] = $row;
    }
    $incomeResult->free();
} else {
    $dbErrors[] = 'Income records could not be retrieved.';
}

$expenseQuery = "SELECT id, date, payment, comment,
        CASE
            WHEN cash = 1 THEN 'Cash'
            WHEN click = 1 THEN 'Click'
            ELSE 'Other'
        END AS method
    FROM transactions
    WHERE cash_out = 1
    ORDER BY date DESC, id DESC
    LIMIT 10";

$expenseResult = $conn->query($expenseQuery);
if ($expenseResult instanceof mysqli_result) {
    while ($row = $expenseResult->fetch_assoc()) {
        $recentExpense[] = $row;
    }
    $expenseResult->free();
} else {
    $dbErrors[] = 'Expense records could not be retrieved.';
}

// Fetch monthly report data for the selected range
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

$monthlyMap = [];
$reportStmt = $conn->prepare($reportSql);
if ($reportStmt) {
    $reportStmt->bind_param('ss', $reportStart, $reportEnd);
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
    } else {
        $dbErrors[] = 'Report data could not be generated: ' . $reportStmt->error;
    }
    $reportStmt->close();
} else {
    $dbErrors[] = 'Unable to prepare report query: ' . $conn->error;
}

$periodStart = (new DateTime($reportStart))->modify('first day of this month');
$periodEnd = (new DateTime($reportEnd))->modify('first day of next month');

for ($cursor = clone $periodStart; $cursor < $periodEnd; $cursor->modify('+1 month')) {
    $periodKey = $cursor->format('Y-m');
    $chartLabel = $cursor->format('M Y');
    $tableLabel = $cursor->format('F Y');

    $incomeValue = $monthlyMap[$periodKey]['income'] ?? 0.0;
    $expenseValue = $monthlyMap[$periodKey]['expense'] ?? 0.0;
    $balanceValue = $incomeValue - $expenseValue;

    $reportLabels[] = $chartLabel;
    $reportIncome[] = $incomeValue;
    $reportExpense[] = $expenseValue;
    $reportBalance[] = $balanceValue;

    $reportTable[] = [
        'label' => $tableLabel,
        'income' => $incomeValue,
        'expense' => $expenseValue,
        'balance' => $balanceValue,
    ];
}

// Fetch range summary totals for cards and doughnut charts
$rangeSummary = [
    'income' => 0.0,
    'expense' => 0.0,
    'balance' => 0.0,
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
    $summaryStmt->bind_param('ss', $reportStart, $reportEnd);
    if ($summaryStmt->execute()) {
        $summaryResult = $summaryStmt->get_result();
        if ($summaryResult instanceof mysqli_result) {
            $rangeSummary = array_merge($rangeSummary, array_map('floatval', $summaryResult->fetch_assoc() ?: []));
            $summaryResult->free();
        }
    } else {
        $dbErrors[] = 'Unable to calculate the selected range summary: ' . $summaryStmt->error;
    }
    $summaryStmt->close();
} else {
    $dbErrors[] = 'Unable to prepare the summary query: ' . $conn->error;
}

$rangeSummary['balance'] = $rangeSummary['income'] - $rangeSummary['expense'];

$incomeMethodData = [
    (float) $rangeSummary['income_cash'],
    (float) $rangeSummary['income_click'],
];

$expenseMethodData = [
    (float) $rangeSummary['expense_cash'],
    (float) $rangeSummary['expense_click'],
];

$conn->close();

$allowedTabs = ['overview', 'income', 'expense', 'reports'];
$sessionActiveTab = $_SESSION['active_tab'] ?? null;
$queryTab = $_GET['tab'] ?? null;
$activeTab = in_array($queryTab, $allowedTabs, true)
    ? $queryTab
    : (in_array($sessionActiveTab, $allowedTabs, true) ? $sessionActiveTab : 'overview');
unset($_SESSION['active_tab']);

if (isset($_GET['start_date']) || isset($_GET['end_date'])) {
    $activeTab = 'reports';
}

$preservedForm = $_SESSION['form_values'] ?? null;
unset($_SESSION['form_values']);

$defaultFormState = [
    'amount' => '',
    'date' => date('Y-m-d'),
    'payment_method' => '',
    'comment' => '',
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashflow Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            500: '#6366f1',
                            600: '#4f46e5',
                        },
                        accent: {
                            400: '#38bdf8',
                            500: '#0ea5e9',
                        }
                    },
                    boxShadow: {
                        glow: '0 25px 60px -35px rgba(79, 70, 229, 0.65)',
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
    </style>
</head>
<body class="min-h-screen text-slate-900 [background:radial-gradient(circle_at_15%_15%,rgba(99,102,241,0.12),transparent_55%),radial-gradient(circle_at_85%_10%,rgba(14,165,233,0.16),transparent_50%),linear-gradient(180deg,#f8fafc_0%,#eef2ff_35%,#fff_100%)]">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-20 top-10 h-64 w-64 rounded-full bg-indigo-300/20 blur-3xl"></div>
        <div class="absolute -right-16 top-40 h-72 w-72 rounded-full bg-sky-300/20 blur-3xl"></div>
    </div>
    <div class="relative z-10 mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8 lg:py-12 space-y-8">
        <div class="flex flex-col gap-4 border border-white/60 bg-white/80 p-6 shadow-lg shadow-slate-200/80 backdrop-blur md:flex-row md:items-end md:justify-between md:rounded-3xl">
            <div class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Financial hub</span>
                <h1 class="text-3xl font-semibold text-slate-900 md:text-4xl">Cashflow dashboard</h1>
                <p class="max-w-xl text-sm text-slate-500 md:text-base">Capture income and expenses, compare cash versus click flows, and explore interactive analytics for any period.</p>
            </div>
            <a class="inline-flex items-center justify-center gap-2 self-start rounded-full bg-white/70 px-5 py-3 text-sm font-semibold text-slate-600 shadow-md shadow-slate-200/70 ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:bg-white hover:text-slate-900 hover:shadow-lg hover:shadow-slate-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500" href="../index.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                Back to dashboard
            </a>
        </div>

        <?php if ($flashMessage): ?>
            <?php
            $flashStyles = [
                'success' => [
                    'container' => 'rounded-3xl border border-emerald-200/80 bg-emerald-50/90 px-5 py-4 text-emerald-900 shadow-sm shadow-emerald-100/60 backdrop-blur',
                    'icon' => 'text-emerald-500',
                    'icon_wrapper' => 'shadow-emerald-100/80',
                    'title' => 'Success',
                ],
                'danger' => [
                    'container' => 'rounded-3xl border border-rose-200/80 bg-rose-50/90 px-5 py-4 text-rose-900 shadow-sm shadow-rose-100/60 backdrop-blur',
                    'icon' => 'text-rose-500',
                    'icon_wrapper' => 'shadow-rose-100/80',
                    'title' => 'Please review',
                ],
            ];
            $flashTheme = $flashStyles[$flashType] ?? $flashStyles['success'];
            ?>
            <div class="<?= htmlspecialchars($flashTheme['container'], ENT_QUOTES) ?> flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white/80 shadow-inner <?= htmlspecialchars($flashTheme['icon_wrapper'] ?? '', ENT_QUOTES) ?> <?= htmlspecialchars($flashTheme['icon'], ENT_QUOTES) ?>">
                    <?php if ($flashType === 'danger'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="space-y-1 text-sm">
                    <p class="text-base font-semibold leading-snug"><?= htmlspecialchars($flashTheme['title']) ?></p>
                    <p><?= htmlspecialchars($flashMessage) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($dbErrors): ?>
            <div class="rounded-3xl border border-rose-200/70 bg-rose-50/90 px-5 py-4 text-sm text-rose-900 shadow-sm shadow-rose-100/60 backdrop-blur">
                <p class="font-semibold">We hit a snag while loading data.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <?php foreach ($dbErrors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php
        $tabBaseClass = 'group flex-1 min-w-[8rem] select-none items-center justify-center gap-2 rounded-2xl px-5 py-3 text-sm font-semibold transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500 sm:text-base';
        $tabActiveClass = 'bg-gradient-to-r from-indigo-500 via-sky-500 to-cyan-400 text-white shadow-xl shadow-sky-200/70';
        $tabInactiveClass = 'bg-white/70 text-slate-500 ring-1 ring-slate-200/70 hover:bg-white hover:text-slate-900 hover:shadow-lg hover:shadow-slate-200/80';
        $panelBaseClass = 'space-y-8';
        ?>

        <div class="rounded-3xl bg-white/70 p-3 shadow-lg shadow-slate-200/70 ring-1 ring-slate-100/80 backdrop-blur">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4" role="tablist" aria-label="Cashflow sections">
                <button type="button"
                        role="tab"
                        aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>"
                        data-tab-target="overview"
                        data-active-class="<?= htmlspecialchars($tabActiveClass, ENT_QUOTES) ?>"
                        data-inactive-class="<?= htmlspecialchars($tabInactiveClass, ENT_QUOTES) ?>"
                        class="<?= htmlspecialchars($tabBaseClass . ' ' . ($activeTab === 'overview' ? $tabActiveClass : $tabInactiveClass), ENT_QUOTES) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7.5 15l4.5-6 3 4.5L21 6" />
                    </svg>
                    Overview
                </button>
                <button type="button"
                        role="tab"
                        aria-selected="<?= $activeTab === 'income' ? 'true' : 'false' ?>"
                        data-tab-target="income"
                        data-active-class="<?= htmlspecialchars($tabActiveClass, ENT_QUOTES) ?>"
                        data-inactive-class="<?= htmlspecialchars($tabInactiveClass, ENT_QUOTES) ?>"
                        class="<?= htmlspecialchars($tabBaseClass . ' ' . ($activeTab === 'income' ? $tabActiveClass : $tabInactiveClass), ENT_QUOTES) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m6-12H6" />
                    </svg>
                    Income
                </button>
                <button type="button"
                        role="tab"
                        aria-selected="<?= $activeTab === 'expense' ? 'true' : 'false' ?>"
                        data-tab-target="expense"
                        data-active-class="<?= htmlspecialchars($tabActiveClass, ENT_QUOTES) ?>"
                        data-inactive-class="<?= htmlspecialchars($tabInactiveClass, ENT_QUOTES) ?>"
                        class="<?= htmlspecialchars($tabBaseClass . ' ' . ($activeTab === 'expense' ? $tabActiveClass : $tabInactiveClass), ENT_QUOTES) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21V3m6 12H6" />
                    </svg>
                    Expense
                </button>
                <button type="button"
                        role="tab"
                        aria-selected="<?= $activeTab === 'reports' ? 'true' : 'false' ?>"
                        data-tab-target="reports"
                        data-active-class="<?= htmlspecialchars($tabActiveClass, ENT_QUOTES) ?>"
                        data-inactive-class="<?= htmlspecialchars($tabInactiveClass, ENT_QUOTES) ?>"
                        class="<?= htmlspecialchars($tabBaseClass . ' ' . ($activeTab === 'reports' ? $tabActiveClass : $tabInactiveClass), ENT_QUOTES) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 3v18M9 8.25l2.25-2.25L13.5 9l4.5-4.5" />
                    </svg>
                    Reports
                </button>
            </div>
        </div>

        <div class="space-y-12">
            <section data-tab-panel="overview" class="<?= htmlspecialchars($panelBaseClass, ENT_QUOTES) ?> <?= $activeTab === 'overview' ? '' : 'hidden' ?>">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/70 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Net balance</p>
                        <div class="mt-3 flex items-end justify-between">
                            <p class="text-3xl font-semibold <?= $balance >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= number_format((float) $balance, 2) ?></p>
                            <?php
                            $monthlyChipClasses = $monthlyBalance >= 0
                                ? 'bg-emerald-100 text-emerald-600'
                                : 'bg-rose-100 text-rose-600';
                            $monthlyChipPrefix = $monthlyBalance >= 0 ? '+' : '';
                            ?>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars($monthlyChipClasses, ENT_QUOTES) ?>">Monthly <?= $monthlyChipPrefix ?><?= number_format((float) $monthlyBalance, 2) ?></span>
                        </div>
                        <p class="mt-4 text-sm text-slate-500">Total income minus expense across all time. The chip highlights this month’s net change.</p>
                    </article>
                    <article class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/70 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Income</p>
                        <div class="mt-3 space-y-3">
                            <p class="text-3xl font-semibold text-slate-900"><?= number_format((float) $totals['total_income'], 2) ?></p>
                            <div class="grid gap-2 text-sm text-slate-500">
                                <div class="flex items-center justify-between rounded-2xl bg-emerald-50/70 px-3 py-2 text-emerald-600">
                                    <span>Cash</span>
                                    <strong><?= number_format((float) $totals['total_income_cash'], 2) ?></strong>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl bg-sky-50/70 px-3 py-2 text-sky-600">
                                    <span>Click</span>
                                    <strong><?= number_format((float) $totals['total_income_click'], 2) ?></strong>
                                </div>
                            </div>
                            <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-400">This month <?= number_format((float) $totals['monthly_income'], 2) ?></p>
                        </div>
                    </article>
                    <article class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/70 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Expense</p>
                        <div class="mt-3 space-y-3">
                            <p class="text-3xl font-semibold text-slate-900"><?= number_format((float) $totals['total_expense'], 2) ?></p>
                            <div class="grid gap-2 text-sm text-slate-500">
                                <div class="flex items-center justify-between rounded-2xl bg-amber-50/80 px-3 py-2 text-amber-600">
                                    <span>Cash</span>
                                    <strong><?= number_format((float) $totals['total_expense_cash'], 2) ?></strong>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl bg-indigo-50/80 px-3 py-2 text-indigo-600">
                                    <span>Click</span>
                                    <strong><?= number_format((float) $totals['total_expense_click'], 2) ?></strong>
                                </div>
                            </div>
                            <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-400">This month <?= number_format((float) $totals['monthly_expense'], 2) ?></p>
                        </div>
                    </article>
                    <article class="rounded-3xl bg-gradient-to-br from-indigo-500/90 via-sky-500/90 to-cyan-400/90 p-6 text-white shadow-xl shadow-sky-400/40">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-white/70">Payment mix</p>
                        <div class="mt-3 space-y-3">
                            <div class="flex items-center justify-between text-sm uppercase tracking-[0.2em] text-white/80">
                                <span>Income</span>
                                <span>Expense</span>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>Cash</span>
                                    <span><?= number_format((float) $totals['total_income_cash'], 2) ?></span>
                                </div>
                                <div class="flex items-center justify-between text-white/90">
                                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-sky-200"></span>Click</span>
                                    <span><?= number_format((float) $totals['total_income_click'], 2) ?></span>
                                </div>
                                <div class="mt-4 h-px w-full bg-white/30"></div>
                                <div class="flex items-center justify-between text-white/90">
                                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-200"></span>Cash</span>
                                    <span><?= number_format((float) $totals['total_expense_cash'], 2) ?></span>
                                </div>
                                <div class="flex items-center justify-between text-white/90">
                                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-indigo-200"></span>Click</span>
                                    <span><?= number_format((float) $totals['total_expense_click'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                        <p class="mt-6 text-xs text-white/80">Monitor how cash and click payments distribute across your totals.</p>
                    </article>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/70 backdrop-blur">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-900">Recent income</h2>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-600">Last 10</span>
                        </div>
                        <div class="mt-5 divide-y divide-slate-100/80">
                            <?php if ($recentIncome): ?>
                                <?php foreach ($recentIncome as $income): ?>
                                    <article class="py-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($income['date']) ?></p>
                                                <?php if (!empty($income['comment'])): ?>
                                                    <p class="text-sm text-slate-500"><?= htmlspecialchars($income['comment']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-base font-semibold text-emerald-600">+<?= number_format((float) $income['payment'], 2) ?></p>
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><?= htmlspecialchars($income['method']) ?></span>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="py-3 text-sm text-slate-500">No income records yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/70 backdrop-blur">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-900">Recent expenses</h2>
                            <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-medium text-rose-600">Last 10</span>
                        </div>
                        <div class="mt-5 divide-y divide-slate-100/80">
                            <?php if ($recentExpense): ?>
                                <?php foreach ($recentExpense as $expense): ?>
                                    <article class="py-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($expense['date']) ?></p>
                                                <?php if (!empty($expense['comment'])): ?>
                                                    <p class="text-sm text-slate-500"><?= htmlspecialchars($expense['comment']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-base font-semibold text-rose-600">-<?= number_format((float) $expense['payment'], 2) ?></p>
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><?= htmlspecialchars($expense['method']) ?></span>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="py-3 text-sm text-slate-500">No expense records yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section data-tab-panel="income" class="<?= htmlspecialchars($panelBaseClass, ENT_QUOTES) ?> <?= $activeTab === 'income' ? '' : 'hidden' ?>">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/80 backdrop-blur">
                        <h2 class="text-xl font-semibold text-slate-900">Add income</h2>
                        <p class="mt-1 text-sm text-slate-500">Log new earnings and choose whether they were received in cash or via click.</p>
                        <form action="save_transaction.php" method="post" class="mt-6 space-y-5">
                            <input type="hidden" name="transaction_type" value="income">
                            <div>
                                <label for="incomeAmount" class="text-sm font-medium text-slate-600">Amount</label>
                                <input type="number" step="0.01" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="incomeAmount" name="amount" value="<?= htmlspecialchars($incomeForm['amount']) ?>" required>
                            </div>
                            <div>
                                <label for="incomeDate" class="text-sm font-medium text-slate-600">Date</label>
                                <input type="date" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="incomeDate" name="date" value="<?= htmlspecialchars($incomeForm['date']) ?>" required>
                            </div>
                            <div>
                                <label for="incomeMethod" class="text-sm font-medium text-slate-600">Payment method</label>
                                <select class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="incomeMethod" name="payment_method" required>
                                    <option value="" disabled <?= $incomeForm['payment_method'] === '' ? 'selected' : '' ?>>Choose…</option>
                                    <option value="cash" <?= $incomeForm['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="click" <?= $incomeForm['payment_method'] === 'click' ? 'selected' : '' ?>>Click</option>
                                </select>
                            </div>
                            <div>
                                <label for="incomeComment" class="text-sm font-medium text-slate-600">Comment</label>
                                <textarea class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="incomeComment" name="comment" rows="3" placeholder="Optional details"><?= htmlspecialchars($incomeForm['comment']) ?></textarea>
                            </div>
                            <button type="submit" class="w-full rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-300/50 transition hover:-translate-y-0.5 hover:bg-emerald-600">Save income</button>
                        </form>
                    </div>
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/80 backdrop-blur">
                        <h2 class="text-xl font-semibold text-slate-900">Income snapshot</h2>
                        <p class="mt-1 text-sm text-slate-500">Totals update instantly so you always know where your inflows stand.</p>
                        <dl class="mt-6 space-y-4 text-sm text-slate-600">
                            <div class="flex items-center justify-between">
                                <dt>Total income</dt>
                                <dd class="text-base font-semibold text-slate-900"><?= number_format((float) $totals['total_income'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Monthly income</dt>
                                <dd class="text-base font-semibold text-slate-900"><?= number_format((float) $totals['monthly_income'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Cash income</dt>
                                <dd class="text-base font-semibold text-emerald-600"><?= number_format((float) $totals['total_income_cash'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Click income</dt>
                                <dd class="text-base font-semibold text-sky-600"><?= number_format((float) $totals['total_income_click'], 2) ?></dd>
                            </div>
                        </dl>
                        <h3 class="mt-6 text-sm font-semibold uppercase tracking-[0.22em] text-slate-400">Recent notes</h3>
                        <ul class="mt-3 space-y-3">
                            <?php if ($recentIncome): ?>
                                <?php foreach (array_slice($recentIncome, 0, 5) as $income): ?>
                                    <li class="rounded-2xl border border-slate-100/70 bg-white/80 px-4 py-3 text-sm shadow-sm">
                                <div class="flex items-center justify-between">
                                            <span class="font-medium text-slate-800"><?= htmlspecialchars($income['date']) ?></span>
                                            <span class="font-semibold text-emerald-600">+<?= number_format((float) $income['payment'], 2) ?></span>
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><?= htmlspecialchars($income['method']) ?></span>
                                            <?php if (!empty($income['comment'])): ?>
                                                <span><?= htmlspecialchars($income['comment']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">No income entries yet.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section data-tab-panel="expense" class="<?= htmlspecialchars($panelBaseClass, ENT_QUOTES) ?> <?= $activeTab === 'expense' ? '' : 'hidden' ?>">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/80 backdrop-blur">
                        <h2 class="text-xl font-semibold text-slate-900">Add expense</h2>
                        <p class="mt-1 text-sm text-slate-500">Track outgoing payments and classify whether they were made via cash or click.</p>
                        <form action="save_transaction.php" method="post" class="mt-6 space-y-5">
                            <input type="hidden" name="transaction_type" value="expense">
                            <div>
                                <label for="expenseAmount" class="text-sm font-medium text-slate-600">Amount</label>
                                <input type="number" step="0.01" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="expenseAmount" name="amount" value="<?= htmlspecialchars($expenseForm['amount']) ?>" required>
                            </div>
                            <div>
                                <label for="expenseDate" class="text-sm font-medium text-slate-600">Date</label>
                                <input type="date" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="expenseDate" name="date" value="<?= htmlspecialchars($expenseForm['date']) ?>" required>
                            </div>
                            <div>
                                <label for="expenseMethod" class="text-sm font-medium text-slate-600">Payment method</label>
                                <select class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="expenseMethod" name="payment_method" required>
                                    <option value="" disabled <?= $expenseForm['payment_method'] === '' ? 'selected' : '' ?>>Choose…</option>
                                    <option value="cash" <?= $expenseForm['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="click" <?= $expenseForm['payment_method'] === 'click' ? 'selected' : '' ?>>Click</option>
                                </select>
                            </div>
                            <div>
                                <label for="expenseComment" class="text-sm font-medium text-slate-600">Comment</label>
                                <textarea class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="expenseComment" name="comment" rows="3" placeholder="Optional details"><?= htmlspecialchars($expenseForm['comment']) ?></textarea>
                            </div>
                            <button type="submit" class="w-full rounded-full bg-rose-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-rose-300/50 transition hover:-translate-y-0.5 hover:bg-rose-600">Save expense</button>
                        </form>
                    </div>
                    <div class="rounded-3xl bg-white/80 p-6 shadow-lg shadow-slate-200/80 ring-1 ring-slate-100/80 backdrop-blur">
                        <h2 class="text-xl font-semibold text-slate-900">Expense snapshot</h2>
                        <p class="mt-1 text-sm text-slate-500">Spot where your spending is going at a glance.</p>
                        <dl class="mt-6 space-y-4 text-sm text-slate-600">
                            <div class="flex items-center justify-between">
                                <dt>Total expense</dt>
                                <dd class="text-base font-semibold text-slate-900"><?= number_format((float) $totals['total_expense'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Monthly expense</dt>
                                <dd class="text-base font-semibold text-slate-900"><?= number_format((float) $totals['monthly_expense'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Cash expense</dt>
                                <dd class="text-base font-semibold text-amber-600"><?= number_format((float) $totals['total_expense_cash'], 2) ?></dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Click expense</dt>
                                <dd class="text-base font-semibold text-indigo-600"><?= number_format((float) $totals['total_expense_click'], 2) ?></dd>
                            </div>
                        </dl>
                        <h3 class="mt-6 text-sm font-semibold uppercase tracking-[0.22em] text-slate-400">Recent notes</h3>
                        <ul class="mt-3 space-y-3">
                            <?php if ($recentExpense): ?>
                                <?php foreach (array_slice($recentExpense, 0, 5) as $expense): ?>
                                    <li class="rounded-2xl border border-slate-100/70 bg-white/80 px-4 py-3 text-sm shadow-sm">
                                        <div class="flex items-center justify-between">
                                            <span class="font-medium text-slate-800"><?= htmlspecialchars($expense['date']) ?></span>
                                            <span class="font-semibold text-rose-600">-<?= number_format((float) $expense['payment'], 2) ?></span>
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><?= htmlspecialchars($expense['method']) ?></span>
                                            <?php if (!empty($expense['comment'])): ?>
                                                <span><?= htmlspecialchars($expense['comment']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">No expense entries yet.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section data-tab-panel="reports" class="<?= htmlspecialchars($panelBaseClass, ENT_QUOTES) ?> <?= $activeTab === 'reports' ? '' : 'hidden' ?>">
                <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                        <div class="space-y-2">
                            <h2 class="text-2xl font-semibold text-slate-900">Dynamic reports</h2>
                            <p class="text-sm text-slate-500">Review income and expenses between <strong><?= htmlspecialchars($reportRangeLabel) ?></strong>.</p>
                        </div>
                        <form id="reportsFilterForm" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" method="get">
                            <input type="hidden" name="tab" value="reports">
                            <label class="text-sm font-medium text-slate-600">
                                <span class="block">Start date</span>
                                <input type="date" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="reportStart" name="start_date" value="<?= htmlspecialchars($reportStartInput) ?>" required>
                            </label>
                            <label class="text-sm font-medium text-slate-600">
                                <span class="block">End date</span>
                                <input type="date" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100/60 focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-200" id="reportEnd" name="end_date" value="<?= htmlspecialchars($reportEndInput) ?>" required>
                            </label>
                            <div class="sm:col-span-2 lg:col-span-1 lg:place-self-end">
                                <button type="submit" class="w-full rounded-full bg-gradient-to-r from-indigo-500 via-sky-500 to-cyan-400 px-5 py-3 text-sm font-semibold text-white shadow-glow transition hover:brightness-110">Update report</button>
                            </div>
                        </form>
                    </div>

                    <?php if ($filterWarnings): ?>
                        <div class="mt-6 rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-900 shadow-sm">
                            <p class="font-semibold">We adjusted your filter:</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                <?php foreach ($filterWarnings as $warning): ?>
                                    <li><?= htmlspecialchars($warning) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <?php foreach ($quickRanges as $range): ?>
                            <button type="button" class="rounded-full border border-slate-200 bg-white/70 px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 transition hover:border-sky-300 hover:text-sky-600" data-range-start="<?= htmlspecialchars($range['start']) ?>" data-range-end="<?= htmlspecialchars($range['end']) ?>">
                                <?= htmlspecialchars($range['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 3v5.25H6" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 20.25H18a.75.75 0 00.75-.75V9l-6-6H6a.75.75 0 00-.75.75v12a.75.75 0 00.75.75z" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Income</p>
                                <p class="mt-1 text-2xl font-semibold text-slate-900"><?= number_format((float) $rangeSummary['income'], 2) ?></p>
                                <p class="text-sm text-slate-500">Captured within the selected range</p>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-rose-100 text-rose-600">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12.75 21v-5.25H18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 3.75H6a.75.75 0 00-.75.75v12l6 6h7.5a.75.75 0 00.75-.75V4.5a.75.75 0 00-.75-.75z" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Expense</p>
                                <p class="mt-1 text-2xl font-semibold text-slate-900"><?= number_format((float) $rangeSummary['expense'], 2) ?></p>
                                <p class="text-sm text-slate-500">Money leaving in the same window</p>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur md:col-span-2">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-100 text-sky-600">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.75l8.25 8.25 8.25-8.25" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5l8.25 8.25L20.25 4.5" />
                                </svg>
                            </span>
                            <div class="flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Balance</p>
                                        <p class="mt-1 text-2xl font-semibold text-slate-900"><?= number_format((float) $rangeSummary['balance'], 2) ?></p>
                                    </div>
                                    <div class="flex gap-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-600">Income cash <?= number_format((float) $rangeSummary['income_cash'], 2) ?></span>
                                        <span class="rounded-full bg-sky-50 px-3 py-1 text-sky-600">Income click <?= number_format((float) $rangeSummary['income_click'], 2) ?></span>
                                        <span class="rounded-full bg-amber-50 px-3 py-1 text-amber-600">Expense cash <?= number_format((float) $rangeSummary['expense_cash'], 2) ?></span>
                                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-indigo-600">Expense click <?= number_format((float) $rangeSummary['expense_click'], 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <h3 class="text-lg font-semibold text-slate-900">Income vs expense</h3>
                        <p class="text-sm text-slate-500">Monthly totals across the selected range.</p>
                        <div class="mt-6 h-80">
                            <canvas id="incomeExpenseChart" class="h-full w-full"></canvas>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <h3 class="text-lg font-semibold text-slate-900">Balance trend</h3>
                        <p class="text-sm text-slate-500">Track how net balance evolves month over month.</p>
                        <div class="mt-6 h-80">
                            <canvas id="balanceTrendChart" class="h-full w-full"></canvas>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <h3 class="text-lg font-semibold text-slate-900">Income payment mix</h3>
                        <p class="text-sm text-slate-500">Distribution of cash and click income inside your range.</p>
                        <div class="mt-6 h-72">
                            <canvas id="incomeMethodChart" class="h-full w-full"></canvas>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                        <h3 class="text-lg font-semibold text-slate-900">Expense payment mix</h3>
                        <p class="text-sm text-slate-500">See which payment method covers your expenses.</p>
                        <div class="mt-6 h-72">
                            <canvas id="expenseMethodChart" class="h-full w-full"></canvas>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-100/70 bg-white/80 p-6 shadow-lg shadow-slate-200/70 backdrop-blur">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Monthly breakdown</h3>
                            <p class="text-sm text-slate-500">Detailed figures per month in the selected range.</p>
                        </div>
                    </div>
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-100/80">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50/80 text-slate-500">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left font-semibold uppercase tracking-[0.2em]">Month</th>
                                    <th scope="col" class="px-4 py-3 text-left font-semibold uppercase tracking-[0.2em]">Income</th>
                                    <th scope="col" class="px-4 py-3 text-left font-semibold uppercase tracking-[0.2em]">Expense</th>
                                    <th scope="col" class="px-4 py-3 text-left font-semibold uppercase tracking-[0.2em]">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100/80 bg-white/90 text-slate-600">
                                <?php foreach ($reportTable as $row): ?>
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-800"><?= htmlspecialchars($row['label']) ?></td>
                                        <td class="px-4 py-3 text-emerald-600"><?= number_format((float) $row['income'], 2) ?></td>
                                        <td class="px-4 py-3 text-rose-600"><?= number_format((float) $row['expense'], 2) ?></td>
                                        <td class="px-4 py-3 font-semibold <?= $row['balance'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= number_format((float) $row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$reportTable): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">No records for this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabButtons = document.querySelectorAll('[data-tab-target]');
            const tabPanels = document.querySelectorAll('[data-tab-panel]');

            function activateTab(id) {
                tabButtons.forEach((btn) => {
                    const target = btn.getAttribute('data-tab-target');
                    const activeClass = btn.getAttribute('data-active-class') || '';
                    const inactiveClass = btn.getAttribute('data-inactive-class') || '';
                    if (target === id) {
                        btn.classList.add(...activeClass.split(' ').filter(Boolean));
                        btn.classList.remove(...inactiveClass.split(' ').filter(Boolean));
                        btn.setAttribute('aria-selected', 'true');
                    } else {
                        btn.classList.add(...inactiveClass.split(' ').filter(Boolean));
                        btn.classList.remove(...activeClass.split(' ').filter(Boolean));
                        btn.setAttribute('aria-selected', 'false');
                    }
                });

                tabPanels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.getAttribute('data-tab-panel') !== id);
                });

                const url = new URL(window.location);
                url.searchParams.set('tab', id);
                window.history.replaceState({}, '', url);
            }

            tabButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    activateTab(btn.getAttribute('data-tab-target'));
                });
            });

            const reportsForm = document.getElementById('reportsFilterForm');
            const quickRangeButtons = document.querySelectorAll('[data-range-start]');
            if (reportsForm && quickRangeButtons.length) {
                quickRangeButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const startInput = reportsForm.querySelector('[name="start_date"]');
                        const endInput = reportsForm.querySelector('[name="end_date"]');
                        const startValue = button.getAttribute('data-range-start');
                        const endValue = button.getAttribute('data-range-end');
                        if (startInput) {
                            startInput.value = startValue;
                        }
                        if (endInput) {
                            endInput.value = endValue;
                        }
                        if (typeof reportsForm.requestSubmit === 'function') {
                            reportsForm.requestSubmit();
                        } else {
                            reportsForm.submit();
                        }
                    });
                });
            }

            const reportLabels = <?= json_encode($reportLabels) ?>;
            const reportIncome = <?= json_encode($reportIncome) ?>;
            const reportExpense = <?= json_encode($reportExpense) ?>;
            const reportBalance = <?= json_encode($reportBalance) ?>;
            const incomeMethodData = <?= json_encode($incomeMethodData) ?>;
            const expenseMethodData = <?= json_encode($expenseMethodData) ?>;

            const incomeExpenseCanvas = document.getElementById('incomeExpenseChart');
            if (incomeExpenseCanvas && reportLabels.length) {
                new Chart(incomeExpenseCanvas, {
                    type: 'bar',
                    data: {
                        labels: reportLabels,
                        datasets: [
                            {
                                label: 'Income',
                                data: reportIncome,
                                backgroundColor: 'rgba(34, 197, 94, 0.75)',
                                borderRadius: 14,
                                borderSkipped: false,
                            },
                            {
                                label: 'Expense',
                                data: reportExpense,
                                backgroundColor: 'rgba(244, 63, 94, 0.75)',
                                borderRadius: 14,
                                borderSkipped: false,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: '#64748b' },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#64748b',
                                    callback: function (value) {
                                        return new Intl.NumberFormat('en-US', {
                                            style: 'currency',
                                            currency: 'UZS',
                                            maximumFractionDigits: 0,
                                        }).format(value);
                                    },
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#0f172a' },
                            },
                        },
                    },
                });
            }

            const balanceTrendCanvas = document.getElementById('balanceTrendChart');
            if (balanceTrendCanvas && reportLabels.length) {
                new Chart(balanceTrendCanvas, {
                    type: 'line',
                    data: {
                        labels: reportLabels,
                        datasets: [
                            {
                                label: 'Net balance',
                                data: reportBalance,
                                fill: true,
                                borderColor: 'rgba(99, 102, 241, 1)',
                                backgroundColor: 'rgba(99, 102, 241, 0.18)',
                                tension: 0.35,
                                pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                                pointBorderWidth: 0,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: '#64748b' },
                            },
                            y: {
                                ticks: {
                                    color: '#64748b',
                                    callback: function (value) {
                                        return new Intl.NumberFormat('en-US', {
                                            style: 'currency',
                                            currency: 'UZS',
                                            maximumFractionDigits: 0,
                                        }).format(value);
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const incomeMethodCanvas = document.getElementById('incomeMethodChart');
            if (incomeMethodCanvas) {
                new Chart(incomeMethodCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Cash', 'Click'],
                        datasets: [
                            {
                                data: incomeMethodData,
                                backgroundColor: ['rgba(34, 197, 94, 0.85)', 'rgba(14, 165, 233, 0.85)'],
                                borderWidth: 0,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#0f172a' },
                            },
                        },
                    },
                });
            }

            const expenseMethodCanvas = document.getElementById('expenseMethodChart');
            if (expenseMethodCanvas) {
                new Chart(expenseMethodCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Cash', 'Click'],
                        datasets: [
                            {
                                data: expenseMethodData,
                                backgroundColor: ['rgba(244, 114, 182, 0.85)', 'rgba(99, 102, 241, 0.85)'],
                                borderWidth: 0,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#0f172a' },
                            },
                        },
                    },
                });
            }
        });
    </script>
</body>
</html>
