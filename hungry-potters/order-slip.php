<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(ROLE_POLICY_ORDER_ENTRY);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';
header('Cache-Control: private, no-store');

$slipNumber = isset($_GET['slip']) && is_string($_GET['slip'])
    ? trim($_GET['slip'])
    : '';

$order = null;
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$bySlip = !isset($_GET['id']) && $slipNumber !== '' && strlen($slipNumber) <= 32;
$pdo->beginTransaction();
if ($bySlip || ($orderId && !isset($_GET['slip']))) {
    $orderStatement = $pdo->prepare(
        "SELECT orders.id, orders.slip_number, orders.order_number, orders.order_type,
            orders.status, orders.cancel_reason, orders.cancelled_at, orders.total,
            DATE_FORMAT(orders.created_at, '%b %e, %Y %l:%i %p') AS slip_created_at,
            orders.cashier_name, orders.queue_number, orders.checkout_mode,
            users.full_name AS staff_name
         FROM orders
         LEFT JOIN users ON users.id = orders.created_by
         WHERE " . ($bySlip ? "orders.slip_number = ? AND orders.order_type = 'takeout'" : 'orders.id = ?')
    );
    $orderStatement->execute([$bySlip ? $slipNumber : $orderId]);
    $order = $orderStatement->fetch() ?: null;
}

