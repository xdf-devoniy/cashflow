<?php
return [
    'token' => getenv('TELEGRAM_BOT_TOKEN') ?: '8020796396:AAGiB5WAs7r_FfOaV1teBNF326ew6NX82Gw',
    'password_hash' => getenv('TELEGRAM_BOT_PASSWORD_HASH') ?: '$2y$12$wzNcrKHWO/DpmBPXRYqEi.TM/fXjv1fabtYPHQjgGigV0g8AzypSW',
    'mini_app_url' => getenv('TELEGRAM_MINI_APP_URL') ?: 'https://oxfordlc.uz/cashflow/telegram/miniapp.php',
    'admin_chat_ids' => getenv('TELEGRAM_ADMIN_CHAT_IDS') ?: [899454270],
];
