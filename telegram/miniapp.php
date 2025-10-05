<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';

ensure_expense_tables($conn);
$categories = fetch_categories($conn);

$today = date('Y-m-d');
$categoriesJson = json_encode($categories, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oxford moliya mini ilovasi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.3/dist/tailwind.min.css">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <header class="text-center space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">Oxford moliya mini ilovasi</h1>
            <p class="text-sm text-slate-600">Daromad va xarajatlarni tezda kiritish, hamda joriy oydagi hisobotni ko'rish uchun ushbu oynadan foydalaning.</p>
        </header>

        <section class="grid gap-6 md:grid-cols-2">
            <article class="bg-white/80 backdrop-blur rounded-2xl shadow-lg p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-medium">Daromad qo'shish</h2>
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">Naqd / Click</span>
                </div>
                <form class="space-y-4" onsubmit="return false;">
                    <label class="block text-sm font-medium text-slate-700">
                        Summa
                        <input id="income-amount" type="text" inputmode="numeric" autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-200" placeholder="Masalan, 1 200 000">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Sana
                        <input id="income-date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        To'lov usuli
                        <select id="income-method" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                            <option value="cash">Naqd</option>
                            <option value="click">Click</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Izoh (ixtiyoriy)
                        <textarea id="income-comment" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-200" placeholder="Masalan, guruh to'lovi"></textarea>
                    </label>
                    <button type="button" id="income-submit" class="w-full inline-flex justify-center items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition">
                        ➕ Saqlash
                    </button>
                </form>
            </article>

            <article class="bg-white/80 backdrop-blur rounded-2xl shadow-lg p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-medium">Xarajat qo'shish</h2>
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-rose-100 text-rose-700 text-xs font-semibold">Turkum va usul</span>
                </div>
                <form class="space-y-4" onsubmit="return false;">
                    <label class="block text-sm font-medium text-slate-700">
                        Summa
                        <input id="expense-amount" type="text" inputmode="numeric" autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200" placeholder="Masalan, 850 000">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Sana
                        <input id="expense-date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        To'lov usuli
                        <select id="expense-method" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200">
                            <option value="cash">Naqd</option>
                            <option value="click">Click</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Turkum
                        <?php if (count($categories) > 0): ?>
                            <select id="expense-category" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200">
                                <option value="">Turkumni tanlang</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id']; ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <div class="mt-1 rounded-xl border border-dashed border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                                Veb-ilovada kamida bitta turkum yarating.
                            </div>
                        <?php endif; ?>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Izoh (ixtiyoriy)
                        <textarea id="expense-comment" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-base focus:border-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-200" placeholder="Masalan, ofis ijarasi"></textarea>
                    </label>
                    <button type="button" id="expense-submit" class="w-full inline-flex justify-center items-center gap-2 rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-rose-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-rose-500 transition" <?php if (count($categories) === 0) echo 'disabled'; ?>>
                        ➖ Saqlash
                    </button>
                </form>
            </article>
        </section>

        <section class="bg-white/80 backdrop-blur rounded-2xl shadow-lg p-6 space-y-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="text-lg font-medium">Hisobot</h2>
                <p class="text-xs text-slate-500">Joriy oydagi daromad, xarajat va balans natijalari chatda yuboriladi.</p>
            </div>
            <button type="button" id="report-submit" class="w-full md:w-auto inline-flex justify-center items-center gap-2 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-400 transition">
                📊 Hisobotni olish
            </button>
        </section>

        <footer class="text-center text-xs text-slate-500">
            <p>Mini ilova faqat Telegram ichida ishlatiladi. Ariza yuborilgach, tasdiq xabari bot chatida ko'rinadi.</p>
        </footer>
    </main>

    <script>
        const telegramApp = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
        if (telegramApp) {
            telegramApp.ready();
            telegramApp.expand();
        }

        const categories = <?= $categoriesJson ?: '[]'; ?>;

        function showAlert(message) {
            if (telegramApp && typeof telegramApp.showAlert === 'function') {
                telegramApp.showAlert(message);
            } else {
                alert(message);
            }
        }

        function showConfirmation(message) {
            if (telegramApp && typeof telegramApp.showPopup === 'function') {
                telegramApp.showPopup({
                    title: 'Jo\'natildi',
                    message,
                    buttons: [{ type: 'close', text: 'Tushunarli' }],
                });
            } else {
                alert(message);
            }
        }

        function sanitizeAmount(value) {
            return value.replace(/[^0-9]/g, '');
        }

        function formatAmountInput(input) {
            input.addEventListener('input', () => {
                const raw = sanitizeAmount(input.value);
                if (!raw) {
                    input.value = '';
                    return;
                }
                const parts = raw.split('').reverse();
                const chunks = [];
                for (let i = 0; i < parts.length; i += 3) {
                    chunks.push(parts.slice(i, i + 3).reverse().join(''));
                }
                input.value = chunks.reverse().join(' ');
            });
        }

        formatAmountInput(document.getElementById('income-amount'));
        formatAmountInput(document.getElementById('expense-amount'));

        function sendPayload(payload) {
            if (!telegramApp) {
                showAlert('Telegram WebApp konteksti topilmadi.');
                return;
            }
            telegramApp.sendData(JSON.stringify(payload));
            showConfirmation('So\'rov botga yuborildi. Javobni chatdan ko\'ring.');
        }

        document.getElementById('income-submit').addEventListener('click', () => {
            const amountField = document.getElementById('income-amount');
            const dateField = document.getElementById('income-date');
            const methodField = document.getElementById('income-method');
            const commentField = document.getElementById('income-comment');

            const amount = sanitizeAmount(amountField.value);
            const date = dateField.value || '<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>';
            const method = methodField.value;

            if (!amount) {
                showAlert('Daromad summasini kiriting.');
                return;
            }

            if (!method || (method !== 'cash' && method !== 'click')) {
                showAlert('To\'lov usulini tanlang.');
                return;
            }

            sendPayload({
                type: 'income',
                amount,
                date,
                payment_method: method,
                comment: commentField.value.trim(),
            });
        });

        document.getElementById('expense-submit').addEventListener('click', () => {
            const amountField = document.getElementById('expense-amount');
            const dateField = document.getElementById('expense-date');
            const methodField = document.getElementById('expense-method');
            const commentField = document.getElementById('expense-comment');
            const categoryField = document.getElementById('expense-category');

            if (!categories.length) {
                showAlert('Avval veb-ilovada turkum yarating.');
                return;
            }

            const amount = sanitizeAmount(amountField.value);
            const date = dateField.value || '<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>';
            const method = methodField.value;
            const categoryId = categoryField ? parseInt(categoryField.value, 10) : 0;

            if (!amount) {
                showAlert('Xarajat summasini kiriting.');
                return;
            }

            if (!method || (method !== 'cash' && method !== 'click')) {
                showAlert('To\'lov usulini tanlang.');
                return;
            }

            if (!categoryId) {
                showAlert('Turkumni tanlang.');
                return;
            }

            sendPayload({
                type: 'expense',
                amount,
                date,
                payment_method: method,
                category_id: categoryId,
                comment: commentField.value.trim(),
            });
        });

        document.getElementById('report-submit').addEventListener('click', () => {
            sendPayload({ type: 'report' });
        });
    </script>
</body>
</html>
