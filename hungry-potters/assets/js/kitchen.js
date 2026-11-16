'use strict';

(() => {
    const board = document.querySelector('[data-kitchen-board]');
    if (!board) {
        return;
    }

    const columns = {
        placed: board.querySelector('[data-order-list="placed"]'),
        preparing: board.querySelector('[data-order-list="preparing"]'),
        completed: board.querySelector('[data-order-list="completed"]')
    };
    const csrfToken = board.dataset.csrfToken;
    const statusEndpoint = board.dataset.statusEndpoint;
    const refreshEndpoint = board.dataset.refreshEndpoint;
    const refreshMilliseconds = Math.max(3, Number(board.dataset.refreshSeconds) || 5) * 1000;
    const message = board.querySelector('[data-kitchen-message]');
    const activeCount = board.querySelector('[data-active-count]');
    const lastRefresh = board.querySelector('[data-last-refresh]');
    let refreshTimer = null;
    let toastTimer = null;
    let requestInProgress = false;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[character]);

    const formatTime = (value) => {
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return new Intl.DateTimeFormat(undefined, {
            hour: 'numeric',
            minute: '2-digit'
        }).format(date);
    };

    const elapsedMinutes = (value) => {
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return 0;
        }
        return Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
    };

    function showMessage(text, isError = false) {
        if (!message) {
            return;
        }
        message.textContent = text;
        message.classList.remove('hidden', 'bg-error');
        if (isError) {
            message.classList.add('bg-error');
        }
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => message.classList.add('hidden'), 4000);
    }

    function buildCard(order) {
        const status = String(order.status);
        const minutes = elapsedMinutes(order.created_at);
        const queue = `Q-${String(order.queue_number).padStart(3, '0')}`;
        const orderType = {
            dine_in: 'Dine-In',
            takeout: 'Takeout',
            delivery: 'Delivery'
        }[order.order_type] || order.order_type;
        const location = order.location ? ` • ${escapeHtml(order.location)}` : '';
        const timerClass = minutes >= 10 && status !== 'completed'
            ? 'bg-error text-on-error'
            : 'bg-secondary-fixed text-on-secondary-fixed';
        const note = order.kitchen_note
            ? `<p class="rounded-lg border-l-4 border-secondary bg-secondary-fixed/40 p-3 font-body-md text-body-md text-on-secondary-fixed"><strong>Kitchen note:</strong> ${escapeHtml(order.kitchen_note)}</p>`
            : '';
        let action;
        if (status === 'placed') {
            action = '<button class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-secondary font-headline-sm text-headline-sm text-on-secondary shadow-md transition-colors hover:bg-on-secondary-container disabled:cursor-wait disabled:opacity-60" data-next-status="preparing" type="button"><span class="material-symbols-outlined text-[24px]">play_circle</span><span>Start Preparing</span></button>';
        } else if (status === 'preparing') {
            action = '<button class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-tertiary font-headline-sm text-headline-sm text-on-tertiary shadow-md transition-colors hover:bg-tertiary-container disabled:cursor-wait disabled:opacity-60" data-next-status="completed" type="button"><span class="material-symbols-outlined text-[24px]">check_circle</span><span>Mark as Completed</span></button>';
        } else {
            action = '<div class="inline-flex items-center gap-2 rounded-lg bg-tertiary-fixed px-3 py-2 font-label-md text-label-md font-bold text-on-tertiary-fixed-variant"><span class="material-symbols-outlined text-[18px]">check_circle</span><span>Completed &amp; Stock Deducted</span></div>';
        }
        const createdDate = new Date(String(order.created_at).replace(' ', 'T'));
        const datetime = Number.isNaN(createdDate.getTime()) ? '' : createdDate.toISOString();

        return `<article class="flex flex-col rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-md" data-order-card data-order-id="${escapeHtml(order.id)}" data-order-status="${escapeHtml(status)}">
            <div class="flex items-start justify-between gap-2 border-b border-surface-container-low pb-3">
                <div class="flex flex-col">
                    <span class="text-[40px] font-bold leading-none tracking-tighter text-primary">${escapeHtml(queue)}</span>
                    <span class="mt-1 font-label-md text-label-md font-bold text-on-surface-variant">#${escapeHtml(order.order_number)}</span>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="rounded-lg px-3 py-1.5 font-mono-metric text-[20px] font-bold ${timerClass}">${minutes}m</span>
                    <time class="font-label-sm text-label-sm font-semibold text-on-surface-variant" datetime="${escapeHtml(datetime)}">${escapeHtml(formatTime(order.created_at))}</time>
                </div>
            </div>
            <div class="mt-3"><span class="inline-block rounded-md bg-surface-container-high px-3 py-1 font-body-md-bold text-body-md-bold text-primary">${escapeHtml(orderType)}${location}</span></div>
            <div class="flex flex-col gap-2 py-4">
                <p class="font-body-lg text-body-lg font-semibold text-on-surface">${escapeHtml(order.items_summary || 'No items')}</p>
                ${note}
            </div>
            ${action}
        </article>`;
    }

    function renderOrders(orders) {
        const grouped = { placed: [], preparing: [], completed: [] };
        orders.forEach((order) => {
            if (Object.prototype.hasOwnProperty.call(grouped, order.status)) {
                grouped[order.status].push(order);
            }
        });

        Object.entries(columns).forEach(([status, list]) => {
            if (!list) {
                return;
            }
            const group = grouped[status];
            list.innerHTML = group.length
                ? group.map(buildCard).join('')
                : `<p class="rounded-xl bg-surface-container-lowest p-5 text-center font-body-md text-body-md text-on-surface-variant" data-empty-state>${status === 'completed' ? 'No recently completed orders.' : 'No orders waiting in this column.'}</p>`;
            const count = list.closest('[data-kitchen-column]')?.querySelector('[data-column-count]');
            if (count) {
                count.textContent = String(group.length);
            }
        });
        if (activeCount) {
            activeCount.textContent = String(grouped.placed.length + grouped.preparing.length);
        }
        if (lastRefresh) {
            lastRefresh.textContent = `Synced ${formatTime(new Date().toISOString())}`;
        }
    }

    async function refreshOrders() {
        if (requestInProgress) {
            return;
        }
        requestInProgress = true;
        try {
            const response = await fetch(refreshEndpoint, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) {
                throw new Error(`Queue refresh failed (HTTP ${response.status}).`);
            }
            const result = await response.json();
            if (!Array.isArray(result.orders)) {
                throw new Error('Queue refresh returned an invalid response.');
            }
            renderOrders(result.orders);
        } catch (error) {
            showMessage(error instanceof Error ? error.message : 'Unable to refresh the kitchen queue.', true);
        } finally {
            requestInProgress = false;
        }
    }

    async function updateOrder(button) {
        const card = button.closest('[data-order-card]');
        if (!card) {
            return;
        }
        button.disabled = true;
        try {
            const response = await fetch(statusEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    order_id: card.dataset.orderId,
                    status: button.dataset.nextStatus
                })
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                throw new Error(result.message || `Order update failed (HTTP ${response.status}).`);
            }
            showMessage(result.message);
            await refreshOrders();
        } catch (error) {
            showMessage(error instanceof Error ? error.message : 'Unable to update the order.', true);
            button.disabled = false;
        }
    }

    board.addEventListener('click', (event) => {
        const button = event.target.closest('[data-next-status]');
        if (button) {
            updateOrder(button);
        }
    });

    refreshOrders();
    refreshTimer = window.setInterval(refreshOrders, refreshMilliseconds);
    window.addEventListener('beforeunload', () => window.clearInterval(refreshTimer), { once: true });
})();
