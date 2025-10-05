<?php
date_default_timezone_set('Asia/Tashkent');

require_once 'db.php';

function format_number($number)
{
    if ($number == intval($number)) {
        return number_format($number, 0, '.', ' ');
    }
    return number_format($number, 2, '.', ' ');
}

$current_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_date)) {
    $current_date = date('Y-m-d');
}

$transactions = [];
$dailyTotals = [
    'income' => 0.0,
    'expense' => 0.0,
    'cashIncome' => 0.0,
    'cashExpense' => 0.0,
    'clickIncome' => 0.0,
    'clickExpense' => 0.0,
];

$dailyQuery = $conn->prepare('SELECT id, payment, comment, cash, click, cash_in, cash_out, xarajat, date FROM transactions WHERE date = ? ORDER BY id DESC');
if ($dailyQuery) {
    $dailyQuery->bind_param('s', $current_date);
    if ($dailyQuery->execute()) {
        $result = $dailyQuery->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['payment'] = (float)$row['payment'];
            $transactions[] = $row;
            if ((int)$row['cash_in'] === 1) {
                $dailyTotals['income'] += $row['payment'];
                if ((int)$row['cash'] === 1) {
                    $dailyTotals['cashIncome'] += $row['payment'];
                }
                if ((int)$row['click'] === 1) {
                    $dailyTotals['clickIncome'] += $row['payment'];
                }
            }
            if ((int)$row['cash_out'] === 1) {
                $dailyTotals['expense'] += $row['payment'];
                if ((int)$row['cash'] === 1) {
                    $dailyTotals['cashExpense'] += $row['payment'];
                }
                if ((int)$row['click'] === 1) {
                    $dailyTotals['clickExpense'] += $row['payment'];
                }
            }
        }
        $result->free();
    }
    $dailyQuery->close();
}

$cashBalance = $dailyTotals['cashIncome'] - $dailyTotals['cashExpense'];
$clickBalance = $dailyTotals['clickIncome'] - $dailyTotals['clickExpense'];

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

if ($totalsResult = $conn->query($totalsSql)) {
    $row = $totalsResult->fetch_assoc();
    if ($row) {
        foreach ($row as $key => $value) {
            $totals[$key] = (float)$value;
        }
    }
    $totalsResult->free();
}

$overallBalance = $totals['total_income'] - $totals['total_expense'];
$overallMonthlyBalance = $totals['monthly_income'] - $totals['monthly_expense'];

$filterStartRaw = $_GET['filter_start_date'] ?? '';
$filterEndRaw = $_GET['filter_end_date'] ?? '';
$filterErrors = [];
$filteredExpenses = [];
$filteredTotal = 0.0;
$filterRange = null;

if ($filterStartRaw !== '' || $filterEndRaw !== '') {
    $startDate = DateTime::createFromFormat('Y-m-d', $filterStartRaw) ?: null;
    $endDate = DateTime::createFromFormat('Y-m-d', $filterEndRaw) ?: null;

    if (!$startDate || !$endDate) {
        $filterErrors[] = 'Sanani to\'g\'ri kiriting (YYYY-MM-DD).';
    } else {
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $filterRange = [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
        ];

        $filterStmt = $conn->prepare('SELECT id, payment, comment, cash, click, date FROM transactions WHERE xarajat = 1 AND date BETWEEN ? AND ? ORDER BY date DESC, id DESC');
        if ($filterStmt) {
            $filterStmt->bind_param('ss', $filterRange['start'], $filterRange['end']);
            if ($filterStmt->execute()) {
                $result = $filterStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $row['payment'] = (float)$row['payment'];
                    $filteredTotal += $row['payment'];
                    $filteredExpenses[] = $row;
                }
                $result->free();
            }
            $filterStmt->close();
        }
    }
}

