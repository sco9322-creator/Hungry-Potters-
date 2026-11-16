<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'kitchen']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$statement = $pdo->query(
    "SELECT
        orders.id,
        orders.order_number,
        orders.queue_number,
        orders.order_type,
        orders.location,
        orders.status,
        orders.kitchen_note,
        orders.created_at,
        GROUP_CONCAT(
            CONCAT(order_items.quantity, '× ', order_items.item_name)
            ORDER BY order_items.id
            SEPARATOR ', '
        ) AS items_summary
     FROM orders
     LEFT JOIN order_items ON order_items.order_id = orders.id
     WHERE orders.status IN ('placed', 'preparing', 'completed')
     GROUP BY orders.id
     ORDER BY
        CASE orders.status
            WHEN 'placed' THEN 1
            WHEN 'preparing' THEN 2
            ELSE 3
        END,
        CASE
            WHEN orders.status = 'completed' THEN orders.completed_at
            ELSE orders.created_at
        END DESC
     LIMIT 60"
);
$orders = $statement->fetchAll();

echo json_encode(
    ['orders' => $orders, 'server_time' => date(DATE_ATOM)],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
