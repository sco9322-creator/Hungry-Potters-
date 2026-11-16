<?php
declare(strict_types=1);

$deniedRole = (string) ($_SESSION['role'] ?? 'staff');
$deniedRoleLabel = ucfirst($deniedRole);
$deniedDestination = match ($deniedRole) {
    'cashier' => ['new-order.php', 'Go to POS'],
    'kitchen' => ['kitchen.php', 'Go to Kitchen Display'],
    default => ['dashboard.php', 'Go to Dashboard'],
};
$deniedScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$deniedBasePath = rtrim(dirname($deniedScriptPath), '/');
if (preg_match('~/(?:actions|api)$~', $deniedBasePath) === 1) {
    $deniedBasePath = dirname($deniedBasePath);
}
$deniedAppUrl = $deniedBasePath . '/';
$deniedHomeUrl = $deniedAppUrl . $deniedDestination[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3fbf6">
    <title>Access Restricted | Hungry Potters</title>
    <link rel="icon" type="image/png" href="<?= h($deniedAppUrl) ?>assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="<?= h($deniedAppUrl) ?>assets/js/tailwind-config.js"></script>
    <link href="<?= h($deniedAppUrl) ?>assets/css/app.css" rel="stylesheet">
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 py-10 font-body-md text-on-surface antialiased">
    <main class="w-full max-w-md">
        <section class="rounded-2xl bg-surface-container-lowest p-8 text-center shadow-lg sm:p-10">
            <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-error-container text-error">
                <svg aria-hidden="true" class="h-8 w-8" fill="none" viewBox="0 0 24 24">
                    <path d="M7 10V7a5 5 0 0 1 10 0v3m-11 0h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2Zm6 5v2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/>
                    <circle cx="12" cy="15" r=".75" fill="currentColor"/>
                </svg>
            </div>
            <p class="mb-2 font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Hungry Potters &bull; Operations &amp; Kitchen</p>
            <h1 class="font-headline-lg text-headline-lg font-bold text-primary">Access Restricted</h1>
            <p class="mt-3 font-body-md text-body-md text-on-surface-variant">
                This page isn’t available to the <?= h($deniedRoleLabel) ?> role. You can return to your authorized workspace.
            </p>
            <a class="mt-7 flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary-container" href="<?= h($deniedHomeUrl) ?>">
                <span><?= h($deniedDestination[1]) ?></span>
                <svg aria-hidden="true" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24">
                    <path d="M5 12h14m-7-7 7 7-7 7" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
            </a>
        </section>
    </main>
</body>
</html>
