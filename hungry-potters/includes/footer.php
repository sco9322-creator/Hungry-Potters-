<?php
declare(strict_types=1);

$pageScript = $pageScript ?? null;
?>
    </main>
</div>
<script src="assets/js/app.js"></script>
<?php if (is_string($pageScript) && $pageScript !== ''): ?>
    <script src="<?= h($pageScript) ?>"></script>
<?php endif; ?>
</body>
</html>
