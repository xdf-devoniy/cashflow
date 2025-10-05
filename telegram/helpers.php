<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tashkent');

const TELEGRAM_DEFAULT_PASSWORD_HASH = '$2y$12$wzNcrKHWO/DpmBPXRYqEi.TM/fXjv1fabtYPHQjgGigV0g8AzypSW';
const TELEGRAM_DEFAULT_MINI_APP_URL = 'https://oxfordlc.uz/cashflow/telegram/miniapp.php';

/**
 * Lazily load the Telegram configuration array.
 */
function load_bot_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $config = [];
    $configPath = __DIR__ . '/config.php';
    if (is_file($configPath)) {
        $fileConfig = include $configPath;
        if (is_array($fileConfig)) {
            $config = $fileConfig;
        }
    }

    return $config;
}

/**
 * Resolve the Telegram bot token from environment variables or an optional config file.
 */
function resolve_bot_token(): ?string
{
    $envToken = getenv('TELEGRAM_BOT_TOKEN');
    if ($envToken) {
        return trim($envToken);
    }

    $config = load_bot_config();
    if (!empty($config['token'])) {
        return trim((string) $config['token']);
    }

    return null;
}

/**
 * Resolve the hashed password required to unlock the bot.
 */
function resolve_bot_password_hash(): string
{
    $envHash = getenv('TELEGRAM_BOT_PASSWORD_HASH');
    if ($envHash) {
        return trim($envHash);
    }

    $config = load_bot_config();
    if (!empty($config['password_hash'])) {
        return trim((string) $config['password_hash']);
    }

    return TELEGRAM_DEFAULT_PASSWORD_HASH;
}

/**
 * Resolve the mini app URL used in keyboards.
 */
function resolve_mini_app_url(): string
{
    $envUrl = getenv('TELEGRAM_MINI_APP_URL');
    if ($envUrl) {
        return trim($envUrl);
    }

    $config = load_bot_config();
    if (!empty($config['mini_app_url'])) {
        return trim((string) $config['mini_app_url']);
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base = rtrim($scheme . '://' . $host, '/');
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/telegram/handler.php';
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($dir === '.' || $dir === '/') {
            $dir = '/telegram';
        }
        return $base . rtrim($dir, '/') . '/miniapp.php';
    }

    return TELEGRAM_DEFAULT_MINI_APP_URL;
}

function verify_bot_password(string $input): bool
{
    $hash = resolve_bot_password_hash();
    if ($hash === '') {
        return false;
    }

    return password_verify($input, $hash);
}

/**
 * Ensure shared expense tables exist before transactions are stored.
 */
function ensure_expense_tables(mysqli $conn): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS expense_categories (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            name VARCHAR(120) NOT NULL UNIQUE,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS transaction_categories (\n            transaction_id INT NOT NULL PRIMARY KEY,\n            category_id INT NOT NULL,\n            CONSTRAINT fk_tc_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,\n            CONSTRAINT fk_tc_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        $conn->query($sql);
    }
}

function fetch_categories(mysqli $conn): array
{
    $categories = [];
    $result = $conn->query('SELECT id, name FROM expense_categories ORDER BY name');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];
        }
        $result->free();
    }

    return $categories;
}

function ensure_telegram_auth_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS telegram_auth (\n        chat_id BIGINT PRIMARY KEY,\n        verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

function is_chat_authenticated(mysqli $conn, int $chatId): bool
{
    $stmt = $conn->prepare('SELECT chat_id FROM telegram_auth WHERE chat_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $stmt->store_result();
    $isAuthenticated = $stmt->num_rows > 0;
    $stmt->close();

    return $isAuthenticated;
}

function mark_chat_authenticated(mysqli $conn, int $chatId): void
{
    $stmt = $conn->prepare('INSERT INTO telegram_auth (chat_id, verified_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE verified_at = NOW()');
    if ($stmt) {
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Retrieve all chat IDs that have successfully authenticated with the bot.
 */
function fetch_authenticated_chat_ids(mysqli $conn): array
{
    $chatIds = [];
    $result = $conn->query('SELECT chat_id FROM telegram_auth');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $chatIds[] = (int) $row['chat_id'];
        }
        $result->free();
    }

    return $chatIds;
}

/**
 * Create the Telegram conversation table if it does not exist.
 */
function ensure_telegram_tables(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS telegram_conversations (\n        chat_id BIGINT PRIMARY KEY,\n        state VARCHAR(50) DEFAULT NULL,\n        payload TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

/**
 * Low-level helper for Telegram Bot API requests.
 */
function telegram_request(string $token, string $method, array $params): array
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'description' => $error];
    }

    curl_close($ch);
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'description' => 'Invalid JSON response'];
    }

    return $decoded;
}

/**
 * Send a standard text message to the chat.
 */
