<?php
declare(strict_types=1);

function fetch_kitchen_orders(PDO $pdo): array
{
    $orders = $pdo->query(
        "SELECT o.id, o.order_number, o.queue_number, o.order_type, o.location,
               o.status, o.kitchen_note, o.created_at,
               TIMESTAMPDIFF(MINUTE, o.created_at, o.completed_at) AS duration_minutes,
               GROUP_CONCAT(CONCAT(i.quantity, '× ', i.item_name)
                            ORDER BY i.id SEPARATOR ', ') AS items_summary
        FROM (
            (SELECT * FROM orders WHERE status IN ('placed', 'preparing')
             ORDER BY (status = 'placed') DESC, created_at DESC, id DESC LIMIT 100)
            UNION ALL
            (SELECT * FROM orders WHERE status = 'completed'
               AND completed_at BETWEEN NOW() - INTERVAL 3 HOUR AND NOW()
             ORDER BY completed_at DESC, id DESC LIMIT 30)
        ) AS o
        LEFT JOIN order_items AS i ON i.order_id = o.id
        GROUP BY o.id, o.order_number, o.queue_number, o.order_type, o.location,
                 o.status, o.kitchen_note, o.created_at, o.completed_at
        ORDER BY (o.status = 'placed') DESC, (o.status = 'preparing') DESC,
                 CASE WHEN o.status = 'completed' THEN o.completed_at ELSE o.created_at END DESC,
                 o.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as $index => $order) {
        if ($order['duration_minutes'] !== null) {
            $orders[$index]['duration_minutes'] = (int) $order['duration_minutes'];
        }
    }

    return $orders;
}
