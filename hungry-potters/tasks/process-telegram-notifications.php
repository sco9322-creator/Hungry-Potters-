<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/telegram.php';

try {
    $processed = telegram_process_queue($pdo);
    fwrite(STDOUT, "Processed {$processed} Telegram notification(s).\n");
} catch (Throwable $exception) {
    error_log('Telegram notification worker failed: ' . $exception->getMessage());
    fwrite(STDERR, "Telegram notification worker failed.\n");
    exit(1);
}
