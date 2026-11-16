<?php
declare(strict_types=1);

function telegram_escape(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegram_build_message(string $type, array $data): string
{
    if ($type === 'low_stock') {
        $ingredients = $data['ingredients'] ?? null;
        if (!is_array($ingredients) || $ingredients === []) {
            throw new InvalidArgumentException('A low-stock notification needs at least one ingredient.');
        }

        $lines = ['<b>Low stock alert</b>'];
        foreach ($ingredients as $ingredient) {
            if (
                !is_array($ingredient)
                || !isset($ingredient['name'], $ingredient['remaining'], $ingredient['unit'])
            ) {
                throw new InvalidArgumentException('A low-stock notification contains an invalid ingredient.');
            }
            $lines[] = telegram_escape($ingredient['name'])
                . ': ' . telegram_escape($ingredient['remaining'])
                . ' ' . telegram_escape($ingredient['unit']) . ' remaining';
        }
        return implode("\n", $lines);
    }

    if ($type === 'takeout_order') {
        $slipNumber = $data['slip_number'] ?? null;
        $items = $data['items'] ?? null;
        $total = $data['total'] ?? null;
        if (!is_string($slipNumber) || $slipNumber === '' || !is_array($items) || $items === []) {
            throw new InvalidArgumentException('A takeout notification needs a slip number and order items.');
        }

        $lines = ['<b>New takeout order</b>', 'Slip: ' . telegram_escape($slipNumber), 'Items:'];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['item_name'], $item['quantity'])) {
                throw new InvalidArgumentException('A takeout notification contains an invalid item.');
            }
            $lines[] = '• ' . telegram_escape($item['quantity'])
                . ' × ' . telegram_escape($item['item_name']);
        }
        $lines[] = 'Total: ₱' . telegram_escape($total);
        return implode("\n", $lines);
    }

    if ($type === 'order_ready') {
        $slipNumber = $data['slip_number'] ?? null;
        if (is_string($slipNumber) && $slipNumber !== '') {
            return '<b>Order ready</b>' . "\n" . 'Slip: ' . telegram_escape($slipNumber);
        }
        $orderNumber = $data['order_number'] ?? null;
        $queueNumber = $data['queue_number'] ?? null;
        if (!is_string($orderNumber) || $orderNumber === '' || !is_numeric($queueNumber)) {
            throw new InvalidArgumentException('An order-ready notification needs an order or slip number.');
        }
        return '<b>Order ready</b>' . "\n"
            . 'Order: ' . telegram_escape($orderNumber)
            . ' (Q-' . telegram_escape(str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT)) . ')';
    }

    if ($type === 'test') {
        return '<b>Hungry Potters Telegram test</b>' . "\n" . 'Your bot notifications are connected.';
    }

    throw new InvalidArgumentException('Unsupported Telegram notification type.');
}

function telegram_send_message(string $message): void
{
    $configPath = __DIR__ . '/../config/telegram.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Telegram configuration is missing. Copy config/telegram.example.php to config/telegram.php.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Telegram configuration must return an array.');
    }
    $botToken = $config['bot_token'] ?? null;
    $chatId = $config['chat_id'] ?? null;
    if (
        !is_string($botToken) || $botToken === '' || str_contains($botToken, 'PASTE_')
        || (!is_string($chatId) && !is_int($chatId))
        || (string) $chatId === '' || str_contains((string) $chatId, 'PASTE_')
    ) {
        throw new RuntimeException('Set a valid Telegram bot token and chat ID in config/telegram.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled.');
    }

    $curl = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Telegram request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => (string) $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 2500,
        CURLOPT_NOSIGNAL => true,
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($response)) {
        throw new RuntimeException($curlError !== '' ? $curlError : 'Telegram request failed.');
    }
    try {
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Telegram returned an invalid response.');
    }
    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($result) || ($result['ok'] ?? false) !== true) {
        $description = is_array($result) && isset($result['description']) && is_string($result['description'])
            ? $result['description']
            : 'Telegram rejected the message.';
        throw new RuntimeException($description);
    }
}

function telegram_update_log(PDO $pdo, int $id, string $status, ?string $errorMessage = null): void
{
    $statement = $pdo->prepare(
        'UPDATE notification_log
         SET status = :status, error_message = :error_message,
             sent_at = CASE WHEN :sent_at_status = \'sent\' THEN NOW() ELSE sent_at END
         WHERE id = :id'
    );
    $statement->execute([
        'status' => $status,
        'error_message' => $errorMessage,
        'sent_at_status' => $status,
        'id' => $id,
    ]);
}

function telegram_notify(PDO $pdo, string $type, array $data = []): array
{
    try {
        $message = telegram_build_message($type, $data);
        $insert = $pdo->prepare(
            'INSERT INTO notification_log (type, message, status)
             VALUES (:type, :message, \'queued\')'
        );
        $insert->execute(['type' => $type, 'message' => $message]);
        $notificationId = (int) $pdo->lastInsertId();

        if ($type !== 'test') {
            return ['success' => true, 'message' => 'Notification queued.'];
        }

        try {
            telegram_send_message($message);
            telegram_update_log($pdo, $notificationId, 'sent');
            return ['success' => true, 'message' => 'Test message sent to Telegram.'];
        } catch (Throwable $exception) {
            telegram_update_log($pdo, $notificationId, 'failed', $exception->getMessage());
            error_log('Telegram test message failed: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Telegram test failed: ' . $exception->getMessage()];
        }
    } catch (Throwable $exception) {
        error_log('Telegram notification could not be queued: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'The notification could not be queued.'];
    }
}

function telegram_process_queue(PDO $pdo, int $limit = 25): int
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Telegram queue processing is available only from the command line.');
    }

    $lock = (int) $pdo->query("SELECT GET_LOCK('hungry_potters_telegram_worker', 0)")->fetchColumn();
    if ($lock !== 1) {
        return 0;
    }

    try {
        $limit = max(1, min(100, $limit));
        $statement = $pdo->query(
            "SELECT id, message
             FROM notification_log
             WHERE status = 'queued' AND type <> 'test'
             ORDER BY id
             LIMIT {$limit}"
        );
        $notifications = $statement->fetchAll();
        foreach ($notifications as $notification) {
            $notificationId = (int) $notification['id'];
            try {
                telegram_send_message((string) $notification['message']);
                telegram_update_log($pdo, $notificationId, 'sent');
            } catch (Throwable $exception) {
                try {
                    telegram_update_log($pdo, $notificationId, 'failed', $exception->getMessage());
                } catch (Throwable $logException) {
                    error_log('Telegram failure could not be recorded: ' . $logException->getMessage());
                }
                error_log('Telegram notification #' . $notificationId . ' failed: ' . $exception->getMessage());
            }
        }
        return count($notifications);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('hungry_potters_telegram_worker')");
    }
}
