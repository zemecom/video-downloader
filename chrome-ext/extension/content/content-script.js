(async function bootstrapYtdOverlay() {
  if (
    globalThis.__YTD_OVERLAY_BOOTSTRAPPED__ ||
    globalThis.__YTD_OVERLAY_BOOTSTRAPPING__
  ) {
    return;
  }
  globalThis.__YTD_OVERLAY_BOOTSTRAPPING__ = true;

  const STATUS_LABELS = {
    downloading: 'Идёт загрузка',
    completed: 'Готово',
    failed: 'Ошибка',
    cancelled: 'Отменено',
    skipped: 'Пропущено',
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
      this.openButton = shadowRoot.querySelector('.open-action');
      this.finderButton = shadowRoot.querySelector('.finder-action');
      this.closeButtons = shadowRoot.querySelectorAll('.close, .close-action');
      this.header = shadowRoot.querySelector('.header');

      this.autoHideTimer = null;
      this.jobId = null;
      this.isUserClosed = false;
      
      this.isDragging = false;
      this.dragOffset = { x: 0, y: 0 };

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

      this.openButton.addEventListener('click', async () => {
        const recentId = this.openButton.dataset.recentDownloadId;
        if (recentId) {
          await chrome.runtime.sendMessage({
            type: 'ytd:open-recent-download',
            entryId: recentId,
          });
        }
      });

      if (this.finderButton) {
        this.finderButton.addEventListener('click', async () => {
          const recentId = this.finderButton.dataset.recentDownloadId;
          if (recentId) {
            await chrome.runtime.sendMessage({
              type: 'ytd:reveal-recent-download',
              entryId: recentId,
            });
          }
        });
      }

      this.closeButtons.forEach((button) => {
        button.addEventListener('click', () => {
          this.stopAutoHide();
          this.isUserClosed = true;
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

      this.header.addEventListener('mousedown', this.onDragStart.bind(this));
      globalThis.addEventListener('mousemove', this.onDragMove.bind(this));
      globalThis.addEventListener('mouseup', this.onDragEnd.bind(this));
    }

    onDragStart(e) {
      if (e.target.closest('button')) return;
      this.isDragging = true;
      const rect = this.overlay.getBoundingClientRect();
      
      this.dragOffset.x = e.clientX - rect.left;
      this.dragOffset.y = e.clientY - rect.top;
      
      this.overlay.style.right = 'auto';
      this.overlay.style.bottom = 'auto';
      this.overlay.style.left = `${rect.left}px`;
      this.overlay.style.top = `${rect.top}px`;
      
      e.preventDefault();
    }

    onDragMove(e) {
      if (!this.isDragging) return;
      
      let left = e.clientX - this.dragOffset.x;
      let top = e.clientY - this.dragOffset.y;
      
      const rect = this.overlay.getBoundingClientRect();
      const maxX = globalThis.innerWidth - rect.width;
      const maxY = globalThis.innerHeight - rect.height;
      
      left = Math.max(0, Math.min(left, maxX));
      top = Math.max(0, Math.min(top, maxY));
      
      this.overlay.style.left = `${left}px`;
      this.overlay.style.top = `${top}px`;
    }

    onDragEnd() {
      this.isDragging = false;
    }

    update(payload) {
      if (payload.jobId && payload.jobId !== this.jobId) {
        this.jobId = payload.jobId;
        this.isUserClosed = false;
      }

      if (!this.isUserClosed) {
        this.overlay.hidden = false;
      }

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

      if (status === 'completed') {
        this.startAutoHide(8000);
      } else if (status === 'cancelled') {
        this.startAutoHide(1000);
      } else if (status === 'skipped') {
        this.startAutoHide(3000);
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

      if (status === 'completed' && recentDownloadId) {
        this.openButton.hidden = false;
        this.openButton.dataset.recentDownloadId = recentDownloadId || '';
        
        if (this.finderButton) {
          this.finderButton.hidden = false;
          this.finderButton.dataset.recentDownloadId = recentDownloadId || '';
        }
      } else {
        this.openButton.hidden = true;
        if (this.finderButton) {
          this.finderButton.hidden = true;
        }
      }

      if (this.isTerminalStatus(status)) {
        this.fillNode.dataset.indeterminate = 'false';
        this.fillNode.style.width = status === 'completed' ? '100%' : '0%';
      } else if (
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
        status === 'completed' ||
        status === 'failed' ||
        status === 'cancelled' ||
        status === 'skipped'
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

    async restoreActiveDownload() {
      const response = await this.sendRuntimeMessage({
        type: 'ytd:get-active-download',
      });

      if (response?.ok && response.payload) {
        this.update(response.payload);
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
    const { response, errorMessage } = await new Promise((resolve) => {
      chrome.runtime.sendMessage(
        { type: 'ytd:get-overlay-resources' },
        (nextResponse) => {
          resolve({
            response: nextResponse,
            errorMessage: chrome.runtime.lastError?.message || null,
          });
        }
      );
    });

    if (errorMessage || !response?.html) {
      console.error('YTD Overlay failed to load resources:', errorMessage);
      host.remove();
      delete globalThis.__YTD_OVERLAY_BOOTSTRAPPING__;
      return;
    }

    shadowRoot.innerHTML = `<style>\n${response.css}\n</style>\n${response.html}`;
  } catch (error) {
    console.error('YTD Overlay failed to load resources:', error);
    host.remove();
    delete globalThis.__YTD_OVERLAY_BOOTSTRAPPING__;
    return;
  }

  const overlay = new DownloadOverlay(shadowRoot);

  const port = chrome.runtime.connect({ name: 'ytd-overlay' });
  port.onMessage.addListener((message) => {
    if (message?.type === 'ytd-overlay-update') {
      overlay.update(message);
    }
  });
  globalThis.__YTD_OVERLAY_BOOTSTRAPPED__ = true;
  delete globalThis.__YTD_OVERLAY_BOOTSTRAPPING__;
  void overlay.restoreActiveDownload();

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
