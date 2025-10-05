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
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            color-scheme: light dark;
            --bg-gradient: linear-gradient(160deg, #f3f5ff 0%, #ecfdf5 50%, #fef6fb 100%);
            --card-bg: rgba(255, 255, 255, 0.88);
            --border-color: rgba(99, 102, 241, 0.12);
            --shadow-color: rgba(15, 23, 42, 0.08);
            --text-primary: #111827;
            --text-secondary: #475569;
            --accent-green: #10b981;
            --accent-rose: #f43f5e;
            --accent-blue: #6366f1;
            --accent-gold: #f59e0b;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-gradient: radial-gradient(circle at 10% 20%, #1f2937 0%, #0f172a 60%, #020617 100%);
                --card-bg: rgba(15, 23, 42, 0.82);
                --border-color: rgba(148, 163, 184, 0.14);
                --shadow-color: rgba(15, 23, 42, 0.6);
                --text-primary: #f8fafc;
                --text-secondary: #cbd5f5;
            }

            input[type="text"],
            input[type="date"],
            select,
            textarea {
                background: rgba(15, 23, 42, 0.9);
                color: var(--text-primary);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg-gradient);
            color: var(--text-primary);
            display: flex;
            justify-content: center;
            padding: 24px;
        }

        main {
            width: min(960px, 100%);
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        header.hero {
            position: relative;
            border-radius: 28px;
            padding: 28px clamp(20px, 4vw, 36px);
            background: var(--card-bg);
            box-shadow: 0 25px 50px -20px var(--shadow-color);
            border: 1px solid var(--border-color);
            overflow: hidden;
            isolation: isolate;
        }

        header.hero::before,
        header.hero::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            filter: blur(0px);
            opacity: 0.55;
            z-index: -1;
        }

        header.hero::before {
            background: radial-gradient(circle, rgba(99, 102, 241, 0.45), transparent 70%);
            top: -70px;
            right: -40px;
        }

        header.hero::after {
            background: radial-gradient(circle, rgba(16, 185, 129, 0.4), transparent 70%);
            bottom: -80px;
            left: -20px;
        }

        header.hero h1 {
            font-size: clamp(1.5rem, 2.8vw, 2.1rem);
            font-weight: 700;
            margin: 0 0 8px;
            letter-spacing: -0.02em;
        }

        header.hero p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            background: rgba(99, 102, 241, 0.08);
            color: var(--accent-blue);
        }

        .pill svg {
            width: 18px;
            height: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .card {
            border-radius: 26px;
            background: var(--card-bg);
            padding: clamp(20px, 3vw, 28px);
            border: 1px solid var(--border-color);
            box-shadow: 0 22px 45px -28px var(--shadow-color);
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .empty-state {
            border-radius: 18px;
            border: 1px dashed rgba(244, 63, 94, 0.35);
            background: rgba(244, 63, 94, 0.08);
            color: var(--accent-rose);
            padding: 14px 16px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .card h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }

        .card .subtitle {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .tag.green {
            background: rgba(16, 185, 129, 0.12);
            color: var(--accent-green);
        }

        .tag.rose {
            background: rgba(244, 63, 94, 0.12);
            color: var(--accent-rose);
        }

        .tag.blue {
            background: rgba(99, 102, 241, 0.12);
            color: var(--accent-blue);
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        label span {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 12px 14px;
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.9);
            color: inherit;
            transition: border 0.2s ease, box-shadow 0.2s ease;
        }

        textarea {
            resize: none;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(99, 102, 241, 0.45);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        button.primary {
            border: none;
            border-radius: 18px;
            padding: 14px 18px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            color: white;
            background: linear-gradient(120deg, var(--accent-blue), var(--accent-green));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 12px 28px -12px rgba(99, 102, 241, 0.6);
        }

        button.primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 40px -16px rgba(16, 185, 129, 0.45);
        }

        button.primary:disabled,
        button.primary[disabled] {
            cursor: not-allowed;
            opacity: 0.6;
            box-shadow: none;
            transform: none;
        }

        button.secondary {
            border-radius: 16px;
            border: 1px solid rgba(99, 102, 241, 0.26);
            background: transparent;
            color: var(--accent-blue);
            padding: 12px 18px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: border 0.2s ease, background 0.2s ease;
        }

        button.secondary:hover {
            border-color: rgba(99, 102, 241, 0.45);
            background: rgba(99, 102, 241, 0.08);
        }

        .report-card {
            display: grid;
            gap: 18px;
            align-items: center;
        }

        .report-illustration {
            width: 100%;
            border-radius: 20px;
            background: linear-gradient(140deg, rgba(99, 102, 241, 0.16), rgba(16, 185, 129, 0.2));
            padding: 20px;
            display: grid;
            grid-template-columns: 56px 1fr;
            gap: 14px;
            align-items: center;
            color: var(--accent-blue);
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .report-illustration span.icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.8);
            font-size: 1.8rem;
            color: var(--accent-blue);
            box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.1);
        }

        footer {
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-secondary);
            padding-bottom: 12px;
        }

        @media (min-width: 768px) {
            .report-card {
                grid-template-columns: 1.1fr 0.9fr;
            }
        }
    </style>
