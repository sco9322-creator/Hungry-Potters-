<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(ROLE_POLICY_ORDER_ENTRY);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';

$today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$currentUserId = (int) $_SESSION['user_id'];
$selectedCashierId = $currentUserId;
if ($isAdmin && isset($_GET['cashier_id'])) {
    $requestedCashierId = filter_var($_GET['cashier_id'], FILTER_VALIDATE_INT);
    if ($requestedCashierId !== false && $requestedCashierId !== null && $requestedCashierId > 0) {
        $selectedCashierId = (int) $requestedCashierId;
    }
}
$selectedDate = isset($_GET['date']) && is_string($_GET['date']) ? $_GET['date'] : $today;
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
if ($dateObject === false || $dateObject->format('Y-m-d') !== $selectedDate || $selectedDate > $today) {
    $selectedDate = $today;
}

$cashierStatement = $pdo->query(
    "SELECT id, full_name, role, is_active
     FROM users
     WHERE role IN ('admin', 'cashier')
     ORDER BY CASE role WHEN 'cashier' THEN 0 ELSE 1 END, full_name"
);
$cashiers = $cashierStatement->fetchAll();
$selectedCashier = null;
foreach ($cashiers as $cashier) {
    if ((int) $cashier['id'] === $selectedCashierId) {
        $selectedCashier = $cashier;
        break;
    }
}
if ($selectedCashier === null) {
    $selectedCashierId = $currentUserId;
    foreach ($cashiers as $cashier) {
        if ((int) $cashier['id'] === $selectedCashierId) {
            $selectedCashier = $cashier;
            break;
        }
    }
}

$flashMessage = $_SESSION['cashier_shift_message'] ?? '';
unset($_SESSION['cashier_shift_message']);
$flashType = $_SESSION['cashier_shift_message_type'] ?? 'success';
unset($_SESSION['cashier_shift_message_type']);

$shiftStatement = $pdo->prepare(
    'SELECT shifts.*, opener.full_name AS opened_by_name, closer.full_name AS closed_by_name
     FROM cashier_shifts shifts
     LEFT JOIN users opener ON opener.id = shifts.opened_by
     LEFT JOIN users closer ON closer.id = shifts.closed_by
     WHERE shifts.cashier_id = :cashier_id AND shifts.business_date = :business_date'
);
$shiftStatement->execute([
    'cashier_id' => $selectedCashierId,
    'business_date' => $selectedDate,
]);
$selectedShift = $shiftStatement->fetch() ?: null;

