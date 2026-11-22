// One-form Bedrock install on Synology. Credentials exist only in this tab's
// form while the wizard is active; every operation logs in/out independently.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-bedrock-wizard]');
  if (!form) return;

  const $ = selector => form.querySelector(selector);
  const install = $('[data-wizard-install]');
  const stopButton = $('[data-wizard-stop]');
  const retry = $('[data-wizard-retry]');
  const recheck = $('[data-wizard-recheck]');
  const repair = $('[data-wizard-repair]');
  const progress = $('[data-wizard-progress]');
  const heading = $('[data-wizard-heading]');
  const fraction = $('[data-wizard-fraction]');
  const meter = $('[data-wizard-meter]');
  const status = $('[data-wizard-message]');
  const errorBox = $('[data-wizard-error]');
  const errorTitle = $('[data-wizard-error-title]');
  const errorDetail = $('[data-wizard-error-detail]');
  const errorHelp = $('[data-wizard-error-help]');
  const completed = $('[data-wizard-complete]');
  const completeText = $('[data-wizard-summary]');
  const openServer = $('[data-wizard-open]');
  const passwordInput = form.elements.namedItem('dsm_password');
  const otpInput = form.elements.namedItem('dsm_otp');
  const labels = [
    'Checking the Bedrock image',
    'Downloading the Bedrock image',
    'Creating your Minecraft server',
    'Verifying your Minecraft server',
    'Starting your Minecraft server'
  ];
  const rows = Array.from(form.querySelectorAll('[data-wizard-step]'));
  const progressValues = [5, 20, 66, 82, 95, 100];

  let phase = form.dataset.wizardResumeId ? 'verify' : 'check';
  let serverId = form.dataset.wizardResumeId || '';
  let pullTask = '';
  let imageCheckRetries = 0;
  let downloadChecks = 0;
  let busy = false;
  let halted = false;
  let activeRequest = null;

  function showStage(index, message = '') {
    const active = Math.max(0, Math.min(index, labels.length));
    progress.hidden = false;
    heading.textContent = active < labels.length ? labels[active] : 'Installation complete';
    fraction.textContent = Math.min(active + 1, 5) + ' of 5';
    meter.value = progressValues[active];
    rows.forEach((row, i) => {
      row.dataset.state = i < active ? 'done' : i === active ? 'active' : 'pending';
    });
    if (message) status.textContent = message;
  }
  function lock(locked) {
    busy = locked;
    install.disabled = locked;
    retry.disabled = locked;
    retry.textContent = locked ? 'Retrying…' : 'Retry this step';
    stopButton.hidden = !locked;
    form.classList.toggle('mc-wizard-busy', locked);
    // readOnly keeps fields in FormData for all successive authenticated calls.
    Array.from(form.querySelectorAll('input:not([type=hidden]):not([type=checkbox])'))
      .forEach(field => { field.readOnly = locked; });
  }
  function forgetCredentials() {
    if (passwordInput) passwordInput.value = '';
    if (otpInput) otpInput.value = '';
  }
  function wait(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
  }
  async function post(operation, extra = {}) {
    if (halted) throw new Error('Monitoring was stopped.');
    const body = new FormData(form);
    body.set('action', 'dsm_wizard');
    body.set('dsm_operation', operation);
    for (const [key, value] of Object.entries(extra)) body.set(key, String(value));
    const controller = new AbortController();
    activeRequest = controller;
    let response;
    try {
      const endpoint = new URL('index.php', window.location.href);
      endpoint.searchParams.set('tab', 'manager');
      response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body, signal: controller.signal
      });
      if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('mcmanager did not return an API reply. Your session may have expired. Log in again and retry this step.');
      }
      const reply = await response.json();
      if (!response.ok || !reply.ok) {
        const failure = new Error(reply.error || 'Container Manager rejected the requested action.');
        failure.title = reply.title;
        failure.help = reply.help;
        throw failure;
      }
      return reply.result || {};
    } catch (error) {
      if (controller.signal.aborted) throw new Error('Monitoring stopped. Container Manager may still be completing the current operation.');
      if (error instanceof TypeError) {
        throw new Error('The connection to mcmanager was interrupted. Retry this step; the installer will avoid creating a duplicate server.');
      }
      throw error;
    } finally {
      if (activeRequest === controller) activeRequest = null;
    }
  }

  async function run() {
    if (busy || form.dataset.wizardComplete === 'true') return;
    halted = false;
    errorBox.hidden = true;
    recheck.hidden = true;
    if (repair) repair.hidden = true;
    completed.hidden = true;
    lock(true);

    try {
      for (;;) {
        if (halted) return;
        if (phase === 'check') {
          showStage(0, 'Checking whether the Bedrock image is already installed…');
          const image = await post('bedrock-image');
          phase = image.image_ready ? 'create' : 'pull';
        } else if (phase === 'pull') {
          showStage(1, 'Starting the Bedrock image download in Container Manager…');
          const result = await post('bedrock-pull');
          if (result.image_ready) {
            phase = 'create';
          } else if (result.task_id) {
            pullTask = result.task_id;
            downloadChecks = 0;
            phase = 'poll';
          } else {
            throw new Error('Container Manager did not return a download task ID. Check the image in Container Manager, then Retry.');
          }
        } else if (phase === 'poll') {
          showStage(1, 'Downloading Minecraft Bedrock. This can take several minutes…');
          const result = await post('bedrock-poll', {pull_task: pullTask});
          if (result.finished) {
            phase = 'confirm-image';
            imageCheckRetries = 0;
          } else {
            downloadChecks++;
            if (typeof result.percent === 'number') {
              meter.value = Math.min(60, 15 + Math.max(0, result.percent) * 0.45);
              status.textContent = 'Downloading image: ' + result.percent + '%. Container Manager is handling the download.';
            }
            if (downloadChecks > 80) {
              throw new Error('The image download is taking longer than expected. Check Container Manager → Image and select Retry to keep checking without restarting the download.');
            }
            await wait(1500);
          }
        } else if (phase === 'confirm-image') {
          showStage(1, 'The download task finished. Checking that the image is available…');
          const image = await post('bedrock-image');
          if (image.image_ready) {
            phase = 'create';
          } else if (++imageCheckRetries < 8) {
            await wait(2500);
          } else {
            throw new Error('Container Manager reported a completed download, but the Bedrock image is not available. Check Container Manager → Image; Retry checks again without creating a container.');
          }
        } else if (phase === 'create') {
          showStage(2, 'Creating an isolated Bedrock server with persistent world storage…');
          const created = await post('bedrock-create');
          if (!created.server_id) throw new Error('Container creation returned no server registration ID. Inspect Container Manager before creating a second server.');
          serverId = created.server_id;
          phase = 'verify';
        } else if (phase === 'repair') {
          showStage(3, 'Repairing an incorrect Docker /data mount on a never-started container...');
          try {
            await post('bedrock-repair', {
              server_id: serverId,
              repair_confirm: 'REPLACE UNSTARTED CONTAINER'
            });
          } catch (error) {
            // A partial network failure may occur after DSM replaced the
            // container. Always inspect on retry; never replay deletion.
            phase = 'verify';
            throw error;
          }
          phase = 'verify';
        } else if (phase === 'verify') {
          showStage(3, 'Checking the container image and persistent /data folder…');
          await post('bedrock-verify', {server_id: serverId});
          phase = form.elements.namedItem('bedrock_autostart').checked ? 'start' : 'done';
        } else if (phase === 'start') {
          showStage(4, 'Starting the new Bedrock container…');
          await post('start', {server_id: serverId});
          phase = 'done';
        } else if (phase === 'done') {
          showStage(5, 'Installation finished. Your new Bedrock server is registered in mcmanager.');
          rows.forEach(row => { row.dataset.state = 'done'; });
          fraction.textContent = '5 of 5';
          meter.value = 100;
          const autoStarted = form.elements.namedItem('bedrock_autostart').checked;
          completeText.textContent = autoStarted
            ? 'Container start succeeded. Bedrock may need a moment to initialize before players can connect.'
            : 'The server was created and verified, but left stopped as requested.';
          if (serverId) {
            const target = new URL('index.php', window.location.href);
            target.searchParams.set('tab', 'overview');
            target.searchParams.set('server', serverId);
            openServer.href = target.toString();
          }
          completed.hidden = false;
          form.dataset.wizardComplete = 'true';
          forgetCredentials();
          install.textContent = 'Installed';
          return;
        } else {
          throw new Error('Installer phase was not recognised. Reload mcmanager and check the registered server.');
        }
      }
    } catch (error) {
      if (halted) return;
      errorTitle.textContent = error.title || 'Installation stopped at this step';
      errorDetail.textContent = error.message || 'An unexpected installation error occurred.';
      errorHelp.textContent = error.help ||
        'Correct the reported problem and select Retry. Completed steps will not be repeated, and no existing containers will be removed.';
      errorBox.hidden = false;
      recheck.hidden = !['pull','poll','confirm-image'].includes(phase);
      if (repair) {
        repair.hidden = !(serverId && phase === 'verify' &&
          /\/data bind mount mismatch/.test(error.message || ''));
      }
      status.textContent = 'Action required: ' + (error.message || 'See the error below.');
      if (phase === 'poll' || phase === 'confirm-image') {
        errorHelp.textContent += ' The image download may still be running in Container Manager.';
      }
    } finally {
      lock(false);
    }
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (busy || form.dataset.wizardComplete === 'true') return;
    if (!form.reportValidity()) return;
    if (phase !== 'check' && !form.elements.namedItem('bedrock_eula').checked) {
      form.elements.namedItem('bedrock_eula').reportValidity();
      return;
    }
    run();
  });
  function retryReady() {
    const username = form.elements.namedItem('dsm_account');
    if (!username?.value?.trim() || !passwordInput?.value) {
      errorBox.hidden = false;
      errorTitle.textContent = 'DSM credentials required to retry';
      errorDetail.textContent = 'Enter the DSM administrator username and password again above, then click Retry this step.';
      errorHelp.textContent = 'Credentials are kept only in this browser form during installation. Previously created Minecraft containers and files are unchanged.';
      status.textContent = 'Action required: DSM credentials must be entered to retry.';
      (!username?.value?.trim() ? username : passwordInput)?.focus();
      return false;
    }
    if (!form.reportValidity()) {
      errorBox.hidden = false;
      errorTitle.textContent = 'Complete the highlighted field';
      errorDetail.textContent = 'The installer is ready to retry, but a required form field is missing or invalid.';
      errorHelp.textContent = 'Correct the highlighted field above; the next Retry will only repeat the unfinished step.';
      return false;
    }
    return true;
  }
  retry.addEventListener('click', () => {
    if (busy || !retryReady()) return;
    status.textContent = 'Retry requested. Rechecking the unfinished operation…';
    run();
  });
  repair?.addEventListener('click', () => {
    if (busy || !retryReady()) return;
    // This is a destructive replacement of a *never-started* container
    // configuration only; the backend independently verifies all conditions.
    if (!window.confirm || !window.confirm(
      'Replace only this mcmanager Bedrock container if DSM proves it was never started? ' +
      'The old anonymous Docker volume may be orphaned or removed by DSM; ' +
      'any files it contains are NOT copied. Existing mcmanager world files are preserved. ' +
      'The new container must pass /data verification before it can start.'
    )) return;
    phase = 'repair';
    run();
  });
  recheck.addEventListener('click', () => {
    if (busy || !retryReady()) return;
    pullTask = '';
    downloadChecks = 0;
    imageCheckRetries = 0;
    phase = 'check';
    run();
  });
  stopButton.addEventListener('click', () => {
    if (!busy) return;
    halted = true;
    activeRequest?.abort();
    lock(false);
    forgetCredentials();
    errorBox.hidden = false;
    errorTitle.textContent = 'Monitoring stopped';
    errorDetail.textContent = 'The wizard stopped making requests. A download or container action already accepted by DSM may continue.';
    errorHelp.textContent = 'When ready, enter fresh DSM credentials and select Retry. The wizard will resume at the last step rather than starting again.';
    status.textContent = 'Paused. No administrator credentials are retained.';
  });
});
