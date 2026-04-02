</main>
  </div><!-- /.main -->
</div><!-- /.wrapper -->

<script src="/assets/js/main.js"></script>
<?php if (!empty($extraScripts)): ?>
  <?php foreach ($extraScripts as $script): ?>
    <script src="<?= htmlspecialchars($script, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($inlineScript)): ?>
  <script><?= $inlineScript ?></script>
<?php endif; ?>
</body>
</html>