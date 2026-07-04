(async function bootstrapYtdOverlay() {
  if (globalThis.__YTD_OVERLAY_BOOTSTRAPPED__) {
    return;
  }
  globalThis.__YTD_OVERLAY_BOOTSTRAPPED__ = true;

  const STATUS_LABELS = {
    downloading: 'Идёт загрузка',
    completed: 'Готово',
    failed: 'Ошибка',
    cancelled: 'Отменено',
    cancelling: 'Останавливаю',
    starting: 'Подготовка',
  };

  class DownloadOverlay {
    constructor(shadowRoot) {
      this.overlay = shadowRoot.querySelector('.overlay');
      this.statusNode = shadowRoot.querySelector('.status');
      this.fillNode = shadowRoot.querySelector('.progress-fill');
      this.phaseNode = shadowRoot.querySelector('.phase');
      this.percentNode = shadowRoot.querySelector('.percent');
      this.cancelButton = shadowRoot.querySelector('.cancel-action');
      this.playButton = shadowRoot.querySelector('.play-action');
      this.closeButtons = shadowRoot.querySelectorAll('.close, .close-action');

      this.autoHideTimer = null;
      this.jobId = null;

      this.bindEvents();
    }

    bindEvents() {
      this.playButton.addEventListener('click', () => {
        const url = this.playButton.dataset.previewUrl;
        const recentId = this.playButton.dataset.recentDownloadId;
        const filePath = this.playButton.dataset.filePath;
        if (url) {
          this.requestPreviewPage(url, recentId, filePath);
        }
      });

      this.closeButtons.forEach((button) => {
        button.addEventListener('click', () => {
          this.stopAutoHide();
          this.overlay.hidden = true;
        });
      });

      this.cancelButton.addEventListener('click', async () => {
        if (!this.jobId) return;

        this.cancelButton.disabled = true;

        if (this.cancelButton.textContent === 'Принудительно остановить') {
          await chrome.runtime.sendMessage({
            type: 'ytd:force-cancel-download',
            jobId: this.jobId,
          });
        } else {
          await chrome.runtime.sendMessage({
            type: 'ytd:cancel-download',
            jobId: this.jobId,
          });
        }
      });
    }

    update(payload) {
      this.overlay.hidden = false;

      const status =
        typeof payload?.status === 'string' ? payload.status : 'starting';
      const progressText =
        typeof payload?.progressText === 'string'
          ? payload.progressText
          : 'Подготавливаю загрузку...';
      const progressPercent =
        typeof payload?.progressPercent === 'number'
          ? payload.progressPercent
          : null;
      const canCancel = Boolean(payload?.canCancel);
      const nextPreviewUrl =
        typeof payload?.previewUrl === 'string' && payload.previewUrl !== ''
          ? payload.previewUrl
          : null;
      const nextFilePath =
        typeof payload?.outputPath === 'string' && payload.outputPath !== ''
          ? payload.outputPath
          : '';
      const previewReady =
        payload?.previewReady === true && nextPreviewUrl !== null;

      const recentDownloadId =
        typeof payload?.recentDownloadId === 'string' &&
        payload.recentDownloadId !== ''
          ? payload.recentDownloadId
          : null;

      if (payload.jobId) {
        this.jobId = payload.jobId;
      }

      if (status === 'completed') {
        this.startAutoHide(8000);
      } else if (status === 'cancelled') {
        this.startAutoHide(1000);
      } else {
        this.stopAutoHide();
      }

      this.statusNode.textContent = progressText;
      this.phaseNode.textContent =
        STATUS_LABELS[status] || STATUS_LABELS.starting;
      this.percentNode.textContent =
        progressPercent === null ? '--' : `${Math.round(progressPercent)}%`;

      this.cancelButton.disabled =
        this.isTerminalStatus(status) ||
        (!canCancel && status !== 'cancelling');
      this.cancelButton.textContent = this.isTerminalStatus(status)
        ? 'Готово'
        : status === 'cancelling'
          ? 'Принудительно остановить'
          : 'Отменить';

      if (this.isTerminalStatus(status)) {
        this.cancelButton.disabled = true;
        this.cancelButton.hidden = true;
      } else {
        this.cancelButton.hidden = false;
      }

      if (status === 'completed' && previewReady) {
        this.playButton.hidden = false;
        this.playButton.dataset.previewUrl = nextPreviewUrl || '';
        this.playButton.dataset.recentDownloadId = recentDownloadId || '';
        this.playButton.dataset.filePath = nextFilePath || '';
      } else {
        this.playButton.hidden = true;
      }

      if (
        progressPercent === null ||
        status === 'starting' ||
        status === 'cancelling'
      ) {
        this.fillNode.dataset.indeterminate = 'true';
        this.fillNode.style.width = '38%';
      } else {
        this.fillNode.dataset.indeterminate = 'false';
        this.fillNode.style.width = `${Math.max(0, Math.min(100, progressPercent))}%`;
      }
    }

    isTerminalStatus(status) {
      return (
        status === 'completed' || status === 'failed' || status === 'cancelled'
      );
    }

    startAutoHide(delayMs) {
      this.stopAutoHide();
      this.autoHideTimer = setTimeout(() => {
        this.overlay.hidden = true;
        this.autoHideTimer = null;
      }, delayMs);
    }

    stopAutoHide() {
      if (this.autoHideTimer !== null) {
        clearTimeout(this.autoHideTimer);
        this.autoHideTimer = null;
      }
    }

    async requestPreviewPage(
      nextPreviewUrl,
      nextRecentDownloadId,
      nextFilePath = ''
    ) {
      this.stopAutoHide();
      this.overlay.hidden = true;
      await this.sendRuntimeMessage({
        type: 'ytd:open-preview-page',
        previewUrl: nextPreviewUrl,
        recentDownloadId: nextRecentDownloadId,
        filePath: nextFilePath,
        url: globalThis.location.href,
      });
    }

    sendRuntimeMessage(message) {
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
  }

  const host = document.createElement('div');
  host.id = '__ytd-download-overlay__';
  const shadowRoot = host.attachShadow({ mode: 'closed' });
  document.documentElement.appendChild(host);

  try {
    const response = await new Promise((resolve) => {
      chrome.runtime.sendMessage(
        { type: 'ytd:get-overlay-resources' },
        resolve
      );
    });

    if (chrome.runtime.lastError || !response?.html) {
      console.error(
        'YTD Overlay failed to load resources:',
        chrome.runtime.lastError
      );
      return;
    }

    shadowRoot.innerHTML = `<style>\n${response.css}\n</style>\n${response.html}`;
  } catch (error) {
    console.error('YTD Overlay failed to load resources:', error);
    return;
  }

  const overlay = new DownloadOverlay(shadowRoot);

  const port = chrome.runtime.connect({ name: 'ytd-overlay' });
  port.onMessage.addListener((message) => {
    if (message?.type === 'ytd-overlay-update') {
      overlay.update(message);
    }
  });

  // Also support the previous direct message events if any
  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'ytd-pause-page-video') {
      let paused = false;
      document.querySelectorAll('video').forEach((video) => {
        if (!video.paused) {
          video.pause();
          paused = true;
        }
      });
      sendResponse({ paused });
    }
  });
})();