</head>
<body>
    <main>
        <header class="hero">
            <h1>Oxford moliya mini ilovasi</h1>
            <p>Daromad va xarajatlarni soniyalarda yuboring, bot esa natijani zudlik bilan tasdiqlaydi.</p>
            <div class="pill-row">
                <span class="pill">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14M12 5v14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Daromad va xarajatlar
                </span>
                <span class="pill">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l4 4 6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Hisobotlar
                </span>
                <span class="pill">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 5h14v14H5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 9h6v6H9z" fill="currentColor" opacity="0.35"/></svg>
                    Mini app interfeysi
                </span>
            </div>
        </header>

        <section class="grid">
            <article class="card">
                <div class="tag green">Naqd / Click</div>
                <div>
                    <h2>Daromad qo'shish</h2>
                    <p class="subtitle">Trening to'lovlari, depozitlar va boshqa tushumlarni kiriting.</p>
                </div>
                <form onsubmit="return false;" id="income-form">
                    <label>
                        <span>Summa</span>
                        <input id="income-amount" type="text" inputmode="numeric" autocomplete="off" placeholder="Masalan, 1 200 000">
                    </label>
                    <label>
                        <span>Sana</span>
                        <input id="income-date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label>
                        <span>To'lov usuli</span>
                        <select id="income-method">
                            <option value="cash">Naqd</option>
                            <option value="click">Click</option>
                        </select>
                    </label>
                    <label>
                        <span>Izoh (ixtiyoriy)</span>
                        <textarea id="income-comment" rows="2" placeholder="Masalan, guruh to'lovi"></textarea>
                    </label>
                    <button type="button" id="income-submit" class="primary">➕ Daromadni yuborish</button>
                </form>
            </article>

            <article class="card">
                <div class="tag rose">Turkum + usul</div>
                <div>
                    <h2>Xarajat qo'shish</h2>
                    <p class="subtitle">Oyliklar, ijara va boshqa sarf-xarajatlarni bir joydan boshqaring.</p>
                </div>
                <form onsubmit="return false;" id="expense-form">
                    <label>
                        <span>Summa</span>
                        <input id="expense-amount" type="text" inputmode="numeric" autocomplete="off" placeholder="Masalan, 850 000">
                    </label>
                    <label>
                        <span>Sana</span>
                        <input id="expense-date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label>
                        <span>To'lov usuli</span>
                        <select id="expense-method">
                            <option value="cash">Naqd</option>
                            <option value="click">Click</option>
                        </select>
                    </label>
                    <label>
                        <span>Turkum</span>
                        <?php if (count($categories) > 0): ?>
                            <select id="expense-category">
                                <option value="">Turkumni tanlang</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id']; ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <div class="empty-state">
                                Veb-ilovada kamida bitta turkum yarating.
                            </div>
                        <?php endif; ?>
                    </label>
                    <label>
                        <span>Izoh (ixtiyoriy)</span>
                        <textarea id="expense-comment" rows="2" placeholder="Masalan, ofis ijarasi"></textarea>
                    </label>
                    <button type="button" id="expense-submit" class="primary" <?php if (count($categories) === 0) echo 'disabled'; ?>>➖ Xarajatni yuborish</button>
                </form>
            </article>
        </section>

        <section class="card report-card">
            <div>
                <div class="tag blue">Joriy oy</div>
                <h2>Hisobotni olish</h2>
                <p class="subtitle">Balans, naqd va Click bo'yicha natijalar to'g'ridan-to'g'ri chatga yuboriladi.</p>
                <button type="button" id="report-submit" class="secondary">📊 Hisobotni yuborish</button>
            </div>
            <div class="report-illustration">
                <span class="icon">📈</span>
                <div>
                    <div>Kiritilgan ma'lumotlar bir necha soniyada qayta ishlanadi.</div>
                    <small style="display:block;margin-top:6px;color:var(--text-secondary);">Natija bot xabarida paydo bo'ladi va CRM bilan sinxronlashadi.</small>
                </div>
            </div>
        </section>

        <footer>
            Mini ilova faqat Telegram ichida ishlaydi. Har bir yuborilgan so'rov bo'yicha bot javobini chatdan kuzating.
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
