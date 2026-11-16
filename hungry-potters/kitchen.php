<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'kitchen']);
require_once __DIR__ . '/config/db.php';

$ordersStatement = $pdo->query(
    "SELECT
        orders.id,
        orders.order_number,
        orders.queue_number,
        orders.order_type,
        orders.location,
        orders.status,
        orders.kitchen_note,
        orders.created_at,
        orders.started_at,
        orders.completed_at,
        GROUP_CONCAT(
            CONCAT(order_items.quantity, '× ', order_items.item_name)
            ORDER BY order_items.id
            SEPARATOR ', '
        ) AS items_summary
     FROM orders
     LEFT JOIN order_items ON order_items.order_id = orders.id
     WHERE orders.status IN ('placed', 'preparing', 'completed')
     GROUP BY orders.id
     ORDER BY
        CASE orders.status
            WHEN 'placed' THEN 1
            WHEN 'preparing' THEN 2
            ELSE 3
        END,
        CASE
            WHEN orders.status = 'completed' THEN orders.completed_at
            ELSE orders.created_at
        END DESC
     LIMIT 60"
);
$orders = $ordersStatement->fetchAll();

$placedOrders = [];
$preparingOrders = [];
$completedOrders = [];
foreach ($orders as $order) {
    if ($order['status'] === 'placed') {
        $placedOrders[] = $order;
    } elseif ($order['status'] === 'preparing') {
        $preparingOrders[] = $order;
    } else {
        $completedOrders[] = $order;
    }
}

$activeCount = count($placedOrders) + count($preparingOrders);
$pageTitle = 'Kitchen Display';
$currentPage = 'kitchen-display';
$pageScript = 'assets/js/kitchen.js';
require __DIR__ . '/includes/header.php';
?>
<div
    class="flex w-full flex-col"
    data-kitchen-board
    data-csrf-token="<?= h(csrf_token()) ?>"
    data-status-endpoint="actions/order-status-update.php"
    data-refresh-endpoint="api/kitchen-orders.php"
    data-refresh-seconds="5"
