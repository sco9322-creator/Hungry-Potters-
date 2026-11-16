<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/telegram.php';

function order_edit_redirect(string $message, string $type, int $orderId = 0, string $returnQuery = ''): never
{
    $_SESSION['orders_message'] = $message;
    $_SESSION['orders_message_type'] = $type;

    $parameters = [];
    if ($returnQuery !== '') {
        parse_str($returnQuery, $returnParameters);
        foreach (['status', 'search', 'sort'] as $key) {
            if (isset($returnParameters[$key]) && is_scalar($returnParameters[$key])) {
                $parameters[$key] = (string) $returnParameters[$key];
            }
        }
    }
    if ($orderId > 0) {
        $parameters['id'] = $orderId;
    }
    $query = http_build_query($parameters);
    header('Location: ../orders.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$orderId = $orderId === false || $orderId === null ? 0 : (int) $orderId;
$returnQuery = isset($_POST['return_query']) && is_string($_POST['return_query'])
    ? $_POST['return_query']
    : '';
$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;
if (!verify_csrf_token($submittedToken)) {
    order_edit_redirect('Invalid request token. Refresh the page and try again.', 'error', $orderId, $returnQuery);
}
if ($orderId < 1) {
    order_edit_redirect('Choose a valid order.', 'error', 0, $returnQuery);
}

$existingInput = $_POST['existing_items'] ?? [];
$expectedInput = $_POST['expected_items'] ?? [];
$newInput = $_POST['new_items'] ?? [];
if (
    !is_array($existingInput)
    || !is_array($expectedInput)
    || !is_array($newInput)
    || count($existingInput) > 50
    || count($expectedInput) > 50
    || count($newInput) > 50
) {
    order_edit_redirect('The order contains an invalid number of item rows.', 'error', $orderId, $returnQuery);
}

$requestedExisting = [];
foreach ($existingInput as $orderItemIdInput => $quantityInput) {
    $orderItemId = filter_var((string) $orderItemIdInput, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);
    if (
        $orderItemId === false
        || $orderItemId < 1
        || $quantity === false
        || $quantity < 0
        || $quantity > 100
    ) {
        order_edit_redirect('Each existing line needs a valid quantity from 0 to 100.', 'error', $orderId, $returnQuery);
    }
    $requestedExisting[(int) $orderItemId] = (int) $quantity;
}
$expectedExisting = [];
foreach ($expectedInput as $orderItemIdInput => $quantityInput) {
    $orderItemId = filter_var((string) $orderItemIdInput, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);
    if ($orderItemId === false || $orderItemId < 1 || $quantity === false || $quantity < 1 || $quantity > 100) {
        order_edit_redirect('The order changed since it was opened. Refresh and try again.', 'error', $orderId, $returnQuery);
    }
    $expectedExisting[(int) $orderItemId] = (int) $quantity;
}

$newItems = [];
foreach ($newInput as $newItem) {
    if (!is_array($newItem)) {
        order_edit_redirect('An added menu item is invalid.', 'error', $orderId, $returnQuery);
    }
    $menuItemId = filter_var($newItem['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($newItem['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (
        $menuItemId === false
        || $menuItemId === null
        || $menuItemId < 1
        || $quantity === false
        || $quantity === null
        || $quantity < 1
        || $quantity > 100
    ) {
        order_edit_redirect('Each added item needs a valid menu item and quantity from 1 to 100.', 'error', $orderId, $returnQuery);
    }
    $newItems[] = [
        'menu_item_id' => (int) $menuItemId,
        'quantity' => (int) $quantity,
    ];
}
$lowStockNotifications = [];
$pdo->beginTransaction();
try {
    $orderStatement = $pdo->prepare(
        'SELECT id, order_number, status
         FROM orders
         WHERE id = :id
         FOR UPDATE'
    );
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) {
        $pdo->rollBack();
        order_edit_redirect('Order not found.', 'error', $orderId, $returnQuery);
    }
    if ($order['status'] !== 'placed') {
        $pdo->rollBack();
        order_edit_redirect('Only orders awaiting preparation can be edited.', 'error', $orderId, $returnQuery);
    }

    $orderItemsStatement = $pdo->prepare(
        'SELECT id, menu_item_id, item_name, quantity, unit_price, item_note
         FROM order_items
         WHERE order_id = :order_id
         ORDER BY id
         FOR UPDATE'
    );
    $orderItemsStatement->execute(['order_id' => $orderId]);
    $orderItems = $orderItemsStatement->fetchAll();
    $existingIds = array_map(static fn (array $item): int => (int) $item['id'], $orderItems);
    $submittedIds = array_keys($requestedExisting);
    $expectedIds = array_keys($expectedExisting);
    sort($existingIds, SORT_NUMERIC);
    sort($submittedIds, SORT_NUMERIC);
    sort($expectedIds, SORT_NUMERIC);
    if ($existingIds !== $submittedIds || $existingIds !== $expectedIds) {
        $pdo->rollBack();
        order_edit_redirect('The order lines changed. Refresh the order and try again.', 'error', $orderId, $returnQuery);
    }
    foreach ($orderItems as $item) {
        if ($expectedExisting[(int) $item['id']] !== (int) $item['quantity']) {
            $pdo->rollBack();
            order_edit_redirect('The order changed since it was opened. Refresh and try again.', 'error', $orderId, $returnQuery);
        }
        if ($item['menu_item_id'] === null) {
            $pdo->rollBack();
            order_edit_redirect('This order has a line without a menu-item mapping and cannot be safely edited.', 'error', $orderId, $returnQuery);
        }
    }

    $menuIds = [];
    foreach ($orderItems as $item) {
        $menuIds[] = (int) $item['menu_item_id'];
    }
    foreach ($newItems as $newItem) {
        $menuIds[] = $newItem['menu_item_id'];
    }
    $menuIds = array_values(array_unique($menuIds));
    sort($menuIds, SORT_NUMERIC);
    if ($menuIds === []) {
        $pdo->rollBack();
        order_edit_redirect('An order must contain at least one menu item.', 'error', $orderId, $returnQuery);
    }

    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
    $menuStatement = $pdo->prepare(
        'SELECT id, name, price, is_active, is_available
         FROM menu_items
         WHERE id IN (' . $placeholders . ')
         ORDER BY id
         FOR UPDATE'
    );
    $menuStatement->execute($menuIds);
    $menuItemsById = [];
    foreach ($menuStatement->fetchAll() as $menuItem) {
        $menuItemsById[(int) $menuItem['id']] = $menuItem;
    }
    if (count($menuItemsById) !== count($menuIds)) {
        $pdo->rollBack();
        order_edit_redirect('A menu item in this order no longer exists.', 'error', $orderId, $returnQuery);
    }

    $targetQuantityByMenuId = [];
    foreach ($orderItems as $item) {
        $menuItemId = (int) $item['menu_item_id'];
        $quantity = $requestedExisting[(int) $item['id']];
        $targetQuantityByMenuId[$menuItemId] = ($targetQuantityByMenuId[$menuItemId] ?? 0) + $quantity;
        if (
            $quantity > (int) $item['quantity']
            && (
                (int) $menuItemsById[$menuItemId]['is_active'] !== 1
                || (int) $menuItemsById[$menuItemId]['is_available'] !== 1
            )
        ) {
            $pdo->rollBack();
            order_edit_redirect(
                $item['item_name'] . ' is no longer available to increase.',
                'error',
                $orderId,
                $returnQuery
            );
        }
    }
    foreach ($newItems as $newItem) {
        $menuItem = $menuItemsById[$newItem['menu_item_id']];
        if ((int) $menuItem['is_active'] !== 1 || (int) $menuItem['is_available'] !== 1) {
            $pdo->rollBack();
            order_edit_redirect($menuItem['name'] . ' is no longer available.', 'error', $orderId, $returnQuery);
        }
        $targetQuantityByMenuId[$newItem['menu_item_id']] =
            ($targetQuantityByMenuId[$newItem['menu_item_id']] ?? 0) + $newItem['quantity'];
    }
    foreach ($targetQuantityByMenuId as $quantity) {
        if ($quantity > 100) {
            $pdo->rollBack();
            order_edit_redirect('A menu item quantity cannot exceed 100.', 'error', $orderId, $returnQuery);
        }
    }

    $recipeStatement = $pdo->prepare(
        'SELECT menu_item_id, inventory_item_id, quantity_required
         FROM menu_recipes
         WHERE menu_item_id IN (' . $placeholders . ')
         ORDER BY menu_item_id, inventory_item_id
         FOR UPDATE'
    );
    $recipeStatement->execute($menuIds);
    $recipesByMenuId = [];
    foreach ($recipeStatement->fetchAll() as $recipe) {
        $recipesByMenuId[(int) $recipe['menu_item_id']][] = [
            'inventory_item_id' => (int) $recipe['inventory_item_id'],
            'quantity_required' => (float) $recipe['quantity_required'],
        ];
    }
    foreach ($menuIds as $menuItemId) {
        if (($recipesByMenuId[$menuItemId] ?? []) === []) {
            $pdo->rollBack();
            order_edit_redirect(
                $menuItemsById[$menuItemId]['name'] . ' has no recipe and cannot be used in an order edit.',
                'error',
                $orderId,
                $returnQuery
            );
        }
    }

    $originalRequired = [];
    foreach ($orderItems as $item) {
        $menuItemId = (int) $item['menu_item_id'];
        foreach ($recipesByMenuId[$menuItemId] as $recipe) {
            $inventoryId = $recipe['inventory_item_id'];
            $originalRequired[$inventoryId] = ($originalRequired[$inventoryId] ?? 0)
                + $recipe['quantity_required'] * (int) $item['quantity'];
        }
    }

    $targetLines = [];
    $targetRequired = [];
    $total = 0.0;
    foreach ($orderItems as $item) {
        $quantity = $requestedExisting[(int) $item['id']];
        if ($quantity === 0) {
            continue;
        }
        $menuItemId = (int) $item['menu_item_id'];
        $lineTotal = round($quantity * (float) $item['unit_price'], 2);
        $total = round($total + $lineTotal, 2);
        $targetLines[] = [
            'existing_id' => (int) $item['id'],
            'menu_item_id' => $menuItemId,
            'item_name' => (string) $item['item_name'],
            'quantity' => $quantity,
            'unit_price' => (float) $item['unit_price'],
            'line_total' => $lineTotal,
            'item_note' => $item['item_note'],
        ];
        foreach ($recipesByMenuId[$menuItemId] as $recipe) {
            $inventoryId = $recipe['inventory_item_id'];
            $targetRequired[$inventoryId] = ($targetRequired[$inventoryId] ?? 0)
                + $recipe['quantity_required'] * $quantity;
        }
    }
    foreach ($newItems as $newItem) {
        $menuItem = $menuItemsById[$newItem['menu_item_id']];
        $unitPrice = (float) $menuItem['price'];
        $lineTotal = round($newItem['quantity'] * $unitPrice, 2);
        $total = round($total + $lineTotal, 2);
        $targetLines[] = [
            'existing_id' => null,
            'menu_item_id' => $newItem['menu_item_id'],
            'item_name' => (string) $menuItem['name'],
            'quantity' => $newItem['quantity'],
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'item_note' => null,
        ];
        foreach ($recipesByMenuId[$newItem['menu_item_id']] as $recipe) {
            $inventoryId = $recipe['inventory_item_id'];
            $targetRequired[$inventoryId] = ($targetRequired[$inventoryId] ?? 0)
                + $recipe['quantity_required'] * $newItem['quantity'];
        }
    }
    if ($targetLines === []) {
        $pdo->rollBack();
        order_edit_redirect('An order must contain at least one menu item.', 'error', $orderId, $returnQuery);
    }
    if (count($targetLines) > 50) {
        $pdo->rollBack();
        order_edit_redirect('An order cannot contain more than 50 item lines.', 'error', $orderId, $returnQuery);
    }
    if ($total > 99999999.99) {
        $pdo->rollBack();
        order_edit_redirect('The edited order total exceeds the supported amount.', 'error', $orderId, $returnQuery);
    }

    $deductionStatement = $pdo->prepare(
        'SELECT inventory_item_id, SUM(quantity_change) AS net_change
         FROM inventory_movements
         WHERE order_id = :order_id
           AND movement_type = \'order_deduction\'
         GROUP BY inventory_item_id
         ORDER BY inventory_item_id'
    );
    $deductionStatement->execute(['order_id' => $orderId]);
    $deductionRows = $deductionStatement->fetchAll();
    $hasRecordedDeductions = $deductionRows !== [];
    $recordedRequired = [];
    foreach ($deductionRows as $deductionRow) {
        $netRequired = round(-(float) $deductionRow['net_change'], 2);
        if ($netRequired < -0.005) {
            $pdo->rollBack();
            order_edit_redirect('Recorded order deductions are inconsistent. The order was not changed.', 'error', $orderId, $returnQuery);
        }
        if ($netRequired > 0) {
            $recordedRequired[(int) $deductionRow['inventory_item_id']] = $netRequired;
        }
    }
    if ($hasRecordedDeductions) {
        $ingredientIdsToCompare = array_unique(array_merge(
            array_keys($originalRequired),
            array_keys($recordedRequired)
        ));
        foreach ($ingredientIdsToCompare as $inventoryId) {
            $expected = round((float) ($originalRequired[$inventoryId] ?? 0), 2);
            $recorded = round((float) ($recordedRequired[$inventoryId] ?? 0), 2);
            if (abs($expected - $recorded) > 0.005) {
                $pdo->rollBack();
                order_edit_redirect(
                    'This order’s recipes have changed since it was placed. It cannot be safely edited.',
                    'error',
                    $orderId,
                    $returnQuery
                );
            }
        }
    }

    foreach ($targetRequired as $inventoryId => $quantity) {
        $targetRequired[$inventoryId] = round($quantity, 2);
    }
    $inventoryIds = array_values(array_unique(array_merge(
        array_keys($recordedRequired),
        array_keys($targetRequired)
    )));
    sort($inventoryIds, SORT_NUMERIC);
    $inventoryById = [];
    if ($inventoryIds !== []) {
        $inventoryPlaceholders = implode(',', array_fill(0, count($inventoryIds), '?'));
        $inventoryStatement = $pdo->prepare(
            'SELECT id, name, unit, current_stock, low_stock_level, is_active
             FROM inventory_items
             WHERE id IN (' . $inventoryPlaceholders . ')
             ORDER BY id
             FOR UPDATE'
        );
        $inventoryStatement->execute($inventoryIds);
        foreach ($inventoryStatement->fetchAll() as $inventoryItem) {
            $inventoryById[(int) $inventoryItem['id']] = $inventoryItem;
        }
        if (count($inventoryById) !== count($inventoryIds)) {
            throw new RuntimeException('An ingredient recorded for this order no longer exists.');
        }
    }

    $stockChanges = [];
    $insufficientItems = [];
    foreach ($inventoryIds as $inventoryId) {
        $target = (float) ($targetRequired[$inventoryId] ?? 0);
        $recorded = $hasRecordedDeductions
            ? (float) ($recordedRequired[$inventoryId] ?? 0)
            : 0.0;
        $delta = round($target - $recorded, 2);
        $inventoryItem = $inventoryById[$inventoryId];
        if ($target > 0 && (int) $inventoryItem['is_active'] !== 1) {
            $pdo->rollBack();
            order_edit_redirect(
                $inventoryItem['name'] . ' is inactive and cannot be used for an order edit.',
                'error',
                $orderId,
                $returnQuery
            );
        }
        $before = (float) $inventoryItem['current_stock'];
        $after = round($before - $delta, 2);
        if ($delta > 0 && $before < $delta) {
            $insufficientItems[] = $inventoryItem['name']
                . ' (need ' . rtrim(rtrim(number_format($delta, 2, '.', ''), '0'), '.')
                . ' ' . $inventoryItem['unit']
                . ', have ' . rtrim(rtrim(number_format($before, 2, '.', ''), '0'), '.') . ')';
        } elseif ($after > 99999999.99) {
            $pdo->rollBack();
            order_edit_redirect(
                'Restoring ingredients would exceed the supported inventory quantity.',
                'error',
                $orderId,
                $returnQuery
            );
        }
        $stockChanges[$inventoryId] = [
            'delta' => $delta,
            'before' => $before,
            'after' => $after,
            'item' => $inventoryItem,
        ];
    }
    if ($insufficientItems !== []) {
        $pdo->rollBack();
        order_edit_redirect(
            'Insufficient stock for this edit: ' . implode('; ', $insufficientItems) . '. No changes were made.',
            'error',
            $orderId,
            $returnQuery
        );
    }

    $stockUpdateStatement = $pdo->prepare(
        'UPDATE inventory_items SET current_stock = :stock WHERE id = :id'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO inventory_movements
            (inventory_item_id, order_id, changed_by, movement_type,
             quantity_change, quantity_before, quantity_after, note)
         VALUES (:item_id, :order_id, :user_id, \'order_deduction\',
                 :quantity_change, :before, :after, :note)'
    );
    foreach ($stockChanges as $inventoryId => $change) {
        $delta = (float) $change['delta'];
        if ($delta === 0.0) {
            continue;
        }
        $stockUpdateStatement->execute([
            'stock' => $change['after'],
            'id' => $inventoryId,
        ]);
        $movementStatement->execute([
            'item_id' => $inventoryId,
            'order_id' => $orderId,
            'user_id' => $_SESSION['user_id'],
            'quantity_change' => -$delta,
            'before' => $change['before'],
            'after' => $change['after'],
            'note' => $delta > 0
                ? 'Additional ingredients deducted after order edit'
                : 'Ingredients restored after order edit',
        ]);
        if (
            $delta > 0
            && (float) $change['item']['current_stock'] > (float) $change['item']['low_stock_level']
            && (float) $change['after'] <= (float) $change['item']['low_stock_level']
        ) {
            $lowStockNotifications[] = [
                'name' => (string) $change['item']['name'],
                'remaining' => (float) $change['after'],
                'unit' => (string) $change['item']['unit'],
            ];
        }
    }

    $updateOrderItemStatement = $pdo->prepare(
        'UPDATE order_items
         SET quantity = :quantity, line_total = :line_total
         WHERE id = :id AND order_id = :order_id'
    );
    $deleteOrderItemStatement = $pdo->prepare(
        'DELETE FROM order_items WHERE id = :id AND order_id = :order_id'
    );
    $insertOrderItemStatement = $pdo->prepare(
        'INSERT INTO order_items
            (order_id, menu_item_id, item_name, quantity, unit_price, line_total, item_note, status)
         VALUES (:order_id, :menu_item_id, :item_name, :quantity, :unit_price, :line_total, NULL, \'placed\')'
    );
    foreach ($targetLines as $line) {
        if ($line['existing_id'] !== null) {
            $updateOrderItemStatement->execute([
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'id' => $line['existing_id'],
                'order_id' => $orderId,
            ]);
        } else {
            $insertOrderItemStatement->execute([
                'order_id' => $orderId,
                'menu_item_id' => $line['menu_item_id'],
                'item_name' => $line['item_name'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ]);
        }
    }
    foreach ($orderItems as $item) {
        if ($requestedExisting[(int) $item['id']] === 0) {
            $deleteOrderItemStatement->execute([
                'id' => (int) $item['id'],
                'order_id' => $orderId,
            ]);
        }
    }

    $updateOrderStatement = $pdo->prepare(
        'UPDATE orders SET subtotal = :subtotal, total = :total WHERE id = :id'
    );
    $updateOrderStatement->execute([
        'subtotal' => $total,
        'total' => $total,
        'id' => $orderId,
    ]);
    $historyStatement = $pdo->prepare(
        'INSERT INTO order_status_history
            (order_id, changed_by, old_status, new_status, note)
         VALUES (:order_id, :user_id, \'placed\', \'placed\', \'Order contents edited before preparation\')'
    );
    $historyStatement->execute([
        'order_id' => $orderId,
        'user_id' => $_SESSION['user_id'],
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Awaiting-prep order edit failed: ' . $exception->getMessage());
    order_edit_redirect('The order could not be updated. No changes were made. Please refresh and try again.', 'error', $orderId, $returnQuery);
}

if ($lowStockNotifications !== []) {
    telegram_notify($pdo, 'low_stock', ['ingredients' => $lowStockNotifications]);
}
order_edit_redirect(
    'Order ' . $order['order_number'] . ' was updated. Ingredient stock and total were adjusted.',
    'success',
    $orderId,
    $returnQuery
);
