<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/../config/db.php';

function order_cancel_redirect(
    string $message,
    string $type,
    string $returnQuery = '',
    ?string $statusOverride = null
): never
{
    $_SESSION['orders_message'] = $message;
    $_SESSION['orders_message_type'] = $type;

    $parameters = [];
    if ($returnQuery !== '' && strlen($returnQuery) <= 500) {
        parse_str($returnQuery, $submittedParameters);
        $allowedStatuses = ['active', 'placed', 'preparing', 'completed', 'cancelled'];
        $allowedSorts = ['oldest', 'newest', 'prep', 'value'];
        if (
            isset($submittedParameters['id'])
            && filter_var($submittedParameters['id'], FILTER_VALIDATE_INT) !== false
            && (int) $submittedParameters['id'] > 0
        ) {
            $parameters['id'] = (int) $submittedParameters['id'];
        }
        if (
            isset($submittedParameters['status'])
            && is_string($submittedParameters['status'])
            && in_array($submittedParameters['status'], $allowedStatuses, true)
        ) {
            $parameters['status'] = $submittedParameters['status'];
        }
        if (isset($submittedParameters['search']) && is_string($submittedParameters['search'])) {
            $parameters['search'] = substr($submittedParameters['search'], 0, 100);
        }
        if (
            isset($submittedParameters['sort'])
            && is_string($submittedParameters['sort'])
            && in_array($submittedParameters['sort'], $allowedSorts, true)
        ) {
            $parameters['sort'] = $submittedParameters['sort'];
        }
    }
    if ($statusOverride !== null && in_array($statusOverride, ['active', 'placed', 'preparing', 'completed', 'cancelled'], true)) {
        $parameters['status'] = $statusOverride;
    }

    header('Location: ../orders.php' . ($parameters !== [] ? '?' . http_build_query($parameters) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;
$returnQuery = isset($_POST['return_query']) && is_string($_POST['return_query'])
    ? $_POST['return_query']
    : '';
if (!verify_csrf_token($submittedToken)) {
    order_cancel_redirect('Invalid request token. Refresh the page and try again.', 'error', $returnQuery);
}

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$reasonType = isset($_POST['cancel_reason_type']) && is_string($_POST['cancel_reason_type'])
    ? trim($_POST['cancel_reason_type'])
    : '';
$otherReason = isset($_POST['cancel_reason_other']) && is_string($_POST['cancel_reason_other'])
    ? trim($_POST['cancel_reason_other'])
    : '';
$cancelMode = isset($_POST['cancel_mode']) && is_string($_POST['cancel_mode'])
    ? $_POST['cancel_mode']
    : '';

$allowedReasonTypes = [
    'Customer cancelled',
    'Wrong order',
    'Out of stock',
    'Other',
];
if (
    $orderId === false
    || $orderId === null
    || $orderId < 1
    || !in_array($reasonType, $allowedReasonTypes, true)
    || ($reasonType === 'Other' && $otherReason === '')
    || strlen($otherReason) > 173
) {
    order_cancel_redirect('Choose a valid cancellation reason and complete the required details.', 'error', $returnQuery);
}

$cancelReason = $reasonType === 'Other'
    ? 'Other: ' . $otherReason
    : $reasonType;
$role = (string) ($_SESSION['role'] ?? '');
$restored = false;
$hasDeductionRecords = false;
$orderNumber = '';

$pdo->beginTransaction();
try {
    $orderStatement = $pdo->prepare(
        'SELECT id, order_number, status, inventory_restored
         FROM orders
         WHERE id = :id
         FOR UPDATE'
    );
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) {
        $pdo->rollBack();
        order_cancel_redirect('Order not found.', 'error', $returnQuery);
    }

    $status = (string) $order['status'];
    $orderNumber = (string) $order['order_number'];
    if ($status === 'preparing' && $role !== 'admin') {
        $pdo->rollBack();
        order_cancel_redirect('Only an admin can cancel an order that is preparing.', 'error', $returnQuery);
    }
    if (!in_array($status, ['placed', 'preparing'], true)) {
        $pdo->rollBack();
        order_cancel_redirect('Only placed or preparing orders can be cancelled.', 'error', $returnQuery);
    }
    if ((int) $order['inventory_restored'] === 1) {
        $pdo->rollBack();
        order_cancel_redirect('Ingredients have already been restored for this order.', 'error', $returnQuery);
    }

    if ($status === 'placed') {
        $cancelMode = 'restore';
    } elseif (!in_array($cancelMode, ['restore', 'waste'], true)) {
        $pdo->rollBack();
        order_cancel_redirect('Choose whether to restore the ingredients or mark them as waste.', 'error', $returnQuery);
    }

    $deductionStatement = $pdo->prepare(
        'SELECT id, inventory_item_id, quantity_change
         FROM inventory_movements
         WHERE order_id = :order_id
           AND movement_type = \'order_deduction\'
           AND quantity_change < 0
         ORDER BY inventory_item_id, id
         FOR UPDATE'
    );
    $deductionStatement->execute(['order_id' => $orderId]);
    $deductionRows = $deductionStatement->fetchAll();
    $deductionsByItem = [];
    foreach ($deductionRows as $deductionRow) {
        $inventoryItemId = (int) $deductionRow['inventory_item_id'];
        $deductionsByItem[$inventoryItemId] = ($deductionsByItem[$inventoryItemId] ?? 0.0)
            + abs((float) $deductionRow['quantity_change']);
    }
    $hasDeductionRecords = $deductionsByItem !== [];
    ksort($deductionsByItem, SORT_NUMERIC);

    $stockStatement = $pdo->prepare(
        'SELECT current_stock, unit
         FROM inventory_items
         WHERE id = :id
         FOR UPDATE'
    );
    $stockUpdateStatement = $pdo->prepare(
        'UPDATE inventory_items
         SET current_stock = current_stock + :quantity
         WHERE id = :id'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO inventory_movements
            (inventory_item_id, order_id, changed_by, movement_type,
             quantity_change, quantity_before, quantity_after, note)
         VALUES (:item_id, :order_id, :user_id, :movement_type,
                 :quantity_change, :before, :after, :note)'
    );

    foreach ($deductionsByItem as $inventoryItemId => $quantity) {
        $stockStatement->execute(['id' => $inventoryItemId]);
        $stock = $stockStatement->fetch();
        if (!$stock) {
            throw new RuntimeException('An inventory item recorded for this order no longer exists.');
        }

        $before = (float) $stock['current_stock'];
        if ($cancelMode === 'restore') {
            $quantity = round($quantity, 2);
            $after = round($before + $quantity, 2);
            $stockUpdateStatement->execute([
                'quantity' => $quantity,
                'id' => $inventoryItemId,
            ]);
            $movementStatement->execute([
                'item_id' => $inventoryItemId,
                'order_id' => $orderId,
                'user_id' => $_SESSION['user_id'],
                'movement_type' => 'cancellation_restore',
                'quantity_change' => $quantity,
                'before' => $before,
                'after' => $after,
                'note' => 'Cancellation restore: ' . $cancelReason,
            ]);
            $restored = true;
            continue;
        }

        $movementStatement->execute([
            'item_id' => $inventoryItemId,
            'order_id' => $orderId,
            'user_id' => $_SESSION['user_id'],
            'movement_type' => 'waste',
            'quantity_change' => 0,
            'before' => $before,
            'after' => $before,
            'note' => 'Waste marked: '
                . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.')
                . ' ' . $stock['unit'] . '; ' . $cancelReason,
        ]);
    }

    $historyNote = $cancelMode === 'restore'
        ? ($restored
            ? 'Ingredients restored from deductions. Reason: ' . $cancelReason
            : 'No deduction records; no ingredients restored. Reason: ' . $cancelReason)
        : ($hasDeductionRecords
            ? 'Marked as waste; stock not restored. Reason: ' . $cancelReason
            : 'No deduction records; no waste movement created. Reason: ' . $cancelReason);

    $cancelStatement = $pdo->prepare(
        'UPDATE orders
         SET status = \'cancelled\',
             cancelled_at = NOW(),
             cancelled_by = :user_id,
             cancel_reason = :cancel_reason,
             inventory_restored = :inventory_restored
         WHERE id = :id AND status = :old_status AND inventory_restored = 0'
    );
    $cancelStatement->execute([
        'user_id' => $_SESSION['user_id'],
        'cancel_reason' => $cancelReason,
        'inventory_restored' => $restored ? 1 : 0,
        'id' => $orderId,
        'old_status' => $status,
    ]);
    if ($cancelStatement->rowCount() !== 1) {
        throw new RuntimeException('The order changed while it was being cancelled.');
    }

    $historyStatement = $pdo->prepare(
        'INSERT INTO order_status_history
            (order_id, changed_by, old_status, new_status, note)
         VALUES (:order_id, :user_id, :old_status, \'cancelled\', :note)'
    );
    $historyStatement->execute([
        'order_id' => $orderId,
        'user_id' => $_SESSION['user_id'],
        'old_status' => $status,
        'note' => $historyNote,
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order cancellation failed: ' . $exception->getMessage());
    order_cancel_redirect('The order could not be cancelled. No inventory changes were saved.', 'error', $returnQuery);
}

if ($cancelMode === 'restore' && !$hasDeductionRecords) {
    order_cancel_redirect(
        'Order ' . $orderNumber . ' was cancelled, but no inventory was restored because it has no recorded order-deduction movements.',
        'success',
        $returnQuery,
        'cancelled'
    );
}
if ($cancelMode === 'waste' && !$hasDeductionRecords) {
    order_cancel_redirect(
        'Order ' . $orderNumber . ' was cancelled, but no waste movement was recorded because it has no recorded order-deduction movements.',
        'success',
        $returnQuery,
        'cancelled'
    );
}

$message = $cancelMode === 'restore'
    ? 'Order ' . $orderNumber . ' was cancelled and its recorded ingredients were restored.'
    : 'Order ' . $orderNumber . ' was cancelled; ingredients were marked as waste and not restored.';
order_cancel_redirect($message, 'success', $returnQuery, 'cancelled');
