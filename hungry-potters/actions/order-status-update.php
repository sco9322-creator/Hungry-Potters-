<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'kitchen']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/telegram.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requestContentType = $_SERVER['CONTENT_TYPE'] ?? '';
$expectsJson = str_contains($requestContentType, 'application/json');

function send_order_update_response(int $statusCode, array $payload): never
{
    global $expectsJson;
    if (!$expectsJson) {
        $_SESSION['orders_message'] = (string) ($payload['message'] ?? 'Order update failed.');
        $_SESSION['orders_message_type'] = !empty($payload['success']) ? 'success' : 'error';
        $query = '';
        $returnQuery = $_POST['return_query'] ?? '';
        if (is_string($returnQuery) && $returnQuery !== '') {
            parse_str($returnQuery, $returnParameters);
            $allowedParameters = [];
            foreach (['id', 'status', 'search', 'sort'] as $key) {
                if (isset($returnParameters[$key]) && is_scalar($returnParameters[$key])) {
                    $allowedParameters[$key] = (string) $returnParameters[$key];
                }
            }
            if (isset($allowedParameters['id']) && ctype_digit($allowedParameters['id'])) {
                $query = '?' . http_build_query($allowedParameters);
            }
        }
        header('Location: ../orders.php' . $query);
        exit;
    }

    http_response_code($statusCode);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_order_update_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$requestBody = file_get_contents('php://input');
if ($requestBody === false) {
    send_order_update_response(400, ['success' => false, 'message' => 'Could not read the request.']);
}
if ($expectsJson) {
    try {
        $payload = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        send_order_update_response(400, ['success' => false, 'message' => 'Invalid request data.']);
    }
} else {
    $payload = $_POST;
}

if (
    !is_array($payload)
    || !isset($payload['csrf_token'])
    || !is_string($payload['csrf_token'])
    || !verify_csrf_token($payload['csrf_token'])
) {
    send_order_update_response(400, ['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
}

$orderId = filter_var($payload['order_id'] ?? null, FILTER_VALIDATE_INT);
$nextStatus = $payload['status'] ?? null;
if ($orderId === false || $orderId === null || $orderId < 1 || !is_string($nextStatus)) {
    send_order_update_response(422, ['success' => false, 'message' => 'Choose a valid order and status.']);
}

if (!in_array($nextStatus, ['preparing', 'completed'], true)) {
    send_order_update_response(422, ['success' => false, 'message' => 'The requested status is not allowed.']);
}

$pdo->beginTransaction();
try {
    $orderStatement = $pdo->prepare(
        'SELECT id, order_number, slip_number, queue_number, status
         FROM orders
         WHERE id = :id
         FOR UPDATE'
    );
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) {
        $pdo->rollBack();
        send_order_update_response(404, ['success' => false, 'message' => 'Order not found.']);
    }

    $oldStatus = (string) $order['status'];
    $expectedOldStatus = $nextStatus === 'preparing' ? 'placed' : 'preparing';
    if ($oldStatus !== $expectedOldStatus) {
        $pdo->rollBack();
        send_order_update_response(409, ['success' => false, 'message' => 'This order has already changed. Refresh the kitchen display.']);
    }

    if ($nextStatus === 'completed') {
        $orderUpdateStatement = $pdo->prepare(
            'UPDATE orders SET status = :status, completed_at = NOW() WHERE id = :id'
        );
        $orderUpdateStatement->execute(['status' => $nextStatus, 'id' => $orderId]);
        $itemUpdateStatement = $pdo->prepare(
            'UPDATE order_items SET status = \'done\' WHERE order_id = :id'
        );
        $itemUpdateStatement->execute(['id' => $orderId]);
    } else {
        $orderUpdateStatement = $pdo->prepare(
            'UPDATE orders SET status = :status, started_at = NOW() WHERE id = :id'
        );
        $orderUpdateStatement->execute(['status' => $nextStatus, 'id' => $orderId]);
        $itemUpdateStatement = $pdo->prepare(
            'UPDATE order_items SET status = \'preparing\' WHERE order_id = :id'
        );
        $itemUpdateStatement->execute(['id' => $orderId]);
    }

    $historyStatement = $pdo->prepare(
        'INSERT INTO order_status_history
            (order_id, changed_by, old_status, new_status)
         VALUES (:order_id, :user_id, :old_status, :new_status)'
    );
    $historyStatement->execute([
        'order_id' => $orderId,
        'user_id' => $_SESSION['user_id'],
        'old_status' => $oldStatus,
        'new_status' => $nextStatus,
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Kitchen order status update failed: ' . $exception->getMessage());
    send_order_update_response(500, ['success' => false, 'message' => 'The order could not be updated. Please try again.']);
}

$message = $nextStatus === 'preparing'
    ? 'Order ' . $order['order_number'] . ' is now preparing.'
    : 'Order ' . $order['order_number'] . ' marked completed. Inventory was already deducted when placed.';
if ($nextStatus === 'completed') {
    telegram_notify($pdo, 'order_ready', [
        'slip_number' => $order['slip_number'] !== null ? (string) $order['slip_number'] : null,
        'order_number' => (string) $order['order_number'],
        'queue_number' => (int) $order['queue_number'],
    ]);
}
send_order_update_response(200, ['success' => true, 'message' => $message]);