$currentDateLabel = (new DateTime($current_date))->format('d.m.Y');
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oxford LC — Cashflow</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        oxford: {
                            50: '#f0f7ff',
                            100: '#dceeff',
                            500: '#2563eb',
                            600: '#1d4ed8',
                            900: '#0f172a'
                        },
                    },
                    boxShadow: {
                        glass: '0 20px 45px -20px rgba(15, 23, 42, 0.4)'
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-oxford-50 via-white to-sky-50 font-sans text-slate-900">
<div class="relative">
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-32 -left-24 h-72 w-72 rounded-full bg-sky-400/30 blur-3xl"></div>
        <div class="absolute bottom-0 -right-40 h-96 w-96 rounded-full bg-emerald-300/30 blur-3xl"></div>
    </div>
    <div class="relative mx-auto flex min-h-screen max-w-7xl flex-col gap-10 px-4 pb-16 pt-10 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.35em] text-slate-500">Bugungi holat</p>
                <h1 class="text-3xl font-semibold text-oxford-900 sm:text-4xl">Oxford LC — Cashflow boshqaruvi</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">Naqd va Click tushumlarini real vaqt rejimida kuzatib boring, xarajatlarni boshqaring va har bir kunning balansini tahlil qiling.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-oxford-500 hover:text-oxford-600" data-action="date" data-direction="prev" data-current-date="<?= htmlspecialchars($current_date) ?>">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-oxford-500/10 text-oxford-600 transition group-hover:bg-oxford-500/20">‹</span>
                    Oldingi kun
                </button>
                <div class="rounded-full bg-white/70 px-5 py-2 text-center text-sm font-semibold text-oxford-600 shadow-sm backdrop-blur">
                    <span id="currentDate" data-date="<?= htmlspecialchars($current_date) ?>"><?= htmlspecialchars($currentDateLabel) ?></span>
                </div>
                <button type="button" class="group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-oxford-500 hover:text-oxford-600" data-action="date" data-direction="next" data-current-date="<?= htmlspecialchars($current_date) ?>">
                    Keyingi kun
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-oxford-500/10 text-oxford-600 transition group-hover:bg-oxford-500/20">›</span>
                </button>
            </div>
        </header>

        <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-white/60 bg-white/80 p-6 shadow-glass backdrop-blur">
                <p class="text-xs font-medium uppercase tracking-widest text-slate-500">Umumiy tushum</p>
                <h2 class="mt-3 text-3xl font-semibold text-oxford-900"><?= format_number($totals['total_income']) ?> so'm</h2>
                <p class="mt-2 flex items-center gap-2 text-sm text-emerald-600"><span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>Naqd: <?= format_number($totals['total_income_cash']) ?> — Click: <?= format_number($totals['total_income_click']) ?></p>
            </article>
            <article class="rounded-3xl border border-white/60 bg-white/80 p-6 shadow-glass backdrop-blur">
                <p class="text-xs font-medium uppercase tracking-widest text-slate-500">Umumiy xarajat</p>
                <h2 class="mt-3 text-3xl font-semibold text-oxford-900"><?= format_number($totals['total_expense']) ?> so'm</h2>
                <p class="mt-2 flex items-center gap-2 text-sm text-rose-600"><span class="inline-flex h-2 w-2 rounded-full bg-rose-500"></span>Naqd: <?= format_number($totals['total_expense_cash']) ?> — Click: <?= format_number($totals['total_expense_click']) ?></p>
            </article>
            <article class="rounded-3xl border border-white/60 bg-gradient-to-br from-oxford-500 to-indigo-500 p-6 text-white shadow-glass">
                <p class="text-xs font-medium uppercase tracking-widest text-white/70">Umumiy balans</p>
                <h2 class="mt-3 text-3xl font-semibold">
                    <?= format_number($overallBalance) ?> so'm
                </h2>
                <p class="mt-2 text-sm text-white/80">Oylik balans: <?= format_number($overallMonthlyBalance) ?> so'm</p>
            </article>
            <article class="rounded-3xl border border-white/60 bg-white/80 p-6 shadow-glass backdrop-blur">
                <p class="text-xs font-medium uppercase tracking-widest text-slate-500">Bugungi qoldiq</p>
                <h2 class="mt-3 text-3xl font-semibold text-oxford-900"><?= format_number($cashBalance + $clickBalance) ?> so'm</h2>
                <p class="mt-2 flex flex-col gap-1 text-sm text-slate-600">
                    <span>Naqd: <?= format_number($cashBalance) ?> so'm</span>
                    <span>Click: <?= format_number($clickBalance) ?> so'm</span>
                </p>
            </article>
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-oxford-900">Kunlik tranzaksiyalar</h2>
                    <button type="button" id="open-transaction" class="inline-flex items-center gap-2 rounded-full bg-oxford-500 px-4 py-2 text-sm font-semibold text-white shadow-glass transition hover:bg-oxford-600">
                        + Tranzaksiya qo'shish
                    </button>
                </div>
                <div class="overflow-hidden rounded-3xl border border-white/70 bg-white/80 shadow-glass">
                    <table class="min-w-full divide-y divide-slate-200/70">
                        <thead class="bg-white/80">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-slate-500">Summa</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-slate-500">Izoh</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-slate-500">To'lov turi</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-slate-500">Yo'nalish</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100/70">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">Ushbu kunda tranzaksiya topilmadi.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                $isIncome = (int)$transaction['cash_in'] === 1;
                                $method = (int)$transaction['cash'] === 1 ? 'Naqd' : ((int)$transaction['click'] === 1 ? 'Click' : '');
                                $directionBadge = $isIncome ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600';
                                $methodBadge = $method === 'Naqd' ? 'bg-yellow-500/10 text-amber-600' : 'bg-sky-500/10 text-sky-600';
                                ?>
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="whitespace-nowrap px-4 py-4 text-sm font-medium <?= $isIncome ? 'text-emerald-600' : 'text-rose-600' ?>"><?= format_number($transaction['payment']) ?> so'm</td>
                                    <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars($transaction['comment'] ?? '') ?></td>
                                    <td class="px-4 py-4 text-sm">
                                        <?php if ($method !== ''): ?>
                                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?= $methodBadge ?>">
                                                <?= $method ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-sm">
                                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?= $directionBadge ?>">
                                            <?= $isIncome ? 'Kirim' : 'Chiqim' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <aside class="space-y-6">
                <div class="rounded-3xl border border-white/70 bg-white/80 p-6 shadow-glass">
                    <h3 class="text-base font-semibold text-oxford-900">Kunlik statistikalar</h3>
                    <dl class="mt-4 space-y-3 text-sm text-slate-600">
                        <div class="flex items-center justify-between">
                            <dt>Kirimlar</dt>
                            <dd class="font-semibold text-emerald-600"><?= format_number($dailyTotals['income']) ?> so'm</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt>Chiqimlar</dt>
                            <dd class="font-semibold text-rose-600"><?= format_number($dailyTotals['expense']) ?> so'm</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt>Kunlik balans</dt>
                            <dd class="font-semibold text-oxford-900"><?= format_number($dailyTotals['income'] - $dailyTotals['expense']) ?> so'm</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt>Naqd qoldiq</dt>
                            <dd class="font-medium text-slate-700"><?= format_number($cashBalance) ?> so'm</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt>Click qoldiq</dt>
                            <dd class="font-medium text-slate-700"><?= format_number($clickBalance) ?> so'm</dd>
                        </div>
                    </dl>
                    <div class="mt-5 rounded-2xl bg-gradient-to-br from-emerald-500/10 to-emerald-500/5 p-4 text-xs text-emerald-700">
                        So'nggi yangilanish: <?= htmlspecialchars(date('d.m.Y H:i')) ?>
                    </div>
                </div>
            </aside>
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-3xl border border-white/70 bg-white/80 p-6 shadow-glass lg:col-span-1">
                <h2 class="text-lg font-semibold text-oxford-900">Xarajatlar uchun filtr</h2>
                <p class="mt-1 text-sm text-slate-600">Kerakli davrni tanlab, "O'qituvchilar oyligi" kabi xarajatlarni saralab ko'ring.</p>
                <?php if (!empty($filterErrors)): ?>
                    <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-600">
                        <?php foreach ($filterErrors as $message): ?>
                            <p><?= htmlspecialchars($message) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="get" class="mt-6 space-y-5">
                    <div>
                        <label for="filter_start_date" class="text-xs font-semibold uppercase tracking-widest text-slate-500">Boshlanish sanasi</label>
                        <input type="date" id="filter_start_date" name="filter_start_date" value="<?= htmlspecialchars($filterRange['start'] ?? $filterStartRaw) ?>" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-inner focus:border-oxford-500 focus:ring-oxford-500">
                    </div>
                    <div>
                        <label for="filter_end_date" class="text-xs font-semibold uppercase tracking-widest text-slate-500">Tugash sanasi</label>
                        <input type="date" id="filter_end_date" name="filter_end_date" value="<?= htmlspecialchars($filterRange['end'] ?? $filterEndRaw) ?>" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-inner focus:border-oxford-500 focus:ring-oxford-500">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-oxford-500 px-4 py-3 text-sm font-semibold text-white shadow-glass transition hover:bg-oxford-600">Filtrlash</button>
                    <a href="index.php" class="block text-center text-xs font-semibold text-slate-400 transition hover:text-oxford-500">Filtrni tozalash</a>
                </form>
                <?php if ($filterRange): ?>
                    <div class="mt-6 rounded-2xl bg-emerald-500/10 p-4 text-xs text-emerald-700">
                        Tanlangan davr: <?= htmlspecialchars($filterRange['start']) ?> — <?= htmlspecialchars($filterRange['end']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-3xl border border-white/70 bg-white/80 shadow-glass">
                    <div class="flex items-center justify-between px-6 py-4">
                        <div>
                            <h3 class="text-base font-semibold text-oxford-900">Xarajatlar jadvali</h3>
                            <p class="text-xs text-slate-500">Tanlangan davrdagi barcha xarajatlar ro'yxati.</p>
                        </div>
                        <div class="rounded-full bg-rose-500/10 px-4 py-1 text-xs font-semibold text-rose-600">
                            Jami: <?= format_number($filteredTotal) ?> so'm
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200/70">
                            <thead class="bg-white/80 text-xs uppercase tracking-widest text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Sana</th>
                                <th class="px-4 py-3 text-left font-semibold">Summa</th>
                                <th class="px-4 py-3 text-left font-semibold">Izoh</th>
                                <th class="px-4 py-3 text-left font-semibold">To'lov</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100/70 text-sm text-slate-600">
                            <?php if (empty($filteredExpenses) && $filterRange): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-400">Tanlangan davr uchun xarajat topilmadi.</td>
                                </tr>
                            <?php elseif (empty($filteredExpenses)): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-400">Davr tanlang va natijalarni ko'ring.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filteredExpenses as $expense): ?>
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="whitespace-nowrap px-4 py-4 text-xs font-semibold text-slate-500"><?= htmlspecialchars((new DateTime($expense['date']))->format('d.m.Y')) ?></td>
                                        <td class="px-4 py-4 text-sm font-semibold text-rose-600"><?= format_number($expense['payment']) ?> so'm</td>
                                        <td class="px-4 py-4 text-sm"><?= htmlspecialchars($expense['comment'] ?? '') ?></td>
                                        <td class="px-4 py-4 text-sm">
                                            <?php if ((int)$expense['cash'] === 1): ?>
                                                <span class="inline-flex items-center rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-600">Naqd</span>
                                            <?php elseif ((int)$expense['click'] === 1): ?>
                                                <span class="inline-flex items-center rounded-full bg-sky-500/10 px-3 py-1 text-xs font-semibold text-sky-600">Click</span>
                                            <?php endif; ?>
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
    </div>
</div>

<div id="transaction-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/50 px-4 py-10 backdrop-blur-sm">
    <div class="w-full max-w-xl rounded-3xl border border-white/70 bg-white p-8 shadow-2xl">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h2 class="text-xl font-semibold text-oxford-900">Yangi tranzaksiya</h2>
                <p class="mt-1 text-sm text-slate-500">Kirim yoki chiqimni tanlang, to'lov usulini ko'rsating.</p>
            </div>
            <button type="button" id="close-transaction" class="rounded-full bg-slate-100 p-2 text-slate-500 transition hover:bg-slate-200 hover:text-slate-700">✕</button>
        </div>
        <form action="process.php" method="post" class="mt-8 space-y-6" onsubmit="return validateTransaction()">
            <div>
                <label for="payment" class="text-xs font-semibold uppercase tracking-widest text-slate-500">To'lov miqdori</label>
                <input type="number" step="0.01" id="payment" name="payment" required class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm shadow-inner focus:border-oxford-500 focus:ring-oxford-500">
            </div>
            <div class="space-y-3">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">To'lov turi</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="group flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-oxford-500 hover:text-oxford-600">
                        <input type="checkbox" id="cash" name="cash" class="peer hidden" data-toggle="payment" data-target="click">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 transition peer-checked:bg-amber-500 peer-checked:text-white">₮</span>
                        <span>Naqd</span>
                    </label>
                    <label class="group flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-oxford-500 hover:text-oxford-600">
                        <input type="checkbox" id="click" name="click" class="peer hidden" data-toggle="payment" data-target="cash">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-sky-500/10 text-sky-600 transition peer-checked:bg-sky-500 peer-checked:text-white">◎</span>
                        <span>Click</span>
                    </label>
                </div>
            </div>
            <div class="space-y-3">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Tranzaksiya turi</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="group flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-emerald-500 hover:text-emerald-600">
                        <input type="checkbox" id="cash_in" name="cash_in" class="peer hidden" data-toggle="direction" data-target="cash_out">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 transition peer-checked:bg-emerald-500 peer-checked:text-white">↑</span>
                        <span>Kirim</span>
                    </label>
                    <label class="group flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-rose-500 hover:text-rose-600">
                        <input type="checkbox" id="cash_out" name="cash_out" class="peer hidden" data-toggle="direction" data-target="cash_in">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-rose-500/10 text-rose-600 transition peer-checked:bg-rose-500 peer-checked:text-white">↓</span>
                        <span>Chiqim</span>
                    </label>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" id="xarajat" name="xarajat" class="h-5 w-5 rounded border-slate-300 text-rose-500 focus:ring-rose-500">
                <label for="xarajat" class="text-sm font-medium text-rose-600">Xarajat sifatida belgilash</label>
            </div>
            <div>
                <label for="comment" class="text-xs font-semibold uppercase tracking-widest text-slate-500">Izoh</label>
                <textarea id="comment" name="comment" rows="3" class="mt-2 block w-full rounded-xl border border-slate-200 px-4 py-3 text-sm shadow-inner focus:border-oxford-500 focus:ring-oxford-500" placeholder="Masalan: IELTS guruhi to'lovi"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="cancel-transaction" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-500 transition hover:border-slate-300 hover:text-slate-700">Bekor qilish</button>
                <button type="submit" class="rounded-xl bg-oxford-500 px-4 py-2 text-sm font-semibold text-white shadow-glass transition hover:bg-oxford-600">Saqlash</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dateButtons = document.querySelectorAll('[data-action="date"]');
        dateButtons.forEach(button => {
            button.addEventListener('click', () => {
                const direction = button.dataset.direction;
                const current = button.dataset.currentDate;
                const currentDate = new Date(current);
                if (Number.isNaN(currentDate.getTime())) return;
                currentDate.setDate(currentDate.getDate() + (direction === 'next' ? 1 : -1));
                const iso = currentDate.toISOString().split('T')[0];
                window.location.href = `index.php?date=${iso}`;
            });
        });

        function hideModal() {
            document.getElementById('transaction-modal').classList.add('hidden');
        }
        function showModal() {
            document.getElementById('transaction-modal').classList.remove('hidden');
        }
        document.getElementById('open-transaction').addEventListener('click', showModal);
        document.getElementById('close-transaction').addEventListener('click', hideModal);
        document.getElementById('cancel-transaction').addEventListener('click', hideModal);
        document.getElementById('transaction-modal').addEventListener('click', (event) => {
            if (event.target.id === 'transaction-modal') {
                hideModal();
            }
        });

        document.querySelectorAll('input[data-toggle]').forEach(input => {
            input.addEventListener('change', () => {
                if (input.checked) {
                    const targetId = input.dataset.target;
                    if (targetId) {
                        const target = document.getElementById(targetId);
                        if (target) target.checked = false;
                    }
                    const group = input.dataset.toggle;
                    document.querySelectorAll(`input[data-toggle="${group}"]`).forEach(peer => {
                        if (peer !== input && peer.dataset.target === input.id) {
                            peer.checked = false;
                        }
                    });
                }
            });
        });

        document.getElementById('xarajat').addEventListener('change', (event) => {
            const checked = event.target.checked;
            const cashOut = document.getElementById('cash_out');
            const cashIn = document.getElementById('cash_in');
            if (checked) {
                cashOut.checked = true;
                cashIn.checked = false;
            }
        });

    });

    function validateTransaction() {
        const cash = document.getElementById('cash');
        const click = document.getElementById('click');
        const cashIn = document.getElementById('cash_in');
        const cashOut = document.getElementById('cash_out');
        if ((!cash.checked && !click.checked) || (!cashIn.checked && !cashOut.checked)) {
            alert('To\'lov turi va kirim/chiqim yo\'nalishini tanlang.');
            return false;
        }
        return true;
    }
</script>
</body>
</html>
