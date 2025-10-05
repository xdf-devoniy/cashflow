<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';

define('STATE_INCOME_AMOUNT', 'income_amount');
define('STATE_INCOME_DATE', 'income_date');
define('STATE_INCOME_METHOD', 'income_method');
define('STATE_INCOME_COMMENT', 'income_comment');
define('STATE_EXPENSE_AMOUNT', 'expense_amount');
define('STATE_EXPENSE_DATE', 'expense_date');
define('STATE_EXPENSE_METHOD', 'expense_method');
define('STATE_EXPENSE_CATEGORY', 'expense_category');
define('STATE_EXPENSE_COMMENT', 'expense_comment');
define('STATE_AUTH_PASSWORD', 'auth_password');

$botToken = resolve_bot_token();
if (!$botToken) {
    http_response_code(500);
    echo 'Telegram bot token not configured';
    exit;
}

ensure_expense_tables($conn);
ensure_telegram_tables($conn);
ensure_telegram_auth_table($conn);

$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput ?: 'null', true);

if (!is_array($update)) {
    echo 'OK';
    exit;
}

if (isset($update['message'])) {
    handle_message($conn, $botToken, $update['message']);
} elseif (isset($update['callback_query'])) {
    handle_callback($conn, $botToken, $update['callback_query']);
}

echo 'OK';

