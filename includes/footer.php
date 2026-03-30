        </main>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    
    <script src="<?= $basePath ?>/assets/js/app.js"></script>
    <?php if (isset($loadTinyMCE) && $loadTinyMCE): ?>
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            initTinyMCE('#emailBody', '<?= $basePath ?>/api/upload-image.php');
        });
    </script>
    <?php endif; ?>
    <?php if (isset($pageScript)): ?>
    <script><?= $pageScript ?></script>
    <?php endif; ?>
</body>
</html>
