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
    const soundToggle = board.querySelector('[data-kitchen-sound-toggle]');
    const soundWarning = board.querySelector('[data-kitchen-sound-warning]');
    const newOrderBanner = board.querySelector('[data-new-order-banner]');
    const alertFeatureAvailable = Boolean(soundToggle && soundWarning && newOrderBanner);
    const normalDocumentTitle = document.title;
    const SOUND_PREFERENCE_KEY = 'hungry-potters-kitchen-sound';
    const KITCHEN_ALERT_VOLUME = 0.6;
    const NEW_ORDER_HIGHLIGHT_MS = 11_000;
    const NEW_ORDER_BANNER_MS = 8_000;
    let refreshTimer = null;
    let toastTimer = null;
    let bannerTimer = null;
    let requestInProgress = false;
    let seenOrderIds = null;
    const highlightedOrderExpiry = new Map();
    let audioContext = null;
    let soundEnabled = false;
    let audioUnlocked = false;
    let alertPlaying = false;
    let soundPreferenceAvailable = true;

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

    function loadSoundPreference() {
        if (!alertFeatureAvailable) {
            return;
        }

        try {
            soundEnabled = window.localStorage.getItem(SOUND_PREFERENCE_KEY) === 'on';
        } catch {
            soundPreferenceAvailable = false;
        }
    }

    function saveSoundPreference() {
        try {
            window.localStorage.setItem(SOUND_PREFERENCE_KEY, soundEnabled ? 'on' : 'off');
            soundPreferenceAvailable = true;
        } catch {
            soundPreferenceAvailable = false;
        }
    }

    function updateSoundControls() {
        if (!alertFeatureAvailable) {
            return;
        }

        soundToggle.textContent = `Sound alerts: ${soundEnabled ? 'on' : 'off'}`;
        soundToggle.setAttribute('aria-pressed', String(soundEnabled));
        if (!soundPreferenceAvailable) {
            soundWarning.textContent = 'Sound preference could not be saved in this browser.';
            soundWarning.hidden = false;
        } else if (soundEnabled && !audioUnlocked) {
            soundWarning.textContent = 'Sound is locked after reload; click to unlock.';
            soundWarning.hidden = false;
        } else {
            soundWarning.textContent = '';
            soundWarning.hidden = true;
        }
    }

    function reportAlertFailure(error) {
        console.error('Kitchen new-order alert failed.', error);
        showMessage('New-order alert failed; the kitchen queue is still refreshing.', true);
    }

    function showNewOrderBanner(orders) {
        if (!alertFeatureAvailable) {
            return;
        }

        newOrderBanner.textContent = orders.length === 1
            ? `New order Q-${String(orders[0].queue_number).padStart(3, '0')}`
            : `${orders.length} new orders`;
        newOrderBanner.hidden = false;
        window.clearTimeout(bannerTimer);
        bannerTimer = window.setTimeout(() => {
            newOrderBanner.hidden = true;
        }, NEW_ORDER_BANNER_MS);
    }

    function playKitchenAlert() {
        if (
            !alertFeatureAvailable
            || !soundEnabled
            || !audioUnlocked
            || !audioContext
            || audioContext.state !== 'running'
            || alertPlaying
        ) {
            return;
        }

        alertPlaying = true;
        let masterGain;
        let compressor;
        const sources = [];
        const sourceNodes = [];
        const startedSources = new Set();
        let remainingSources = 0;

        const registerSource = (source, nodes) => {
            sources.push(source);
            sourceNodes.push(...nodes);
            remainingSources += 1;
            source.onended = () => {
                source.disconnect();
                nodes.forEach((node) => node.disconnect());
                remainingSources -= 1;
                if (remainingSources === 0) {
                    masterGain.disconnect();
                    compressor.disconnect();
                    alertPlaying = false;
                }
            };
        };

        try {
            const now = audioContext.currentTime;
            masterGain = audioContext.createGain();
            compressor = audioContext.createDynamicsCompressor();
            masterGain.gain.setValueAtTime(KITCHEN_ALERT_VOLUME, now);
            compressor.threshold.setValueAtTime(-12, now);
            compressor.knee.setValueAtTime(6, now);
            compressor.ratio.setValueAtTime(12, now);
            compressor.attack.setValueAtTime(.003, now);
            compressor.release.setValueAtTime(.25, now);
            masterGain.connect(compressor);
            compressor.connect(audioContext.destination);

            const clickLength = Math.ceil(audioContext.sampleRate * .035);
            const clickBuffer = audioContext.createBuffer(1, clickLength, audioContext.sampleRate);
            const clickSamples = clickBuffer.getChannelData(0);
            for (let index = 0; index < clickLength; index += 1) {
                const fade = 1 - index / clickLength;
                clickSamples[index] = (Math.random() * 2 - 1) * fade * fade;
            }

            const clickSource = audioContext.createBufferSource();
            const clickFilter = audioContext.createBiquadFilter();
            const clickGain = audioContext.createGain();
            clickSource.buffer = clickBuffer;
            clickFilter.type = 'bandpass';
            clickFilter.frequency.setValueAtTime(1900, now);
            clickFilter.Q.setValueAtTime(.7, now);
            clickGain.gain.setValueAtTime(.0001, now);
            clickGain.gain.exponentialRampToValueAtTime(.12, now + .003);
            clickGain.gain.exponentialRampToValueAtTime(.0001, now + .03);
            registerSource(clickSource, [clickFilter, clickGain]);
            clickSource.connect(clickFilter);
            clickFilter.connect(clickGain);
            clickGain.connect(masterGain);
            clickSource.start(now);
            startedSources.add(clickSource);
            clickSource.stop(now + .035);

            const notes = [
                { frequency: 988, offset: 0 },
                { frequency: 1318.5, offset: .18 },
            ];
            const partials = [
                { ratio: 1, volume: .7, decay: .9 },
                { ratio: 2.01, volume: .28, decay: .62 },
                { ratio: 2.76, volume: .14, decay: .42 },
                { ratio: 5.4, volume: .06, decay: .24 },
            ];
            for (const note of notes) {
                for (const partial of partials) {
                    const start = now + note.offset;
                    const oscillator = audioContext.createOscillator();
                    const partialGain = audioContext.createGain();
                    oscillator.type = 'sine';
                    oscillator.frequency.setValueAtTime(note.frequency * partial.ratio, start);
                    partialGain.gain.setValueAtTime(.0001, start);
                    partialGain.gain.linearRampToValueAtTime(partial.volume, start + .004);
                    partialGain.gain.exponentialRampToValueAtTime(.0001, start + partial.decay);
                    registerSource(oscillator, [partialGain]);
                    oscillator.connect(partialGain);
                    partialGain.connect(masterGain);
                    oscillator.start(start);
                    startedSources.add(oscillator);
                    oscillator.stop(start + partial.decay + .02);
                }
            }
        } catch (error) {
            sources.forEach((source) => {
                source.onended = null;
                if (startedSources.has(source)) {
                    try {
                        source.stop();
                    } catch (stopError) {
                        console.error('Could not stop a kitchen alert source.', stopError);
                    }
                }
                source.disconnect();
            });
            sourceNodes.forEach((node) => node.disconnect());
            masterGain?.disconnect();
            compressor?.disconnect();
            alertPlaying = false;
            throw error;
        }
    }

    async function toggleSound() {
        if (!alertFeatureAvailable) {
            return;
        }

        if (soundEnabled && audioUnlocked) {
            soundEnabled = false;
            saveSoundPreference();
            updateSoundControls();
            return;
        }

        if (typeof window.AudioContext !== 'function') {
            soundWarning.textContent = 'This browser does not support audio alerts.';
            soundWarning.hidden = false;
            return;
        }

        try {
            audioContext ??= new window.AudioContext();
            await audioContext.resume();
            audioUnlocked = audioContext.state === 'running';
            if (!audioUnlocked) {
                updateSoundControls();
                soundWarning.textContent = 'Audio is blocked. Click to try again.';
                soundWarning.hidden = false;
                return;
            }

            soundEnabled = true;
            saveSoundPreference();
            updateSoundControls();
            playKitchenAlert();
        } catch (error) {
            audioUnlocked = false;
            updateSoundControls();
            soundWarning.textContent = 'Could not enable audio. Click to try again.';
            soundWarning.hidden = false;
            console.error('Could not enable kitchen sound alerts.', error);
        }
    }

    function buildCard(order) {
        const status = String(order.status);
        const completed = status === 'completed';
        const minutes = completed ? order.duration_minutes : elapsedMinutes(order.created_at);
        const timerLabel = completed
            ? (minutes === 0 ? 'Took under 1 min' : `Took ${minutes} min`)
            : `${minutes}m`;
        const highlightClass = alertFeatureAvailable
            && highlightedOrderExpiry.get(String(order.id)) > Date.now()
            ? ' kitchen-order-card--new'
            : '';
        const queue = `Q-${String(order.queue_number).padStart(3, '0')}`;
        const orderType = {
            dine_in: 'Dine-In',
            takeout: 'Takeout',
            delivery: 'Delivery'
        }[order.order_type] || order.order_type;
        const location = order.location ? ` • ${escapeHtml(order.location)}` : '';
        const timerClass = completed
            ? 'bg-surface-container text-on-surface-variant'
            : (minutes >= 10 ? 'bg-error text-on-error' : 'bg-secondary-fixed text-on-secondary-fixed');
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

        return `<article class="flex flex-col rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-4 shadow-md${highlightClass}" data-order-card data-order-id="${escapeHtml(order.id)}" data-order-status="${escapeHtml(status)}">
            <div class="flex items-start justify-between gap-2 border-b border-surface-container-low pb-3">
                <div class="flex flex-col">
                    <span class="text-[40px] font-bold leading-none tracking-tighter text-primary">${escapeHtml(queue)}</span>
                    <span class="mt-1 font-label-md text-label-md font-bold text-on-surface-variant">#${escapeHtml(order.order_number)}</span>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="rounded-lg px-3 py-1.5 font-mono-metric text-[20px] font-bold ${timerClass}">${escapeHtml(timerLabel)}</span>
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
        const now = Date.now();
        for (const [orderId, expiresAt] of highlightedOrderExpiry) {
            if (expiresAt <= now) {
                highlightedOrderExpiry.delete(orderId);
            }
        }

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
        if (alertFeatureAvailable) {
            document.title = grouped.placed.length > 0
                ? `(${grouped.placed.length}) New orders | Kitchen`
                : normalDocumentTitle;
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

            const previousOrderIds = seenOrderIds;
            const currentOrderIds = new Set(result.orders.map((order) => String(order.id)));
            let newPlacedOrders = [];
            if (alertFeatureAvailable && previousOrderIds !== null) {
                try {
                    newPlacedOrders = result.orders.filter((order) => (
                        order.status === 'placed' && !previousOrderIds.has(String(order.id))
                    ));
                    const expiresAt = Date.now() + NEW_ORDER_HIGHLIGHT_MS;
                    newPlacedOrders.forEach((order) => {
                        highlightedOrderExpiry.set(String(order.id), expiresAt);
                    });
                } catch (error) {
                    reportAlertFailure(error);
                }
            }

            renderOrders(result.orders);
            seenOrderIds = currentOrderIds;
            if (alertFeatureAvailable && newPlacedOrders.length > 0) {
                try {
                    showNewOrderBanner(newPlacedOrders);
                    playKitchenAlert();
                } catch (error) {
                    reportAlertFailure(error);
                }
            }
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

    if (alertFeatureAvailable) {
        loadSoundPreference();
        updateSoundControls();
        soundToggle.addEventListener('click', toggleSound);
    }

    refreshOrders();
    refreshTimer = window.setInterval(refreshOrders, refreshMilliseconds);
    window.addEventListener('beforeunload', () => window.clearInterval(refreshTimer), { once: true });
})();
