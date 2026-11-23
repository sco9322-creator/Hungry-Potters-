<?php
declare(strict_types=1);

$sidebarRole = match ($_SESSION['role'] ?? null) {
    'admin' => ['admin', 'Admin Console', 'shield_person'],
    'cashier' => ['cashier', 'Cashier Station', 'point_of_sale'],
    'kitchen' => ['kitchen', 'Kitchen Station', 'skillet'],
    default => null,
};

$currentPage = $currentPage ?? '';
$navigationItems = [
    ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => 'dashboard.php'],
    ['page' => 'orders', 'label' => 'Orders', 'icon' => 'receipt_long', 'href' => 'orders.php'],
    ['page' => 'new-order', 'label' => 'New Order', 'icon' => 'add_circle', 'href' => 'new-order.php', 'roles' => ROLE_POLICY_ORDER_ENTRY],
    ['page' => 'kitchen-display', 'label' => 'Kitchen Display', 'icon' => 'skillet', 'href' => 'kitchen.php', 'roles' => ROLE_POLICY_KITCHEN_OPERATIONS],
    ['page' => 'queue-display', 'label' => 'Customer Queue', 'icon' => 'tv', 'href' => 'queue-display.php', 'roles' => ROLE_POLICY_ORDER_ENTRY, 'new_tab' => true],
    ['page' => 'inventory', 'label' => 'Inventory', 'icon' => 'inventory_2', 'href' => 'inventory.php'],
    ['page' => 'cashier-closeout', 'label' => 'Cashier Closeout', 'icon' => 'point_of_sale', 'href' => 'cashier-closeout.php', 'roles' => ROLE_POLICY_ORDER_ENTRY],
    ['page' => 'menu-management', 'label' => 'Menu Management', 'icon' => 'restaurant_menu', 'href' => 'menu-management.php', 'roles' => ROLE_POLICY_ADMIN],
    ['page' => 'user-management', 'label' => 'User Management', 'icon' => 'manage_accounts', 'href' => 'user-management.php', 'roles' => ROLE_POLICY_ADMIN],
];
?>
<aside<?= $sidebarRole !== null ? ' data-role="' . h($sidebarRole[0]) . '"' : '' ?> class="fixed left-0 top-0 z-50 flex h-full w-72 flex-col bg-surface-container-low data-[role]:bg-sidebar group/sidebar shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
    <div class="flex h-20 items-center gap-2 bg-surface-container-low px-6 group-data-[role]/sidebar:bg-sidebar">
        <img
            alt="Hungry Potters logo"
            class="h-12 w-12 shrink-0 object-contain"
            src="assets/images/logo.png"
        />
        <div class="flex min-w-0 flex-col">
            <span class="truncate font-headline-sm text-headline-sm tracking-tight text-primary group-data-[role]/sidebar:text-sidebar-text">Hungry Potters</span>
            <span class="truncate font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant group-data-[role]/sidebar:text-sidebar-accent">
                <?php if ($sidebarRole !== null): ?>
                    <span aria-hidden="true" class="material-symbols-outlined align-middle text-[14px]"><?= h($sidebarRole[2]) ?></span>
                    <?= h($sidebarRole[1]) ?>
                <?php else: ?>
                    Operations &amp; Kitchen
                <?php endif; ?>
            </span>
        </div>
    </div>
    <div class="px-4 py-2"><div class="h-px bg-surface-container-highest group-data-[role]/sidebar:bg-sidebar-accent"></div></div>
    <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-4 py-2">
        <?php foreach ($navigationItems as $item): ?>
            <?php if (isset($item['roles']) && !role_is_allowed($item['roles'])) { continue; } ?>
            <?php $isActive = $currentPage === $item['page']; ?>
            <a
                aria-current="<?= $isActive ? 'page' : 'false' ?>"
                class="flex min-h-12 items-center gap-4 rounded-lg px-4 py-2 transition-colors <?= $isActive ? 'bg-primary-container font-body-md-bold text-on-primary shadow-sm group-data-[role]/sidebar:bg-sidebar-active group-data-[role]/sidebar:ring-2 group-data-[role]/sidebar:ring-inset group-data-[role]/sidebar:ring-sidebar-accent' : 'font-body-md text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface group-data-[role]/sidebar:text-sidebar-text group-data-[role]/sidebar:hover:bg-sidebar-panel group-data-[role]/sidebar:hover:text-sidebar-text' ?>"
                href="<?= h($item['href']) ?>"
                <?php if (!empty($item['new_tab'])): ?>
                    target="_blank"
                    rel="noopener noreferrer"
                <?php endif; ?>
            >
                <span class="material-symbols-outlined text-[22px] <?= $isActive ? '' : 'group-data-[role]/sidebar:text-sidebar-accent' ?>"><?= h($item['icon']) ?></span>
                <span class="truncate"><?= h($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="bg-surface-container-low p-4 group-data-[role]/sidebar:bg-sidebar">
        <div class="flex items-center justify-between rounded-xl bg-surface-container p-4 group-data-[role]/sidebar:bg-sidebar-panel">
            <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant group-data-[role]/sidebar:text-sidebar-accent">Terminal Status</span>
                <span class="flex items-center gap-1 font-body-md-bold text-body-md-bold text-primary group-data-[role]/sidebar:text-sidebar-text">
                    <span class="inline-block h-2 w-2 rounded-full bg-secondary-container group-data-[role]/sidebar:bg-sidebar-accent"></span>
                    Online &bull; POS-01
                </span>
            </div>
            <span class="material-symbols-outlined text-[20px] text-on-surface-variant group-data-[role]/sidebar:text-sidebar-accent">sensors</span>
        </div>
    </div>
</aside>