function send_message(string $token, int $chatId, string $text, array $options = []): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
    ];

    if (!empty($options['parse_mode'])) {
        $payload['parse_mode'] = $options['parse_mode'];
    }

    if (!empty($options['disable_web_page_preview'])) {
        $payload['disable_web_page_preview'] = $options['disable_web_page_preview'];
    }

    if (!empty($options['reply_markup'])) {
        $payload['reply_markup'] = json_encode($options['reply_markup'], JSON_UNESCAPED_UNICODE);
    }

    telegram_request($token, 'sendMessage', $payload);
}

/**
 * Send a response to acknowledge callback queries.
 */
function answer_callback(string $token, string $callbackId, string $text = ''): void
{
    $payload = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $payload['text'] = $text;
        $payload['show_alert'] = false;
    }
    telegram_request($token, 'answerCallbackQuery', $payload);
}

/**
 * Provide the persistent main menu keyboard.
 */
function send_main_menu(string $token, int $chatId): void
{
    $miniAppUrl = resolve_mini_app_url();
    $keyboard = [
        'keyboard' => [
            [
                [
                    'text' => '📱 Mini ilova',
                    'web_app' => ['url' => $miniAppUrl],
                ],
            ],
            [
                ['text' => "➕ Daromad qo'shish"],
                ['text' => "➖ Xarajat qo'shish"],
            ],
            [
                ['text' => '📊 Hisobot'],
            ],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ];

    send_message(
        $token,
        $chatId,
        "Salom! Kerakli amalni tanlang yoki mini ilovani oching.",
        [
            'reply_markup' => $keyboard,
        ]
    );
}

function remove_keyboard_markup(): array
{
    return ['remove_keyboard' => true];
}

function html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_currency(float $value): string
{
    return number_format($value, 0, '.', ' ');
}

function normalize_amount(string $input): ?float
{
    $clean = preg_replace('/[^0-9.,-]/u', '', $input);
    if ($clean === null || $clean === '') {
        return null;
    }

    $clean = str_replace([' ', "\u{00A0}"], '', $clean);
    $commaCount = substr_count($clean, ',');
    $dotCount = substr_count($clean, '.');

    if ($commaCount > 0 && $dotCount === 0) {
        $clean = str_replace(',', '.', $clean);
    } elseif ($commaCount > 0 && $dotCount > 0) {
        if (strrpos($clean, ',') > strrpos($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    }

    if (!is_numeric($clean)) {
        return null;
    }

    $amount = (float) $clean;
    return $amount > 0 ? $amount : null;
}

function parse_date_input(string $input): ?string
{
    $trimmed = trim(mb_strtolower($input, 'UTF-8'));
    if ($trimmed === '' || $trimmed === 'bugun') {
        return date('Y-m-d');
    }

    if ($trimmed === "kecha") {
        return date('Y-m-d', strtotime('-1 day'));
    }

    $candidates = [
        'Y-m-d',
        'd.m.Y',
        'd/m/Y',
    ];

    foreach ($candidates as $format) {
        $date = DateTime::createFromFormat($format, $input);
        if ($date instanceof DateTime && $date->format($format) === $input) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function load_conversation(mysqli $conn, int $chatId): array
{
    $stmt = $conn->prepare('SELECT state, payload FROM telegram_conversations WHERE chat_id = ?');
    if (!$stmt) {
        return ['state' => null, 'payload' => []];
    }

    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $stmt->bind_result($state, $payloadJson);
    if ($stmt->fetch()) {
        $stmt->close();
        $payload = [];
        if ($payloadJson) {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        return ['state' => $state, 'payload' => $payload];
    }
    $stmt->close();
    return ['state' => null, 'payload' => []];
}

function save_conversation(mysqli $conn, int $chatId, string $state, array $payload): void
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare('INSERT INTO telegram_conversations (chat_id, state, payload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), payload = VALUES(payload), updated_at = CURRENT_TIMESTAMP');
    if ($stmt) {
        $stmt->bind_param('iss', $chatId, $state, $payloadJson);
        $stmt->execute();
        $stmt->close();
    }
}

function clear_conversation(mysqli $conn, int $chatId): void
{
    $stmt = $conn->prepare('DELETE FROM telegram_conversations WHERE chat_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stmt->close();
    }
}

function payment_keyboard(): array
{
    return [
        'keyboard' => [
            ['Naqd', 'Click'],
            ['Bekor qilish'],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

function cancel_requested(string $text): bool
{
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    return in_array($normalized, ['/cancel', 'bekor qilish', 'bekor', 'cancel'], true);
}

function month_label(string $date): string
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
    $months = [
        '01' => 'Yanvar',
        '02' => 'Fevral',
        '03' => 'Mart',
        '04' => 'Aprel',
        '05' => 'May',
        '06' => 'Iyun',
        '07' => 'Iyul',
        '08' => 'Avgust',
        '09' => 'Sentyabr',
        '10' => 'Oktyabr',
        '11' => 'Noyabr',
        '12' => 'Dekabr',
    ];

    $monthKey = $dateTime->format('m');
    $year = $dateTime->format('Y');
    $monthName = $months[$monthKey] ?? $dateTime->format('F');
    return $monthName . ' ' . $year;
}
