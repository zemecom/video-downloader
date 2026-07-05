const STATUS_LABELS = {
  downloading: 'Идёт загрузка',
  completed: 'Готово',
  failed: 'Ошибка',
  cancelled: 'Отменено',
  cancelling: 'Останавливаю',
  starting: 'Подготовка',
};

const recentDownloadsUi = globalThis.YtdRecentDownloadsUi || {};
const {
  RECENT_DOWNLOADS_PREVIEW_LIMIT = 5,
  buildRecentDownloadActions = () => [],
  createRecentDownloadsViewModel = (items) => ({
    totalCount: Array.isArray(items) ? items.length : 0,
    visibleItems: Array.isArray(items) ? items : [],
    hasHiddenItems: false,
  }),
  getRecentDownloadModeLabel = (mode) => (mode === 'audio' ? 'Аудио' : 'Видео'),
  normalizeRecentDownloadsPayload = (payload) =>
    Array.isArray(payload?.items) ? payload.items : [],
} = recentDownloadsUi;

const buttons = Array.from(document.querySelectorAll('[data-mode]'));
const statusNode = document.querySelector('.status');
const recentListNode = document.querySelector('.recent-list');
const recentEmptyNode = document.querySelector('.recent-empty');
const recentOpenAllButton = document.querySelector('.recent-open-all');
const recentClearAllButton = document.querySelector('.recent-clear-all');
const activeDownloadNode = document.querySelector('.active-download');
const activeDownloadStatusNode = document.querySelector(
  '.active-download-status'
);
const activeDownloadFillNode = document.querySelector('.active-download-fill');
const activeDownloadPhaseNode = document.querySelector(
  '.active-download-phase'
);
const activeDownloadPercentNode = document.querySelector(
  '.active-download-percent'
);
const activeDownloadCancelButton = document.querySelector(
  '.active-download-cancel'
);
const activeDownloadCloseButton = document.querySelector(
  '.active-download-close'
);

let activeJobId = null;
let lastKnownStatus = null;

void loadRecentDownloads();

const port = chrome.runtime.connect({ name: 'ytd-popup' });
port.onMessage.addListener(async (message) => {
  if (message?.type === 'ytd-overlay-update') {
    renderActiveDownload(message);
    const newStatus = message.status;
    if (isTerminalStatus(newStatus) && !isTerminalStatus(lastKnownStatus)) {
      await loadRecentDownloads();
    }
    lastKnownStatus = newStatus;
  }
});

buttons.forEach((button) => {
  button.addEventListener('click', () => {
    void startDownload(button.dataset.mode === 'audio' ? 'audio' : 'video');
  });
});

recentOpenAllButton?.addEventListener('click', () => {
  void openAllDownloadsPage();
});

recentClearAllButton?.addEventListener('click', async () => {
  const confirmed = confirm('Очистить всю историю загрузок?');
  if (!confirmed) return;
  
  await chrome.runtime.sendMessage({ type: 'ytd:clear-recent-downloads-history' });
  await loadRecentDownloads();
});

activeDownloadCancelButton.addEventListener('click', () => {
  if (activeDownloadCancelButton.disabled) return;

  if (activeDownloadCancelButton.textContent === 'Принудительно остановить') {
    void forceCancelActiveDownload();
  } else if (activeDownloadCancelButton.textContent === 'Отменить') {
    void cancelActiveDownload();
  } else {
    hideActiveDownload();
  }
});

activeDownloadCloseButton?.addEventListener('click', () => {
  hideActiveDownload();
});

async function startDownload(mode) {
  setBusyState(true);
  setStatus(
    mode === 'audio'
      ? 'Запускаю загрузку аудио...'
      : 'Запускаю загрузку видео...'
  );

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
  toggleRecentOpenAllButton(true);

  const response = await sendMessage({
    type: 'ytd:list-recent-downloads',
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось загрузить список файлов.');
    return;
  }

  const allItems = normalizeRecentDownloadsPayload(response?.payload);
  const viewModel = createRecentDownloadsViewModel(allItems, {
    limit: RECENT_DOWNLOADS_PREVIEW_LIMIT,
  });

  renderRecentDownloads(viewModel.visibleItems);
  toggleRecentOpenAllButton(true);
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
    meta.textContent = getRecentDownloadModeLabel(item?.mode);
    row.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'recent-actions';

    appendRecentDownloadActions(actions, item, title.textContent, item?.path);

    row.appendChild(actions);
    recentListNode.appendChild(row);
  });
}

