<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/telegram.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;
if (!verify_csrf_token($submittedToken)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the page and try again.');
}

$result = telegram_notify($pdo, 'test');
$_SESSION['dashboard_message'] = $result['message'];
$_SESSION['dashboard_message_type'] = $result['success'] ? 'success' : 'error';
header('Location: ../dashboard.php');
exit;
