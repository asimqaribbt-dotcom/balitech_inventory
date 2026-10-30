<?php
/**
 * Application footer: closes the layout markup and loads the scripts.
 */
$load_charts = $load_charts ?? false;
$extra_js    = $extra_js ?? [];
?>
        </main>

        <footer class="app-content py-3 text-center text-muted no-print" style="font-size:12.2px;border-top:1px solid var(--gray-200)">
            &copy; <?= date('Y') ?> <?= e(APP_COMPANY) ?> &middot; IT Inventory Management System v<?= e(APP_VERSION) ?>
            <span class="mx-2">|</span> Logged in as <?= e(current_user()['full_name'] ?? '') ?>
        </footer>
    </div>
</div>

<script>
    window.APP = {
        baseUrl: <?= json_encode(BASE_URL) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        currentPage: <?= json_encode($active_menu ?? '') ?>
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($load_charts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?php foreach ($extra_js as $js): ?>
<script src="<?= url('assets/js/' . $js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
