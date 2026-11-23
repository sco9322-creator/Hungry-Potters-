<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password-policy.php';
require_login();

if (empty($_SESSION['must_change_password'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (!verify_csrf_token($submittedToken)) {
        http_response_code(400);
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? null;
        $newPassword = $_POST['new_password'] ?? null;
        $confirmPassword = $_POST['confirm_password'] ?? null;
        if (
            !is_string($currentPassword)
            || $currentPassword === ''
            || strlen($currentPassword) > 255
            || !is_string($newPassword)
            || !password_meets_policy($newPassword)
            || !is_string($confirmPassword)
            || $newPassword !== $confirmPassword
        ) {
            $errorMessage = 'Enter your current password and a matching new password of at least 12 characters (maximum 72 UTF-8 bytes).';
        } else {
            $statement = $pdo->prepare(
                'SELECT password_hash
                 FROM users
                 WHERE id = :id AND is_active = 1 AND must_change_password = 1
                 LIMIT 1'
            );
            $statement->execute(['id' => (int) $_SESSION['user_id']]);
            $user = $statement->fetch();

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $errorMessage = 'The current password is incorrect. Please try again.';
            } elseif (password_verify($newPassword, $user['password_hash'])) {
                $errorMessage = 'Choose a new password that is different from your current password.';
            } else {
                $update = $pdo->prepare(
                    'UPDATE users
                     SET password_hash = :password_hash,
                         must_change_password = 0,
                         session_version = session_version + 1
                     WHERE id = :id AND is_active = 1 AND must_change_password = 1'
                );
                $update->execute([
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => (int) $_SESSION['user_id'],
                ]);

                if ($update->rowCount() !== 1) {
                    $errorMessage = 'Your password could not be updated. Please try again.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['must_change_password'] = false;
                    $_SESSION['session_version']++;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: dashboard.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3fbf6">
    <title>Change Password | Hungry Potters</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 py-10 font-body-md text-on-surface antialiased">
    <main class="w-full max-w-md">
        <section class="rounded-2xl bg-surface-container-lowest p-8 shadow-lg sm:p-10">
            <h1 class="font-headline-lg text-headline-lg font-bold text-primary">Choose a new password</h1>
            <p class="mb-6 mt-2 font-body-md text-body-md text-on-surface-variant">
                For account security, create a unique password before continuing to the operations workspace.
            </p>

            <?php if ($errorMessage !== ''): ?>
                <div class="mb-5 rounded-lg border border-error/30 bg-error-container p-3 font-body-md text-body-md text-on-error-container" role="alert">
                    <?= h($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form action="change-password.php" method="post" class="flex flex-col gap-4">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div>
                    <label class="mb-1 block font-label-md text-label-md text-on-surface" for="current_password">Current password</label>
                    <input
                        autocomplete="current-password"
                        class="h-12 w-full rounded-lg border border-outline-variant bg-surface px-4 font-body-md text-body-md text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-container/30"
                        id="current_password"
                        maxlength="255"
                        name="current_password"
                        required
                        type="password"
                    >
                </div>
                <div>
                    <label class="mb-1 block font-label-md text-label-md text-on-surface" for="new_password">New password</label>
                    <input
                        autocomplete="new-password"
                        class="h-12 w-full rounded-lg border border-outline-variant bg-surface px-4 font-body-md text-body-md text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-container/30"
                        id="new_password"
                        maxlength="72"
                        minlength="12"
                        name="new_password"
                        required
                        type="password"
                    >
                </div>
                <div>
                    <label class="mb-1 block font-label-md text-label-md text-on-surface" for="confirm_password">Confirm new password</label>
                    <input
                        autocomplete="new-password"
                        class="h-12 w-full rounded-lg border border-outline-variant bg-surface px-4 font-body-md text-body-md text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-container/30"
                        id="confirm_password"
                        maxlength="72"
                        minlength="12"
                        name="confirm_password"
                        required
                        type="password"
                    >
                </div>
                <button class="mt-2 flex min-h-12 w-full items-center justify-center rounded-lg bg-primary px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary-container" type="submit">
                    Update password
                </button>
            </form>
        </section>
    </main>
</body>
</html>
