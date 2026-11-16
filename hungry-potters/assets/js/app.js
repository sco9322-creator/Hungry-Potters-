'use strict';

function updateLiveClocks() {
    const now = new Date();
    const time = new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit'
    }).format(now);

    document.querySelectorAll('[data-live-clock]').forEach((clock) => {
        clock.textContent = time;
        clock.dateTime = now.toISOString();
    });
}

updateLiveClocks();
window.setInterval(updateLiveClocks, 30_000);
