'use strict';

(() => {
    const dialog = document.querySelector('[data-user-dialog]');
    const form = document.querySelector('#user-account-form');
    const modalTitle = dialog?.querySelector('[data-user-modal-title]');
    const saveButton = dialog?.querySelector('[data-user-save]');
    const initialPasswordSection = dialog?.querySelector('[data-initial-password-section]');
    const initialPasswordInput = dialog?.querySelector('[data-initial-password-input]');
    const resetPasswordSection = dialog?.querySelector('[data-reset-password-section]');
    const resetPasswordInput = dialog?.querySelector('[data-reset-password-input]');
    const helpText = dialog?.querySelector('[data-password-help]');
    const confirmDialog = document.querySelector('[data-user-confirm-dialog]');
    const confirmForm = document.querySelector('#user-action-form');
    const confirmTitle = confirmDialog?.querySelector('[data-user-confirm-title]');
    const confirmMessage = confirmDialog?.querySelector('[data-user-confirm-message]');
    const confirmButton = confirmDialog?.querySelector('[data-submit-user-action]');

    const setPasswordMode = (mode) => {
        const isAdd = mode === 'add';
        dialog?.querySelectorAll('[data-toggle-password]').forEach((button) => {
            const input = button.closest('span')?.querySelector('input');
            if (input) {
                input.type = 'password';
            }
            button.setAttribute('aria-label', 'Show password');
            button.querySelector('[data-eye-show]')?.classList.remove('hidden');
            button.querySelector('[data-eye-hide]')?.classList.add('hidden');
        });
        if (initialPasswordSection) {
            initialPasswordSection.classList.toggle('hidden', !isAdd);
        }
        if (initialPasswordInput) {
            initialPasswordInput.disabled = !isAdd;
            initialPasswordInput.required = isAdd;
        }
        if (resetPasswordSection) {
            resetPasswordSection.classList.toggle('hidden', isAdd);
        }
        if (resetPasswordInput) {
            resetPasswordInput.disabled = isAdd;
            resetPasswordInput.required = false;
        }
        if (helpText) {
            helpText.textContent = isAdd ? 'At least 12 characters; maximum 72 UTF-8 bytes.' : 'Leave blank to keep the current password.';
        }
    };

    document.querySelector('[data-open-user-add]')?.addEventListener('click', () => {
        if (!dialog || !form || !modalTitle || !saveButton) {
            return;
        }
        form.reset();
        form.querySelector('[data-user-modal-id]').value = '';
        setPasswordMode('add');
        modalTitle.textContent = 'Add User';
        saveButton.textContent = 'Add User';
        dialog.showModal();
        form.querySelector('[name="full_name"]').focus();
    });

    document.querySelectorAll('[data-edit-user]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!dialog || !form || !modalTitle || !saveButton) {
                return;
            }
            const user = JSON.parse(button.dataset.editUser || '{}');
            form.reset();
            form.querySelector('[data-user-modal-id]').value = String(user.id ?? '');
            form.querySelector('[name="full_name"]').value = String(user.fullName ?? '');
            form.querySelector('[name="username"]').value = String(user.username ?? '');
            form.querySelector('[name="role"]').value = String(user.role ?? 'cashier');
            setPasswordMode('edit');
            modalTitle.textContent = 'Edit User';
            saveButton.textContent = 'Save Changes';
            dialog.showModal();
            form.querySelector('[name="full_name"]').focus();
        });
    });

    document.querySelectorAll('[data-close-user-dialog]').forEach((button) => {
        button.addEventListener('click', () => dialog?.close());
    });

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('span')?.querySelector('input');
            if (!input) {
                return;
            }
            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            button.querySelector('[data-eye-show]')?.classList.toggle('hidden', showPassword);
            button.querySelector('[data-eye-hide]')?.classList.toggle('hidden', !showPassword);
        });
    });

    document.querySelectorAll('[data-confirm-user-action]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!confirmDialog || !confirmTitle || !confirmMessage || !confirmForm || !confirmButton) {
                return;
            }
            const action = button.dataset.confirmUserAction || 'deactivate';
            const name = button.dataset.userName || 'this account';
            const deactivate = action === 'deactivate';
            confirmTitle.textContent = deactivate ? 'Deactivate account?' : 'Reactivate account?';
            confirmMessage.textContent = deactivate
                ? `Deactivate ${name}? They will no longer be able to sign in.`
                : `Reactivate ${name}? They will be able to sign in again.`;
            confirmForm.querySelector('[data-user-action]').value = action;
            confirmForm.querySelector('[data-user-action-id]').value = button.dataset.userId || '';
            confirmButton.textContent = deactivate ? 'Deactivate account' : 'Reactivate account';
            confirmButton.classList.toggle('bg-error-container', deactivate);
            confirmButton.classList.toggle('text-on-error-container', deactivate);
            confirmButton.classList.toggle('bg-primary', !deactivate);
            confirmButton.classList.toggle('text-on-primary', !deactivate);
            confirmDialog.showModal();
        });
    });

    document.querySelector('[data-close-user-confirm]')?.addEventListener('click', () => confirmDialog?.close());
    confirmButton?.addEventListener('click', () => confirmForm?.submit());
})();
