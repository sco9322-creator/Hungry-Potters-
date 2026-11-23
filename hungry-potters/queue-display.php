<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pickup Queue | Hungry Potters</title>
    <style>
        :root {
            color-scheme: light;
            --primary: #06241f;
            --surface: #f3fbf6;
            --surface-low: #edf6f0;
            --surface-high: #e7f0eb;
            --on-surface: #151d1a;
            --amber: #904d00;
            --amber-card: #ffdcc3;
            --on-amber-card: #2f1500;
            --mint: #b1f0ce;
            --on-mint: #002114;
        }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { background: var(--surface); color: var(--on-surface); font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        [hidden] { display: none !important; }
        .queue-screen { --chip-w: clamp(84px, 5vw, 112px); --chip-h: clamp(44px, 2.8vw, 60px); --chip-font: clamp(18px, 1.25vw, 24px); --chip-radius: clamp(.65rem, .7vw, .9rem); --chip-gap: clamp(.35rem, .8vw, .75rem); display: flex; width: 100%; height: 100vh; height: 100dvh; flex-direction: column; gap: clamp(.65rem, 1.3vh, 1.1rem); overflow: hidden; padding: clamp(.65rem, 1.8vw, 1.75rem); }
        .queue-header { display: flex; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: 1.25rem; min-height: clamp(5.25rem, 11vh, 7.5rem); padding: clamp(.7rem, 1.4vw, 1.25rem) clamp(.85rem, 1.8vw, 1.6rem); border-radius: 1.2rem; background: var(--primary); color: #fff; }
        .brand { display: flex; min-width: 0; align-items: center; gap: clamp(.65rem, 1.2vw, 1.1rem); }
        .brand-logo { display: block; width: clamp(3.8rem, 7vw, 5.4rem); height: clamp(3.8rem, 7vw, 5.4rem); flex: 0 0 auto; border-radius: 1rem; background: var(--surface); object-fit: contain; }
        .brand-eyebrow { margin: 0 0 .2rem; color: var(--mint); font-size: clamp(.65rem, 1vw, .9rem); font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(1.8rem, 3.8vw, 3.5rem); font-weight: 800; letter-spacing: -.035em; line-height: 1; }
        .header-status { display: flex; flex: 0 1 auto; align-items: center; justify-content: flex-end; gap: clamp(.5rem, 1.1vw, 1rem); }
        .time-block { display: grid; gap: .25rem; text-align: right; white-space: nowrap; }
        .live-time { display: flex; align-items: center; justify-content: flex-end; gap: .45rem; color: #fff; font-size: clamp(.9rem, 1.35vw, 1.2rem); font-weight: 700; font-variant-numeric: tabular-nums; }
        .live-dot { width: .55rem; height: .55rem; border-radius: 50%; background: var(--mint); box-shadow: 0 0 0 .25rem rgb(177 240 206 / 16%); }
        .last-updated { margin: 0; color: #c1d9cf; font-size: clamp(.68rem, 1vw, .88rem); font-variant-numeric: tabular-nums; }
        .sound-control { display: grid; justify-items: end; gap: .25rem; }
        .sound-toggle { min-height: 2.5rem; padding: .45rem .8rem; border: 1px solid #86a49c; border-radius: .75rem; background: #1e3a34; color: #fff; font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; }
        .sound-toggle[aria-pressed="true"] { background: var(--mint); color: var(--primary); }
        .sound-help { max-width: 15rem; color: #c1d9cf; font-size: .65rem; line-height: 1.25; text-align: right; }
        .connection-message { flex: 0 0 auto; margin: 0; padding: .65rem 1rem; border: 1px solid #ba1a1a; border-radius: .75rem; background: #ffdad6; color: #93000a; font-weight: 700; }
        .queue-columns { display: grid; min-height: 0; flex: 1; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: clamp(.65rem, 1.4vw, 1.25rem); }
        .queue-lane { display: flex; min-width: 0; min-height: 0; flex-direction: column; gap: clamp(.45rem, .9vh, .8rem); overflow: hidden; padding: clamp(.65rem, 1.2vw, 1.1rem); border: 1px solid #dce5df; border-radius: 1.2rem; }
        .queue-lane--preparing { background: var(--surface-low); border-color: #fe932c; }
        .queue-lane--ready { background: var(--primary); border-color: #48645d; }
        .lane-heading { display: flex; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: .5rem; min-height: clamp(2.4rem, 5vh, 3.6rem); }
        .lane-heading h2 { margin: 0; font-size: clamp(1.25rem, 2.5vw, 2.3rem); font-weight: 800; letter-spacing: -.025em; line-height: 1; }
        .queue-lane--preparing h2 { color: var(--amber); }
        .queue-lane--ready h2 { color: var(--mint); }
        .lane-caption { color: #58635e; font-size: clamp(.65rem, .9vw, .85rem); font-weight: 700; text-align: right; }
        .queue-lane--ready .lane-caption { color: #c1d9cf; }
        .queue-list { display: grid; min-width: 0; min-height: 0; flex: 1; grid-template-columns: repeat(auto-fill, var(--chip-w)); grid-auto-rows: var(--chip-h); justify-content: start; align-content: start; gap: var(--chip-gap); overflow: hidden; }
        .queue-list[data-count="0"] { display: block; }
        .queue-card, .queue-overflow { display: flex; width: var(--chip-w); height: var(--chip-h); align-items: center; justify-content: center; overflow: hidden; padding: 0 .3rem; border: 1px solid rgb(6 36 31 / 14%); border-radius: var(--chip-radius); font-size: var(--chip-font); font-weight: 500; font-variant-numeric: tabular-nums; line-height: 1; white-space: nowrap; }
        .queue-lane--preparing .queue-card, .queue-lane--preparing .queue-overflow { background: #ffdcc3; color: #2f1500; }
        .queue-lane--ready .queue-card, .queue-lane--ready .queue-overflow { background: #b1f0ce; color: #002114; }
        .queue-overflow { font-size: calc(var(--chip-font) * .65); font-weight: 700; }
        .queue-card--new { animation: ready-arrival 3s ease-in-out 1; }
        .queue-empty { margin: .25rem 0 0; padding: .55rem .1rem; color: #58635e; font-size: clamp(1rem, 1.8vw, 1.5rem); font-weight: 600; }
        .queue-lane--ready .queue-empty { color: #dce5df; }
        @keyframes ready-arrival {
            0%, 100% { box-shadow: inset 0 0 0 0 rgb(6 36 31 / 0%); }
            18% { box-shadow: inset 0 0 0 3px rgb(6 36 31 / 55%); }
            60% { box-shadow: inset 0 0 0 2px rgb(6 36 31 / 30%); }
        }
        @media (max-width: 760px) {
            .queue-screen { padding: .65rem; }
            .queue-header { align-items: flex-start; gap: .65rem; padding: .65rem; }
            .brand { gap: .55rem; }
            .brand-logo { width: 3.25rem; height: 3.25rem; border-radius: .7rem; }
            .brand-eyebrow { font-size: .58rem; }
            h1 { font-size: clamp(1.45rem, 5vw, 2rem); }
            .header-status { flex-wrap: wrap; gap: .4rem .6rem; }
            .time-block { font-size: .75rem; }
            .sound-help { display: none; }
            .queue-columns { gap: .5rem; }
            .queue-lane { gap: .4rem; padding: .5rem; border-radius: .8rem; }
            .lane-heading { align-items: flex-start; flex-direction: column; gap: .2rem; }
            .lane-heading h2 { font-size: clamp(1rem, 3vw, 1.4rem); }
            .lane-caption { text-align: left; }
        }
        @media (prefers-reduced-motion: reduce) {
            .queue-card--new { animation: none; }
        }
    </style>
    <script src="assets/js/queue-display.js" defer></script>
</head>
<body>
    <main class="queue-screen">
        <header class="queue-header">
            <div class="brand">
                <img class="brand-logo" src="assets/images/logo.png" alt="Hungry Potters logo" width="768" height="768">
                <div>
                    <p class="brand-eyebrow">Hungry Potters</p>
                    <h1>Pickup Queue</h1>
                </div>
            </div>
            <div class="header-status">
                <div class="time-block">
                    <div class="live-time"><span class="live-dot" aria-hidden="true"></span><span>Live</span><time data-live-clock>--:--:--</time></div>
                    <p class="last-updated">Last updated <time data-last-updated>Waiting for update</time></p>
                </div>
                <div class="sound-control">
                    <button class="sound-toggle" type="button" data-sound-toggle aria-pressed="false">Chime: off</button>
                    <small class="sound-help" data-sound-help>Click to enable sound; browsers require a user gesture first.</small>
                </div>
            </div>
        </header>
        <p class="connection-message" data-connection-message role="status" aria-live="polite" hidden></p>
        <div class="queue-columns">
            <section class="queue-lane queue-lane--preparing" aria-labelledby="preparing-heading">
                <div class="lane-heading"><h2 id="preparing-heading">Preparing</h2><span class="lane-caption">Being prepared</span></div>
                <div class="queue-list" data-queue-list="preparing" data-count="0" aria-live="polite"></div>
            </section>
            <section class="queue-lane queue-lane--ready" aria-labelledby="ready-heading">
                <div class="lane-heading"><h2 id="ready-heading">Ready</h2><span class="lane-caption">Ready for pickup</span></div>
                <div class="queue-list" data-queue-list="ready" data-count="0" aria-live="polite"></div>
            </section>
        </div>
    </main>
</body>
</html>
