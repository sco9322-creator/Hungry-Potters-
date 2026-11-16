<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$errorMessage = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (!verify_csrf_token($submittedToken)) {
        http_response_code(400);
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? null;

        if (
            $username === ''
            || strlen($username) > 50
            || !is_string($password)
            || $password === ''
            || strlen($password) > 255
        ) {
            $errorMessage = 'Enter a valid username and password.';
        } else {
            $statement = $pdo->prepare(
                'SELECT id, username, full_name, password_hash, role
                 FROM users
                 WHERE username = :username AND is_active = 1
                 LIMIT 1'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $errorMessage = 'The username or password is incorrect.';
        }
    }
}

function login_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3fbf6">
    <title>Staff Login | Hungry Potters</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind-config.js"></script>
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 py-10 font-body-md text-on-surface antialiased">
    <main class="w-full max-w-md">
        <section class="rounded-2xl bg-surface-container-lowest p-8 shadow-lg sm:p-10">
            <div class="mb-8 text-center">
                <img alt="Hungry Potters logo" class="mx-auto mb-4 h-28 w-28 object-contain" src="assets/images/logo.png">
                <h1 class="font-headline-lg text-headline-lg font-bold text-primary">Hungry Potters</h1>
                <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Operations &amp; Kitchen</p>
            </div>

            <h2 class="mb-1 font-headline-md text-headline-md font-bold text-primary">Staff Login</h2>
            <p class="mb-6 font-body-md text-body-md text-on-surface-variant">Sign in to open your operations workspace.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="mb-5 rounded-lg border border-error/30 bg-error-container p-3 font-body-md text-body-md text-on-error-container" role="alert">
                    <?= login_h($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post" class="flex flex-col gap-4">
                <input type="hidden" name="csrf_token" value="<?= login_h(csrf_token()) ?>">
                <div>
                    <label class="mb-1 block font-label-md text-label-md text-on-surface" for="username">Username</label>
                    <input
                        autocomplete="username"
                        class="h-12 w-full rounded-lg border border-outline-variant bg-surface px-4 font-body-md text-body-md text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-container/30"
                        id="username"
                        maxlength="50"
                        name="username"
                        required
                        value="<?= login_h($username) ?>"
                    >
                </div>
                <div>
                    <label class="mb-1 block font-label-md text-label-md text-on-surface" for="password">Password</label>
                    <input
                        autocomplete="current-password"
                        class="h-12 w-full rounded-lg border border-outline-variant bg-surface px-4 font-body-md text-body-md text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary-container/30"
                        id="password"
                        maxlength="255"
                        name="password"
                        required
                        type="password"
                    >
                </div>
                <button class="mt-2 flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary-container" type="submit">
                    <span>Sign In</span>
                    <svg aria-hidden="true" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24">
                        <path d="M5 12h14m-7-7 7 7-7 7" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    </svg>
                </button>
            </form>
        </section>
    </main>
</body>
</html>
