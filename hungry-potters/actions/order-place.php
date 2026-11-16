<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/telegram.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_order_place_response(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_order_place_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$requestBody = file_get_contents('php://input');
if ($requestBody === false) {
    send_order_place_response(400, ['success' => false, 'message' => 'Could not read the order request.']);
}

try {
    $payload = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    send_order_place_response(400, ['success' => false, 'message' => 'Invalid order data.']);
}

if (
    !is_array($payload)
    || !isset($payload['csrf_token'])
    || !is_string($payload['csrf_token'])
    || !verify_csrf_token($payload['csrf_token'])
) {
    send_order_place_response(400, ['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
}

$orderType = $payload['order_type'] ?? null;
$location = isset($payload['location']) && is_string($payload['location'])
    ? trim($payload['location'])
    : '';
$kitchenNote = isset($payload['kitchen_note']) && is_string($payload['kitchen_note'])
    ? trim($payload['kitchen_note'])
    : '';
$items = $payload['items'] ?? null;
$validOrderTypes = ['dine_in', 'takeout', 'delivery'];

if (
    !is_string($orderType)
    || !in_array($orderType, $validOrderTypes, true)
    || strlen($location) > 100
    || strlen($kitchenNote) > 500
    || !is_array($items)
    || count($items) < 1
    || count($items) > 50
    || (in_array($orderType, ['dine_in', 'delivery'], true) && $location === '')
) {
    send_order_place_response(422, ['success' => false, 'message' => 'Enter a valid order type, location and at least one menu item.']);
}

$requestedItems = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        send_order_place_response(422, ['success' => false, 'message' => 'Order contains an invalid menu item.']);
    }
    $menuItemId = filter_var($item['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (
        $menuItemId === false
        || $menuItemId === null
        || $menuItemId < 1
        || $quantity === false
        || $quantity === null
        || $quantity < 1
        || $quantity > 100
    ) {
        send_order_place_response(422, ['success' => false, 'message' => 'Each menu item needs a valid ID and quantity.']);
    }
    if (isset($requestedItems[$menuItemId])) {
        $requestedItems[$menuItemId] += $quantity;
    } else {
        $requestedItems[$menuItemId] = $quantity;
    }
    if ($requestedItems[$menuItemId] > 100) {
        send_order_place_response(422, ['success' => false, 'message' => 'An item quantity cannot exceed 100.']);
    }
}

$queueLock = $pdo->query("SELECT GET_LOCK('hungry_potters_order_queue', 5)")->fetchColumn();
if ((int) $queueLock !== 1) {
    send_order_place_response(503, ['success' => false, 'message' => 'The order queue is busy. Please try placing the order again.']);
}

$lowStockNotifications = [];
$pdo->beginTransaction();
try {
    $menuStatement = $pdo->prepare(
        'SELECT id, name, price
         FROM menu_items
         WHERE id = :id AND is_active = 1 AND is_available = 1
         FOR UPDATE'
    );
    $recipeStatement = $pdo->prepare(
        'SELECT recipe.inventory_item_id, recipe.quantity_required
         FROM menu_recipes recipe
         WHERE recipe.menu_item_id = :menu_item_id
         ORDER BY recipe.inventory_item_id'
    );

    $menuOrderItems = [];
    $total = 0.0;
    $requiredIngredients = [];
    foreach ($requestedItems as $menuItemId => $quantity) {
        $menuStatement->execute(['id' => $menuItemId]);
        $menuItem = $menuStatement->fetch();
        if (!$menuItem) {
            $pdo->rollBack();
            send_order_place_response(422, ['success' => false, 'message' => 'A selected menu item is unavailable. Refresh the menu and try again.']);
        }
        $recipeStatement->execute(['menu_item_id' => $menuItemId]);
        $recipe = $recipeStatement->fetchAll();
        if ($recipe === []) {
            $pdo->rollBack();
            send_order_place_response(409, ['success' => false, 'message' => $menuItem['name'] . ' has no inventory recipe configured.']);
        }

        foreach ($recipe as $ingredient) {
            $inventoryId = (int) $ingredient['inventory_item_id'];
            $requiredIngredients[$inventoryId] = ($requiredIngredients[$inventoryId] ?? 0)
                + (float) $ingredient['quantity_required'] * $quantity;
        }

        $price = (float) $menuItem['price'];
        $lineTotal = round($price * $quantity, 2);
        $total = round($total + $lineTotal, 2);
        $menuOrderItems[] = [
            'menu_item_id' => (int) $menuItem['id'],
            'item_name' => $menuItem['name'],
            'quantity' => $quantity,
            'unit_price' => $price,
            'line_total' => $lineTotal,
        ];
    }

    $stockStatement = $pdo->prepare(
        'SELECT id, name, unit, current_stock, low_stock_level
         FROM inventory_items
         WHERE id = :id AND is_active = 1
         FOR UPDATE'
    );
    $stockById = [];
    foreach ($requiredIngredients as $inventoryId => $requiredQuantity) {
        $stockStatement->execute(['id' => $inventoryId]);
        $stock = $stockStatement->fetch();
        if (!$stock) {
            $pdo->rollBack();
            send_order_place_response(409, ['success' => false, 'message' => 'An ingredient in this order is not active in inventory.']);
        }
        $stock['required_quantity'] = $requiredQuantity;
        $stockById[$inventoryId] = $stock;
    }

    $insufficientItems = [];
    foreach ($stockById as $stock) {
        if ((float) $stock['current_stock'] < (float) $stock['required_quantity']) {
            $formatQuantity = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            $insufficientItems[] = $stock['name']
                . ' (need ' . $formatQuantity((float) $stock['required_quantity'])
                . ' ' . $stock['unit']
                . ', have ' . $formatQuantity((float) $stock['current_stock']) . ')';
        }
    }
    if ($insufficientItems !== []) {
        $pdo->rollBack();
        send_order_place_response(409, [
            'success' => false,
            'message' => 'Insufficient stock: ' . implode(', ', $insufficientItems) . '.',
        ]);
    }

    $queueStatement = $pdo->query(
        'SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue FROM orders'
    );
    $queueNumber = (int) $queueStatement->fetchColumn();
    $orderNumber = 'ORD-' . str_pad((string) $queueNumber, 4, '0', STR_PAD_LEFT);
    $slipNumber = null;
    if ($orderType === 'takeout') {
        $slipDate = (string) $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d')")->fetchColumn();
        $counterInsert = $pdo->prepare(
            'INSERT INTO order_slip_counters (slip_date, last_number)
             VALUES (:slip_date, 0)
             ON DUPLICATE KEY UPDATE last_number = last_number'
        );
        $counterInsert->execute(['slip_date' => $slipDate]);
        $counterSelect = $pdo->prepare(
            'SELECT last_number FROM order_slip_counters WHERE slip_date = :slip_date FOR UPDATE'
        );
        $counterSelect->execute(['slip_date' => $slipDate]);
        $slipSequence = (int) $counterSelect->fetchColumn() + 1;
        $counterUpdate = $pdo->prepare(
            'UPDATE order_slip_counters SET last_number = :last_number WHERE slip_date = :slip_date'
        );
        $counterUpdate->execute([
            'last_number' => $slipSequence,
            'slip_date' => $slipDate,
        ]);
        $slipNumber = 'TO-' . str_replace('-', '', $slipDate) . '-' . str_pad((string) $slipSequence, 3, '0', STR_PAD_LEFT);
    }

    $orderStatement = $pdo->prepare(
        'INSERT INTO orders
            (order_number, slip_number, queue_number, order_type, location, status,
             subtotal, total, kitchen_note, created_by, cashier_name, created_at)
         VALUES
            (:order_number, :slip_number, :queue_number, :order_type, :location, \'placed\',
             :subtotal, :total, :kitchen_note, :created_by, :cashier_name, NOW())'
    );
    $orderStatement->execute([
        'order_number' => $orderNumber,
        'slip_number' => $slipNumber,
        'queue_number' => $queueNumber,
        'order_type' => $orderType,
        'location' => $orderType === 'takeout' || $location === '' ? null : $location,
        'subtotal' => $total,
        'total' => $total,
        'kitchen_note' => $kitchenNote !== '' ? $kitchenNote : null,
        'created_by' => $_SESSION['user_id'],
        'cashier_name' => $_SESSION['full_name'] ?? $_SESSION['username'],
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $orderItemStatement = $pdo->prepare(
        'INSERT INTO order_items
            (order_id, menu_item_id, item_name, quantity, unit_price, line_total, status)
         VALUES
            (:order_id, :menu_item_id, :item_name, :quantity, :unit_price, :line_total, \'placed\')'
    );
    foreach ($menuOrderItems as $item) {
        $orderItemStatement->execute([
            'order_id' => $orderId,
            'menu_item_id' => $item['menu_item_id'],
            'item_name' => $item['item_name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total'],
        ]);
    }

    $updateStockStatement = $pdo->prepare(
        'UPDATE inventory_items
         SET current_stock = current_stock - :quantity
         WHERE id = :id AND current_stock >= :required_quantity'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO inventory_movements
            (inventory_item_id, order_id, changed_by, movement_type,
             quantity_change, quantity_before, quantity_after, note)
         VALUES (:item_id, :order_id, :user_id, \'order_deduction\',
                 :quantity_change, :before, :after, :note)'
    );
    foreach ($stockById as $inventoryId => $stock) {
        $before = (float) $stock['current_stock'];
        $deduction = (float) $stock['required_quantity'];
        $after = round($before - $deduction, 2);
        $updateStockStatement->execute([
            'quantity' => $deduction,
            'id' => $inventoryId,
            'required_quantity' => $deduction,
        ]);
        if ($updateStockStatement->rowCount() !== 1) {
            throw new RuntimeException('Inventory changed while placing the order.');
        }
        $movementStatement->execute([
            'item_id' => $inventoryId,
            'order_id' => $orderId,
            'user_id' => $_SESSION['user_id'],
            'quantity_change' => -$deduction,
            'before' => $before,
            'after' => $after,
            'note' => 'Deducted when ' . $orderNumber . ' was placed',
        ]);
        if (
            (float) $stock['current_stock'] > (float) $stock['low_stock_level']
            && $after <= (float) $stock['low_stock_level']
        ) {
            $lowStockNotifications[] = [
                'name' => (string) $stock['name'],
                'remaining' => $after,
                'unit' => (string) $stock['unit'],
            ];
        }
    }

    $historyStatement = $pdo->prepare(
        'INSERT INTO order_status_history
            (order_id, changed_by, old_status, new_status, note)
         VALUES (:order_id, :user_id, NULL, \'placed\', \'Order placed\')'
    );
    $historyStatement->execute([
        'order_id' => $orderId,
        'user_id' => $_SESSION['user_id'],
    ]);
    $pdo->commit();
    $pdo->query("SELECT RELEASE_LOCK('hungry_potters_order_queue')");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->query("SELECT RELEASE_LOCK('hungry_potters_order_queue')");
    error_log('Order placement failed: ' . $exception->getMessage());
    send_order_place_response(500, ['success' => false, 'message' => 'The order could not be placed. Please try again.']);
}

if ($lowStockNotifications !== []) {
    telegram_notify($pdo, 'low_stock', ['ingredients' => $lowStockNotifications]);
}
if ($orderType === 'takeout') {
    telegram_notify($pdo, 'takeout_order', [
        'slip_number' => (string) $slipNumber,
        'items' => $menuOrderItems,
        'total' => number_format($total, 2, '.', ''),
    ]);
}

send_order_place_response(201, [
    'success' => true,
    'order_id' => $orderId,
    'order_number' => $orderNumber,
    'slip_number' => $slipNumber,
    'queue_number' => $queueNumber,
    'total' => number_format($total, 2, '.', ''),
    'message' => 'Order placed successfully.',
]);
