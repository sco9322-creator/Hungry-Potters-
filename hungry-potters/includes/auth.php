<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
    ]);

    session_start();
}

const ROLE_POLICY_ADMIN = ['admin'];
const ROLE_POLICY_ORDER_ENTRY = ['admin', 'cashier'];
const ROLE_POLICY_KITCHEN_OPERATIONS = ['admin', 'kitchen'];

function auth_app_base_path(): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('~/(?:actions|api)/[^/]+$~', '', $scriptPath);
    if ($basePath === $scriptPath) {
        $basePath = preg_replace('~/[^/]+$~', '', $scriptPath);
    }

    return $basePath ?? '';
}

function auth_clear_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookieParameters = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookieParameters['path'],
            $cookieParameters['domain'],
            $cookieParameters['secure'],
            $cookieParameters['httponly']
        );
    }

    session_destroy();
}

function require_login(): void
{
    $basePath = auth_app_base_path();

    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
        header('Location: ' . $basePath . '/login.php');
        exit;
    }

    $now = time();
    $startedAt = $_SESSION['auth_started_at'] ?? null;
    $lastActivity = $_SESSION['auth_last_activity'] ?? null;
    if (
        !is_int($startedAt)
        || !is_int($lastActivity)
        || $now - $lastActivity > 1800
        || $now - $startedAt > 43200
    ) {
        auth_clear_session();
        header('Location: ' . $basePath . '/login.php');
        exit;
    }
    $_SESSION['auth_last_activity'] = $now;

    global $pdo;
    require_once __DIR__ . '/../config/db.php';

    $statement = $pdo->prepare(
        'SELECT username, full_name, role, is_active, must_change_password, session_version
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        auth_clear_session();
        header('Location: ' . $basePath . '/login.php');
        exit;
    }

    $sessionVersionIsStale = !array_key_exists('session_version', $_SESSION)
        ? (int) $user['session_version'] !== 0
        : !is_int($_SESSION['session_version'])
            || $_SESSION['session_version'] !== (int) $user['session_version'];
    if (
        $sessionVersionIsStale
        || !is_string($_SESSION['role'])
        || $_SESSION['role'] !== $user['role']
    ) {
        auth_clear_session();
        header('Location: ' . $basePath . '/login.php');
        exit;
    }

    $_SESSION['session_version'] = (int) $user['session_version'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;

    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!empty($_SESSION['must_change_password']) && basename($scriptPath) !== 'change-password.php') {
        header('Location: ' . $basePath . '/change-password.php');
        exit;
    }
}

function require_role(array $allowedRoles): void
{
    require_login();

    if (!role_is_allowed($allowedRoles)) {
        http_response_code(403);
        require __DIR__ . '/access-denied.php';
        exit;
    }
}

function role_is_allowed(array $allowedRoles, ?string $role = null): bool
{
    $role = $role ?? ($_SESSION['role'] ?? null);

    return is_string($role) && in_array($role, $allowedRoles, true);
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $submittedToken): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}
