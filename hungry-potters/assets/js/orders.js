'use strict';

(() => {
    const cancelDialog = document.querySelector('[data-order-cancel-dialog]');
    const cancelForm = document.querySelector('#order-cancel-form');
    const cancelSummary = cancelDialog?.querySelector('[data-cancel-order-summary]');
    const dispositionFieldset = cancelDialog?.querySelector('[data-cancel-disposition]');
    const autoRestoreMessage = cancelDialog?.querySelector('[data-cancel-auto-restore]');
    const reasonSelect = cancelDialog?.querySelector('[name="cancel_reason_type"]');
    const otherLabel = cancelDialog?.querySelector('[data-cancel-other-label]');
    const otherInput = cancelDialog?.querySelector('[data-cancel-other-input]');

    const updateOtherReason = () => {
        const showOther = reasonSelect?.value === 'Other';
        otherLabel?.classList.toggle('hidden', !showOther);
        if (otherInput) {
            otherInput.classList.toggle('hidden', !showOther);
            otherInput.required = Boolean(showOther);
            if (!showOther) {
                otherInput.value = '';
            }
        }
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const button = event.target.closest('[data-open-order-cancel]');
        if (!button) {
            return;
        }
        if (!cancelDialog || !cancelForm || !cancelSummary || !reasonSelect) {
            const message = document.querySelector('[data-orders-message]');
            if (message) {
                message.textContent = 'The cancellation dialog is unavailable. Refresh the page and try again.';
                message.classList.add('bg-error');
            }
            return;
        }

        cancelForm.reset();
        cancelForm.querySelector('[data-cancel-order-id]').value = button.dataset.orderId || '';
        cancelForm.querySelector('[data-cancel-return-query]').value = button.dataset.returnQuery || '';
        const isPreparing = button.dataset.orderStatus === 'preparing';
        cancelSummary.textContent = `Cancel ${button.dataset.orderNumber || 'this order'}?`;
        dispositionFieldset?.classList.toggle('hidden', !isPreparing);
        autoRestoreMessage?.classList.toggle('hidden', isPreparing);
        dispositionFieldset?.querySelectorAll('[data-cancel-mode-input]').forEach((input) => {
            input.disabled = !isPreparing;
            input.required = isPreparing;
        });
        updateOtherReason();
        try {
            if (typeof cancelDialog.showModal === 'function') {
                cancelDialog.showModal();
            } else {
                cancelDialog.setAttribute('open', '');
            }
            reasonSelect.focus();
        } catch (error) {
            const message = document.querySelector('[data-orders-message]');
            if (message) {
                message.textContent = error instanceof Error
                    ? `Unable to open the cancellation dialog: ${error.message}`
                    : 'Unable to open the cancellation dialog. Refresh the page and try again.';
                message.classList.remove('hidden');
                message.classList.add('bg-error');
            }
        }
    });

    reasonSelect?.addEventListener('change', updateOtherReason);
    document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-close-order-cancel]')) {
            if (cancelDialog && typeof cancelDialog.close === 'function') {
                cancelDialog.close();
            } else {
                cancelDialog?.removeAttribute('open');
            }
        }
    });

    const currency = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
    document.addEventListener('hp-confirm-details', event => {
        const form = event.target;
        const fields = form.elements;
        const details = event.detail;
        if (fields.entry_type) {
            details.push({label: 'Amount', value: currency.format(fields.amount.value)},
                {label: 'Method', value: fields.method.selectedOptions?.[0]?.textContent.trim() || fields.method.value});
            if (fields.method.value === 'cash' && fields.tendered_amount?.value) {
                details.push({label: 'Tendered', value: currency.format(fields.tendered_amount.value)},
                    {label: 'Change', value: currency.format(Math.max(0, fields.tendered_amount.value - fields.amount.value))});
            }
            if (fields.reference_code?.value) details.push({label: 'Reference', value: fields.reference_code.value});
            if (fields.reason?.value) details.push({label: 'Reason', value: fields.reason.value});
            if (fields.related_payment_id) details.push({label: 'Payment ID', value: fields.related_payment_id.value});
        } else if (form.matches('[data-order-edit-form]')) {
            for (const qty of form.querySelectorAll('[data-item-name]')) {
                if (qty.value !== qty.defaultValue) details.push({label: qty.dataset.itemName, value: qty.defaultValue + ' → ' + qty.value});
            }
            for (const row of form.querySelectorAll('[data-order-add-item]')) {
                details.push({label: 'Add', value: row.querySelector('input').value + ' × ' + row.querySelector('select').selectedOptions[0].textContent.trim()});
            }
        }
    });

    document.querySelectorAll('[data-order-status-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const message = document.querySelector('[data-orders-message]');
            if (button?.disabled) return;
            if (button) button.disabled = true;
            const completing = form.elements.status.value === 'completed';
            let confirmed;
            try {
                confirmed = await window.HPConfirm.ask({
                    title: completing ? 'Mark order completed?' : 'Start preparing order?',
                    message: 'Update this order on the kitchen board.',
                    details: [{label: 'Order', value: form.dataset.orderNumber},
                        {label: 'New status', value: completing ? 'Completed' : 'Preparing'}],
                    confirmText: completing ? 'Mark completed' : 'Start preparing', trigger: button
                });
            } finally {
                if (button) {
                    button.disabled = false;
                    button.focus();
                }
            }
            if (!confirmed) return;
            if (!button || !message) {
                form.submit();
                return;
            }

            const originalButton = button.innerHTML;
            button.disabled = true;
            button.textContent = 'Updating order...';
            try {
                const response = await fetch(form.dataset.statusEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: form.elements.csrf_token.value,
                        order_id: form.elements.order_id.value,
                        status: form.elements.status.value
                    })
                });
                const result = await response.json();
                if (!response.ok || result.success !== true) {
                    throw new Error(result.message || `Order update failed (HTTP ${response.status}).`);
                }
                message.textContent = result.message;
                message.classList.remove('hidden', 'bg-error');
                window.setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                message.textContent = error instanceof Error ? error.message : 'Unable to update the order.';
                message.classList.remove('hidden');
                message.classList.add('bg-error');
                button.disabled = false;
                button.innerHTML = originalButton;
            }
        });
    });

    const addOrderItemButton = document.querySelector('[data-add-order-item]');
    const orderAddItems = document.querySelector('[data-order-add-items]');
    const orderAddItemTemplate = document.querySelector('[data-order-add-item-template]');
    let orderAddItemIndex = 0;
    addOrderItemButton?.addEventListener('click', () => {
        if (!orderAddItems || !orderAddItemTemplate) {
            return;
        }
        const row = orderAddItemTemplate.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace('__INDEX__', String(orderAddItemIndex));
        });
        orderAddItemIndex += 1;
        row.querySelector('[data-remove-order-item]')?.addEventListener('click', () => row.remove());
        orderAddItems.append(row);
        row.querySelector('select')?.focus();
    });
})();
