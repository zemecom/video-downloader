const buttons = Array.from(document.querySelectorAll('[data-mode]'));
const statusNode = document.querySelector('.status');
const recentListNode = document.querySelector('.recent-list');
const recentEmptyNode = document.querySelector('.recent-empty');
const activeDownloadNode = document.querySelector('.active-download');
const activeDownloadStatusNode = document.querySelector('.active-download-status');
const activeDownloadFillNode = document.querySelector('.active-download-fill');
const activeDownloadPhaseNode = document.querySelector('.active-download-phase');
const activeDownloadPercentNode = document.querySelector('.active-download-percent');
const activeDownloadCancelButton = document.querySelector('.active-download-cancel');

let activePollTimer = null;
let activePollGeneration = 0;
let activeJobId = null;

loadRecentDownloads();
startActiveDownloadPolling();

buttons.forEach((button) => {
  button.addEventListener('click', () => {
    startDownload(button.dataset.mode === 'audio' ? 'audio' : 'video');
  });
});

activeDownloadCancelButton.addEventListener('click', () => {
  cancelActiveDownload();
});

async function startDownload(mode) {
  setBusyState(true);
  setStatus(mode === 'audio' ? 'Запускаю загрузку аудио...' : 'Запускаю загрузку видео...');

  const [tab] = await chrome.tabs.query({
    active: true,
    currentWindow: true,
  });

  const response = await sendMessage({
    type: 'ytd:start-download',
    mode,
    tabId: tab?.id,
    url: tab?.url,
  });

  if (response?.ok) {
    if (response.payload) {
      renderActiveDownload(response.payload);
    }

    window.close();
    return;
  }

  setBusyState(false);
  setStatus(response?.errorMessage || 'Не удалось запустить загрузку.');
}

