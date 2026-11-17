<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(ROLE_POLICY_ORDER_ENTRY);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payments.php';

function cashier_shift_redirect(int $cashierId, string $message, string $type, string $date = ''): never
{
    $_SESSION['cashier_shift_message'] = $message;
    $_SESSION['cashier_shift_message_type'] = $type;

    $parameters = ['cashier_id' => $cashierId];
    if ($date !== '') {
        $parameters['date'] = $date;
    }
    header('Location: ../cashier-closeout.php?' . http_build_query($parameters));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$submittedCashierId = filter_var($_POST['cashier_id'] ?? null, FILTER_VALIDATE_INT);
$cashierId = $submittedCashierId === false || $submittedCashierId === null
    ? 0
    : (int) $submittedCashierId;
$userId = (int) $_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
if (!$isAdmin) {
    $cashierId = $userId;
}
$amountField = $action === 'open' ? 'opening_float' : 'cash_counted';
$amountInput = isset($_POST[$amountField]) && is_string($_POST[$amountField])
    ? $_POST[$amountField]
    : '';
$amount = payment_parse_amount($amountInput);
$date = isset($_POST['date']) && is_string($_POST['date']) ? $_POST['date'] : '';
$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;

if (
    !verify_csrf_token($submittedToken)
    || !in_array($action, ['open', 'close'], true)
    || $cashierId < 1
    || $amount === null
) {
    cashier_shift_redirect($cashierId > 0 ? $cashierId : $userId, 'Enter a valid cashier shift amount and try again.', 'error', $date);
}

$businessDate = '';
$pdo->beginTransaction();
try {
    $cashierLock = $pdo->prepare(
        'SELECT id, role, is_active
         FROM users
         WHERE id = :id
         FOR UPDATE'
    );
    $cashierLock->execute(['id' => $cashierId]);
    $cashier = $cashierLock->fetch();
    if (
        !$cashier
        || !in_array($cashier['role'], ['admin', 'cashier'], true)
        || ($action === 'open' && (int) $cashier['is_active'] !== 1)
    ) {
        throw new DomainException('Choose an active admin or cashier account.');
    }

    if (!$isAdmin && $cashierId !== $userId) {
        throw new DomainException('You can only manage your own cashier shift.');
    }

    $today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $dateObject = $date !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : null;
    if (
        ($date !== '' && ($dateObject === false || $dateObject->format('Y-m-d') !== $date))
        || ($action === 'open' && $date !== '' && $date !== $today)
        || ($action === 'close' && $date !== '' && $date > $today)
    ) {
        throw new DomainException('Choose a valid business date.');
    }
    $businessDate = $action === 'open' || $date === '' ? $today : $date;
    $shiftStatement = $pdo->prepare(
        'SELECT id, opening_float, closed_at
         FROM cashier_shifts
         WHERE business_date = :business_date AND cashier_id = :cashier_id
         FOR UPDATE'
    );
    $shiftStatement->execute([
        'business_date' => $businessDate,
        'cashier_id' => $cashierId,
    ]);
    $shift = $shiftStatement->fetch();

    if ($action === 'open') {
        if ($shift) {
            throw new DomainException('A shift has already been opened for this cashier and business date.');
        }

        $insertStatement = $pdo->prepare(
            'INSERT INTO cashier_shifts
                (business_date, cashier_id, opening_float, opened_by)
             VALUES (:business_date, :cashier_id, :opening_float, :opened_by)'
        );
        $insertStatement->execute([
            'business_date' => $businessDate,
            'cashier_id' => $cashierId,
            'opening_float' => $amount,
            'opened_by' => $userId,
        ]);
    } else {
        if (!$shift || $shift['closed_at'] !== null) {
            throw new DomainException('There is no open shift for this cashier and business date.');
        }

        $paymentTotalsStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(
                CASE
                    WHEN method = \'cash\' AND entry_type = \'payment\' THEN amount
                    WHEN method = \'cash\' AND entry_type IN (\'refund\', \'void\') THEN -amount
                    ELSE 0
                END
             ), 0)
             FROM order_payments
             WHERE recorded_by = :cashier_id AND business_date = :business_date'
        );
        $paymentTotalsStatement->execute([
            'cashier_id' => $cashierId,
            'business_date' => $businessDate,
        ]);
        $expectedCash = round((float) $shift['opening_float'] + (float) $paymentTotalsStatement->fetchColumn(), 2);
        $variance = round($amount - $expectedCash, 2);

        $updateStatement = $pdo->prepare(
            'UPDATE cashier_shifts
             SET closed_at = NOW(),
                 cash_expected = :cash_expected,
                 cash_counted = :cash_counted,
                 cash_variance = :cash_variance,
                 closed_by = :closed_by
             WHERE id = :id AND closed_at IS NULL'
        );
        $updateStatement->execute([
            'cash_expected' => $expectedCash,
            'cash_counted' => $amount,
            'cash_variance' => $variance,
            'closed_by' => $userId,
            'id' => $shift['id'],
        ]);
        if ($updateStatement->rowCount() !== 1) {
            throw new RuntimeException('The cashier shift was closed by another request.');
        }
    }

    $pdo->commit();
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cashier_shift_redirect($cashierId, $exception->getMessage(), 'error', $businessDate !== '' ? $businessDate : $date);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Cashier shift update failed: ' . $exception->getMessage());
    cashier_shift_redirect($cashierId, 'The cashier shift was not changed. Refresh and try again.', 'error', $businessDate !== '' ? $businessDate : $date);
}

$message = $action === 'open'
    ? 'Cashier shift opened.'
    : 'Cashier shift closed and reconciled.';
cashier_shift_redirect($cashierId, $message, 'success', $businessDate);
