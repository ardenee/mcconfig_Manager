document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form?.requestSubmit()));
  document.querySelectorAll('form[data-confirm]').forEach(el => el.addEventListener('submit', event => {
    if (!window.confirm(el.dataset.confirm || 'Are you sure?')) event.preventDefault();
  }));
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


// Refresh the selected management job every three seconds until it finishes.
document.addEventListener('DOMContentLoaded', () => {
  const original = document.querySelector('[data-manager-job]');
  if (!original) return;
  let loading = false;
  const fetchJob = async () => {
    if (document.hidden || loading) return;
    const current = document.querySelector('[data-manager-job]');
    if (!current || !['queued', 'running'].includes(current.dataset.jobState)) return;
    loading = true;
    try {
      const url = new URL('index.php', window.location.href);
      url.searchParams.set('tab', 'manager');
      url.searchParams.set('job', original.dataset.managerJob);
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Job refresh failed');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const refreshed = parsed.querySelector('[data-manager-job]');
      if (refreshed && refreshed.dataset.managerJob === original.dataset.managerJob) {
        current.replaceWith(document.importNode(refreshed, true));
      }
    } catch (error) {
      // The existing result stays visible; manual refresh remains available.
    } finally { loading = false; }
  };
  const interval = setInterval(() => {
    const panel = document.querySelector('[data-manager-job]');
    if (!panel || !['queued', 'running'].includes(panel.dataset.jobState)) {
      clearInterval(interval); return;
    }
    fetchJob();
  }, 3000);
  fetchJob();
});
