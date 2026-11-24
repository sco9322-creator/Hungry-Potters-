<?php
declare(strict_types=1);

$pageScript = $pageScript ?? null;
?>
    </main>
</div>
<dialog id="app-confirm-dialog" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message"
        class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl bg-surface-container-lowest p-5 text-on-surface shadow-2xl backdrop:bg-primary/40">
    <h2 id="app-confirm-title" class="font-headline-sm text-xl font-bold text-primary"></h2>
    <p id="app-confirm-message" class="mt-2 text-on-surface-variant"></p>
    <dl data-confirm-details hidden class="mt-4 rounded-xl bg-surface-container-low p-3"></dl>
    <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" data-confirm-cancel class="min-h-12 rounded-lg bg-surface-container px-5 text-primary">Cancel</button>
        <button type="button" data-confirm-accept class="min-h-12 rounded-lg px-5 font-bold"></button>
    </div>
</dialog>
<script src="assets/js/app.js"></script>
<script src="assets/js/confirm.js"></script>
<?php if (is_string($pageScript) && $pageScript !== ''): ?>
    <script src="<?= h($pageScript) ?>"></script>
<?php endif; ?>
</body>
</html>