function handle_message(mysqli $conn, string $token, array $message): void
{
    $chatId = isset($message['chat']['id']) ? (int) $message['chat']['id'] : 0;
    if ($chatId === 0) {
        return;
    }

    $webAppPayload = $message['web_app_data']['data'] ?? null;
    $text = isset($message['text']) ? trim((string) $message['text']) : '';

    $conversation = load_conversation($conn, $chatId);
    $state = $conversation['state'];
    $payload = $conversation['payload'];

    $isAuthenticated = is_chat_authenticated($conn, $chatId);

    if (!$isAuthenticated) {
        if ($webAppPayload !== null) {
            send_message($token, $chatId, "Iltimos, avval parolni yuboring.");
            return;
        }

        if ($state !== STATE_AUTH_PASSWORD) {
            save_conversation($conn, $chatId, STATE_AUTH_PASSWORD, []);
            send_message($token, $chatId, "Botdan foydalanish uchun parolni kiriting.");
            return;
        }

        if ($text === '') {
            send_message($token, $chatId, "Parolni matn ko'rinishida yuboring.");
            return;
        }

        if (verify_bot_password($text)) {
            mark_chat_authenticated($conn, $chatId);
            clear_conversation($conn, $chatId);
            send_message($token, $chatId, "Tasdiq muvaffaqiyatli. Endi botdan foydalanishingiz mumkin.");
            send_main_menu($token, $chatId);
        } else {
            send_message($token, $chatId, "Parol noto'g'ri. Qayta urinib ko'ring.");
        }

        return;
    }

    if ($webAppPayload !== null) {
        handle_web_app_submission($conn, $token, $chatId, $webAppPayload);
        return;
    }

    if ($text === '') {
        send_message($token, $chatId, "Faqat matnli xabarlar qo'llab-quvvatlanadi.");
        return;
    }

    if (cancel_requested($text)) {
        clear_conversation($conn, $chatId);
        send_message(
            $token,
            $chatId,
            "Jarayon bekor qilindi.",
            ['reply_markup' => remove_keyboard_markup()]
        );
        send_main_menu($token, $chatId);
        return;
    }

    $normalized = mb_strtolower($text, 'UTF-8');
    $isIncomeCommand = in_array($normalized, ['/income', "daromad", "daromad qo'shish", "➕ daromad qo'shish"], true);
    $isExpenseCommand = in_array($normalized, ['/expense', 'xarajat', "xarajat qo'shish", "➖ xarajat qo'shish"], true);
    $isReportCommand = in_array($normalized, ['/report', 'hisobot', '📊 hisobot', 'hisobotlar'], true);
    $isStart = str_starts_with($normalized, '/start');

    if ($isStart) {
        clear_conversation($conn, $chatId);
        send_message($token, $chatId, "Xush kelibsiz! Quyidagi menyudan amalni tanlang.");
        send_main_menu($token, $chatId);
        return;
    }

    if ($isIncomeCommand) {
        start_income_flow($conn, $token, $chatId);
        return;
    }

    if ($isExpenseCommand) {
        start_expense_flow($conn, $token, $chatId);
        return;
    }

    if ($isReportCommand) {
        clear_conversation($conn, $chatId);
        send_monthly_report($conn, $token, $chatId);
        return;
    }

    if ($state === null) {
        send_message($token, $chatId, "Tugmalar orqali amal tanlang yoki /start buyrug'ini yuboring.");
        return;
    }

    switch ($state) {
        case STATE_INCOME_AMOUNT:
            handle_income_amount($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_INCOME_DATE:
            handle_income_date($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_INCOME_METHOD:
            handle_income_method($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_INCOME_COMMENT:
            handle_income_comment($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_EXPENSE_AMOUNT:
            handle_expense_amount($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_EXPENSE_DATE:
            handle_expense_date($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_EXPENSE_METHOD:
            handle_expense_method($conn, $token, $chatId, $text, $payload);
            break;
        case STATE_EXPENSE_COMMENT:
            handle_expense_comment($conn, $token, $chatId, $text, $payload);
            break;
        default:
            send_message($token, $chatId, "Amalni qayta boshlash uchun /start yuboring.");
            clear_conversation($conn, $chatId);
    }
}

function handle_callback(mysqli $conn, string $token, array $callback): void
{
    $message = $callback['message'] ?? null;
    if (!$message) {
        return;
    }

    $chatId = isset($message['chat']['id']) ? (int) $message['chat']['id'] : 0;
    if ($chatId === 0) {
        return;
    }

    $data = (string) ($callback['data'] ?? '');
    $conversation = load_conversation($conn, $chatId);
    $state = $conversation['state'];
    $payload = $conversation['payload'];

    if ($state === STATE_EXPENSE_CATEGORY && str_starts_with($data, 'cat_')) {
        $categoryId = (int) substr($data, 4);
        if ($categoryId > 0) {
            $categoryName = fetch_category_name($conn, $categoryId);
            if ($categoryName === null) {
                answer_callback($token, (string) $callback['id'], 'Turkum topilmadi.');
                return;
            }

            $payload['category_id'] = $categoryId;
            $payload['category_name'] = $categoryName;
            save_conversation($conn, $chatId, STATE_EXPENSE_COMMENT, $payload);
            answer_callback($token, (string) $callback['id'], 'Turkum tanlandi');
            send_message(
                $token,
                $chatId,
                "Izoh kiriting (ixtiyoriy). Agar izoh bo'lmasa, /skip deb yozing.",
                ['reply_markup' => remove_keyboard_markup()]
            );
            return;
        }
    }

    answer_callback($token, (string) $callback['id']);
}

function start_income_flow(mysqli $conn, string $token, int $chatId): void
{
    $payload = ['type' => 'income'];
    save_conversation($conn, $chatId, STATE_INCOME_AMOUNT, $payload);
    send_message(
        $token,
        $chatId,
        "Daromad summasini kiriting (masalan 1 200 000).",
        ['reply_markup' => remove_keyboard_markup()]
    );
}

function start_expense_flow(mysqli $conn, string $token, int $chatId): void
{
    $categories = fetch_categories($conn);
    if (count($categories) === 0) {
        send_message($token, $chatId, "Avval veb-ilovada xarajat turkumlarini yarating.");
        send_main_menu($token, $chatId);
        return;
    }

    $payload = ['type' => 'expense'];
    save_conversation($conn, $chatId, STATE_EXPENSE_AMOUNT, $payload);
    send_message(
        $token,
        $chatId,
        "Xarajat summasini kiriting (masalan 850 000).",
        ['reply_markup' => remove_keyboard_markup()]
    );
}

function handle_income_amount(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $amount = normalize_amount($text);
    if ($amount === null) {
        send_message($token, $chatId, "Summani faqat raqamlarda kiriting, masalan 1250000 yoki 1 250 000.");
        return;
    }

    $payload['amount'] = $amount;
    save_conversation($conn, $chatId, STATE_INCOME_DATE, $payload);
    send_message($token, $chatId, "Sana kiriting (YYYY-MM-DD). Sukut bo'yicha bugungi sana qo'llanadi.");
}

function handle_income_date(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    if ($normalized === '/skip') {
        $date = date('Y-m-d');
    } else {
        $date = parse_date_input($text);
    }
    if ($date === null) {
        send_message($token, $chatId, "Sana noto'g'ri. Masalan, 2024-05-01 yoki 01.05.2024 shaklida kiriting.");
        return;
    }

    $payload['date'] = $date;
    save_conversation($conn, $chatId, STATE_INCOME_METHOD, $payload);
    send_message(
        $token,
        $chatId,
        "To'lov usulini tanlang.",
        ['reply_markup' => payment_keyboard()]
    );
}

function handle_income_method(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $normalized = mb_strtolower($text, 'UTF-8');
    $method = null;
    if (in_array($normalized, ['naqd', 'cash'], true)) {
        $method = 'cash';
    } elseif ($normalized === 'click') {
        $method = 'click';
    }

    if ($method === null) {
        send_message($token, $chatId, "Faqat Naqd yoki Click ni tanlang.");
        return;
    }

    $payload['payment_method'] = $method;
    save_conversation($conn, $chatId, STATE_INCOME_COMMENT, $payload);
    send_message(
        $token,
        $chatId,
        "Izoh kiriting (ixtiyoriy). Agar izoh bo'lmasa, /skip deb yozing.",
        ['reply_markup' => remove_keyboard_markup()]
    );
}

function handle_income_comment(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    if (mb_strtolower(trim($text), 'UTF-8') === '/skip') {
        $text = '';
    }

    $payload['comment'] = $text;
    $success = persist_transaction($conn, $payload);

    if ($success) {
        $methodLabel = $payload['payment_method'] === 'cash' ? 'Naqd' : 'Click';
        $dateLabel = DateTime::createFromFormat('Y-m-d', $payload['date'])->format('d.m.Y');
        $message = sprintf(
            "<b>Daromad saqlandi</b>\n\nSumma: %s so'm\nSana: %s\nUsul: %s%s",
            format_currency((float) $payload['amount']),
            $dateLabel,
            $methodLabel,
            $payload['comment'] !== '' ? "\nIzoh: " . html_escape($payload['comment']) : ''
        );
        send_message($token, $chatId, $message, [
            'parse_mode' => 'HTML',
            'reply_markup' => remove_keyboard_markup(),
        ]);
    } else {
        send_message($token, $chatId, "Daromadni saqlashda xatolik yuz berdi. Iltimos, qayta urinib ko'ring.");
    }

    clear_conversation($conn, $chatId);
    send_main_menu($token, $chatId);
}

function handle_expense_amount(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $amount = normalize_amount($text);
    if ($amount === null) {
        send_message($token, $chatId, "Summani faqat raqamlarda kiriting, masalan 350000 yoki 350 000.");
        return;
    }

    $payload['amount'] = $amount;
    save_conversation($conn, $chatId, STATE_EXPENSE_DATE, $payload);
    send_message($token, $chatId, "Sana kiriting (YYYY-MM-DD). Sukut bo'yicha bugungi sana qo'llanadi.");
}

function handle_expense_date(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    if ($normalized === '/skip') {
        $date = date('Y-m-d');
    } else {
        $date = parse_date_input($text);
    }
    if ($date === null) {
        send_message($token, $chatId, "Sana noto'g'ri. Masalan 2024-05-01 shaklida kiriting.");
        return;
    }

    $payload['date'] = $date;
    save_conversation($conn, $chatId, STATE_EXPENSE_METHOD, $payload);
    send_message(
        $token,
        $chatId,
        "To'lov usulini tanlang.",
        ['reply_markup' => payment_keyboard()]
    );
}

function handle_expense_method(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    $normalized = mb_strtolower($text, 'UTF-8');
    $method = null;
    if (in_array($normalized, ['naqd', 'cash'], true)) {
        $method = 'cash';
    } elseif ($normalized === 'click') {
        $method = 'click';
    }

    if ($method === null) {
        send_message($token, $chatId, "Faqat Naqd yoki Click ni tanlang.");
        return;
    }

    $categories = fetch_categories($conn);
    if (count($categories) === 0) {
        send_message($token, $chatId, "Turkum topilmadi. Veb-ilovada kamida bitta turkum yarating.");
        clear_conversation($conn, $chatId);
        send_main_menu($token, $chatId);
        return;
    }

    $payload['payment_method'] = $method;
    save_conversation($conn, $chatId, STATE_EXPENSE_CATEGORY, $payload);
    send_message(
        $token,
        $chatId,
        "Turkumni tanlang.",
        ['reply_markup' => ['inline_keyboard' => build_category_keyboard($categories)]]
    );
}

function handle_expense_comment(mysqli $conn, string $token, int $chatId, string $text, array $payload): void
{
    if (mb_strtolower(trim($text), 'UTF-8') === '/skip') {
        $text = '';
    }

    $payload['comment'] = $text;
    $success = persist_transaction($conn, $payload);

    if ($success) {
        $methodLabel = $payload['payment_method'] === 'cash' ? 'Naqd' : 'Click';
        $dateLabel = DateTime::createFromFormat('Y-m-d', $payload['date'])->format('d.m.Y');
        $message = sprintf(
            "<b>Xarajat saqlandi</b>\n\nSumma: %s so'm\nSana: %s\nUsul: %s\nTurkum: %s%s",
            format_currency((float) $payload['amount']),
            $dateLabel,
            $methodLabel,
            html_escape($payload['category_name'] ?? ''),
            $payload['comment'] !== '' ? "\nIzoh: " . html_escape($payload['comment']) : ''
        );
        send_message($token, $chatId, $message, [
            'parse_mode' => 'HTML',
            'reply_markup' => remove_keyboard_markup(),
        ]);
    } else {
        send_message($token, $chatId, "Xarajatni saqlashda xatolik yuz berdi. Iltimos, qayta urinib ko'ring.");
    }

    clear_conversation($conn, $chatId);
    send_main_menu($token, $chatId);
}

function handle_web_app_submission(mysqli $conn, string $token, int $chatId, string $rawData): void
{
    $decoded = json_decode($rawData, true);
    if (!is_array($decoded)) {
        send_message($token, $chatId, 'Mini ilovadan noto\'g\'ri ma\'lumot keldi.');
        return;
    }

    $action = $decoded['type'] ?? '';
    switch ($action) {
        case 'income':
            $amountInput = $decoded['amount'] ?? '';
            $amount = is_numeric($amountInput) ? (float) $amountInput : normalize_amount((string) $amountInput);
            $date = $decoded['date'] ?? date('Y-m-d');
            $method = $decoded['payment_method'] ?? '';
            $comment = trim((string) ($decoded['comment'] ?? ''));

            $dateParsed = parse_date_input((string) $date) ?? $date;

            $payload = [
                'type' => 'income',
                'amount' => $amount,
                'date' => $dateParsed,
                'payment_method' => $method,
                'comment' => $comment,
            ];

            if ($amount === null || $amount <= 0 || !in_array($method, ['cash', 'click'], true)) {
                send_message($token, $chatId, 'Mini ilova: ma\'lumotlar to\'liq emas.');
                return;
            }

            if (!persist_transaction($conn, $payload)) {
                send_message($token, $chatId, 'Mini ilova: daromadni saqlashda xatolik yuz berdi.');
                return;
            }

            $methodLabel = $method === 'cash' ? 'Naqd' : 'Click';
            $dateObj = DateTime::createFromFormat('Y-m-d', $payload['date']);
            if (!$dateObj) {
                $dateObj = new DateTime($payload['date']);
            }
            $dateLabel = $dateObj->format('d.m.Y');
            $message = sprintf(
                "<b>Mini ilova orqali daromad qo'shildi</b>\n\nSumma: %s so'm\nSana: %s\nUsul: %s%s",
                format_currency((float) $amount),
                $dateLabel,
                $methodLabel,
                $comment !== '' ? "\nIzoh: " . html_escape($comment) : ''
            );
            send_message($token, $chatId, $message, ['parse_mode' => 'HTML']);
            clear_conversation($conn, $chatId);
            send_main_menu($token, $chatId);
            return;

        case 'expense':
            $amountInput = $decoded['amount'] ?? '';
            $amount = is_numeric($amountInput) ? (float) $amountInput : normalize_amount((string) $amountInput);
            $date = $decoded['date'] ?? date('Y-m-d');
            $method = $decoded['payment_method'] ?? '';
            $comment = trim((string) ($decoded['comment'] ?? ''));
            $categoryId = isset($decoded['category_id']) ? (int) $decoded['category_id'] : 0;

            $dateParsed = parse_date_input((string) $date) ?? $date;

            if ($amount === null || $amount <= 0 || !in_array($method, ['cash', 'click'], true) || $categoryId <= 0) {
                send_message($token, $chatId, 'Mini ilova: ma\'lumotlar to\'liq emas.');
                return;
            }

            $categoryName = fetch_category_name($conn, $categoryId);
            if ($categoryName === null) {
                send_message($token, $chatId, 'Mini ilova: tanlangan turkum topilmadi.');
                return;
            }

            $payload = [
                'type' => 'expense',
                'amount' => $amount,
                'date' => $dateParsed,
                'payment_method' => $method,
                'comment' => $comment,
                'category_id' => $categoryId,
                'category_name' => $categoryName,
            ];

            if (!persist_transaction($conn, $payload)) {
                send_message($token, $chatId, 'Mini ilova: xarajatni saqlashda xatolik yuz berdi.');
                return;
            }

            $methodLabel = $method === 'cash' ? 'Naqd' : 'Click';
            $dateObj = DateTime::createFromFormat('Y-m-d', $payload['date']);
            if (!$dateObj) {
                $dateObj = new DateTime($payload['date']);
            }
            $dateLabel = $dateObj->format('d.m.Y');
            $message = sprintf(
                "<b>Mini ilova orqali xarajat qo'shildi</b>\n\nSumma: %s so'm\nSana: %s\nUsul: %s\nTurkum: %s%s",
                format_currency((float) $amount),
                $dateLabel,
                $methodLabel,
                html_escape($categoryName),
                $comment !== '' ? "\nIzoh: " . html_escape($comment) : ''
            );
            send_message($token, $chatId, $message, ['parse_mode' => 'HTML']);
            clear_conversation($conn, $chatId);
            send_main_menu($token, $chatId);
            return;

        case 'report':
            clear_conversation($conn, $chatId);
            send_monthly_report($conn, $token, $chatId);
            return;

        default:
            send_message($token, $chatId, 'Mini ilova buyruqni tushunmadi.');
    }
}

function persist_transaction(mysqli $conn, array $payload): bool
{
    $type = $payload['type'] ?? '';
    $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
    $date = $payload['date'] ?? date('Y-m-d');
    $method = $payload['payment_method'] ?? '';
    $comment = trim((string) ($payload['comment'] ?? ''));
    $categoryId = isset($payload['category_id']) ? (int) $payload['category_id'] : 0;

    if ($amount <= 0 || !in_array($type, ['income', 'expense'], true) || !in_array($method, ['cash', 'click'], true)) {
        return false;
    }

    $cash = $method === 'cash' ? 1 : 0;
    $click = $method === 'click' ? 1 : 0;
    $cashIn = $type === 'income' ? 1 : 0;
    $cashOut = $type === 'expense' ? 1 : 0;
    $xarajat = $type === 'expense' ? 1 : 0;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('diiiiiss', $amount, $cash, $click, $cashIn, $cashOut, $xarajat, $comment, $date);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $transactionId = $stmt->insert_id;
        $stmt->close();

        if ($type === 'expense' && $categoryId > 0) {
            $categoryStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
            if (!$categoryStmt) {
                throw new RuntimeException($conn->error);
            }
            $categoryStmt->bind_param('ii', $transactionId, $categoryId);
            if (!$categoryStmt->execute()) {
                throw new RuntimeException($categoryStmt->error);
            }
            $categoryStmt->close();
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Telegram transaction error: ' . $e->getMessage());
        return false;
    }
}

function fetch_category_name(mysqli $conn, int $categoryId): ?string
{
    $stmt = $conn->prepare('SELECT name FROM expense_categories WHERE id = ?');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $stmt->bind_result($name);
    $found = $stmt->fetch();
    $stmt->close();

    return $found ? $name : null;
}

function build_category_keyboard(array $categories): array
{
    $rows = [];
    $currentRow = [];
    foreach ($categories as $category) {
        $currentRow[] = [
            'text' => $category['name'],
            'callback_data' => 'cat_' . $category['id'],
        ];
        if (count($currentRow) === 2) {
            $rows[] = $currentRow;
            $currentRow = [];
        }
    }
    if (!empty($currentRow)) {
        $rows[] = $currentRow;
    }

    return $rows;
}

function send_monthly_report(mysqli $conn, string $token, int $chatId): void
{
    $start = date('Y-m-01');
    $end = date('Y-m-t');

    $stmt = $conn->prepare("SELECT\n            COALESCE(SUM(CASE WHEN cash_in = 1 THEN payment END), 0) AS total_income,\n            COALESCE(SUM(CASE WHEN cash_out = 1 THEN payment END), 0) AS total_expense,\n            COALESCE(SUM(CASE WHEN cash_in = 1 AND cash = 1 THEN payment END), 0) AS income_cash,\n            COALESCE(SUM(CASE WHEN cash_in = 1 AND click = 1 THEN payment END), 0) AS income_click,\n            COALESCE(SUM(CASE WHEN cash_out = 1 AND cash = 1 THEN payment END), 0) AS expense_cash,\n            COALESCE(SUM(CASE WHEN cash_out = 1 AND click = 1 THEN payment END), 0) AS expense_click\n        FROM transactions\n        WHERE date BETWEEN ? AND ?");

    $totals = [
        'total_income' => 0.0,
        'total_expense' => 0.0,
        'income_cash' => 0.0,
        'income_click' => 0.0,
        'expense_cash' => 0.0,
        'expense_click' => 0.0,
    ];

    if ($stmt) {
        $stmt->bind_param('ss', $start, $end);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                if ($row) {
                    foreach ($totals as $key => $_) {
                        $totals[$key] = (float) ($row[$key] ?? 0);
                    }
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    $cashLeft = $totals['income_cash'] - $totals['expense_cash'];
    $clickLeft = $totals['income_click'] - $totals['expense_click'];
    $balance = ($totals['total_income'] - $totals['total_expense']);

    $topCategories = fetch_top_categories($conn, $start, $end);

    $lines = [];
    $lines[] = '<b>📊 Hisobot (' . html_escape(month_label($start)) . ')</b>';
    $lines[] = '';
    $lines[] = '<b>Daromad:</b> ' . format_currency($totals['total_income']) . " so'm";
    $lines[] = ' • Naqd: ' . format_currency($totals['income_cash']) . " so'm";
    $lines[] = ' • Click: ' . format_currency($totals['income_click']) . " so'm";
    $lines[] = '';
    $lines[] = '<b>Xarajat:</b> ' . format_currency($totals['total_expense']) . " so'm";
    $lines[] = ' • Naqd: ' . format_currency($totals['expense_cash']) . " so'm";
    $lines[] = ' • Click: ' . format_currency($totals['expense_click']) . " so'm";
    $lines[] = '';
    $lines[] = '<b>Balans:</b> ' . format_currency($balance) . " so'm";
    $lines[] = 'Naqd qoldiq: ' . format_currency($cashLeft) . " so'm";
    $lines[] = 'Click qoldiq: ' . format_currency($clickLeft) . " so'm";
    $lines[] = 'Umumiy qoldiq: ' . format_currency($cashLeft + $clickLeft) . " so'm";

    if (count($topCategories) > 0) {
        $lines[] = '';
        $lines[] = '<b>Top xarajat turkumlari:</b>';
        foreach ($topCategories as $idx => $category) {
            $lines[] = ($idx + 1) . '. ' . html_escape($category['name']) . ' — ' . format_currency($category['total']) . " so'm";
        }
    }

    send_message($token, $chatId, implode("\n", $lines), [
        'parse_mode' => 'HTML',
        'reply_markup' => remove_keyboard_markup(),
        'disable_web_page_preview' => true,
    ]);

    send_main_menu($token, $chatId);
}

function fetch_top_categories(mysqli $conn, string $start, string $end): array
{
    $categories = [];
    $stmt = $conn->prepare("SELECT c.name, COALESCE(SUM(t.payment), 0) AS total\n        FROM expense_categories c\n        JOIN transaction_categories tc ON tc.category_id = c.id\n        JOIN transactions t ON t.id = tc.transaction_id\n        WHERE t.cash_out = 1 AND t.date BETWEEN ? AND ?\n        GROUP BY c.id, c.name\n        ORDER BY total DESC\n        LIMIT 5");

    if ($stmt) {
        $stmt->bind_param('ss', $start, $end);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $categories[] = [
                        'name' => $row['name'],
                        'total' => (float) $row['total'],
                    ];
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    return $categories;
}
