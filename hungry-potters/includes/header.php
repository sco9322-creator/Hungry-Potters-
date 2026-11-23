<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Hungry Potters';
$hidePageHeading = $hidePageHeading ?? false;
$displayName = $_SESSION['full_name'] ?? 'Staff Member';
$displayRole = ucfirst((string) ($_SESSION['role'] ?? 'staff'));
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
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-surface font-body-md text-on-surface antialiased">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="flex min-h-screen flex-col <?= $hidePageHeading ? 'pl-0 md:pl-72' : 'pl-72' ?>">
    <header class="<?= $hidePageHeading ? 'static left-auto right-auto top-auto h-auto md:fixed md:left-72 md:right-0 md:top-0 md:h-20' : 'fixed left-72 right-0 top-0 h-20' ?> z-40 bg-surface/90 shadow-[0_1px_8px_rgba(0,0,0,0.04)] backdrop-blur-xl">
        <div class="flex w-full items-center justify-between <?= $hidePageHeading ? 'min-h-16 flex-wrap gap-2 px-4 py-3 md:h-20 md:flex-nowrap md:px-8 md:py-0' : 'h-20 px-8' ?>">
            <div class="flex items-center gap-4">
                <?php if (!$hidePageHeading): ?>
                    <h1 class="font-headline-lg text-headline-lg font-bold text-primary"><?= h($pageTitle) ?></h1>
                <?php endif; ?>
                <div class="flex items-center gap-1 rounded-full bg-surface-container px-4 py-1 text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px]">schedule</span>
                    <time class="font-label-lg text-label-lg font-mono-metric tracking-tight" data-live-clock></time>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 rounded-full bg-surface-container px-4 py-1.5">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-secondary-container"></span>
                    <span class="font-body-md text-body-md text-on-surface">
                        <strong class="font-body-md-bold text-primary"><?= h($displayRole) ?></strong>
                        &bull; <?= h($displayName) ?>
                    </span>
                </div>
                <form action="logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button class="flex min-h-12 items-center gap-1 rounded-lg px-3 font-body-md-bold text-body-md-bold text-on-surface-variant transition-colors hover:bg-surface-container" type="submit">
                        <span class="material-symbols-outlined text-[20px]">logout</span>
                        <span>Sign out</span>
                    </button>
                </form>
            </div>
        </div>
    </header>
    <main class="w-full flex-1 bg-surface <?= $hidePageHeading ? 'px-4 pb-8 pt-6 md:px-8 md:pb-12 md:pt-20' : 'px-8 pb-12 pt-20' ?>">
