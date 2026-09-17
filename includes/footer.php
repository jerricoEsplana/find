</main>

<?php if ($isStudentPortal ?? false): ?>

<footer class="student-footer">
    <div class="student-footer-inner">

        <div class="footer-brand">
            <span>M</span>
            <div>
                <strong>Find IT · Mapúa University</strong>
                <small>Student Lost &amp; Found Portal</small>
            </div>
        </div>

        <div class="footer-contact">
            <span>Campus Lost &amp; Found Services</span>
            <span>Student Portal</span>
            <span>Mapúa University</span>
        </div>

    </div>
</footer>

<?php else: ?>

<footer class="site-footer">
    <div class="container footer-grid">

        <div>
            <strong>Find IT</strong>
            <p>Find it. Report it. Return it.</p>
        </div>

        <div>
            <strong>Quick Links</strong>
            <a href="search.php">Search Items</a>
            <a href="report.php?type=lost">Report Lost</a>
            <a href="report.php?type=found">Report Found</a>
        </div>

        <div>
            <strong>Verification</strong>
            <p>Ownership claims are reviewed by authorized campus administrators before release.</p>
        </div>

    </div>

    <div class="container footer-bottom">
        © <?= date('Y') ?> Find IT · Mapúa University
    </div>
</footer>

<?php endif; ?>

<script>
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm)) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>
