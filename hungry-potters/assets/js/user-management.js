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

    form?.addEventListener('hp-confirm-details', event => {
        const adding = !form.querySelector('[data-user-modal-id]').value;
        event.detail.push({label: 'Action', value: adding ? 'Create account' : 'Update account'});
        if (!adding && resetPasswordInput.value) event.detail.push({label: 'Password', value: 'Reset requested'});
    });
})();
