(function bootstrapYtdOverlay() {
  if (globalThis.__YTD_OVERLAY_BOOTSTRAPPED__) {
    return;
  }

  globalThis.__YTD_OVERLAY_BOOTSTRAPPED__ = true;

  let pollTimer = null;
  let pollGeneration = 0;
  let autoHideTimer = null;
  let jobId = null;

  const host = document.createElement('div');
  host.id = '__ytd-download-overlay__';
  const shadowRoot = host.attachShadow({ mode: 'open' });
  document.documentElement.appendChild(host);

  shadowRoot.innerHTML = `
    <style>
      :host {
        all: initial;
      }

      .overlay {
        position: fixed;
        right: 20px;
        bottom: 20px;
        width: 360px;
        max-width: calc(100vw - 32px);
        border-radius: 18px;
        background:
          radial-gradient(circle at top left, rgba(79, 182, 255, 0.18), transparent 36%),
          radial-gradient(circle at bottom right, rgba(15, 92, 255, 0.16), transparent 34%),
          linear-gradient(180deg, rgba(15, 24, 42, 0.96), rgba(8, 12, 22, 0.98));
        color: #f7f4ee;
        box-shadow: 0 20px 60px rgba(2, 8, 20, 0.42);
        border: 1px solid rgba(125, 220, 255, 0.14);
        font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        z-index: 2147483647;
        padding: 16px;
        display: none;
      }

      .overlay[data-visible="true"] {
        display: block;
      }

      .header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
      }

      .title {
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.01em;
      }

      .close {
        border: 0;
        background: transparent;
        color: rgba(194, 234, 255, 0.78);
        font-size: 18px;
        cursor: pointer;
      }

      .status {
        margin-top: 10px;
        font-size: 13px;
        line-height: 1.45;
        color: rgba(255, 255, 255, 0.82);
        white-space: pre-wrap;
        word-break: break-word;
      }

      .progress-shell {
        margin-top: 14px;
        height: 10px;
        border-radius: 999px;
        background: rgba(125, 220, 255, 0.08);
        overflow: hidden;
      }

      .progress-fill {
        height: 100%;
        width: 0%;
        border-radius: inherit;
        background: linear-gradient(90deg, #7ddcff, #4fb6ff, #0f5cff);
        transition: width 180ms ease;
      }

      .progress-fill[data-indeterminate="true"] {
        width: 38%;
        background: linear-gradient(90deg, rgba(125, 220, 255, 0.08), #7ddcff, rgba(15, 92, 255, 0.18));
        animation: ytd-overlay-slide 1.25s ease-in-out infinite;
      }

      .meta {
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-size: 12px;
        color: rgba(194, 234, 255, 0.68);
      }

      .actions {
        margin-top: 14px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
      }

      .button {
        border: 0;
        border-radius: 999px;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
      }

      .button-secondary {
        background: rgba(125, 220, 255, 0.1);
        color: #f7f4ee;
      }

      .button-primary {
        background: linear-gradient(135deg, #22b8ff, #0f5cff);
        color: #eff8ff;
      }

      .button:disabled {
        opacity: 0.55;
        cursor: default;
      }

      @keyframes ytd-overlay-slide {
        0% { transform: translateX(-105%); }
        100% { transform: translateX(255%); }
      }
    </style>
    <section class="overlay" data-visible="false">
      <div class="header">
        <div class="title">YTD: загрузка</div>
        <button class="close" type="button" aria-label="Закрыть">×</button>
      </div>
      <div class="status">Подготавливаю загрузку...</div>
      <div class="progress-shell">
        <div class="progress-fill" data-indeterminate="true"></div>
      </div>
      <div class="meta">
        <span class="phase">Ожидание</span>
        <span class="percent">--</span>
      </div>
      <div class="actions">
        <button class="button button-secondary close-action" type="button">Скрыть</button>
        <button class="button button-primary cancel-action" type="button">Отменить</button>
      </div>
    </section>
  `;

  const overlay = shadowRoot.querySelector('.overlay');
  const statusNode = shadowRoot.querySelector('.status');
  const fillNode = shadowRoot.querySelector('.progress-fill');
  const phaseNode = shadowRoot.querySelector('.phase');
  const percentNode = shadowRoot.querySelector('.percent');
  const closeButtons = shadowRoot.querySelectorAll('.close, .close-action');
  const cancelButton = shadowRoot.querySelector('.cancel-action');

  closeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      stopAutoHide();
      overlay.dataset.visible = 'false';
    });
  });

  cancelButton.addEventListener('click', async () => {
    if (!jobId) {
      return;
    }

    cancelButton.disabled = true;
    renderState({
      status: 'cancelling',
      progressPercent: null,
      progressText: 'Останавливаю загрузку...',
      canCancel: false,
    });

    const response = await sendRuntimeMessage({
      type: 'ytd:cancel-download',
      jobId,
    });

    if (response?.payload) {
      renderState(response.payload);
    }
  });

  chrome.runtime.onMessage.addListener((message) => {
    if (message?.type === 'ytd-overlay-show') {
      overlay.dataset.visible = 'true';
      renderState(message);
      return;
    }

    if (message?.type === 'ytd-overlay-update') {
      overlay.dataset.visible = 'true';
      renderState(message);
      return;
    }

    if (message?.type === 'ytd-overlay-bind-job') {
      overlay.dataset.visible = 'true';
      jobId = typeof message.jobId === 'string' ? message.jobId : null;
      renderState(message);
      startPolling();
    }
  });

  function startPolling() {
    stopPolling();

    if (!jobId) {
      return;
    }

    const generation = ++pollGeneration;
    scheduleNextPoll(generation);
  }

  function scheduleNextPoll(generation, delayMs = 1000) {
    pollTimer = setTimeout(async () => {
      pollTimer = null;
      if (generation !== pollGeneration || !jobId) {
        return;
      }

      const response = await sendRuntimeMessage({
        type: 'ytd:get-job-status',
        jobId,
      });

      if (generation !== pollGeneration) {
        return;
      }

      if (!response) {
        renderState({
          status: 'failed',
          progressPercent: null,
          progressText: 'Не удалось получить статус загрузки.',
          canCancel: false,
        });
        stopPolling();
        return;
      }

      if (response.payload) {
        renderState(response.payload);
        if (isTerminalStatus(response.payload.status)) {
          stopPolling();
          return;
        }

        scheduleNextPoll(generation);
        return;
      }

      renderState({
        status: 'failed',
        progressPercent: null,
        progressText: response.errorMessage || 'Native host вернул ошибку статуса.',
        canCancel: false,
      });
      stopPolling();
    }, delayMs);
  }

  function stopPolling() {
    pollGeneration += 1;

    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
  }

  function startAutoHide(delayMs) {
    stopAutoHide();
    autoHideTimer = setTimeout(() => {
      overlay.dataset.visible = 'false';
      autoHideTimer = null;
    }, delayMs);
  }

  function stopAutoHide() {
    if (autoHideTimer !== null) {
      clearTimeout(autoHideTimer);
      autoHideTimer = null;
    }
  }

  function renderState(payload) {
    const status = typeof payload?.status === 'string' ? payload.status : 'starting';
    const progressText = typeof payload?.progressText === 'string' ? payload.progressText : 'Подготавливаю загрузку...';
    const progressPercent = typeof payload?.progressPercent === 'number' ? payload.progressPercent : null;
    const canCancel = Boolean(payload?.canCancel);

    if (status === 'completed') {
      startAutoHide(3000);
    } else if (status === 'cancelled') {
      startAutoHide(1000);
    } else {
      stopAutoHide();
    }

    statusNode.textContent = progressText;
    phaseNode.textContent = statusLabel(status);
    percentNode.textContent = progressPercent === null ? '--' : `${Math.round(progressPercent)}%`;
    cancelButton.disabled = !canCancel;
    cancelButton.textContent = isTerminalStatus(status) ? 'Готово' : 'Отменить';

    if (isTerminalStatus(status)) {
      cancelButton.disabled = true;
    }

    if (progressPercent === null || status === 'starting' || status === 'cancelling') {
      fillNode.dataset.indeterminate = 'true';
      fillNode.style.width = '38%';
    } else {
      fillNode.dataset.indeterminate = 'false';
      fillNode.style.width = `${Math.max(0, Math.min(100, progressPercent))}%`;
    }
  }

  function statusLabel(status) {
    switch (status) {
      case 'downloading':
        return 'Идёт загрузка';
      case 'completed':
        return 'Готово';
      case 'failed':
        return 'Ошибка';
      case 'cancelled':
        return 'Отменено';
      case 'cancelling':
        return 'Останавливаю';
      default:
        return 'Подготовка';
    }
  }

  function isTerminalStatus(status) {
    return status === 'completed' || status === 'failed' || status === 'cancelled';
  }

  function sendRuntimeMessage(message) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage(message, (response) => {
        const error = chrome.runtime.lastError;
        if (error) {
          resolve({
            payload: null,
            errorMessage: error.message,
          });
          return;
        }

        resolve(response);
      });
    });
  }
})();
