document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form?.requestSubmit()));
  // Delegation also covers job controls replaced during live updates.
  document.addEventListener('submit', event => {
    const form = event.target.closest?.('form');
    const confirmation = event.submitter?.dataset?.confirm || form?.dataset?.confirm;
    if (confirmation && !window.confirm(confirmation)) event.preventDefault();
  });
  document.querySelector('[data-toggle-nav]')?.addEventListener('click', () => document.body.classList.toggle('show-sidebar'));
  document.querySelectorAll('.sidebar nav a').forEach(el => el.addEventListener('click', () => document.body.classList.remove('show-sidebar')));
  document.getElementById('property-search')?.addEventListener('input', event => {
    const value = event.target.value.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('[data-property]').forEach(el => {
      el.hidden = !el.dataset.property.includes(value);
      if (!el.hidden) visible++;
    });
    const count = document.querySelector('[data-property-count]');
    if (count) count.textContent = String(visible);
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
      const workerStatus = document.querySelector('[data-worker-status]');
      const nextWorkerStatus = next.querySelector('[data-worker-status]');
      if (workerStatus && nextWorkerStatus && workerStatus.outerHTML !== nextWorkerStatus.outerHTML) {
        workerStatus.replaceWith(document.importNode(nextWorkerStatus, true));
      }
      // Refresh the colour-coded status and main controls without replacing
      // expanded settings forms or unsaved input in any server card.
      const liveCards = Array.from(next.querySelectorAll?.('[data-server-card]') || []);
      (document.querySelectorAll?.('[data-server-card]') || []).forEach(card => {
        const refreshed = liveCards.find(candidate =>
          candidate.dataset.serverCard === card.dataset.serverCard);
        if (!refreshed) return;
        for (const selector of ['[data-server-status]', '[data-server-actions]']) {
          const oldPart = card.querySelector(selector);
          const newPart = refreshed.querySelector(selector);
          if (oldPart && newPart && oldPart.outerHTML !== newPart.outerHTML) {
            oldPart.replaceWith(document.importNode(newPart, true));
          }
        }
      });
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


// Allow plugin JARs to be dropped onto their own upload target.
// Native file input remains the fallback; server-side validation is unchanged.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-plugin-dropzone]').forEach(zone => {
    const input = zone.querySelector('[data-plugin-file]');
    const label = zone.querySelector('[data-plugin-filename]');
    if (!input) return;
    input.addEventListener('change', () => {
      if (label) label.textContent = input.files?.[0]?.name || 'No file selected';
    });
    zone.addEventListener('dragenter', event => {
      event.preventDefault();
      zone.classList.add('is-dragover');
    });
    zone.addEventListener('dragover', event => {
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
      zone.classList.add('is-dragover');
    });
    zone.addEventListener('dragleave', event => {
      if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragover');
    });
    zone.addEventListener('drop', event => {
      event.preventDefault();
      zone.classList.remove('is-dragover');
      const file = event.dataTransfer?.files?.[0];
      if (!file || !/\.(?:jar|zip)$/i.test(file.name)) {
        if (label) label.textContent = 'Choose a Minecraft plugin .jar or .zip archive';
        return;
      }
      try {
        const picked = new DataTransfer();
        picked.items.add(file);
        input.files = picked.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (error) {
        if (label) label.textContent = 'Drag-and-drop is unsupported by this browser; use Choose file.';
      }
    });
    zone.addEventListener('keydown', event => {
      if (event.target === zone && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        input.click();
      }
    });
  });
});


