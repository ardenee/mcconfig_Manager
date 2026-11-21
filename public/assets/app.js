document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form?.requestSubmit()));
  // Delegation also covers job controls replaced during live updates.
  document.addEventListener('submit', event => {
    const form = event.target.closest?.('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm || 'Are you sure?')) event.preventDefault();
  });
  document.querySelector('[data-toggle-nav]')?.addEventListener('click', () => document.body.classList.toggle('show-sidebar'));
  document.querySelectorAll('.sidebar nav a').forEach(el => el.addEventListener('click', () => document.body.classList.remove('show-sidebar')));
  document.getElementById('property-search')?.addEventListener('input', event => {
    const value = event.target.value.toLowerCase().trim();
    document.querySelectorAll('[data-property]').forEach(el => { el.hidden = !el.dataset.property.includes(value); });
  });
});


// Signed console snapshots are served only to authenticated mcmanager sessions.
// Never interpret Minecraft log text as HTML or JavaScript.
document.addEventListener('DOMContentLoaded', () => {
  const panel = document.querySelector('[data-console-server]');
  if (!panel) return;

  const serverId = panel.dataset.consoleServer;
  let jobId = panel.dataset.consoleJob || '';
  const output = document.getElementById('mc-console-output');
  const form = panel.querySelector('.console-form');
  const commandInput = form?.querySelector('input[name="command"]');
  const submitButton = form?.querySelector('button[type="submit"]');
  const commandHistory = [];
  let commandHistoryIndex = 0;
  const state = panel.querySelector('[data-console-state]');
  const source = panel.querySelector('[data-console-source]');
  const updated = panel.querySelector('[data-console-updated]');
  const jobResult = panel.querySelector('[data-console-job-result]');
  let busy = false;

  const refresh = async () => {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const url = new URL('index.php', window.location.href);
      url.searchParams.set('action', 'console_poll');
      url.searchParams.set('server_id', serverId);
      if (jobId) url.searchParams.set('job', jobId);

      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Console session expired or unavailable.');
      const data = await response.json();
      if (data.error) throw new Error(data.error);

      const snapshot = data.snapshot || {};
      const text = typeof snapshot.output === 'string' ? snapshot.output : '';
      const nearBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 90;
      if (output.textContent !== (text || 'Waiting for Minecraft server output…')) {
        output.textContent = text || 'Waiting for Minecraft server output…';
        if (nearBottom) output.scrollTop = output.scrollHeight;
      }
      state.textContent = 'Server: ' + (data.status || 'unknown') + (data.online ? '' : ' · worker offline');
      source.textContent = snapshot.source || 'No server output';
      updated.textContent = snapshot.updated
        ? 'Updated ' + new Date(snapshot.updated * 1000).toLocaleTimeString()
        : 'No output yet';

      if (jobId && data.job) {
        const status = data.job;
        const message = [status.state, status.message, status.result].filter(Boolean).join(' · ');
        jobResult.textContent = message || 'Command queued';
        jobResult.dataset.state = status.state || '';
      }
    } catch (error) {
      state.textContent = String(error.message || 'Console refresh failed');
    } finally {
      busy = false;
    }
  };
  form?.addEventListener('submit', async event => {
    event.preventDefault();
    const command = commandInput?.value || '';
    if (!command.trim() || !submitButton || submitButton.disabled) return;

    submitButton.disabled = true;
    jobResult.textContent = 'Sending Minecraft command…';
    jobResult.dataset.state = 'running';
    try {
      const endpoint = new URL('index.php', window.location.href);
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      });
      const data = await response.json();
      if (!response.ok || !data.job) throw new Error(data.error || 'Command was not accepted.');

      jobId = data.job;
      panel.dataset.consoleJob = jobId;
      commandHistory.push(command);
      if (commandHistory.length > 30) commandHistory.shift();
      commandHistoryIndex = commandHistory.length;
      commandInput.value = '';
      jobResult.textContent = 'Command queued for the selected Minecraft server.';
      jobResult.dataset.state = 'queued';
      refresh();
    } catch (error) {
      jobResult.textContent = String(error.message || 'Could not submit Minecraft command.');
      jobResult.dataset.state = 'failed';
    } finally {
      submitButton.disabled = false;
      commandInput?.focus();
    }
  });
  commandInput?.addEventListener('keydown', event => {
    if (!['ArrowUp', 'ArrowDown'].includes(event.key) || !commandHistory.length) return;
    event.preventDefault();
    commandHistoryIndex += event.key === 'ArrowUp' ? -1 : 1;
    commandHistoryIndex = Math.max(0, Math.min(commandHistory.length, commandHistoryIndex));
    commandInput.value = commandHistory[commandHistoryIndex] || '';
  });
  panel.querySelector('[data-console-refresh]')?.addEventListener('click', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  refresh();
  setInterval(refresh, 5000);
});


// Keep selected job results, the queue and job history current. Polling does
// not stop when a job succeeds: later operations may still complete on this page.
document.addEventListener('DOMContentLoaded', () => {
  if (!document.querySelector('[data-manager-recent]')) return;
  const url = new URL(window.location.href);
  url.searchParams.set('tab', 'manager');
  let busy = false;
  let failures = 0;
  const report = message => {
    document.querySelectorAll('[data-manager-sync]').forEach(node => {
      node.textContent = message;
    });
  };
  const refresh = async () => {
    if (document.hidden || busy) return;
    busy = true;
    try {
      const response = await fetch(url.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (!next.querySelector('[data-manager-recent]')) {
        throw new Error('Management session expired or page not available');
      }
      for (const selector of ['[data-manager-job]', '[data-manager-queued]', '[data-manager-recent]']) {
        const current = document.querySelector(selector);
        const fresh = next.querySelector(selector);
        if (!current || !fresh) continue;
        if (selector === '[data-manager-job]' &&
            current.dataset.managerJob !== fresh.dataset.managerJob) continue;
        current.replaceWith(document.importNode(fresh, true));
      }
      failures = 0;
      report('Updated ' + new Date().toLocaleTimeString() + ' (every 3 seconds)');
    } catch (error) {
      failures += 1;
      report('Auto-refresh unavailable (' + (error?.message || 'connection error') +
        '). Use Refresh, or check your login.');
    } finally {
      busy = false;
    }
  };
  window.setInterval(refresh, 3000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refresh();
  });
  window.addEventListener('focus', refresh);
  refresh();
});
