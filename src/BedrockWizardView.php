<?php
declare(strict_types=1);
$wizardPlatformReady=PHP_OS_FAMILY==='Linux' && !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS']!=='off' && extension_loaded('curl')
    && in_array(strtolower(php_uname('m')),['x86_64','amd64'],true);
?>
<section class="panel mc-bedrock-wizard" id="mc-create-bedrock">
  <div class="panel-title">
    <div>
      <h2>Install Bedrock Edition</h2>
      <p class="muted">One setup form. Image download, installation, verification and startup happen automatically.</p>
    </div>
  </div>
  <?php if (!$wizardPlatformReady): ?>
    <div class="alert warning">
      <?php if (PHP_OS_FAMILY!=='Linux'): ?>
        The installer runs on your Synology NAS, not on the Windows development host.
      <?php elseif (empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'): ?>
        Open mcmanager via <strong>HTTPS</strong> to enter DSM administrator credentials and install Bedrock.
      <?php elseif (!extension_loaded('curl')): ?>
        Enable the <code>curl</code> extension in the mcconfig Web Station PHP profile, then reload this page.
      <?php else: ?>
        This Bedrock server image requires an x86-64 NAS. Detected architecture: <?= h(php_uname('m')) ?>.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" action="?tab=manager" data-bedrock-wizard autocomplete="off">
      <?php hidden_csrf(); ?>
      <input type="hidden" name="action" value="dsm_wizard">
      <input type="hidden" name="bedrock_id" value="<?= h(bin2hex(random_bytes(6))) ?>">
      <div class="mc-wizard-fields">
        <label>Server name
          <input name="bedrock_name" maxlength="64" required placeholder="My Bedrock Server">
        </label>
        <details class="mc-wizard-advanced">
          <summary>Port and memory <span class="muted">(defaults usually work)</span></summary>
          <div class="form-row">
            <label>Bedrock UDP port
              <input name="bedrock_port" type="number" min="1024" max="65535" value="19132" required>
            </label>
            <label>Maximum memory (MiB)
              <input name="bedrock_memory" type="number" min="512" max="32768" value="2048" required>
            </label>
          </div>
        </details>
      </div>
      <div class="mc-wizard-auth">
        <strong>Authorize installation with DSM</strong>
        <p class="hint">Enter the DSM administrator credentials once. They are used during this installation only and are not saved or added to mcmanager's background worker.</p>
        <div class="form-row">
          <label>DSM username
            <input name="dsm_account" autocomplete="off" maxlength="64" required>
          </label>
          <label>DSM password
            <input name="dsm_password" type="password" autocomplete="new-password" maxlength="1024" required>
          </label>
          <label>One-time code <small>(if applicable)</small>
            <input name="dsm_otp" inputmode="numeric" autocomplete="off" maxlength="8" placeholder="Optional">
          </label>
        </div>
      </div>
      <label class="check"><input type="checkbox" name="bedrock_eula" value="1" required>
        I accept the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">Minecraft EULA</a>
      </label>
      <label class="check"><input type="checkbox" name="bedrock_autostart" value="1" checked>
        Start the server automatically after installation
      </label>
      <div class="mc-wizard-buttons">
        <button type="submit" class="primary" data-wizard-install>Install Bedrock server</button>
        <button type="button" class="subtle" data-wizard-stop hidden>Stop monitoring</button>
      </div>
      <div class="mc-wizard-progress" data-wizard-progress hidden aria-live="polite">
        <div class="mc-wizard-progress-title">
          <strong data-wizard-heading>Preparing installation</strong>
          <span data-wizard-fraction>0 of 5</span>
        </div>
        <progress max="100" value="0" data-wizard-meter></progress>
        <ol class="mc-wizard-checklist">
          <li data-wizard-step="0">Checking Bedrock image</li>
          <li data-wizard-step="1">Downloading image if needed</li>
          <li data-wizard-step="2">Creating server and storage</li>
          <li data-wizard-step="3">Verifying container setup</li>
          <li data-wizard-step="4">Starting server</li>
        </ol>
        <p class="hint" data-wizard-message>Checking Container Manager…</p>
      </div>
      <div class="alert warning mc-wizard-error" data-wizard-error hidden role="alert">
        <strong data-wizard-error-title></strong>
        <p data-wizard-error-detail></p>
        <p data-wizard-error-help></p>
        <div class="mc-wizard-recovery-actions">
          <button type="button" class="subtle" data-wizard-retry>Retry this step</button>
          <button type="button" class="subtle" data-wizard-recheck hidden>Check image again</button>
        </div>
      </div>
      <div class="mc-wizard-complete" data-wizard-complete hidden role="status">
        <strong>Bedrock server installed</strong>
        <p data-wizard-summary></p>
        <a class="subtle" data-wizard-open href="?tab=overview">Open the new server</a>
      </div>
      <noscript><p class="alert warning">Enable JavaScript to complete the automatic Bedrock installation.</p></noscript>
    </form>
  <?php endif; ?>
</section>