// Keep edition, server implementation and deployment consistent in Create Server.
// A Bedrock selection always requires the Docker container deployment.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form.manager-install');
  if (!form) return;
  const edition = form.querySelector('select[name="edition"]');
  const software = form.querySelector('select[name="software"]');
  const deployment = form.querySelector('select[name="deployment"]');
  const port = form.querySelector('input[name="port"]');
  const native = form.querySelector('.manager-install-launch');
  if (!edition || !software || !deployment) return;
  let updating = false;
  const sync = changed => {
    if (updating) return;
    updating = true;
    try {
      if (changed === 'edition' && edition.value === 'bedrock') {
        if (software.querySelector('option[value="bedrock"]')) software.value = 'bedrock';
        if (deployment.querySelector('option[value="container"]')) deployment.value = 'container';
        if (port && port.value === '25565') port.value = '19132';
      } else if (changed === 'edition' && edition.value === 'java') {
        if (software.value === 'bedrock') software.value = 'paper';
        if (port && port.value === '19132') port.value = '25565';
      } else if (changed === 'software') {
        if (software.value === 'bedrock') {
          edition.value = 'bedrock';
          if (deployment.querySelector('option[value="container"]')) deployment.value = 'container';
          if (port && port.value === '25565') port.value = '19132';
        } else {
          edition.value = 'java';
          if (!['paper','vanilla'].includes(software.value)) {
            if (deployment.querySelector('option[value="container"]')) deployment.value = 'container';
          }
        }
      } else if (changed === 'deployment' && deployment.value === 'native') {
        edition.value = 'java';
        if (!['paper','vanilla'].includes(software.value)) software.value = 'paper';
        if (port && port.value === '19132') port.value = '25565';
      }
      if (native) native.hidden = deployment.value !== 'native' || edition.value !== 'java';
    } finally { updating = false; }
  };
  edition.addEventListener('change', () => sync('edition'));
  software.addEventListener('change', () => sync('software'));
  deployment.addEventListener('change', () => sync('deployment'));
  sync('init');
});


// DSM-controlled Minecraft containers need an explicit, visible action panel.
// A hash link alone does not expand a closed <details> element.
document.addEventListener('DOMContentLoaded', () => {
  const panel=document.getElementById('mc-dsm-on-demand');
  if (!panel) return;
  const form=panel.querySelector('[data-dsm-approval]');
  if (!form) return;
  const operation=form.querySelector('[name="dsm_operation"]');
  const target=form.querySelector('[name="server_id"]');
  const cleanup=form.querySelector('[data-dsm-cleanup]');
  const name=form.querySelector('[name="confirm_name"]');
  const erase=form.querySelector('[name="delete_world_files"]');
  const worldConfirm=form.querySelector('[name="confirm_world_delete"]');
  const worldBox=form.querySelector('[data-dsm-world-confirm]');
  const setOptions=()=>{
    const destructive=operation.value==='cleanup';
    cleanup.hidden=!destructive;
    name.disabled=!destructive;
    name.required=destructive;
    erase.disabled=!destructive;
    const worldDelete=destructive&&erase.checked;
    worldBox.hidden=!worldDelete;
    worldConfirm.disabled=!worldDelete;
    worldConfirm.required=worldDelete;
    if (!destructive){name.value='';erase.checked=false;worldConfirm.value='';}
    if (destructive) {
      const selected=target.selectedOptions[0];
      name.placeholder=selected?.dataset.serverName||'Exact registered server name';
    }
  };
  operation.addEventListener('change',setOptions);
  target.addEventListener('change',setOptions);
  erase.addEventListener('change',setOptions);
  setOptions();
  const openFor=(id,action)=>{
    panel.open=true;
    if (id && [...target.options].some(option=>option.value===id)) target.value=id;
    if (action && [...operation.options].some(option=>option.value===action))
      operation.value=action;
    setOptions();
    panel.scrollIntoView({behavior:'smooth',block:'start'});
    if (operation.value==='cleanup') name.focus({preventScroll:true});
    else form.querySelector('[name="dsm_account"]')?.focus({preventScroll:true});
  };
  document.addEventListener('click',event=>{
    const link=event.target.closest('[data-dsm-open]');
    if (!link) return;
    event.preventDefault();
    openFor(link.dataset.dsmOpen,link.dataset.dsmOperation);
  });
  document.addEventListener('click',event=>{
    const link=event.target.closest('[data-dsm-forget]');
    if (!link) return;
    const details=document.getElementById('mc-forget-'+link.dataset.dsmForget);
    if (!details) return;
    event.preventDefault();
    details.open=true;
    details.scrollIntoView({behavior:'smooth',block:'center'});
    details.querySelector('[name="confirm_name"]')?.focus({preventScroll:true});
  });
  const query=new URLSearchParams(window.location.search);
  if (query.has('dsm_server')) openFor(query.get('dsm_server'),'bedrock-verify');
});


