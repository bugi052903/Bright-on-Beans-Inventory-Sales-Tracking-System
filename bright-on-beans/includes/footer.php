        </div><!-- /.content -->
    </main>

    <!-- Logout Confirmation Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Confirm Logout</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to logout?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="logoutCancel">Cancel</button>
                <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-danger" id="logoutConfirm">Yes, Logout</a>
            </div>
        </div>
    </div>

    <!-- Generic Confirmation Modal (reusable) -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="confirmModalTitle">Confirm</h3>
            </div>
            <div class="modal-body">
                <p id="confirmModalMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="confirmCancel">Cancel</button>
                <button class="btn btn-danger" id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // Pass PHP constants to JavaScript
        window.APP = {
            baseUrl: '<?= BASE_URL ?>',
            csrfToken: '<?= generateCSRFToken() ?>',
            userRole: '<?= getCurrentUserRole() ?>'
        };
    </script>
    <script src="<?= BASE_URL ?>/assets/js/app.js"></script>
    <?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>
