<?php
declare(strict_types=1);

function order_net_paid_amount(PDO $pdo, int $orderId): float
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(
            CASE WHEN entry_type = \'payment\' THEN amount ELSE -amount END
         ), 0)
         FROM order_payments
         WHERE order_id = :order_id'
    );
    $statement->execute(['order_id' => $orderId]);

    return (float) $statement->fetchColumn();
}

function payment_method_label(string $method): string
{
    return match ($method) {
        'cash' => 'Cash',
        'ewallet' => 'GCash / e-wallet',
        default => $method,
    };
}

function payment_parse_amount(string $input): ?float
{
    $input = trim($input);
    if (preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/D', $input) !== 1) {
        return null;
    }

    return round((float) $input, 2);
}
