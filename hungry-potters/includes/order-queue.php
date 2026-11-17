<?php
declare(strict_types=1);

function order_queue_preview(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT CURRENT_DATE() AS business_date,
                COALESCE(
                    (SELECT last_number
                     FROM order_queue_counters
                     WHERE business_date = CURRENT_DATE()),
                    0
                ) + 1 AS next_queue_number'
    );
    $preview = $statement->fetch();
    if ($preview === false) {
        throw new RuntimeException('Could not read the next queue number.');
    }

    return [
        'business_date' => (string) $preview['business_date'],
        'next_queue_number' => (int) $preview['next_queue_number'],
    ];
}

function reserve_order_queue_number(PDO $pdo, string $businessDate): int
{
    $counterInsert = $pdo->prepare(
        'INSERT INTO order_queue_counters (business_date, last_number)
         VALUES (:business_date, 0)
         ON DUPLICATE KEY UPDATE last_number = last_number'
    );
    $counterInsert->execute(['business_date' => $businessDate]);

    $counterSelect = $pdo->prepare(
        'SELECT last_number
         FROM order_queue_counters
         WHERE business_date = :business_date
         FOR UPDATE'
    );
    $counterSelect->execute(['business_date' => $businessDate]);
    $lastNumber = $counterSelect->fetchColumn();
    if ($lastNumber === false) {
        throw new RuntimeException('Could not reserve a queue number.');
    }

    $nextNumber = (int) $lastNumber + 1;
    if ($nextNumber > 4294967295) {
        throw new OverflowException('The daily order queue has reached its maximum number.');
    }

    $counterUpdate = $pdo->prepare(
        'UPDATE order_queue_counters
         SET last_number = :last_number
         WHERE business_date = :business_date'
    );
    $counterUpdate->execute([
        'last_number' => $nextNumber,
        'business_date' => $businessDate,
    ]);

    return $nextNumber;
}

function format_order_number(string $businessDate, int $queueNumber): string
{
    return 'ORD-'
        . str_replace('-', '', $businessDate)
        . '-'
        . str_pad((string) $queueNumber, 4, '0', STR_PAD_LEFT);
}