$items = [];
if ($order !== null) {
    $itemsStatement = $pdo->prepare(
        'SELECT item_name, quantity, unit_price, line_total
         FROM order_items
         WHERE order_id = :order_id
         ORDER BY id'
    );
    $itemsStatement->execute(['order_id' => $order['id']]);
    $items = $itemsStatement->fetchAll();
    $history = $pdo->prepare('SELECT p.*, u.full_name AS collector FROM order_payments p
        LEFT JOIN users u ON u.id = p.recorded_by WHERE p.order_id = ? ORDER BY p.recorded_at, p.id');
    $history->execute([$order['id']]);
    $entries = $history->fetchAll();
    $totals = ['payment' => 0.0, 'refund' => 0.0, 'void' => 0.0];
    foreach ($entries as $entry) {
        $totals[$entry['entry_type']] += (float) $entry['amount'];
    }
    $net = round($totals['payment'] - $totals['refund'] - $totals['void'], 2);
    $cancelled = $order['status'] === 'cancelled';
    $due = $cancelled ? 0.0 : max(0, round((float) $order['total'] - $net, 2));
    $paymentState = $cancelled ? ($entries ? 'Reversed' : 'Cancelled') : ($due == 0 ? 'Paid' : ($net > 0 ? 'Partially paid' : 'Unpaid'));
} else {
    http_response_code(404);
}

$pdo->commit();
$pageTitle = $bySlip ? 'Takeout Order Slip' : 'Order receipt';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3fbf6">
    <title><?= h($pageTitle) ?> | Hungry Potters</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <style>
        @media print {
            body { background: white !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .slip { box-shadow: none !important; border: 0 !important; max-width: none !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-surface px-4 py-10 font-body-md text-on-surface antialiased">
    <?php if ($order === null): ?>
        <main class="mx-auto max-w-xl rounded-2xl bg-surface-container-lowest p-8 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-error-container text-error">
                <span aria-hidden="true" class="material-symbols-outlined text-[30px]">receipt_long</span>
            </div>
            <div class="mb-2 flex items-center gap-2">
                <img alt="Hungry Potters logo" class="h-10 w-10 shrink-0 object-contain" src="assets/images/logo.png">
                <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Hungry Potters</p>
            </div>
            <h1 class="mt-2 font-headline-lg font-bold text-primary">Slip not found</h1>
            <p class="mt-2 font-body-md text-on-surface-variant">Check the order or takeout slip number and try again.</p>
            <a class="mt-6 inline-flex min-h-12 items-center rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" href="orders.php">Back to Orders</a>
        </main>
    <?php else: ?>
    <main class="slip mx-auto max-w-xl rounded-2xl bg-surface-container-lowest p-8 shadow-lg">
        <div class="mb-6 flex items-start justify-between gap-4 border-b border-outline-variant pb-5">
            <div>
                <div class="mb-2 flex items-center gap-2">
                    <img alt="Hungry Potters logo" class="h-10 w-10 shrink-0 object-contain" src="assets/images/logo.png">
                    <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Hungry Potters</p>
                </div>
                <h1 class="mt-1 font-headline-lg font-bold text-primary"><?= h($pageTitle) ?></h1>
                <p><?= h($order['order_number']) ?> · <?= h(ucfirst(str_replace('_', '-', $order['order_type']))) ?></p>
                <p class="mt-2 font-label-md text-on-surface-variant"><?= h($order['slip_created_at']) ?></p>
            </div>
            <div class="rounded-xl bg-secondary-container px-4 py-3 text-center text-on-secondary-container">
                <strong class="font-headline-md">Q-<?= h(str_pad((string)$order['queue_number'], 3, '0', STR_PAD_LEFT)) ?></strong>
                <?php if ($order['slip_number']): ?><span class="block">Slip # <?= h($order['slip_number']) ?></span><?php endif; ?>
            </div>
        </div>
        <?php if ($order['status'] === 'cancelled'): ?>
            <section aria-label="Cancelled order" class="mb-5 rounded-xl border-2 border-error bg-error-container p-4 text-on-error-container">
                <div class="flex items-center gap-2 font-headline-md font-bold tracking-wider">
                    <span class="material-symbols-outlined">cancel</span>
                    <span>CANCELLED</span>
                </div>
                <p class="mt-2 break-words font-body-md text-body-md"><strong>Reason:</strong> <?= h($order['cancel_reason'] ?: 'No reason recorded.') ?></p>
                <?php if ($order['cancelled_at']): ?>
                    <p class="mt-1 font-label-sm text-label-sm"><?= h(date('M j, Y g:i A', strtotime((string) $order['cancelled_at']))) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="mb-5 flex items-center justify-between gap-4">
            <span class="font-label-md text-on-surface-variant">Cashier</span>
            <strong><?= h($order['cashier_name'] ?: $order['staff_name'] ?: 'Staff') ?></strong>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-y border-outline-variant font-label-sm uppercase tracking-wider text-on-surface-variant">
                        <th class="py-3 pr-2">Item</th>
                        <th class="py-3 px-2 text-center">Qty</th>
                        <th class="py-3 px-2 text-right">Price</th>
                        <th class="py-3 pl-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr class="border-b border-surface-container-high">
                            <td class="py-3 pr-2 font-body-md-bold"><?= h($item['item_name']) ?></td>
                            <td class="py-3 px-2 text-center"><?= h($item['quantity']) ?></td>
                            <td class="py-3 px-2 text-right">₱<?= h(number_format((float) $item['unit_price'], 2)) ?></td>
                            <td class="py-3 pl-2 text-right">₱<?= h(number_format((float) $item['line_total'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-5 flex justify-between border-t border-outline-variant pt-4 font-headline-md font-bold">
            <span>Total</span>
            <span>₱<?= h(number_format((float) $order['total'], 2)) ?></span>
        </div>
        <section class="mt-5">
            <h2 class="font-body-md-bold">Payment history · <?= h($paymentState) ?></h2>
            <?php if (!$entries): ?><p>No money recorded.</p><?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <div class="border-b py-3">
                    <strong>#<?= h($entry['id']) ?> <?= h(ucfirst($entry['entry_type'])) ?> · <?= h(payment_method_label($entry['method'])) ?> · ₱<?= h($entry['amount']) ?></strong>
                    <p><?= h($entry['recorded_at']) ?> · <?= h($entry['collector'] ?? 'Staff') ?></p>
                    <?php if ($entry['tendered_amount'] !== null): ?><p>Tendered ₱<?= h($entry['tendered_amount']) ?> · Change ₱<?= h($entry['change_amount']) ?></p><?php endif; ?>
                    <?php if ($entry['reference_code'] !== null): ?><p>Reference <?= h($entry['reference_code']) ?></p><?php endif; ?>
                    <?php if ($entry['related_payment_id'] !== null): ?><p>Against payment #<?= h($entry['related_payment_id']) ?> · <?= h($entry['reason']) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p>Total payments ₱<?= h(number_format($totals['payment'], 2)) ?> · Refunds ₱<?= h(number_format($totals['refund'], 2)) ?> · Voids ₱<?= h(number_format($totals['void'], 2)) ?></p>
            <p>Net paid ₱<?= h(number_format($net, 2)) ?> · Remaining balance ₱<?= h(number_format($due, 2)) ?></p>
            <?php if ($order['checkout_mode'] === 'cod' && $due > 0): ?><p>Cash on delivery · collect the remaining balance.</p><?php endif; ?>
        </section>
        <div class="no-print mt-7 flex flex-wrap gap-3">
            <button class="min-h-12 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" onclick="window.print()" type="button">Print slip</button>
            <a class="flex min-h-12 items-center rounded-lg bg-surface-container px-5 font-body-md-bold text-primary" href="new-order.php">Back to New Order</a>
        </div>
    </main>
    <?php endif; ?>
</body>
</html>
