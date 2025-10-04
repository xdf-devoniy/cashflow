<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

date_default_timezone_set('Asia/Tashkent');

require_once '../db.php';

$dbErrors = [];

// Fetch aggregated totals
$totalsSql = "SELECT
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN cash_in = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_income,
        COALESCE(SUM(CASE WHEN cash_out = 1 AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN payment END), 0) AS monthly_expense
    FROM transactions";
$totalsResult = $conn->query($totalsSql);
if ($totalsResult instanceof mysqli_result) {
    $totals = $totalsResult->fetch_assoc();
    $totalsResult->free();
} else {
    $totals = [
        'total_income' => 0,
        'total_expense' => 0,
        'monthly_income' => 0,
        'monthly_expense' => 0,
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

// Fetch monthly report data for the chart
$reportSql = "SELECT DATE_FORMAT(date, '%Y-%m') AS period,
        COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS income,
        COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS expense
    FROM transactions
    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY period
    ORDER BY period";

$reportLabels = [];
$reportIncome = [];
$reportExpense = [];
$reportTable = [];

$reportResult = $conn->query($reportSql);
if ($reportResult instanceof mysqli_result) {
    while ($row = $reportResult->fetch_assoc()) {
        $label = $row['period'];
        $reportLabels[] = $label;
        $reportIncome[] = (float) $row['income'];
        $reportExpense[] = (float) $row['expense'];
        $reportTable[] = $row;
    }
    $reportResult->free();
} else {
    $dbErrors[] = 'Report data could not be generated.';
}

$conn->close();

$allowedTabs = ['overview', 'income', 'expense', 'reports'];
$sessionActiveTab = $_SESSION['active_tab'] ?? null;
$queryTab = $_GET['tab'] ?? null;
$activeTab = in_array($queryTab, $allowedTabs, true)
    ? $queryTab
    : (in_array($sessionActiveTab, $allowedTabs, true) ? $sessionActiveTab : 'overview');
unset($_SESSION['active_tab']);

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
    <style>
        body {
            background-color: #f5f6fa;
        }
        .card h5 {
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.05rem;
        }
        .balance-positive {
            color: #28a745;
        }
        .balance-negative {
            color: #dc3545;
        }
        .form-section {
            background: #ffffff;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(31, 38, 135, 0.1);
        }
        .tab-content {
            margin-top: 1.5rem;
        }
        .chart-container {
            position: relative;
            height: 320px;
        }
    </style>
</head>
<body>
<header class="bg-dark text-white">
    <div class="container py-3 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h1 class="h3 mb-0">Cashflow App</h1>
            <p class="mb-0 small text-white-50">Track daily income and expenses with ease</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-light" href="../index.php">Back to Dashboard</a>
        </div>
    </div>
</header>

<div class="container py-4">
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
                    <div class="card h-100">
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
                    <div class="card h-100">
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
                    <div class="card">
                        <div class="card-header">Income Overview</div>
                        <div class="card-body">
                            <p class="mb-2">Total Income: <strong><?= number_format((float) $totals['total_income'], 2) ?></strong></p>
                            <p class="mb-2">Monthly Income: <strong><?= number_format((float) $totals['monthly_income'], 2) ?></strong></p>
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
                    <div class="card">
                        <div class="card-header">Expense Overview</div>
                        <div class="card-body">
                            <p class="mb-2">Total Expense: <strong><?= number_format((float) $totals['total_expense'], 2) ?></strong></p>
                            <p class="mb-2">Monthly Expense: <strong><?= number_format((float) $totals['monthly_expense'], 2) ?></strong></p>
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
            <div class="card mb-4">
                <div class="card-header">Income vs Expense (Last 12 Months)</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="incomeExpenseChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">Monthly Breakdown</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Period</th>
                                <th>Income</th>
                                <th>Expense</th>
                                <th>Balance</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($reportTable): ?>
                                <?php foreach ($reportTable as $row): ?>
                                    <?php $rowBalance = $row['income'] - $row['expense']; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['period']) ?></td>
                                        <td><?= number_format((float) $row['income'], 2) ?></td>
                                        <td><?= number_format((float) $row['expense'], 2) ?></td>
                                        <td class="<?= $rowBalance >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format((float) $rowBalance, 2) ?>
                                        </td>
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

        const ctx = document.getElementById('incomeExpenseChart');
        if (ctx) {
            const chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($reportLabels) ?>,
                    datasets: [
                        {
                            label: 'Income',
                            data: <?= json_encode($reportIncome) ?>,
                            backgroundColor: 'rgba(25, 135, 84, 0.7)'
                        },
                        {
                            label: 'Expense',
                            data: <?= json_encode($reportExpense) ?>,
                            backgroundColor: 'rgba(220, 53, 69, 0.7)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
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
    });
</script>
</body>
</html>
