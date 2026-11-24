'use strict';

(() => {
    const root = document.querySelector('[data-pos]');
    if (!root) {
        return;
    }

    const cart = new Map();
    const list = root.querySelector('[data-ticket-items]');
    const submitButton = root.querySelector('[data-submit-order]');
    const message = root.querySelector('[data-pos-message]');
    const search = root.querySelector('[data-menu-search]');
    const categoryNav = root.querySelector('[data-menu-categories]');
    const menuCards = Array.from(root.querySelectorAll('[data-menu-card]'));
    const orderType = root.querySelector('[data-order-type]');
    const locationInput = root.querySelector('[data-order-location]');
    const locationField = root.querySelector('[data-order-location-field]');
    const takeoutSlipHint = root.querySelector('[data-takeout-slip-hint]');
    let selectedCategory = 'all';
    let toastTimer = null;
    let submitting = false;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[character]);

    const money = (value) => Number(value).toFixed(2);

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

    function updateOrderLocation() {
        if (!orderType || !locationInput) {
            return;
        }
        const isTakeout = orderType.value === 'takeout';
        if (locationField) {
            locationField.hidden = isTakeout;
        }
        if (takeoutSlipHint) {
            takeoutSlipHint.hidden = !isTakeout;
        }
        locationInput.disabled = isTakeout;
        if (orderType.value === 'dine_in') {
            locationInput.placeholder = 'Table 04';
            locationInput.required = true;
        } else if (orderType.value === 'takeout') {
            locationInput.placeholder = '';
            locationInput.required = false;
        } else {
            locationInput.placeholder = 'Delivery address';
            locationInput.required = true;
        }
    }

    function renderCart() {
        if (!list) {
            return;
        }

        let subtotal = 0;
        let totalUnits = 0;
        const entries = Array.from(cart.values());
        if (entries.length === 0) {
            list.innerHTML = `<div class="flex flex-col items-center justify-center gap-1 rounded-2xl bg-surface-container-low p-6 text-center text-on-surface-variant" data-empty-ticket>
                <span class="material-symbols-outlined text-[32px] text-outline">shopping_bag</span>
                <span class="font-body-md-bold text-body-md-bold">Ticket is empty</span>
                <span class="font-label-sm text-label-sm">Select menu items to start an order.</span>
            </div>`;
        } else {
            list.innerHTML = entries.map((item) => {
                const lineTotal = item.price * item.quantity;
                subtotal += lineTotal;
                totalUnits += item.quantity;
                return `<article class="flex flex-col gap-2 rounded-2xl bg-surface-container-low p-4" data-cart-item="${escapeHtml(item.id)}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <span class="block truncate font-body-lg-bold text-body-lg-bold font-semibold text-on-surface">${escapeHtml(item.name)}</span>
                            <span class="font-label-sm text-label-sm text-on-surface-variant">${item.quantity} × ₱${money(item.price)}</span>
                        </div>
                        <span class="font-body-lg-bold text-body-lg-bold font-bold text-on-surface">₱${money(lineTotal)}</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="flex items-center gap-1 font-label-sm text-label-sm text-tertiary-container">
                            <span class="material-symbols-outlined text-[16px]">check_circle</span>
                            <span>Stock available (${item.available} max)</span>
                        </span>
                        <div class="flex items-center gap-1 rounded-xl bg-surface-container p-0.5">
                            <button aria-label="Remove one ${escapeHtml(item.name)}" class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-container-lowest text-on-surface transition-colors hover:bg-surface-container-highest" data-cart-action="decrease" data-item-id="${escapeHtml(item.id)}" type="button">
                                <span class="material-symbols-outlined text-[16px]">remove</span>
                            </button>
                            <span class="w-8 text-center font-body-md-bold text-body-md-bold text-on-surface">${item.quantity}</span>
                            <button aria-label="Add one ${escapeHtml(item.name)}" class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-container-lowest text-on-surface transition-colors hover:bg-surface-container-highest disabled:cursor-not-allowed disabled:opacity-40" data-cart-action="increase" data-item-id="${escapeHtml(item.id)}" ${item.quantity >= item.available ? 'disabled' : ''} type="button">
                                <span class="material-symbols-outlined text-[16px]">add</span>
                            </button>
                        </div>
                    </div>
                </article>`;
            }).join('');
        }

        const vat = subtotal > 0 ? subtotal * 12 / 112 : 0;
        root.querySelector('[data-ticket-subtotal]').textContent = money(subtotal);
        root.querySelector('[data-ticket-vat]').textContent = money(vat);
        root.querySelector('[data-ticket-total]').textContent = money(subtotal);
        root.querySelector('[data-ticket-unit-count]').textContent = String(totalUnits);
        if (submitButton) {
            submitButton.disabled = entries.length === 0 || submitting;
        }
    }

    function filterMenu() {
        const query = (search?.value || '').trim().toLocaleLowerCase();
        let visibleCount = 0;
        menuCards.forEach((card) => {
            const matchesCategory = selectedCategory === 'all' || card.dataset.category === selectedCategory;
            const matchesSearch = !query || (card.dataset.search || '').toLocaleLowerCase().includes(query);
            const visible = matchesCategory && matchesSearch;
            card.hidden = !visible;
            if (visible) {
                visibleCount++;
            }
        });
        const noResults = root.querySelector('[data-no-menu-results]');
        if (noResults) {
            noResults.classList.toggle('hidden', visibleCount !== 0);
        }
    }

    categoryNav?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-category-filter]');
        if (!button) {
            return;
        }
        selectedCategory = button.dataset.categoryFilter || 'all';
        categoryNav.querySelectorAll('[data-category-filter]').forEach((categoryButton) => {
            categoryButton.classList.remove('bg-primary', 'text-on-primary', 'font-body-md-bold', 'shadow-sm');
            categoryButton.classList.add('text-on-surface-variant');
        });
        button.classList.remove('text-on-surface-variant');
        button.classList.add('bg-primary', 'text-on-primary', 'font-body-md-bold', 'shadow-sm');
        filterMenu();
    });
    search?.addEventListener('input', filterMenu);
    orderType?.addEventListener('change', updateOrderLocation);
    updateOrderLocation();

    root.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-item]');
        if (addButton) {
            const card = addButton.closest('[data-menu-card]');
            if (!card || card.dataset.available !== 'true') {
                return;
            }
            const id = card.dataset.itemId;
            const existing = cart.get(id);
            const available = Number(card.dataset.itemAvailableQuantity);
            if (existing && existing.quantity >= available) {
                showMessage(`Only ${available} ${card.dataset.itemName} can be made with current stock.`, true);
                return;
            }
            cart.set(id, {
                id,
                name: card.dataset.itemName,
                price: Number(card.dataset.itemPrice),
                available,
                quantity: existing ? existing.quantity + 1 : 1
            });
            renderCart();
            showMessage(`${card.dataset.itemName} added to the ticket.`);
            return;
        }

        const cartButton = event.target.closest('[data-cart-action]');
        if (cartButton) {
            const id = cartButton.dataset.itemId;
            const item = cart.get(id);
            if (!item) {
                return;
            }
            if (cartButton.dataset.cartAction === 'increase' && item.quantity < item.available) {
                item.quantity++;
            } else if (cartButton.dataset.cartAction === 'decrease') {
                item.quantity--;
                if (item.quantity <= 0) {
                    cart.delete(id);
                }
            }
            renderCart();
        }
    });

    root.querySelector('[data-clear-ticket]')?.addEventListener('click', () => {
        cart.clear();
        renderCart();
        showMessage('The ticket has been cleared.');
    });

    submitButton?.addEventListener('click', async () => {
        if (submitting || cart.size === 0 || !orderType || !locationInput) {
            return;
        }
        if (!locationInput.checkValidity()) {
            locationInput.reportValidity();
            return;
        }

        submitting = true;
        renderCart();
        try {
            const response = await fetch(root.dataset.orderEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: root.dataset.csrfToken,
                    order_type: orderType.value,
                    location: locationInput.value.trim(),
                    kitchen_note: root.querySelector('[data-kitchen-note]')?.value.trim() || '',
                    items: Array.from(cart.values()).map((item) => ({
                        menu_item_id: Number(item.id),
                        quantity: item.quantity
                    }))
                })
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                throw new Error(result.message || `Order could not be placed (HTTP ${response.status}).`);
            }
            if (orderType.value === 'takeout') {
                if (!result.slip_number) {
                    throw new Error('The order was placed, but its slip number could not be loaded.');
                }
                window.location.assign(`order-slip.php?slip=${encodeURIComponent(result.slip_number)}`);
                return;
            }
            showMessage(`Order ${result.order_number} placed as Q-${String(result.queue_number).padStart(3, '0')}.`);
            window.setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            submitting = false;
            renderCart();
            showMessage(error instanceof Error ? error.message : 'Could not place the order.', true);
        }
    });

    // Intercept the integrated checkout before its submit handler creates a pending attempt.
    const checkoutForm = root.querySelector('[data-checkout]');
    let reviewingCheckout = false;
    let approvedCheckout = false;
    checkoutForm?.addEventListener('submit', async event => {
        if (approvedCheckout) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (reviewingCheckout || submitting) return;
        reviewingCheckout = true;
        const submitter = event.submitter;
        try {
            if (!window.HPConfirm) {
                showMessage('Checkout confirmation is unavailable. Refresh and try again.', true);
                return;
            }
            const review = pending?.checkout ?? {
                method: method.value, total: field('checkout-total').textContent,
                tendered: tender.value, reference: reference.value.trim()
            };
            const lines = pending?.items ?? Array.from(cart.values());
            const cod = review.method === 'cod';
            const pesos = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
            const details = [
                {label: 'Order type', value: {dine_in: 'Dine-in', takeout: 'Takeout', delivery: 'Delivery'}[pending?.order_type ?? orderType.value]},
                {label: 'Items', value: lines.reduce((n, line) => n + Number(line.quantity), 0)},
                {label: 'Order total', value: pesos.format(review.total)},
                {label: 'Payment method', value: {cash: 'Cash', ewallet: 'GCash / e-wallet', cod: 'Cash on delivery'}[review.method]}
            ];
            if (review.method === 'cash') {
                details.push({label: 'Cash tendered', value: pesos.format(review.tendered)},
                    {label: 'Change to give', value: pesos.format(review.tendered - review.total)});
            } else if (cod) {
                details.push({label: 'Payment status', value: 'Unpaid — will be sent to kitchen'});
            } else {
                details.push({label: 'Transaction reference', value: review.reference});
            }
            const ok = await HPConfirm.ask({
                title: pending ? 'Retry this checkout?' : 'Confirm checkout?',
                message: cod ? 'Collect payment on delivery.' : pending
                    ? 'Use the same payment already collected.' : 'Record this payment and send the order to the kitchen.',
                details, confirmText: pending ? 'Retry same checkout' : cod ? 'Send unpaid order' : 'Pay ' + pesos.format(review.total) + ' and send',
                cancelText: pending ? 'Cancel' : 'Back to checkout', trigger: submitter || document.activeElement
            });
            if (ok) {
                approvedCheckout = true;
                if (submitter) checkoutForm.requestSubmit(submitter); else checkoutForm.requestSubmit();
            }
        } catch {
            showMessage('Checkout confirmation failed. Review the ticket and try again.', true);
        } finally {
            approvedCheckout = false;
            reviewingCheckout = false;
        }
    }, true);

    renderCart();
})();
