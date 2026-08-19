<?php
// includes/footer.php
// Footer layout and script attachments
?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา. สงวนลิขสิทธิ์.</p>
            <p style="margin-top: 8px; font-size: 0.75rem; color: var(--color-text-muted);">
                พัฒนาและปรับปรุงระบบด้วยเทคโนโลยี PHP & MySQL | รันบนระบบปฏิบัติการ IIS Server
            </p>
        </div>
    </footer>

    <!-- Main Interactivity Script -->
    <script src="<?php echo $base_url ?? './'; ?>assets/js/main.js"></script>
</body>
</html>