>
    <section class="mb-6 w-full rounded-xl bg-primary px-6 py-5 text-on-primary shadow-lg">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-tertiary-container text-tertiary-fixed shadow-inner">
                    <span class="material-symbols-outlined text-[28px]">skillet</span>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-headline-lg text-headline-lg font-bold tracking-tight">KITCHEN DISPLAY STATION</h2>
                        <span class="rounded-full bg-secondary-container px-3 py-1 font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-secondary-fixed">STATION #01 &bull; HOT LINE</span>
                    </div>
                    <div class="mt-1 flex items-center gap-2 font-body-md text-body-md text-on-primary-container">
                        <span class="material-symbols-outlined text-[18px]">schedule</span>
                        <span>Live kitchen queue</span>
                        <span>&bull; Refreshes every 5 seconds</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2 rounded-lg border border-tertiary-fixed/30 bg-primary-container px-4 py-2 text-tertiary-fixed">
                    <span class="relative flex h-3 w-3">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-tertiary-fixed opacity-75"></span>
                        <span class="relative inline-flex h-3 w-3 rounded-full bg-tertiary-fixed"></span>
                    </span>
                    <span class="font-label-md text-label-md font-bold">Counter Synced</span>
                </div>
                <div class="flex items-center gap-2 rounded-lg bg-secondary px-4 py-2 font-bold text-on-secondary shadow-md">
                    <span class="material-symbols-outlined text-[22px]">local_fire_department</span>
                    <span class="font-headline-sm text-headline-sm"><span data-active-count><?= h($activeCount) ?></span> Orders Active</span>
                </div>
                <div class="rounded-lg bg-primary-container px-4 py-2 font-body-md text-on-primary-container">
                    <span data-last-refresh>Syncing...</span>
                </div>
            </div>
        </div>
    </section>

    <div class="grid w-full grid-cols-1 items-start gap-6 xl:grid-cols-3" data-kitchen-columns>
        <?php
        $columns = [
            [
                'status' => 'placed',
                'orders' => $placedOrders,
                'title' => 'PLACED',
                'description' => 'Awaiting Prep',
                'dot' => 'bg-secondary',
                'badge' => 'bg-secondary-fixed text-on-secondary-fixed',
                'badgeText' => 'New',
            ],
            [
                'status' => 'preparing',
                'orders' => $preparingOrders,
                'title' => 'PREPARING',
                'description' => 'In Progress',
                'dot' => 'bg-secondary-container',
                'badge' => 'bg-primary text-on-primary',
                'badgeText' => 'Active',
            ],
            [
                'status' => 'completed',
                'orders' => $completedOrders,
                'title' => 'RECENTLY COMPLETED',
                'description' => 'Last Expedited',
                'dot' => 'bg-primary-container',
                'badge' => 'bg-surface-container-highest text-on-surface-variant',
                'badgeText' => 'History',
            ],
        ];
        foreach ($columns as $column):
        ?>
            <section class="flex flex-col gap-4 rounded-xl border border-outline-variant/40 bg-surface-container p-4 shadow-sm" data-kitchen-column="<?= h($column['status']) ?>">
                <div class="flex items-center justify-between border-b border-surface-container-highest px-1 pb-3">
                    <div class="flex items-center gap-3">
                        <span class="h-4 w-4 rounded-full <?= h($column['dot']) ?>"></span>
                        <div>
                            <h3 class="font-headline-md text-headline-md font-bold tracking-tight text-primary">
                                <?= h($column['title']) ?> (<span data-column-count><?= h(count($column['orders'])) ?></span>)
                            </h3>
                            <p class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant"><?= h($column['description']) ?></p>
                        </div>
                    </div>
                    <span class="rounded-full px-3 py-1 font-label-md text-label-md font-bold <?= h($column['badge']) ?>"><?= h($column['badgeText']) ?></span>
                </div>

                <div class="flex flex-col gap-4" data-order-list="<?= h($column['status']) ?>">
                    <?php if ($column['orders'] === []): ?>
                        <p class="rounded-xl bg-surface-container-lowest p-5 text-center font-body-md text-body-md text-on-surface-variant" data-empty-state>
                            <?= $column['status'] === 'completed' ? 'No recently completed orders.' : 'No orders waiting in this column.' ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($column['orders'] as $order): ?>
                            <?php
                            $createdTimestamp = strtotime((string) $order['created_at']);
                            $elapsedMinutes = max(0, (int) floor((time() - $createdTimestamp) / 60));
                            $orderTypeLabel = [
                                'dine_in' => 'Dine-In',
                                'takeout' => 'Takeout',
                                'delivery' => 'Delivery',
                            ][$order['order_type']] ?? $order['order_type'];
                            ?>
                            <article class="flex flex-col rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-md" data-order-card data-order-id="<?= h($order['id']) ?>" data-order-status="<?= h($order['status']) ?>">
                                <div class="flex items-start justify-between gap-2 border-b border-surface-container-low pb-3">
                                    <div class="flex flex-col">
                                        <span class="text-[40px] font-bold leading-none tracking-tighter text-primary">Q-<?= h(str_pad((string) $order['queue_number'], 3, '0', STR_PAD_LEFT)) ?></span>
                                        <span class="mt-1 font-label-md text-label-md font-bold text-on-surface-variant">#<?= h($order['order_number']) ?></span>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="rounded-lg px-3 py-1.5 font-mono-metric text-[20px] font-bold <?= $elapsedMinutes >= 10 && $order['status'] !== 'completed' ? 'bg-error text-on-error' : 'bg-secondary-fixed text-on-secondary-fixed' ?>">
                                            <?= h($elapsedMinutes) ?>m
                                        </span>
                                        <time class="font-label-sm text-label-sm font-semibold text-on-surface-variant" datetime="<?= h(date(DATE_ATOM, $createdTimestamp)) ?>"><?= h(date('g:i A', $createdTimestamp)) ?></time>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <span class="inline-block rounded-md bg-surface-container-high px-3 py-1 font-body-md-bold text-body-md-bold text-primary">
                                        <?= h($orderTypeLabel) ?><?= $order['location'] ? ' • ' . h($order['location']) : '' ?>
                                    </span>
                                </div>
                                <div class="flex flex-col gap-2 py-4">
                                    <p class="font-body-lg text-body-lg font-semibold text-on-surface"><?= h($order['items_summary'] ?: 'No items') ?></p>
                                    <?php if ($order['kitchen_note']): ?>
                                        <p class="rounded-lg border-l-4 border-secondary bg-secondary-fixed/40 p-3 font-body-md text-body-md text-on-secondary-fixed">
                                            <strong>Kitchen note:</strong> <?= h($order['kitchen_note']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($order['status'] === 'placed'): ?>
                                    <button class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-secondary font-headline-sm text-headline-sm text-on-secondary shadow-md transition-colors hover:bg-on-secondary-container disabled:cursor-wait disabled:opacity-60" data-next-status="preparing" type="button">
                                        <span class="material-symbols-outlined text-[24px]">play_circle</span>
                                        <span>Start Preparing</span>
                                    </button>
                                <?php elseif ($order['status'] === 'preparing'): ?>
                                    <button class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-tertiary font-headline-sm text-headline-sm text-on-tertiary shadow-md transition-colors hover:bg-tertiary-container disabled:cursor-wait disabled:opacity-60" data-next-status="completed" type="button">
                                        <span class="material-symbols-outlined text-[24px]">check_circle</span>
                                        <span>Mark as Completed</span>
                                    </button>
                                <?php else: ?>
                                    <div class="inline-flex items-center gap-2 rounded-lg bg-tertiary-fixed px-3 py-2 font-label-md text-label-md font-bold text-on-tertiary-fixed-variant">
                                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                                        <span>Completed &amp; Stock Deducted</span>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="fixed bottom-6 right-6 z-50 hidden max-w-md rounded-xl bg-primary px-5 py-3 font-body-md-bold text-body-md-bold text-on-primary shadow-2xl" data-kitchen-message role="status"></div>

    <footer class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-outline-variant/30 bg-surface-container-low px-6 py-4 shadow-sm">
        <div class="flex items-center gap-3">
            <span class="h-3 w-3 animate-pulse rounded-full bg-tertiary-container"></span>
            <span class="font-body-lg text-body-lg font-bold text-primary">Kitchen Station Online</span>
            <span class="text-outline-variant">•</span>
            <span class="font-body-md text-body-md text-on-surface-variant">Orders sync from the counter</span>
        </div>
        <span class="font-label-md text-label-md text-on-surface-variant">Stock is deducted when an order is placed.</span>
    </footer>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