function appendRecentDownloadActions(actionsNode, item, title, filePath) {
  buildRecentDownloadActions(item).forEach((action) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className =
      action.kind === 'delete'
        ? 'recent-button recent-button-danger'
        : 'recent-button';
    button.textContent = action.label;
    button.addEventListener('click', () => {
      void handleRecentDownloadAction(action.kind, item?.id, title, filePath);
    });
    actionsNode.appendChild(button);
  });
}

async function handleRecentDownloadAction(actionKind, entryId, title, filePath) {
  if (actionKind === 'play') {
    await playRecentVideo(entryId, title, filePath);
    return;
  }

  if (actionKind === 'delete') {
    const shouldDelete = globalThis.confirm(`Удалить ${title}?`);
    if (!shouldDelete) {
      return;
    }

    await runRecentAction(
      'ytd:delete-recent-download',
      entryId,
      `Удаляю ${title}...`,
      true
    );
    return;
  }

  if (actionKind === 'open') {
    await runRecentAction(
      'ytd:open-recent-download',
      entryId,
      `Открываю ${title}...`
    );
    return;
  }

  if (actionKind === 'reveal') {
    await runRecentAction(
      'ytd:reveal-recent-download',
      entryId,
      `Показываю ${title} в Finder...`
    );
  }
}

function toggleRecentOpenAllButton(visible) {
  if (recentOpenAllButton) {
    recentOpenAllButton.hidden = !visible;
  }
  if (recentClearAllButton) {
    recentClearAllButton.hidden = !visible;
  }
}

async function openAllDownloadsPage() {
  await chrome.tabs.create({
    url: chrome.runtime.getURL('pages/downloads/downloads.html'),
  });
  window.close();
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

async function runRecentAction(
  type,
  entryId,
  pendingMessage,
  reloadOnSuccess = false
) {
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
    renderActiveDownloadError(
      response?.errorMessage || 'Не удалось отменить загрузку.'
    );
    return;
  }

  if (response.payload) {
    renderActiveDownload(response.payload);
  }
}

async function forceCancelActiveDownload() {
  if (!activeJobId) {
    return;
  }

  activeDownloadCancelButton.disabled = true;
  renderActiveDownload({
    jobId: activeJobId,
    status: 'cancelling',
    progressPercent: null,
    progressText: 'Принудительно останавливаю...',
    canCancel: false,
  });

  const response = await sendMessage({
    type: 'ytd:force-cancel-download',
    jobId: activeJobId,
  });

  if (!response?.ok) {
    renderActiveDownloadError(
      response?.errorMessage || 'Не удалось остановить загрузку.'
    );
    return;
  }

  if (response.payload) {
    renderActiveDownload(response.payload);
  }
}

function renderActiveDownload(payload) {
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

  activeJobId =
    typeof payload?.jobId === 'string' && payload.jobId !== ''
      ? payload.jobId
      : activeJobId;
  activeDownloadNode.hidden = false;
  activeDownloadStatusNode.textContent = progressText;
  activeDownloadPhaseNode.textContent =
    STATUS_LABELS[status] || STATUS_LABELS.starting;
  activeDownloadPercentNode.textContent =
    progressPercent === null ? '--' : `${Math.round(progressPercent)}%`;
  activeDownloadCancelButton.textContent = isTerminalStatus(status)
    ? 'Готово'
    : status === 'cancelling'
      ? 'Принудительно остановить'
      : 'Отменить';
  activeDownloadCancelButton.disabled =
    isTerminalStatus(status) || (!canCancel && status !== 'cancelling');

  if (activeDownloadCloseButton) {
    activeDownloadCloseButton.hidden = !isTerminalStatus(status);
  }

  if (
    progressPercent === null ||
    status === 'starting' ||
    status === 'cancelling'
  ) {
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
  activeDownloadPhaseNode.textContent = STATUS_LABELS.failed;
  activeDownloadPercentNode.textContent = '--';
  activeDownloadCancelButton.disabled = true;
  if (activeDownloadCloseButton) {
    activeDownloadCloseButton.hidden = false;
  }
  activeDownloadFillNode.dataset.indeterminate = 'true';
  activeDownloadFillNode.style.width = '38%';
}

function hideActiveDownload() {
  activeJobId = null;
  activeDownloadNode.hidden = true;
  activeDownloadStatusNode.textContent = '';
  activeDownloadPhaseNode.textContent = STATUS_LABELS.starting;
  activeDownloadPercentNode.textContent = '--';
  activeDownloadCancelButton.disabled = false;
  activeDownloadFillNode.dataset.indeterminate = 'true';
  activeDownloadFillNode.style.width = '38%';
}

function isTerminalStatus(status) {
  return (
    status === 'completed' || status === 'failed' || status === 'cancelled'
  );
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
