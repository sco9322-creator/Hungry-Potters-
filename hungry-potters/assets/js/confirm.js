'use strict';

(() => {
    const dialog = document.getElementById('app-confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const accept = dialog.querySelector('[data-confirm-accept]');
    const cancel = dialog.querySelector('[data-confirm-cancel]');
    const rows = dialog.querySelector('[data-confirm-details]');
    const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    let active = null;

    function ask({title, message, details = [], confirmText = 'Confirm', cancelText = 'Cancel', tone = 'default', trigger = document.activeElement}) {
        if (active || dialog.open) return Promise.resolve(false);
        const danger = tone === 'danger';
        dialog.querySelector('h2').textContent = title;
        dialog.querySelector('p').textContent = message;
        accept.textContent = confirmText;
        cancel.textContent = cancelText;
        accept.disabled = false;
        accept.classList.toggle('bg-error-container', danger);
        accept.classList.toggle('text-on-error-container', danger);
        accept.classList.toggle('bg-primary', !danger);
        accept.classList.toggle('text-on-primary', !danger);
        rows.replaceChildren();
        for (const {label, value} of details) {
            if (value == null || value === '') continue;
            const row = document.createElement('div');
            row.className = 'flex items-baseline justify-between gap-4 py-1';
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dd.className = 'min-w-0 break-words text-right font-semibold';
            dt.textContent = label;
            dd.textContent = value;
            row.append(dt, dd);
            rows.append(row);
        }
        rows.hidden = !rows.childElementCount;
        return new Promise((resolve, reject) => {
            active = {resolve, trigger, danger};
            dialog.returnValue = 'cancel';
            try {
                dialog.showModal();
                (danger ? cancel : accept).focus();
            } catch (error) {
                active = null;
                reject(error);
            }
        });
    }

    function dismiss() { if (dialog.open) dialog.close('cancel'); }
    cancel.addEventListener('click', dismiss);
    accept.addEventListener('click', () => {
        if (!dialog.open || accept.disabled) return;
        accept.disabled = true;
        dialog.close('confirm');
    });
    dialog.addEventListener('keydown', event => {
        if (active?.danger && event.key === 'Enter' && event.target !== cancel) event.preventDefault();
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss(); });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right ||
            event.clientY < box.top || event.clientY > box.bottom) dismiss();
    });
    dialog.addEventListener('close', () => {
        const request = active;
        active = null;
        if (request?.trigger?.isConnected) request.trigger.focus();
        request?.resolve(dialog.returnValue === 'confirm');
    });
    window.HPConfirm = {ask};

    const reviewing = new WeakSet();
    const replay = new WeakSet();
    let submitted = new WeakSet();
    window.addEventListener('pageshow', () => { submitted = new WeakSet(); });
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('form[data-confirm]') || event.defaultPrevented) return;
        if (replay.delete(form)) {
            submitted.add(form);
            queueMicrotask(() => { if (event.defaultPrevented) submitted.delete(form); });
            return;
        }
        event.preventDefault();
        if (reviewing.has(form) || submitted.has(form)) return;
        reviewing.add(form);
        const button = event.submitter;
        try {
            const details = Object.entries(JSON.parse(form.dataset.confirmRows || '{}'))
                .map(([label, value]) => ({label, value}));
            for (const el of form.querySelectorAll('[data-confirm-label]')) {
                if (el.disabled || !el.value || (el.type === 'checkbox' && !el.checked)) continue;
                const value = el.dataset.confirmFormat === 'peso' ? pesos.format(el.value)
                    : el.selectedOptions?.[0]?.textContent.trim() ?? el.value;
                details.push({label: el.dataset.confirmLabel, value});
            }
            form.dispatchEvent(new CustomEvent('hp-confirm-details', {bubbles: true, detail: details}));
            const ok = await ask({
                title: form.dataset.confirmTitle, message: form.dataset.confirmMessage,
                confirmText: form.dataset.confirmText, tone: form.dataset.confirmTone, details,
                trigger: button || document.activeElement
            });
            if (ok && form.isConnected && (!button || button.form === form)) {
                replay.add(form);
                if (button) form.requestSubmit(button); else form.requestSubmit();
            }
        } catch (error) {
            window.alert('Could not open the confirmation. Refresh the page and try again.');
            console.error(error);
        } finally {
            reviewing.delete(form);
            replay.delete(form);
        }
    });
})();