// Inline one-time Synology container controls; forms are kept outside the
// auto-refreshed server action row, so pending credentials are never replaced.
document.addEventListener('DOMContentLoaded', () => {
  const labels = {start:'Start',stop:'Stop',status:'Check status'};
  const showFeedback = (card, message, error=false) => {
    const feedback = card.querySelector('[data-dsm-quick-feedback]');
    if (!feedback) return;
    feedback.hidden = false;
    feedback.dataset.result = error ? 'error' : 'success';
    feedback.textContent = message;
  };
  document.addEventListener('click', event => {
    const target = event.target.closest?.('[data-dsm-quick-action]');
    if (target) {
      event.preventDefault();
      const card = target.closest('[data-server-card]');
      const form = card?.querySelector('[data-dsm-quick-form]');
      const operation = target.dataset.dsmQuickAction;
      if (!form || !labels[operation] || form.dataset.busy === 'true') return;
      form.querySelector('[name="dsm_operation"]').value = operation;
      form.querySelector('[data-dsm-quick-title]').textContent =
        'Authorize ' + labels[operation] + ' for this Minecraft server';
      form.querySelector('[data-dsm-quick-submit]').textContent =
        operation === 'status' ? 'Check status in DSM' : 'Authorize ' + labels[operation];
      form.hidden = false;
      form.querySelector('[name="dsm_password"]').value = '';
      form.querySelector('[name="dsm_otp"]').value = '';
      card.querySelector('[data-dsm-quick-feedback]').hidden = true;
      form.querySelector('[name="dsm_account"]').focus();
      return;
    }
    const cancel = event.target.closest?.('[data-dsm-quick-cancel]');
    if (!cancel) return;
    const form = cancel.closest('[data-dsm-quick-form]');
    if (!form || form.dataset.busy === 'true') return;
    form.querySelector('[name="dsm_password"]').value = '';
    form.querySelector('[name="dsm_otp"]').value = '';
    form.hidden = true;
  });
  document.addEventListener('submit', async event => {
    const form = event.target.closest?.('[data-dsm-quick-form]');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.busy === 'true' || !form.reportValidity()) return;
    const card = form.closest('[data-server-card]');
    if (!card) return;
    const operation = form.querySelector('[name="dsm_operation"]').value;
    const submit = form.querySelector('[data-dsm-quick-submit]');
    const password = form.querySelector('[name="dsm_password"]');
    const otp = form.querySelector('[name="dsm_otp"]');
    form.dataset.busy = 'true';
    submit.disabled = true;
    submit.textContent = 'Contacting DSM...';
    showFeedback(card, labels[operation] + ' request in progress...');
    try {
      const endpoint = new URL('index.php', window.location.href);
      const response = await fetch(endpoint.href, {
        method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:new FormData(form)
      });
      if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('mcmanager did not return a status reply. Sign in again and retry.');
      }
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'DSM rejected the action.');
      const observed = payload.result || {};
      const health = payload.health || {};
      if (typeof health.label === 'string' && ['running','stopped','warning'].includes(health.tone)) {
        const badge = card.querySelector('[data-server-status]');
        if (badge) {
          badge.textContent = health.label;
          badge.className = 'mc-status mc-status--' + health.tone;
          badge.title = health.hint || '';
        }
        // Keep lifecycle controls consistent with the new observation
        // immediately; the 3-second page refresh uses the same PHP rules.
        const start = card.querySelector('[data-dsm-quick-action="start"]');
        const stop = card.querySelector('[data-dsm-quick-action="stop"]');
        if (start) start.hidden = health.stopped !== true;
        if (stop) stop.hidden = health.running !== true;
      }
      const current = observed.state === 'running' ? 'Running' :
        observed.state === 'stopped' ? 'Stopped' : 'not yet confirmed';
      const message = (operation === 'status' ? 'Status checked.' :
        labels[operation] + ' request accepted.') +
        ' DSM state: ' + current + '. ' +
        (observed.state === 'unknown' ? 'Use Check status again to verify.' : '');
      showFeedback(card, message);
      form.hidden = true;
    } catch (error) {
      showFeedback(card, error.message || 'Unable to contact DSM.', true);
      // Keep the form visible so the user can provide fresh credentials.
    } finally {
      password.value = '';
      otp.value = '';
      form.dataset.busy = 'false';
      submit.disabled = false;
      submit.textContent = operation === 'status' ? 'Check status in DSM' : 'Authorize ' + labels[operation];
    }
  });
});
