<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(ROLE_POLICY_ORDER_ENTRY);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payments.php';

function payment_record_redirect(int $orderId, string $message, string $type): never
{
    $_SESSION['orders_message'] = $message;
    $_SESSION['orders_message_type'] = $type;

    $query = $orderId > 0 ? '?' . http_build_query(['id' => $orderId]) : '';
    header('Location: ../orders.php' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$orderId = $orderId === false || $orderId === null ? 0 : (int) $orderId;
$entryType = isset($_POST['entry_type']) && is_string($_POST['entry_type'])
    ? $_POST['entry_type']
    : '';
$method = isset($_POST['method']) && is_string($_POST['method'])
    ? $_POST['method']
    : '';
$amountInput = isset($_POST['amount']) && is_string($_POST['amount'])
    ? $_POST['amount']
    : '';
$amount = payment_parse_amount($amountInput);
$reference = isset($_POST['reference_code']) && is_string($_POST['reference_code'])
    ? trim($_POST['reference_code'])
    : '';
$reason = isset($_POST['reason']) && is_string($_POST['reason'])
    ? trim($_POST['reason'])
    : '';
$relatedPaymentId = filter_var($_POST['related_payment_id'] ?? null, FILTER_VALIDATE_INT);
$relatedPaymentId = $relatedPaymentId === false || $relatedPaymentId === null
    ? 0
    : (int) $relatedPaymentId;
$tenderedInput = isset($_POST['tendered_amount']) && is_string($_POST['tendered_amount'])
    ? $_POST['tendered_amount']
    : '';
$tenderedAmount = $tenderedInput !== '' ? payment_parse_amount($tenderedInput) : null;
$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;

if (!verify_csrf_token($submittedToken)) {
    payment_record_redirect($orderId, 'Invalid request token. Refresh the page and try again.', 'error');
}
if (
    $orderId < 1
    || !in_array($entryType, ['payment', 'refund', 'void'], true)
    || !in_array($method, ['cash', 'ewallet'], true)
    || $amount === null
    || $amount <= 0
    || strlen($reference) > 100
    || ($entryType !== 'payment' && ($relatedPaymentId < 1 || $reason === '' || strlen($reason) > 255))
    || ($entryType === 'payment' && $method === 'cash' && ($tenderedAmount === null || $tenderedAmount < $amount))
) {
    payment_record_redirect($orderId, 'Enter a valid payment amount and required details.', 'error');
}

$userId = (int) $_SESSION['user_id'];
$pdo->beginTransaction();
try {
    $userLock = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
    $userLock->execute(['id' => $userId]);
    if (!$userLock->fetchColumn()) {
        throw new DomainException('Your account is no longer active.');
    }

    $orderStatement = $pdo->prepare(
        'SELECT id, total, status
         FROM orders
         WHERE id = :id
         FOR UPDATE'
    );
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) {
        throw new DomainException('Order not found.');
    }
    if ($order['status'] === 'cancelled') {
        throw new DomainException('Payments cannot be recorded on a cancelled order.');
    }

    $businessDate = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $shiftStatement = $pdo->prepare(
        'SELECT id, closed_at
         FROM cashier_shifts
         WHERE business_date = :business_date AND cashier_id = :cashier_id
         FOR UPDATE'
    );
    $shiftStatement->execute([
        'business_date' => $businessDate,
        'cashier_id' => $userId,
    ]);
    $shift = $shiftStatement->fetch();
    if (!$shift) {
        throw new DomainException('Open your cashier shift before recording payments.');
    }
    if ($shift['closed_at'] !== null) {
        throw new DomainException('Your cashier shift is already closed for today.');
    }

    $relatedPayment = null;
    if ($entryType === 'payment') {
        $balanceDue = round((float) $order['total'] - order_net_paid_amount($pdo, $orderId), 2);
        if ($amount > $balanceDue) {
            throw new DomainException('The payment exceeds the remaining order balance.');
        }
        [$tenderedAmount, $changeAmount] = payment_tender($method, $amount, $tenderedAmount);
        $relatedPaymentId = null;
        $reason = null;
    } else {
        $paymentStatement = $pdo->prepare(
            'SELECT id, method, amount
             FROM order_payments
             WHERE id = :payment_id
               AND order_id = :order_id
               AND entry_type = \'payment\'
             FOR UPDATE'
        );
        $paymentStatement->execute([
            'payment_id' => $relatedPaymentId,
            'order_id' => $orderId,
        ]);
        $relatedPayment = $paymentStatement->fetch();
        if (!$relatedPayment) {
            throw new DomainException('The original payment was not found for this order.');
        }

        $reversedStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM order_payments
             WHERE related_payment_id = :payment_id
               AND entry_type IN (\'refund\', \'void\')'
        );
        $reversedStatement->execute(['payment_id' => $relatedPaymentId]);
        $reversedAmount = (float) $reversedStatement->fetchColumn();
        $reversibleAmount = round((float) $relatedPayment['amount'] - $reversedAmount, 2);
        if ($amount > $reversibleAmount) {
            throw new DomainException('The reversal exceeds the remaining amount of that payment.');
        }
        if ($entryType === 'void' && ($reversedAmount > 0 || $amount !== (float) $relatedPayment['amount'])) {
            throw new DomainException('A payment can only be voided before any refund, for its full amount.');
        }
        $method = $entryType === 'void' ? (string) $relatedPayment['method'] : $method;
        $tenderedAmount = null;
        $changeAmount = 0.0;
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO order_payments
            (order_id, entry_type, related_payment_id, method, amount,
             tendered_amount, change_amount, reference_code, reason, recorded_by)
         VALUES
            (:order_id, :entry_type, :related_payment_id, :method, :amount,
             :tendered_amount, :change_amount, :reference_code, :reason, :recorded_by)'
    );
    $insertStatement->execute([
        'order_id' => $orderId,
        'entry_type' => $entryType,
        'related_payment_id' => $relatedPaymentId,
        'method' => $method,
        'amount' => $amount,
        'tendered_amount' => $tenderedAmount,
        'change_amount' => $changeAmount,
        'reference_code' => $reference !== '' ? $reference : null,
        'reason' => $reason !== '' ? $reason : null,
        'recorded_by' => $userId,
    ]);
    $pdo->commit();
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    payment_record_redirect($orderId, $exception->getMessage(), 'error');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment record failed: ' . $exception->getMessage());
    payment_record_redirect($orderId, 'Payment could not be recorded. No payment changes were saved.', 'error');
}

$successMessage = match ($entryType) {
    'payment' => 'Payment recorded.',
    'refund' => 'Refund recorded.',
    'void' => 'Payment void recorded.',
};
payment_record_redirect($orderId, $successMessage, 'success');
