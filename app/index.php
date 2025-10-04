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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
    <style>
        :root {
            --surface-muted: #f3f6ff;
            --surface-highlight: #e0f2fe;
            --surface-bright: #ffffff;
            --accent: #4f46e5;
            --accent-contrast: #0ea5e9;
            --accent-soft: rgba(79, 70, 229, 0.12);
            --glass-bg: rgba(255, 255, 255, 0.92);
            --glass-border: rgba(148, 163, 184, 0.25);
            --text-muted: #64748b;
            --hero-shadow: rgba(15, 23, 42, 0.15);
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at 12% 18%, rgba(79, 70, 229, 0.12), transparent 55%),
                radial-gradient(circle at 85% 12%, rgba(14, 165, 233, 0.14), transparent 50%),
                linear-gradient(180deg, var(--surface-muted) 0%, var(--surface-bright) 100%);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: #0f172a;
        }

        header {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(224, 242, 254, 0.9));
            box-shadow: 0 18px 42px -28px var(--hero-shadow);
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            color: #0f172a;
        }

        .hero-surface {
            border-bottom-left-radius: clamp(1.5rem, 4vw, 3.25rem);
            border-bottom-right-radius: clamp(1.5rem, 4vw, 3.25rem);
        }

        header::before,
        header::after {
            content: "";
            position: absolute;
            width: clamp(220px, 32vw, 360px);
            height: clamp(220px, 32vw, 360px);
            border-radius: 999px;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.16), transparent 65%);
            z-index: 0;
        }

        header::before {
            top: -40%;
            right: -10%;
        }

        header::after {
            bottom: -45%;
            left: -15%;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.18), transparent 60%);
        }

        header .container-xxl {
            position: relative;
            z-index: 1;
        }

        header .btn {
            border-radius: 999px;
            padding-inline: 1.25rem;
            font-weight: 600;
            backdrop-filter: saturate(160%) blur(6px);
        }

        .hero-title {
            font-size: clamp(1.875rem, 1.2rem + 1.5vw, 2.75rem);
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .hero-subtitle {
            color: rgba(15, 23, 42, 0.68);
            max-width: 38rem;
        }

        .eyebrow-label {
            font-size: 0.75rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.55);
            font-weight: 600;
        }

        .btn-glass {
            background: rgba(79, 70, 229, 0.08);
            color: #1d4ed8;
            border: 1px solid rgba(79, 70, 229, 0.18);
            padding-block: 0.65rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .btn-glass:hover,
        .btn-glass:focus {
            background: rgba(79, 70, 229, 0.12);
            box-shadow: 0 10px 20px -15px rgba(79, 70, 229, 0.6);
            transform: translateY(-1px);
            color: #1d4ed8;
        }

        .app-shell {
            margin-top: -2.25rem;
        }

        .nav-pills .nav-link {
            border-radius: 999px;
            font-weight: 600;
            letter-spacing: 0.03em;
            color: #1f2937;
            background-color: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.25);
            transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
        }

        .nav-pills .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.95);
            box-shadow: 0 10px 25px -18px rgba(15, 23, 42, 0.35);
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.98), rgba(14, 165, 233, 0.92));
            color: #fff;
            box-shadow: 0 14px 28px -18px rgba(79, 70, 229, 0.55);
            transform: translateY(-2px);
        }

        .glass-card,
        .form-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 1.5rem;
            box-shadow: 0 25px 45px -30px rgba(15, 23, 42, 0.25);
            backdrop-filter: saturate(160%) blur(18px);
        }

        .card {
            border: none;
            border-radius: 1.5rem;
            overflow: hidden;
        }

        .card h5,
        .card-header {
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .balance-positive {
            color: #16a34a;
        }

        .balance-negative {
            color: #dc2626;
        }

        .form-section {
            padding: 2rem;
        }

        .tab-content {
            margin-top: 2rem;
        }

        .chart-container {
            position: relative;
            height: 320px;
        }

        @media (min-width: 992px) {
            .chart-container--wide {
                height: 380px;
            }
        }

        .text-muted-soft {
            color: var(--text-muted);
        }

        .reports-summary .stat-card {
            border-radius: 1.5rem;
            border: 1px solid rgba(148, 163, 184, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.85));
            box-shadow: 0 18px 45px -32px rgba(15, 23, 42, 0.7);
        }

        .reports-summary .icon-badge {
            width: 3rem;
            height: 3rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 1.25rem;
        }

        .reports-filter {
            border-radius: 1.5rem;
            background: rgba(148, 163, 184, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .table thead th {
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .table tbody td {
            vertical-align: middle;
        }

        .badge-soft {
            background: rgba(99, 102, 241, 0.12);
            color: #4338ca;
            border-radius: 999px;
            font-weight: 600;
        }

        .small-caps {
            font-size: 0.75rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .quick-range button {
            border-radius: 999px;
            font-size: 0.85rem;
        }

        .glass-card .list-group-item {
            background-color: transparent;
        }
    </style>
</head>
<body>
<header class="hero-surface">
    <div class="container-xxl py-3 py-md-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 gap-lg-4">
        <div class="d-flex flex-column gap-2">
            <span class="eyebrow-label">Finance cockpit</span>
            <h1 class="hero-title mb-0">Cashflow Intelligence Hub</h1>
            <p class="hero-subtitle mb-0">Monitor real-time balances, capture new activity, and visualise payment trends with confidence.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center justify-content-lg-end gap-2">
            <a class="btn btn-glass shadow-sm" href="../index.php"><i class="bi bi-arrow-left-circle me-2"></i>Back to dashboard</a>
        </div>
    </div>
</header>

<div class="container py-5 app-shell">
    <ul class="nav nav-pills flex-column flex-md-row gap-2 justify-content-center" id="cashflowTabs" role="tablist">
        <li class="nav-item flex-fill" role="presentation">
            <button class="nav-link w-100 <?= $activeTab === 'overview' ? 'active' : '' ?>" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>">
                Overview
            </button>
        </li>
        <li class="nav-item flex-fill" role="presentation">
            <button class="nav-link w-100 <?= $activeTab === 'income' ? 'active' : '' ?>" id="income-tab" data-bs-toggle="tab" data-bs-target="#income" type="button" role="tab" aria-controls="income" aria-selected="<?= $activeTab === 'income' ? 'true' : 'false' ?>">
                Income
            </button>
        </li>
        <li class="nav-item flex-fill" role="presentation">
            <button class="nav-link w-100 <?= $activeTab === 'expense' ? 'active' : '' ?>" id="expense-tab" data-bs-toggle="tab" data-bs-target="#expense" type="button" role="tab" aria-controls="expense" aria-selected="<?= $activeTab === 'expense' ? 'true' : 'false' ?>">
                Expense
            </button>
        </li>
        <li class="nav-item flex-fill" role="presentation">
            <button class="nav-link w-100 <?= $activeTab === 'reports' ? 'active' : '' ?>" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab" aria-controls="reports" aria-selected="<?= $activeTab === 'reports' ? 'true' : 'false' ?>">
                Reports
            </button>
        </li>
    </ul>

    <?php if (!empty($dbErrors)): ?>
        <div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
            <h2 class="h6 mb-2">Some data could not be loaded</h2>
            <ul class="mb-0 ps-3">
                <?php foreach ($dbErrors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show mt-3" role="alert">
            <?= htmlspecialchars($flashMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="tab-content mt-4" id="cashflowTabContent">
        <div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>" id="overview" role="tabpanel" aria-labelledby="overview-tab">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="card text-bg-success">
                        <div class="card-body">
                            <h5>Total Income</h5>
                            <p class="display-6 mb-0">
                                <?= number_format((float) $totals['total_income'], 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card text-bg-danger">
                        <div class="card-body">
                            <h5>Total Expense</h5>
                            <p class="display-6 mb-0">
                                <?= number_format((float) $totals['total_expense'], 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card text-bg-primary">
                        <div class="card-body">
                            <h5>Balance</h5>
                            <p class="display-6 mb-0 <?= $balance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                <?= number_format((float) $balance, 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card text-bg-secondary">
                        <div class="card-body">
                            <h5>This Month</h5>
                            <p class="mb-1">Income: <?= number_format((float) $totals['monthly_income'], 2) ?></p>
                            <p class="mb-0">Expense: <?= number_format((float) $totals['monthly_expense'], 2) ?>
                                <br><small class="<?= $monthlyBalance >= 0 ? 'balance-positive' : 'balance-negative' ?>">Balance: <?= number_format((float) $monthlyBalance, 2) ?></small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-lg-6">
                    <div class="card border-success h-100">
                        <div class="card-header text-bg-success bg-opacity-75">Income by Method</div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Cash</span>
                                <span class="fw-semibold"><?= number_format((float) $totals['total_income_cash'], 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span>Click</span>
                                <span class="fw-semibold"><?= number_format((float) $totals['total_income_click'], 2) ?></span>
                            </div>
                            <p class="mb-0 small text-muted">This month — Cash: <?= number_format((float) $totals['monthly_income_cash'], 2) ?> · Click: <?= number_format((float) $totals['monthly_income_click'], 2) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-danger h-100">
                        <div class="card-header text-bg-danger bg-opacity-75">Expense by Method</div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Cash</span>
                                <span class="fw-semibold"><?= number_format((float) $totals['total_expense_cash'], 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span>Click</span>
                                <span class="fw-semibold"><?= number_format((float) $totals['total_expense_click'], 2) ?></span>
                            </div>
                            <p class="mb-0 small text-muted">This month — Cash: <?= number_format((float) $totals['monthly_expense_cash'], 2) ?> · Click: <?= number_format((float) $totals['monthly_expense_click'], 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-lg-6">
                    <div class="card glass-card h-100 border-0">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Recent Income</span>
                            <span class="badge text-bg-success">Last 10</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Comment</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($recentIncome): ?>
                                        <?php foreach ($recentIncome as $income): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($income['date']) ?></td>
                                                <td><?= number_format((float) $income['payment'], 2) ?></td>
                                                <td><?= htmlspecialchars($income['method']) ?></td>
                                                <td><?= htmlspecialchars($income['comment'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-3">No income records yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card glass-card h-100 border-0">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Recent Expenses</span>
                            <span class="badge text-bg-danger">Last 10</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Comment</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($recentExpense): ?>
                                        <?php foreach ($recentExpense as $expense): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($expense['date']) ?></td>
                                                <td><?= number_format((float) $expense['payment'], 2) ?></td>
                                                <td><?= htmlspecialchars($expense['method']) ?></td>
                                                <td><?= htmlspecialchars($expense['comment'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-3">No expense records yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'income' ? 'show active' : '' ?>" id="income" role="tabpanel" aria-labelledby="income-tab">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="form-section">
                        <h4 class="mb-3">Add Income</h4>
                        <form action="save_transaction.php" method="post" class="row g-3 needs-validation" novalidate>
                            <input type="hidden" name="transaction_type" value="income">
                            <div class="col-12">
                                <label for="incomeAmount" class="form-label">Amount</label>
                                <input type="number" step="0.01" class="form-control" id="incomeAmount" name="amount" value="<?= htmlspecialchars($incomeForm['amount']) ?>" required>
                                <div class="invalid-feedback">Please enter the income amount.</div>
                            </div>
                            <div class="col-12">
                                <label for="incomeDate" class="form-label">Date</label>
                                <input type="date" class="form-control" id="incomeDate" name="date" value="<?= htmlspecialchars($incomeForm['date']) ?>" required>
                                <div class="invalid-feedback">Please select a valid date.</div>
                            </div>
                            <div class="col-12">
                                <label for="incomeMethod" class="form-label">Payment Method</label>
                                <select class="form-select" id="incomeMethod" name="payment_method" required>
                                    <option value="" disabled <?= $incomeForm['payment_method'] === '' ? 'selected' : '' ?>>Choose...</option>
                                    <option value="cash" <?= $incomeForm['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="click" <?= $incomeForm['payment_method'] === 'click' ? 'selected' : '' ?>>Click</option>
                                </select>
                                <div class="invalid-feedback">Select a payment method.</div>
                            </div>
                            <div class="col-12">
                                <label for="incomeComment" class="form-label">Comment</label>
                                <textarea class="form-control" id="incomeComment" name="comment" rows="3" placeholder="Optional details"><?= htmlspecialchars($incomeForm['comment']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success w-100">Save Income</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card glass-card border-0">
                        <div class="card-header">Income Overview</div>
                        <div class="card-body">
                            <p class="mb-2">Total Income: <strong><?= number_format((float) $totals['total_income'], 2) ?></strong></p>
                            <p class="mb-2">Monthly Income: <strong><?= number_format((float) $totals['monthly_income'], 2) ?></strong></p>
                            <p class="mb-2">Cash Income: <strong><?= number_format((float) $totals['total_income_cash'], 2) ?></strong></p>
                            <p class="mb-2">Click Income: <strong><?= number_format((float) $totals['total_income_click'], 2) ?></strong></p>
                            <p class="mb-3 small text-muted">This month — Cash: <?= number_format((float) $totals['monthly_income_cash'], 2) ?> · Click: <?= number_format((float) $totals['monthly_income_click'], 2) ?></p>
                            <p class="mb-0">Recent Notes:</p>
                            <ul class="list-group list-group-flush">
                                <?php if ($recentIncome): ?>
                                    <?php foreach (array_slice($recentIncome, 0, 5) as $income): ?>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between">
                                                <span><?= htmlspecialchars($income['date']) ?></span>
                                                <span><?= number_format((float) $income['payment'], 2) ?></span>
                                            </div>
                                            <small class="text-muted">Method: <?= htmlspecialchars($income['method']) ?><?php if (!empty($income['comment'])): ?> · <?= htmlspecialchars($income['comment']) ?><?php endif; ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="list-group-item">No income entries yet.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'expense' ? 'show active' : '' ?>" id="expense" role="tabpanel" aria-labelledby="expense-tab">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="form-section">
                        <h4 class="mb-3">Add Expense</h4>
                        <form action="save_transaction.php" method="post" class="row g-3 needs-validation" novalidate>
                            <input type="hidden" name="transaction_type" value="expense">
                            <div class="col-12">
                                <label for="expenseAmount" class="form-label">Amount</label>
                                <input type="number" step="0.01" class="form-control" id="expenseAmount" name="amount" value="<?= htmlspecialchars($expenseForm['amount']) ?>" required>
                                <div class="invalid-feedback">Please enter the expense amount.</div>
                            </div>
                            <div class="col-12">
                                <label for="expenseDate" class="form-label">Date</label>
                                <input type="date" class="form-control" id="expenseDate" name="date" value="<?= htmlspecialchars($expenseForm['date']) ?>" required>
                                <div class="invalid-feedback">Please select a valid date.</div>
                            </div>
                            <div class="col-12">
                                <label for="expenseMethod" class="form-label">Payment Method</label>
                                <select class="form-select" id="expenseMethod" name="payment_method" required>
                                    <option value="" disabled <?= $expenseForm['payment_method'] === '' ? 'selected' : '' ?>>Choose...</option>
                                    <option value="cash" <?= $expenseForm['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="click" <?= $expenseForm['payment_method'] === 'click' ? 'selected' : '' ?>>Click</option>
                                </select>
                                <div class="invalid-feedback">Select a payment method.</div>
                            </div>
                            <div class="col-12">
                                <label for="expenseComment" class="form-label">Comment</label>
                                <textarea class="form-control" id="expenseComment" name="comment" rows="3" placeholder="Optional details"><?= htmlspecialchars($expenseForm['comment']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-danger w-100">Save Expense</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card glass-card border-0">
                        <div class="card-header">Expense Overview</div>
                        <div class="card-body">
                            <p class="mb-2">Total Expense: <strong><?= number_format((float) $totals['total_expense'], 2) ?></strong></p>
                            <p class="mb-2">Monthly Expense: <strong><?= number_format((float) $totals['monthly_expense'], 2) ?></strong></p>
                            <p class="mb-2">Cash Expense: <strong><?= number_format((float) $totals['total_expense_cash'], 2) ?></strong></p>
                            <p class="mb-2">Click Expense: <strong><?= number_format((float) $totals['total_expense_click'], 2) ?></strong></p>
                            <p class="mb-3 small text-muted">This month — Cash: <?= number_format((float) $totals['monthly_expense_cash'], 2) ?> · Click: <?= number_format((float) $totals['monthly_expense_click'], 2) ?></p>
                            <p class="mb-0">Recent Notes:</p>
                            <ul class="list-group list-group-flush">
                                <?php if ($recentExpense): ?>
                                    <?php foreach (array_slice($recentExpense, 0, 5) as $expense): ?>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between">
                                                <span><?= htmlspecialchars($expense['date']) ?></span>
                                                <span><?= number_format((float) $expense['payment'], 2) ?></span>
                                            </div>
                                            <small class="text-muted">Method: <?= htmlspecialchars($expense['method']) ?><?php if (!empty($expense['comment'])): ?> · <?= htmlspecialchars($expense['comment']) ?><?php endif; ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="list-group-item">No expense entries yet.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'reports' ? 'show active' : '' ?>" id="reports" role="tabpanel" aria-labelledby="reports-tab">
            <div class="glass-card p-4 p-lg-5 mb-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-4">
                    <div>
                        <h2 class="h5 fw-semibold mb-2">Dynamic financial reports</h2>
                        <p class="mb-0 text-muted-soft">Review income and expenses between <strong><?= htmlspecialchars($reportRangeLabel) ?></strong>.</p>
                    </div>
                    <form id="reportsFilterForm" class="row g-3 align-items-end reports-filter p-3 p-lg-4" method="get">
                        <input type="hidden" name="tab" value="reports">
                        <div class="col-md-4">
                            <label for="reportStart" class="form-label">Start date</label>
                            <input type="date" class="form-control" id="reportStart" name="start_date" value="<?= htmlspecialchars($reportStartInput) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="reportEnd" class="form-label">End date</label>
                            <input type="date" class="form-control" id="reportEnd" name="end_date" value="<?= htmlspecialchars($reportEndInput) ?>" required>
                        </div>
                        <div class="col-md-4 col-xl-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Update report
                            </button>
                        </div>
                    </form>
                </div>
                <?php if ($filterWarnings): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                        <h3 class="h6 mb-2">We adjusted your filter</h3>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($filterWarnings as $warning): ?>
                                <li><?= htmlspecialchars($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="quick-range d-flex flex-wrap gap-2 mt-4">
                    <?php foreach ($quickRanges as $range): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-range-start="<?= htmlspecialchars($range['start']) ?>" data-range-end="<?= htmlspecialchars($range['end']) ?>">
                            <?= htmlspecialchars($range['label']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-4 reports-summary mb-4">
                <div class="col-md-4">
                    <div class="stat-card p-4 h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="icon-badge">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div>
                                <p class="small-caps mb-1">Income</p>
                                <h3 class="h4 mb-0"><?= number_format((float) $rangeSummary['income'], 2) ?></h3>
                                <small class="text-muted-soft">Captured in the selected period</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-4 h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="icon-badge">
                                <i class="bi bi-cash-coin"></i>
                            </div>
                            <div>
                                <p class="small-caps mb-1">Expense</p>
                                <h3 class="h4 mb-0"><?= number_format((float) $rangeSummary['expense'], 2) ?></h3>
                                <small class="text-muted-soft">Money out within the range</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-4 h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="icon-badge">
                                <i class="bi bi-piggy-bank"></i>
                            </div>
                            <div>
                                <p class="small-caps mb-1">Net balance</p>
                                <h3 class="h4 mb-0 <?= $rangeSummary['balance'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format((float) $rangeSummary['balance'], 2) ?></h3>
                                <small class="text-muted-soft">Income minus expense</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="glass-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="h6 mb-0 fw-semibold"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Income vs Expense</h3>
                            <span class="badge badge-soft"><?= htmlspecialchars($reportRangeLabel) ?></span>
                        </div>
                        <div class="chart-container chart-container--wide">
                            <canvas id="incomeExpenseChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex align-items-center mb-3">
                            <i class="bi bi-activity text-primary me-2"></i>
                            <h3 class="h6 mb-0 fw-semibold">Monthly balance trend</h3>
                        </div>
                        <div class="chart-container" style="height: 260px;">
                            <canvas id="balanceTrendChart"></canvas>
                        </div>
                    </div>
                    <div class="glass-card p-4">
                        <div class="d-flex align-items-center mb-3">
                            <i class="bi bi-wallet2 text-primary me-2"></i>
                            <h3 class="h6 mb-0 fw-semibold">Payment methods split</h3>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-center">
                                    <span class="small-caps d-block mb-2">Income</span>
                                    <div class="chart-container" style="height: 180px;">
                                        <canvas id="incomeMethodChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <span class="small-caps d-block mb-2">Expense</span>
                                    <div class="chart-container" style="height: 180px;">
                                        <canvas id="expenseMethodChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="glass-card p-4 p-lg-5 mt-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-calendar3 text-primary"></i>
                        <h3 class="h6 mb-0 fw-semibold">Monthly breakdown</h3>
                    </div>
                    <span class="text-muted-soft small">Values reflect actual activity within each month of the selected period.</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Period</th>
                            <th scope="col" class="text-end">Income</th>
                            <th scope="col" class="text-end">Expense</th>
                            <th scope="col" class="text-end">Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($reportTable): ?>
                            <?php foreach ($reportTable as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                    <td class="text-end"><?= number_format((float) $row['income'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $row['expense'], 2) ?></td>
                                    <td class="text-end <?= $row['balance'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format((float) $row['balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-3">Not enough data to generate a report.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const validationForms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(validationForms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });

        const tabs = document.querySelectorAll('#cashflowTabs button[data-bs-toggle="tab"]');
        tabs.forEach(function (tabButton) {
            tabButton.addEventListener('shown.bs.tab', function (event) {
                const targetId = event.target.getAttribute('data-bs-target');
                if (!targetId) {
                    return;
                }
                const tabName = targetId.replace('#', '');
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabName);
                const newUrl = url.pathname + url.search + url.hash;
                history.replaceState(null, '', newUrl);
            });
        });

        const reportLabels = <?= json_encode($reportLabels) ?>;
        const reportIncome = <?= json_encode($reportIncome) ?>;
        const reportExpense = <?= json_encode($reportExpense) ?>;
        const reportBalance = <?= json_encode($reportBalance) ?>;
        const incomeMethodData = <?= json_encode($incomeMethodData) ?>;
        const expenseMethodData = <?= json_encode($expenseMethodData) ?>;

        const reportsForm = document.getElementById('reportsFilterForm');
        const quickRangeButtons = document.querySelectorAll('.quick-range button[data-range-start]');
        if (reportsForm) {
            const startInput = reportsForm.querySelector('input[name="start_date"]');
            const endInput = reportsForm.querySelector('input[name="end_date"]');
            quickRangeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (startInput && endInput) {
                        const startValue = button.getAttribute('data-range-start');
                        const endValue = button.getAttribute('data-range-end');
                        if (startValue) {
                            startInput.value = startValue;
                        }
                        if (endValue) {
                            endInput.value = endValue;
                        }
                        if (typeof reportsForm.requestSubmit === 'function') {
                            reportsForm.requestSubmit();
                        } else {
                            reportsForm.submit();
                        }
                    }
                });
            });
        }

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
                            borderRadius: 12,
                            borderSkipped: false,
                        },
                        {
                            label: 'Expense',
                            data: reportExpense,
                            backgroundColor: 'rgba(239, 68, 68, 0.75)',
                            borderRadius: 12,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    return new Intl.NumberFormat('en-US', {
                                        style: 'currency',
                                        currency: 'UZS',
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
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
                            backgroundColor: 'rgba(99, 102, 241, 0.2)',
                            tension: 0.35,
                            pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                            pointBorderWidth: 0,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            ticks: {
                                callback: function (value) {
                                    return new Intl.NumberFormat('en-US', {
                                        style: 'currency',
                                        currency: 'UZS',
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        }
                    }
                }
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
                            backgroundColor: ['rgba(34, 197, 94, 0.8)', 'rgba(14, 165, 233, 0.8)'],
                            borderWidth: 0,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
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
                            backgroundColor: ['rgba(239, 68, 68, 0.85)', 'rgba(99, 102, 241, 0.85)'],
                            borderWidth: 0,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    });
</script>
</body>
</html>
