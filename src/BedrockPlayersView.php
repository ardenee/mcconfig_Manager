<?php
/** Bedrock uses Xbox XUIDs and gamertags, NOT Java UUIDs or JSON operator lists. */
?>
<div class="alert warning">Bedrock access is managed through <code>allowlist.json</code> and <code>permissions.json</code>. Operator and visitor permissions use Xbox XUIDs, not Minecraft Java UUIDs. Changes may require a server restart or reload.</div>
<div class="two-column">
<?php foreach (['bedrock_allowlist'=>'Bedrock allowlist', 'bedrock_permissions'=>'Bedrock permissions'] as $type=>$label): ?>
  <section class="panel"><h2><?= h($label) ?></h2>
  <?php try { [$listFile, , $entries] = bedrock_source($server, $type); ?>
  <p class="muted"><?= h(basename($listFile)) ?> · <?= count($entries) ?> entries</p>
  <?php if (!$entries): ?><p class="empty">No entries.</p><?php endif; ?>
  <div class="players">
  <?php foreach ($entries as $i=>$entry): ?>
  <div class="player-row">
    <div><strong><?= h($entry['name'] ?? $entry['xuid'] ?? 'Unknown') ?></strong>
      <small><?= h(isset($entry['permission']) ? 'Role: '.$entry['permission'] : '') ?><?= isset($entry['xuid']) ? ' · XUID: '.h($entry['xuid']) : '' ?></small>
    </div>
    <form method="post" data-confirm="Remove this Bedrock entry?">
      <?php hidden_csrf(); hidden_server($selected); ?>
      <input type="hidden" name="action" value="player_remove">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <input type="hidden" name="row" value="<?= $i ?>">
      <input type="hidden" name="hash" value="<?= h(hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))) ?>">
      <button class="subtle danger-text">Remove</button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
  <form method="post" class="add-player">
    <?php hidden_csrf(); hidden_server($selected); ?>
    <input type="hidden" name="action" value="player_add">
    <input type="hidden" name="type" value="<?= h($type) ?>">
    <?php if ($type === 'bedrock_allowlist'): ?>
      <label>Xbox gamertag <input name="name" required placeholder="GamerTag"></label>
      <label>XUID (optional) <input name="xuid" inputmode="numeric" placeholder="Player Xbox user ID"></label>
    <?php else: ?>
      <label>Xbox XUID <input name="xuid" required inputmode="numeric" placeholder="15–20 digits"></label>
      <label>Permission <select name="permission"><option value="operator">Operator</option><option value="member">Member</option><option value="visitor">Visitor</option></select></label>
    <?php endif; ?>
    <button class="primary">Add entry</button>
  </form>
  <?php } catch (Throwable $e) { ?><div class="alert danger"><?= h($e->getMessage()) ?></div><?php } ?>
  </section>
<?php endforeach; ?>
</div>