async function loadRecentDownloads() {
  renderRecentDownloads([]);

  const response = await sendMessage({
    type: 'ytd:list-recent-downloads',
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось загрузить список файлов.');
    return;
  }

  const items = Array.isArray(response?.payload?.items) ? response.payload.items.slice(0, 5) : [];
  renderRecentDownloads(items);
}

function renderRecentDownloads(items) {
  recentListNode.textContent = '';
  recentEmptyNode.hidden = items.length > 0;

  items.forEach((item) => {
    const row = document.createElement('article');
    row.className = 'recent-item';

    const title = document.createElement('div');
    title.className = 'recent-name';
    title.textContent = typeof item?.name === 'string' ? item.name : 'Файл';
    title.title = typeof item?.path === 'string' ? item.path : '';
    row.appendChild(title);

    const meta = document.createElement('div');
    meta.className = 'recent-meta';
    meta.textContent = item?.mode === 'audio' ? 'Аудио' : 'Видео';
    row.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'recent-actions';

    if (item?.mode === 'video') {
      const playButton = document.createElement('button');
      playButton.type = 'button';
      playButton.className = 'recent-button';
      playButton.textContent = 'Воспроизвести';
      playButton.addEventListener('click', () => {
        playRecentVideo(item?.id, title.textContent, item?.path);
      });
      actions.appendChild(playButton);
    }

    const openButton = document.createElement('button');
    openButton.type = 'button';
    openButton.className = 'recent-button';
    openButton.textContent = 'Открыть';
    openButton.addEventListener('click', () => {
      runRecentAction('ytd:open-recent-download', item?.id, `Открываю ${title.textContent}...`);
    });
    actions.appendChild(openButton);

    const revealButton = document.createElement('button');
    revealButton.type = 'button';
    revealButton.className = 'recent-button';
    revealButton.textContent = 'Finder';
    revealButton.addEventListener('click', () => {
      runRecentAction('ytd:reveal-recent-download', item?.id, `Показываю ${title.textContent} в Finder...`);
    });
    actions.appendChild(revealButton);

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'recent-button recent-button-danger';
    deleteButton.textContent = 'Удалить';
    deleteButton.addEventListener('click', async () => {
      const shouldDelete = globalThis.confirm(`Удалить ${title.textContent}?`);
      if (!shouldDelete) {
        return;
      }

      await runRecentAction('ytd:delete-recent-download', item?.id, `Удаляю ${title.textContent}...`, true);
    });
    actions.appendChild(deleteButton);

    row.appendChild(actions);
    recentListNode.appendChild(row);
  });
}

async function playRecentVideo(entryId, title, filePath) {
  if (typeof entryId !== 'string' || entryId === '') {
    setStatus('Файл из списка больше недоступен.');
    return;
  }

  const [tab] = await chrome.tabs.query({
    active: true,
    currentWindow: true,
  });

  setStatus(`Открываю ${title}...`);
  const response = await sendMessage({
    type: 'ytd:preview-recent-download',
    entryId,
    tabId: tab?.id,
    url: tab?.url,
    filePath,
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось открыть просмотр.');
    await loadRecentDownloads();
    return;
  }

  window.close();
}

async function runRecentAction(type, entryId, pendingMessage, reloadOnSuccess = false) {
  if (typeof entryId !== 'string' || entryId === '') {
    setStatus('Файл из списка больше недоступен.');
    return;
  }

  setStatus(pendingMessage);
  const response = await sendMessage({
    type,
    entryId,
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось выполнить действие.');
    await loadRecentDownloads();
    return;
  }

  setStatus('');
  if (reloadOnSuccess) {
    await loadRecentDownloads();
  }
}

function startActiveDownloadPolling() {
  if (activePollTimer !== null) {
    clearTimeout(activePollTimer);
    activePollTimer = null;
  }

  const generation = ++activePollGeneration;
  void pollActiveDownload(generation);
}

function scheduleActiveDownloadPoll(generation, delayMs = 1000) {
  activePollTimer = setTimeout(() => {
    activePollTimer = null;
    void pollActiveDownload(generation);
  }, delayMs);
}

function stopActiveDownloadPolling() {
  activePollGeneration += 1;

  if (activePollTimer !== null) {
    clearTimeout(activePollTimer);
    activePollTimer = null;
  }
}

async function pollActiveDownload(generation) {
  const response = await sendMessage({
    type: 'ytd:get-active-download',
  });

  if (generation !== activePollGeneration) {
    return;
  }

  if (!response?.ok) {
    renderActiveDownloadError(response?.errorMessage || 'Не удалось получить статус загрузки.');
    scheduleActiveDownloadPoll(generation, 2000);
    return;
  }

  if (!response.payload) {
    hideActiveDownload();
    return;
  }

  renderActiveDownload(response.payload);

  if (isTerminalStatus(response.payload.status)) {
    await loadRecentDownloads();
    return;
  }

  scheduleActiveDownloadPoll(generation);
}

async function cancelActiveDownload() {
  if (!activeJobId) {
    return;
  }

  activeDownloadCancelButton.disabled = true;
  renderActiveDownload({
    jobId: activeJobId,
    status: 'cancelling',
    progressPercent: null,
    progressText: 'Останавливаю загрузку...',
    canCancel: false,
  });

  const response = await sendMessage({
    type: 'ytd:cancel-download',
    jobId: activeJobId,
  });

  if (!response?.ok) {
    renderActiveDownloadError(response?.errorMessage || 'Не удалось отменить загрузку.');
    return;
  }

  if (response.payload) {
    renderActiveDownload(response.payload);
  }

  startActiveDownloadPolling();
}

function renderActiveDownload(payload) {
  const status = typeof payload?.status === 'string' ? payload.status : 'starting';
  const progressText = typeof payload?.progressText === 'string' ? payload.progressText : 'Подготавливаю загрузку...';
  const progressPercent = typeof payload?.progressPercent === 'number' ? payload.progressPercent : null;
  const canCancel = Boolean(payload?.canCancel);

  activeJobId = typeof payload?.jobId === 'string' && payload.jobId !== '' ? payload.jobId : activeJobId;
  activeDownloadNode.hidden = false;
  activeDownloadStatusNode.textContent = progressText;
  activeDownloadPhaseNode.textContent = statusLabel(status);
  activeDownloadPercentNode.textContent = progressPercent === null ? '--' : `${Math.round(progressPercent)}%`;
  activeDownloadCancelButton.textContent = isTerminalStatus(status) ? 'Готово' : 'Отменить';
  activeDownloadCancelButton.disabled = !canCancel || isTerminalStatus(status);

  if (progressPercent === null || status === 'starting' || status === 'cancelling') {
    activeDownloadFillNode.dataset.indeterminate = 'true';
    activeDownloadFillNode.style.width = '38%';
    return;
  }

  activeDownloadFillNode.dataset.indeterminate = 'false';
  activeDownloadFillNode.style.width = `${Math.max(0, Math.min(100, progressPercent))}%`;
}

function renderActiveDownloadError(message) {
  activeDownloadNode.hidden = false;
  activeDownloadStatusNode.textContent = message;
  activeDownloadPhaseNode.textContent = 'Ошибка';
  activeDownloadPercentNode.textContent = '--';
  activeDownloadCancelButton.disabled = true;
  activeDownloadFillNode.dataset.indeterminate = 'true';
  activeDownloadFillNode.style.width = '38%';
}

function hideActiveDownload() {
  activeJobId = null;
  activeDownloadNode.hidden = true;
  activeDownloadStatusNode.textContent = '';
  activeDownloadPhaseNode.textContent = 'Подготовка';
  activeDownloadPercentNode.textContent = '--';
  activeDownloadCancelButton.disabled = false;
  activeDownloadFillNode.dataset.indeterminate = 'true';
  activeDownloadFillNode.style.width = '38%';
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

function setBusyState(isBusy) {
  buttons.forEach((button) => {
    button.disabled = isBusy;
  });
}

function setStatus(message) {
  statusNode.textContent = typeof message === 'string' ? message : '';
}

function sendMessage(message) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(message, (response) => {
      const error = chrome.runtime.lastError;
      if (error) {
        resolve({
          ok: false,
          errorMessage: error.message,
        });
        return;
      }

      resolve(response);
    });
  });
}

globalThis.addEventListener('unload', () => {
  stopActiveDownloadPolling();
});