$paymentsStatement = $pdo->prepare(
    'SELECT method,
            SUM(CASE WHEN entry_type = \'payment\' THEN amount ELSE 0 END) AS gross_payments,
            SUM(CASE WHEN entry_type IN (\'refund\', \'void\') THEN amount ELSE 0 END) AS reversals,
            SUM(CASE WHEN entry_type = \'payment\' THEN amount ELSE -amount END) AS net_amount,
            COUNT(*) AS entry_count
     FROM order_payments
     WHERE recorded_by = :cashier_id AND business_date = :business_date
     GROUP BY method
     ORDER BY method'
);
$paymentsStatement->execute([
    'cashier_id' => $selectedCashierId,
    'business_date' => $selectedDate,
]);
$paymentTotals = $paymentsStatement->fetchAll();
$netCash = 0.0;
$netEwallet = 0.0;
foreach ($paymentTotals as $paymentTotal) {
    if ($paymentTotal['method'] === 'cash') {
        $netCash = (float) $paymentTotal['net_amount'];
    } elseif ($paymentTotal['method'] === 'ewallet') {
        $netEwallet = (float) $paymentTotal['net_amount'];
    }
}
$expectedCash = $selectedShift !== null
    ? round((float) $selectedShift['opening_float'] + $netCash, 2)
    : $netCash;
$canManageSelectedShift = $isAdmin || $selectedCashierId === $currentUserId;
$canOpenSelectedShift = $selectedDate === $today
    && $canManageSelectedShift
    && $selectedCashier !== null
    && (int) $selectedCashier['is_active'] === 1;

$dailySummary = [];
if ($isAdmin) {
    $dailySummaryStatement = $pdo->prepare(
        'SELECT users.id, users.full_name, users.role,
                shifts.opening_float, shifts.closed_at, shifts.cash_expected,
                shifts.cash_counted, shifts.cash_variance,
                COALESCE(payments.cash_net, 0) AS cash_net,
                COALESCE(payments.ewallet_net, 0) AS ewallet_net
         FROM users
         LEFT JOIN cashier_shifts shifts
            ON shifts.cashier_id = users.id AND shifts.business_date = :shift_date
         LEFT JOIN (
            SELECT recorded_by,
                   SUM(CASE WHEN method = \'cash\'
                            THEN CASE WHEN entry_type = \'payment\' THEN amount ELSE -amount END
                            ELSE 0 END) AS cash_net,
                   SUM(CASE WHEN method = \'ewallet\'
                            THEN CASE WHEN entry_type = \'payment\' THEN amount ELSE -amount END
                            ELSE 0 END) AS ewallet_net
            FROM order_payments
            WHERE business_date = :payment_date
            GROUP BY recorded_by
         ) payments ON payments.recorded_by = users.id
         WHERE users.role IN (\'admin\', \'cashier\')
         ORDER BY CASE users.role WHEN \'cashier\' THEN 0 ELSE 1 END, users.full_name'
    );
    $dailySummaryStatement->execute([
        'shift_date' => $selectedDate,
        'payment_date' => $selectedDate,
    ]);
    $dailySummary = $dailySummaryStatement->fetchAll();
}

$pageTitle = 'Cashier Closeout';
$currentPage = 'cashier-closeout';
require __DIR__ . '/includes/header.php';
?>
<div class="flex w-full flex-col gap-6">
    <?php if ($flashMessage !== ''): ?>
        <div class="rounded-xl border p-4 font-body-md text-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status"><?= h($flashMessage) ?></div>
    <?php endif; ?>

    <section class="rounded-xl bg-surface-container-lowest p-5 shadow-sm">
        <form class="flex flex-col gap-4 sm:flex-row sm:items-end" method="get">
            <?php if ($isAdmin): ?>
                <label class="flex flex-1 flex-col gap-1 font-label-sm text-on-surface-variant" for="cashier-id">
                    Cashier
                    <select class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface" id="cashier-id" name="cashier_id">
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?= h($cashier['id']) ?>" <?= (int) $cashier['id'] === $selectedCashierId ? 'selected' : '' ?>>
                                <?= h($cashier['full_name'] . ' (' . ucfirst((string) $cashier['role']) . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant" for="business-date">
                Business date
                <input class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface" id="business-date" max="<?= h($today) ?>" name="date" type="date" value="<?= h($selectedDate) ?>">
            </label>
            <button class="flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 font-body-md-bold text-on-primary" type="submit">View closeout</button>
        </form>
    </section>

    <?php if ($selectedCashier !== null): ?>
        <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <article class="rounded-xl bg-surface-container-lowest p-5 shadow-sm">
                <span class="font-label-sm uppercase tracking-wider text-on-surface-variant">Cash payments (net)</span>
                <div class="mt-2 font-mono-metric text-2xl font-bold text-primary">₱<?= h(number_format($netCash, 2)) ?></div>
            </article>
            <article class="rounded-xl bg-surface-container-lowest p-5 shadow-sm">
                <span class="font-label-sm uppercase tracking-wider text-on-surface-variant">GCash / e-wallet (net)</span>
                <div class="mt-2 font-mono-metric text-2xl font-bold text-primary">₱<?= h(number_format($netEwallet, 2)) ?></div>
            </article>
            <article class="rounded-xl bg-surface-container-lowest p-5 shadow-sm">
                <span class="font-label-sm uppercase tracking-wider text-on-surface-variant">Payments received (net)</span>
                <div class="mt-2 font-mono-metric text-2xl font-bold text-primary">₱<?= h(number_format($netCash + $netEwallet, 2)) ?></div>
            </article>
        </section>

        <section class="rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div>
                    <h2 class="font-headline-md text-headline-md font-bold text-primary"><?= h($selectedCashier['full_name']) ?>’s shift</h2>
                    <p class="mt-1 font-label-md text-label-md text-on-surface-variant">
                        <?= h($selectedDate) ?> · one shift per cashier per business date
                    </p>
                </div>
                <?php if ($selectedShift !== null): ?>
                    <span class="rounded-full px-3 py-1 font-label-sm font-bold <?= $selectedShift['closed_at'] !== null ? 'bg-tertiary-fixed text-on-tertiary-fixed-variant' : 'bg-secondary-fixed text-on-secondary-fixed' ?>">
                        <?= $selectedShift['closed_at'] !== null ? 'Closed' : 'Open' ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($selectedShift === null && $canOpenSelectedShift): ?>
                <p class="mt-4 rounded-lg bg-surface-container-low p-3 font-label-md text-label-md text-on-surface-variant">
                    Open the till with its starting cash float before recording payments. Orders can still be placed and sent to the kitchen while unpaid.
                </p>
                <form action="actions/cashier-shift.php" data-confirm data-confirm-title="Open cashier shift?" data-confirm-message="Record the opening float for this drawer." data-confirm-text="Open shift" data-confirm-rows="<?= h(json_encode(['Cashier' => $selectedCashier['full_name'], 'Date' => $selectedDate])) ?>" class="mt-4 flex flex-col gap-3 sm:max-w-md" method="post">
                    <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                    <input name="action" type="hidden" value="open">
                    <input name="cashier_id" type="hidden" value="<?= h($selectedCashierId) ?>">
                    <input name="date" type="hidden" value="<?= h($selectedDate) ?>">
                    <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant" for="opening-float">
                        Opening cash float
                        <input class="h-12 rounded-lg bg-surface-container-low px-3 font-mono-metric text-on-surface" data-confirm-label="Opening float" data-confirm-format="peso" id="opening-float" inputmode="decimal" max="99999999.99" min="0" name="opening_float" required step="0.01" type="number" value="0.00">
                    </label>
                    <button class="flex min-h-12 items-center justify-center rounded-lg bg-primary px-4 font-body-md-bold text-on-primary" type="submit">Open cashier shift</button>
                </form>
            <?php elseif ($selectedShift !== null): ?>
                <dl class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-surface-container-low p-3">
                        <dt class="font-label-sm text-on-surface-variant">Opening float</dt>
                        <dd class="mt-1 font-mono-metric font-bold text-primary">₱<?= h(number_format((float) $selectedShift['opening_float'], 2)) ?></dd>
                    </div>
                    <div class="rounded-lg bg-surface-container-low p-3">
                        <dt class="font-label-sm text-on-surface-variant">Expected cash</dt>
                        <dd class="mt-1 font-mono-metric font-bold text-primary">₱<?= h(number_format($selectedShift['closed_at'] !== null ? (float) $selectedShift['cash_expected'] : $expectedCash, 2)) ?></dd>
                    </div>
                    <?php if ($selectedShift['closed_at'] !== null): ?>
                        <div class="rounded-lg bg-surface-container-low p-3">
                            <dt class="font-label-sm text-on-surface-variant">Cash counted</dt>
                            <dd class="mt-1 font-mono-metric font-bold text-primary">₱<?= h(number_format((float) $selectedShift['cash_counted'], 2)) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <?php if ($selectedShift['closed_at'] !== null): ?>
                    <?php
                    $variance = (float) $selectedShift['cash_variance'];
                    $varianceClass = $variance === 0.0
                        ? 'bg-tertiary-fixed text-on-tertiary-fixed-variant'
                        : 'bg-error-container text-on-error-container';
                    ?>
                    <div class="mt-4 rounded-lg p-4 <?= h($varianceClass) ?>">
                        <span class="font-label-sm font-bold uppercase tracking-wider">Cash variance</span>
                        <span class="ml-2 font-mono-metric font-bold">₱<?= h(number_format($variance, 2)) ?></span>
                        <p class="mt-1 font-label-sm">
                            Closed <?= h(date('M j, g:i A', strtotime((string) $selectedShift['closed_at']))) ?>
                            <?= $selectedShift['closed_by_name'] ? ' by ' . h($selectedShift['closed_by_name']) : '' ?>
                        </p>
                    </div>
                <?php elseif ($canManageSelectedShift): ?>
                    <form action="actions/cashier-shift.php" data-confirm data-confirm-title="Close and reconcile shift?" data-confirm-message="Closing finalizes this drawer count." data-confirm-text="Close shift" data-confirm-tone="danger" data-confirm-rows="<?= h(json_encode(['Cashier' => $selectedCashier['full_name'], 'Date' => $selectedDate, 'Expected cash' => '₱' . number_format($expectedCash, 2)])) ?>" class="mt-5 flex flex-col gap-3 sm:max-w-md" method="post">
                        <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                        <input name="action" type="hidden" value="close">
                        <input name="cashier_id" type="hidden" value="<?= h($selectedCashierId) ?>">
                        <input name="date" type="hidden" value="<?= h($selectedDate) ?>">
                        <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant" for="cash-counted">
                            Cash counted in till
                            <input class="h-12 rounded-lg bg-surface-container-low px-3 font-mono-metric text-on-surface" data-confirm-label="Counted cash" data-confirm-format="peso" data-confirm-expected="<?= h(number_format($expectedCash, 2, '.', '')) ?>" id="cash-counted" inputmode="decimal" max="99999999.99" min="0" name="cash_counted" required step="0.01" type="number">
                        </label>
                        <button class="flex min-h-12 items-center justify-center rounded-lg bg-primary px-4 font-body-md-bold text-on-primary" type="submit">Close and reconcile shift</button>
                    </form>
                <?php endif; ?>

                <?php if ($selectedShift['opened_by_name']): ?>
                    <p class="mt-4 font-label-sm text-label-sm text-on-surface-variant">
                        Opened <?= h(date('M j, g:i A', strtotime((string) $selectedShift['opened_at']))) ?>
                        by <?= h($selectedShift['opened_by_name']) ?>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="mt-4 rounded-lg bg-surface-container-low p-3 font-label-md text-label-md text-on-surface-variant">
                    No cashier shift was opened for this cashier and date.
                </p>
            <?php endif; ?>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-surface-container font-label-sm uppercase tracking-wider text-on-surface-variant">
                            <th class="py-3 pr-4">Method</th>
                            <th class="py-3 pr-4 text-right">Payments</th>
                            <th class="py-3 pr-4 text-right">Refunds / voids</th>
                            <th class="py-3 text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container font-body-md">
                        <?php if ($paymentTotals === []): ?>
                            <tr><td class="py-4 text-on-surface-variant" colspan="4">No payment entries for this cashier and date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($paymentTotals as $total): ?>
                                <tr>
                                    <td class="py-3 pr-4"><?= h(payment_method_label((string) $total['method'])) ?></td>
                                    <td class="py-3 pr-4 text-right">₱<?= h(number_format((float) $total['gross_payments'], 2)) ?></td>
                                    <td class="py-3 pr-4 text-right">₱<?= h(number_format((float) $total['reversals'], 2)) ?></td>
                                    <td class="py-3 text-right font-mono-metric font-bold">₱<?= h(number_format((float) $total['net_amount'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <section class="rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <h2 class="font-headline-md text-headline-md font-bold text-primary">All cashiers · <?= h($selectedDate) ?></h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-surface-container font-label-sm uppercase tracking-wider text-on-surface-variant">
                            <th class="py-3 pr-4">Cashier</th>
                            <th class="py-3 pr-4 text-right">Cash net</th>
                            <th class="py-3 pr-4 text-right">E-wallet net</th>
                            <th class="py-3 pr-4 text-right">Expected cash</th>
                            <th class="py-3">Shift</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container font-body-md">
                        <?php foreach ($dailySummary as $summary): ?>
                            <?php
                            $summaryHasShift = $summary['opening_float'] !== null;
                            $summaryExpected = $summary['closed_at'] !== null
                                ? (float) $summary['cash_expected']
                                : ($summaryHasShift ? (float) $summary['opening_float'] + (float) $summary['cash_net'] : 0.0);
                            ?>
                            <tr>
                                <td class="py-3 pr-4">
                                    <a class="font-body-md-bold text-primary hover:underline" href="cashier-closeout.php?cashier_id=<?= h($summary['id']) ?>&amp;date=<?= h($selectedDate) ?>"><?= h($summary['full_name']) ?></a>
                                    <span class="block font-label-sm text-on-surface-variant"><?= h(ucfirst((string) $summary['role'])) ?></span>
                                </td>
                                <td class="py-3 pr-4 text-right">₱<?= h(number_format((float) $summary['cash_net'], 2)) ?></td>
                                <td class="py-3 pr-4 text-right">₱<?= h(number_format((float) $summary['ewallet_net'], 2)) ?></td>
                                <td class="py-3 pr-4 text-right"><?= $summaryHasShift ? '₱' . h(number_format($summaryExpected, 2)) : '—' ?></td>
                                <td class="py-3"><?= !$summaryHasShift ? 'Not opened' : ($summary['closed_at'] !== null ? 'Closed' : 'Open') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
