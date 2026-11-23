<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security-headers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$statement = $pdo->prepare(
    'SELECT queue_number, status
     FROM orders
     WHERE business_date = CURRENT_DATE()
       AND status <> :cancelled_status
       AND (
            status = :preparing_status
            OR (
                status = :completed_status
                AND completed_at >= NOW() - INTERVAL 15 MINUTE
                AND completed_at <= NOW()
            )
       )
     ORDER BY queue_number ASC'
);
$statement->execute([
    'cancelled_status' => 'cancelled',
    'preparing_status' => 'preparing',
    'completed_status' => 'completed',
]);

$orders = array_map(
    static fn (array $order): array => [
        'queue_number' => (int) $order['queue_number'],
        'status' => (string) $order['status'],
    ],
    $statement->fetchAll(PDO::FETCH_ASSOC)
);

echo json_encode($orders, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
