<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(ROLE_POLICY_KITCHEN_OPERATIONS);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/kitchen-orders.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$orders = fetch_kitchen_orders($pdo);

echo json_encode(
    ['orders' => $orders, 'server_time' => date(DATE_ATOM)],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
