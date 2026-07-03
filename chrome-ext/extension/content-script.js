(async function bootstrapYtdOverlay() {
  if (globalThis.__YTD_OVERLAY_BOOTSTRAPPED__) {
    return;
  }

  globalThis.__YTD_OVERLAY_BOOTSTRAPPED__ = true;

  let pollTimer = null;
  let pollGeneration = 0;
  let autoHideTimer = null;
  let jobId = null;
  let isDomReady = false;
  let pendingState = null;

  let overlay, statusNode, fillNode, phaseNode, percentNode, cancelButton, playButton;

  const host = document.createElement('div');
  host.id = '__ytd-download-overlay__';
  const shadowRoot = host.attachShadow({ mode: 'closed' });
  document.documentElement.appendChild(host);

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (!isDomReady && (message?.type === 'ytd-overlay-show' || message?.type === 'ytd-overlay-update' || message?.type === 'ytd-overlay-bind-job')) {
      pendingState = message;
      if (message.type === 'ytd-overlay-bind-job') {
        jobId = typeof message.jobId === 'string' ? message.jobId : null;
        startPolling();
      }
      return;
    }

    if (message?.type === 'ytd-overlay-show' || message?.type === 'ytd-overlay-update') {
      if (overlay) overlay.dataset.visible = 'true';
      renderState(message);
      return;
    }

    if (message?.type === 'ytd-overlay-bind-job') {
      if (overlay) overlay.dataset.visible = 'true';
      jobId = typeof message.jobId === 'string' ? message.jobId : null;
      renderState(message);
      startPolling();
      return;
    }

    if (message?.type === 'ytd-overlay-open-preview') {
      const nextFilePath = typeof message?.filePath === 'string' && message.filePath !== ''
        ? message.filePath
        : (typeof message?.outputPath === 'string' ? message.outputPath : '');
      void requestPreviewPage(message.previewUrl, message.recentDownloadId, nextFilePath);
      return;
    }

    if (message?.type === 'ytd-pause-page-video') {
      sendResponse({
        paused: pausePageVideos(),
      });
    }
  });

  try {
    const htmlUrl = chrome.runtime.getURL('overlay.html');
    const cssUrl = chrome.runtime.getURL('overlay.css');
    const [htmlResponse, cssResponse] = await Promise.all([
      fetch(htmlUrl),
      fetch(cssUrl)
    ]);
    const html = await htmlResponse.text();
    const css = await cssResponse.text();
    shadowRoot.innerHTML = `<style>\n${css}\n</style>\n${html}`;
  } catch (error) {
    console.error('YTD Overlay failed to load resources:', error);
    return;
  }

  overlay = shadowRoot.querySelector('.overlay');
  statusNode = shadowRoot.querySelector('.status');
  fillNode = shadowRoot.querySelector('.progress-fill');
  phaseNode = shadowRoot.querySelector('.phase');
  percentNode = shadowRoot.querySelector('.percent');
  cancelButton = shadowRoot.querySelector('.cancel-action');
  playButton = shadowRoot.querySelector('.play-action');
  const closeButtons = shadowRoot.querySelectorAll('.close, .close-action');

  playButton.addEventListener('click', () => {
    const url = playButton.dataset.previewUrl;
    const recentId = playButton.dataset.recentDownloadId;
    const filePath = playButton.dataset.filePath;
    if (url) {
      void requestPreviewPage(url, recentId, filePath);
    }
  });

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

  isDomReady = true;
  if (pendingState) {
    overlay.dataset.visible = 'true';
    renderState(pendingState);
    pendingState = null;
  }

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
      if (overlay) {
        overlay.dataset.visible = 'false';
      }
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
    if (!isDomReady) return;

    const status = typeof payload?.status === 'string' ? payload.status : 'starting';
    const progressText = typeof payload?.progressText === 'string' ? payload.progressText : 'Подготавливаю загрузку...';
    const progressPercent = typeof payload?.progressPercent === 'number' ? payload.progressPercent : null;
    const canCancel = Boolean(payload?.canCancel);
    const nextPreviewUrl = typeof payload?.previewUrl === 'string' && payload.previewUrl !== '' ? payload.previewUrl : null;
    const nextFilePath = typeof payload?.outputPath === 'string' && payload.outputPath !== '' ? payload.outputPath : '';
    const previewReady = payload?.previewReady === true && nextPreviewUrl !== null;

    const recentDownloadId = typeof payload?.recentDownloadId === 'string' && payload.recentDownloadId !== ''
      ? payload.recentDownloadId
      : null;

    if (status === 'completed') {
      startAutoHide(8000);
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
      cancelButton.style.display = 'none';
    } else {
      cancelButton.style.display = '';
    }

    if (status === 'completed' && previewReady) {
      playButton.style.display = '';
      playButton.dataset.previewUrl = nextPreviewUrl || '';
      playButton.dataset.recentDownloadId = recentDownloadId || '';
      playButton.dataset.filePath = nextFilePath || '';
    } else {
      playButton.style.display = 'none';
    }

    if (progressPercent === null || status === 'starting' || status === 'cancelling') {
      fillNode.dataset.indeterminate = 'true';
      fillNode.style.width = '38%';
    } else {
      fillNode.dataset.indeterminate = 'false';
      fillNode.style.width = `${Math.max(0, Math.min(100, progressPercent))}%`;
    }
  }

  async function requestPreviewPage(nextPreviewUrl, nextRecentDownloadId, nextFilePath = '') {
    stopAutoHide();
    if (overlay) {
      overlay.dataset.visible = 'false';
    }
    await sendRuntimeMessage({
      type: 'ytd:open-preview-page',
      previewUrl: nextPreviewUrl,
      recentDownloadId: nextRecentDownloadId,
      filePath: nextFilePath,
      url: globalThis.location.href,
    });
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
            ok: false,
            payload: null,
            errorMessage: error.message,
          });
          return;
        }

        resolve(response);
      });
    });
  }

  function pausePageVideos() {
    let paused = false;

    document.querySelectorAll('video').forEach((video) => {
      if (video.paused) {
        return;
      }

      video.pause();
      paused = true;
    });

    return paused;
  }
})();
