<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(ROLE_POLICY_ADMIN);
require_once __DIR__ . '/config/db.php';

$flashMessage = $_SESSION['users_message'] ?? '';
unset($_SESSION['users_message']);
$flashType = $_SESSION['users_message_type'] ?? 'success';
unset($_SESSION['users_message_type']);

$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$usersSql = 'SELECT id, username, full_name, role, is_active, created_at FROM users';
$usersParameters = [];
if ($search !== '') {
    $usersSql .= ' WHERE full_name LIKE :full_name OR username LIKE :username OR role LIKE :role';
    $term = '%' . $search . '%';
    $usersParameters = ['full_name' => $term, 'username' => $term, 'role' => $term];
}
$usersSql .= ' ORDER BY is_active DESC, full_name, username';
$usersStatement = $pdo->prepare($usersSql);
$usersStatement->execute($usersParameters);
$users = $usersStatement->fetchAll();

$pageTitle = 'User Management';
$currentPage = 'user-management';
$hidePageHeading = true;
$pageScript = 'assets/js/user-management.js';
require __DIR__ . '/includes/header.php';
?>
<div class="flex w-full flex-col gap-6">
    <section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
            <p class="mb-1 font-label-md uppercase tracking-wider text-on-surface-variant">Administration</p>
            <h2 class="font-body-md-bold text-2xl text-primary sm:text-3xl">User Management</h2>
            <p class="mt-1 font-body-md text-on-surface-variant">Manage staff accounts, roles, and access.</p>
        </div>
        <button class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary shadow-sm transition-colors hover:bg-primary-container" data-open-user-add type="button">
            <span aria-hidden="true" class="text-xl leading-none">+</span>
            <span>Add User</span>
        </button>
    </section>

    <?php if ($flashMessage !== ''): ?>
        <div class="rounded-xl border p-4 font-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>"><?= h($flashMessage) ?></div>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl bg-surface-container-lowest shadow-sm">
        <div class="flex flex-col gap-4 border-b border-surface-container-high bg-surface-container-low p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div>
                <h3 class="font-body-md-bold text-xl text-primary">Staff Accounts</h3>
                <p class="mt-1 font-label-md text-on-surface-variant"><?= h(count($users)) ?> account<?= count($users) === 1 ? '' : 's' ?></p>
            </div>
            <form action="user-management.php" class="flex w-full gap-2 sm:w-auto" method="get">
                <label class="sr-only" for="user-admin-search">Search staff accounts</label>
                <input class="h-10 min-w-0 flex-1 rounded-lg bg-surface px-3 text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container sm:w-72 sm:flex-none" id="user-admin-search" name="search" placeholder="Search name, username, or role..." type="search" value="<?= h($search) ?>">
                <button class="min-h-10 rounded-lg bg-primary px-4 font-body-md-bold text-on-primary hover:bg-primary-container" type="submit">Search</button>
            </form>
        </div>

        <?php if ($users === []): ?>
            <div class="px-6 py-14 text-center">
                <p class="font-body-md-bold text-xl text-primary">No accounts found</p>
                <p class="mt-2 font-body-md text-on-surface-variant"><?= $search !== '' ? 'Try another search.' : 'Add a staff account to get started.' ?></p>
            </div>
        <?php else: ?>
            <div class="space-y-3 p-4 md:hidden">
                <?php foreach ($users as $user): ?>
                    <?php
                    $isSelf = (int) $user['id'] === (int) $_SESSION['user_id'];
                    $isActive = (int) $user['is_active'] === 1;
                    $userData = [
                        'id' => (int) $user['id'],
                        'fullName' => (string) $user['full_name'],
                        'username' => (string) $user['username'],
                        'role' => (string) $user['role'],
                    ];
                    $roleStyles = [
                        'admin' => 'bg-primary-fixed text-primary',
                        'cashier' => 'bg-secondary-fixed text-on-secondary-fixed',
                        'kitchen' => 'bg-tertiary-fixed text-on-tertiary-fixed-variant',
                    ];
                    ?>
                    <article class="rounded-xl border border-surface-container-high p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="break-words font-body-md-bold text-body-md-bold text-primary"><?= h($user['full_name']) ?></h4>
                                    <?php if ($isSelf): ?><span class="rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-on-surface-variant">You</span><?php endif; ?>
                                </div>
                                <p class="mt-1 break-all font-body-md text-on-surface-variant">@<?= h($user['username']) ?></p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full px-3 py-1 font-label-sm <?= $roleStyles[$user['role']] ?? 'bg-surface-container text-on-surface-variant' ?>"><?= h(ucfirst((string) $user['role'])) ?></span>
                                <span class="rounded-full px-3 py-1 font-label-sm <?= $isActive ? 'bg-primary-fixed text-primary' : 'bg-surface-container text-on-surface-variant' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-surface-container-high pt-3">
                            <button class="min-h-9 rounded-lg bg-surface-container px-3 font-label-md text-primary hover:bg-surface-container-high" data-edit-user="<?= h(json_encode($userData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>" type="button">Edit</button>
                            <form action="actions/user-save.php" method="post" data-confirm data-confirm-title="<?= $isActive ? 'Deactivate account' : 'Reactivate account' ?>?" data-confirm-message="<?= $isActive ? 'This user will lose sign-in access.' : 'This user will regain sign-in access.' ?>" data-confirm-text="<?= $isActive ? 'Deactivate account' : 'Reactivate account' ?>" data-confirm-tone="<?= $isActive ? 'danger' : 'default' ?>" data-confirm-rows="<?= h(json_encode(['Name' => $user['full_name'], 'Username' => $user['username']])) ?>">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                                <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                                <input type="hidden" name="return_search" value="<?= h($search) ?>">
                                <button class="min-h-9 rounded-lg px-3 font-label-md <?= $isActive ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-primary' ?>" <?= $isSelf && $isActive ? 'disabled title="You cannot deactivate your own account."' : '' ?> type="submit"><?= $isActive ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[700px] text-left">
                    <thead class="bg-surface-container-low text-label-sm uppercase tracking-wider text-on-surface-variant">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Full name</th>
                            <th class="px-5 py-3 font-semibold">Username</th>
                            <th class="px-5 py-3 font-semibold">Role</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container-high">
                        <?php foreach ($users as $user): ?>
                            <?php
                            $isSelf = (int) $user['id'] === (int) $_SESSION['user_id'];
                            $isActive = (int) $user['is_active'] === 1;
                            $userData = [
                                'id' => (int) $user['id'],
                                'fullName' => (string) $user['full_name'],
                                'username' => (string) $user['username'],
                                'role' => (string) $user['role'],
                            ];
                            $roleStyles = [
                                'admin' => 'bg-primary-fixed text-primary',
                                'cashier' => 'bg-secondary-fixed text-on-secondary-fixed',
                                'kitchen' => 'bg-tertiary-fixed text-on-tertiary-fixed-variant',
                            ];
                            ?>
                            <tr class="hover:bg-surface-container-low/60">
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-2 font-body-md-bold text-primary">
                                        <span><?= h($user['full_name']) ?></span>
                                        <?php if ($isSelf): ?><span class="rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-on-surface-variant">You</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-body-md text-on-surface-variant"><?= h($user['username']) ?></td>
                                <td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-label-sm <?= $roleStyles[$user['role']] ?? 'bg-surface-container text-on-surface-variant' ?>"><?= h(ucfirst((string) $user['role'])) ?></span></td>
                                <td class="px-5 py-4"><span class="rounded-full px-3 py-1 font-label-sm <?= $isActive ? 'bg-primary-fixed text-primary' : 'bg-surface-container text-on-surface-variant' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button class="min-h-9 rounded-lg bg-surface-container px-3 font-label-md text-primary hover:bg-surface-container-high" data-edit-user="<?= h(json_encode($userData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>" type="button">Edit</button>
                                        <form action="actions/user-save.php" method="post" data-confirm data-confirm-title="<?= $isActive ? 'Deactivate account' : 'Reactivate account' ?>?" data-confirm-message="<?= $isActive ? 'This user will lose sign-in access.' : 'This user will regain sign-in access.' ?>" data-confirm-text="<?= $isActive ? 'Deactivate account' : 'Reactivate account' ?>" data-confirm-tone="<?= $isActive ? 'danger' : 'default' ?>" data-confirm-rows="<?= h(json_encode(['Name' => $user['full_name'], 'Username' => $user['username']])) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                                            <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                                            <input type="hidden" name="return_search" value="<?= h($search) ?>">
                                            <button class="min-h-9 rounded-lg px-3 font-label-md <?= $isActive ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-primary' ?>" <?= $isSelf && $isActive ? 'disabled title="You cannot deactivate your own account."' : '' ?> type="submit"><?= $isActive ? 'Deactivate' : 'Reactivate' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<dialog aria-labelledby="user-modal-title" class="m-auto w-[calc(100%-2rem)] max-w-xl rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-2xl backdrop:bg-primary/40" data-user-dialog>
    <form action="actions/user-save.php" class="flex max-h-[90vh] flex-col" data-confirm data-confirm-title="Save account?" data-confirm-message="Review the account details and access level." data-confirm-text="Save account" id="user-account-form" method="post">
        <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
        <input name="action" type="hidden" value="save">
        <input data-user-modal-id name="user_id" type="hidden" value="">
        <input name="return_search" type="hidden" value="<?= h($search) ?>">
        <div class="flex items-start justify-between gap-4 border-b border-surface-container-high px-5 py-4 sm:px-6">
            <div>
                <h2 class="font-body-md-bold text-xl text-primary" data-user-modal-title id="user-modal-title">Add User</h2>
                <p class="mt-1 font-body-md text-on-surface-variant">Set up staff details and role access.</p>
            </div>
            <button aria-label="Close dialog" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container" data-close-user-dialog type="button"><span aria-hidden="true" class="text-xl">×</span></button>
        </div>
        <div class="grid gap-4 overflow-y-auto p-5 sm:grid-cols-2 sm:p-6">
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant sm:col-span-2">Full name
                <input class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" maxlength="100" data-confirm-label="Full name" name="full_name" required>
            </label>
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant">Username
                <input autocomplete="off" class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" maxlength="50" data-confirm-label="Username" name="username" required>
            </label>
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant">Role
                <select class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" data-confirm-label="Role" name="role" required>
                    <option value="admin">Admin</option><option value="cashier">Cashier</option><option value="kitchen">Kitchen</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant sm:col-span-2" data-initial-password-section>Initial password
                <span class="flex h-11 overflow-hidden rounded-lg bg-surface-container-low focus-within:ring-2 focus-within:ring-primary-container">
                    <input autocomplete="new-password" class="min-w-0 flex-1 bg-transparent px-3 text-on-surface outline-none" data-initial-password-input minlength="12" maxlength="72" name="password" required type="password">
                    <button aria-label="Show password" class="flex w-11 shrink-0 items-center justify-center text-on-surface-variant hover:text-primary" data-toggle-password type="button">
                        <svg aria-hidden="true" class="h-5 w-5" data-eye-show fill="none" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.7"/></svg>
                        <svg aria-hidden="true" class="hidden h-5 w-5" data-eye-hide fill="none" viewBox="0 0 24 24"><path d="m3 3 18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a16.5 16.5 0 0 1-3.1 3.6M6.2 6.2C3.9 7.7 2.5 12 2.5 12s3.5 6 9.5 6c1 0 1.9-.2 2.7-.5M9.9 9.9a3 3 0 0 0 4.2 4.2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/></svg>
                    </button>
                </span>
                <span class="font-label-sm text-on-surface-variant" data-password-help>At least 12 characters; maximum 72 UTF-8 bytes.</span>
            </label>
            <section class="hidden rounded-xl border border-surface-container-high bg-surface-container-low p-4 sm:col-span-2" data-reset-password-section>
                <h3 class="font-body-md-bold text-body-md-bold text-primary">Reset password</h3>
                <p class="mt-1 font-label-md text-on-surface-variant">Leave blank to keep the current password.</p>
                <label class="mt-3 flex flex-col gap-1 font-label-md text-on-surface-variant">New password
                    <span class="flex h-11 overflow-hidden rounded-lg bg-surface-container-lowest focus-within:ring-2 focus-within:ring-primary-container">
                        <input autocomplete="new-password" class="min-w-0 flex-1 bg-transparent px-3 text-on-surface outline-none" data-reset-password-input disabled minlength="12" maxlength="72" name="password" type="password">
                        <button aria-label="Show password" class="flex w-11 shrink-0 items-center justify-center text-on-surface-variant hover:text-primary" data-toggle-password type="button">
                            <svg aria-hidden="true" class="h-5 w-5" data-eye-show fill="none" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.7"/></svg>
                            <svg aria-hidden="true" class="hidden h-5 w-5" data-eye-hide fill="none" viewBox="0 0 24 24"><path d="m3 3 18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a16.5 16.5 0 0 1-3.1 3.6M6.2 6.2C3.9 7.7 2.5 12 2.5 12s3.5 6 9.5 6c1 0 1.9-.2 2.7-.5M9.9 9.9a3 3 0 0 0 4.2 4.2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/></svg>
                        </button>
                    </span>
                </label>
            </section>
        </div>
        <div class="flex flex-col-reverse gap-2 border-t border-surface-container-high px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
            <button class="min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary hover:bg-surface-container-high" data-close-user-dialog type="button">Cancel</button>
            <button class="min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary hover:bg-primary-container" data-user-save type="submit">Add User</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
