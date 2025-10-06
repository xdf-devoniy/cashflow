<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$token = $config['token'] ?? null;

if (!$token) {
    http_response_code(500);
    echo 'Bot token is not configured.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/handler.php';
    return;
}

$action = $_GET['action'] ?? null;

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo 'PHP cURL kengaytmasi yoqilmagan.';
    exit;
}

function telegram_api_request(string $token, string $method, array $params = []): array
{
    $url = sprintf('https://api.telegram.org/bot%s/%s', $token, $method);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'description' => $error ?: 'Curl error',
        ]; 
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'description' => sprintf('Unexpected response (HTTP %d): %s', $statusCode, $response),
        ];
    }

    return $decoded;
}

function current_webhook_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/telegram/index.php'), '/');
    $path = ($script ? $script : '') . '/index.php';

    return sprintf('%s://%s%s', $scheme, $host, $path);
}

$webhookUrl = current_webhook_url();
$result = null;
$webhookInfo = telegram_api_request($token, 'getWebhookInfo');

if ($action === 'set-webhook') {
    $result = telegram_api_request($token, 'setWebhook', ['url' => $webhookUrl]);
} elseif ($action === 'delete-webhook') {
    $result = telegram_api_request($token, 'deleteWebhook');
}

?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram bot konfiguratsiyasi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.3/dist/tailwind.min.css">
</head>
<body class="bg-slate-100 text-slate-900">
    <main class="max-w-3xl mx-auto px-6 py-12">
        <section class="bg-white shadow-xl rounded-2xl p-8 space-y-6">
            <header>
                <h1 class="text-2xl font-semibold text-slate-900">Telegram bot integratsiyasi</h1>
                <p class="mt-2 text-sm text-slate-600">
                    Ushbu sahifa webhook holatini tekshirish va sozlash uchun mo'ljallangan. Bot tokeni serverda saqlangan.
                </p>
            </header>

            <div class="space-y-4">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <h2 class="text-lg font-medium text-slate-800">Webhook URL</h2>
                    <p class="mt-2 text-sm text-slate-600 break-words"><?php echo htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>

                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <h2 class="text-lg font-medium text-slate-800">Joriy webhook ma'lumoti</h2>
                    <pre class="mt-2 text-xs text-slate-600 whitespace-pre-wrap"><?php echo htmlspecialchars(json_encode($webhookInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="?action=set-webhook" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium transition">
                        Webhookni o'rnatish
                    </a>
                    <a href="?action=delete-webhook" class="inline-flex items-center px-4 py-2 rounded-lg bg-rose-500 hover:bg-rose-600 text-white text-sm font-medium transition">
                        Webhookni o'chirish
                    </a>
                    <a href="https://t.me/oxfordmoliyaboty" target="_blank" rel="noopener" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-800 text-sm font-medium transition">
                        Botni ochish ↗
                    </a>
                </div>

                <?php if ($result !== null): ?>
                    <div class="p-4 rounded-xl border <?php echo $result['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'; ?>">
                        <h3 class="text-sm font-semibold mb-2">So'nggi amal natijasi</h3>
                        <pre class="text-xs whitespace-pre-wrap"><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-slate-500">
                    Eslatma: Agar serveringiz HTTPS bo'lmasa, Telegram webhook ishlamaydi. Zarur hollarda <code>deleteWebhook</code> buyrug'i orqali qayta sozlang.
                </p>
            </div>
        </section>
    </main>
</body>
</html>
