const recentDownloadsUi = globalThis.YtdRecentDownloadsUi || {};
const {
  buildRecentDownloadActions = () => [],
  createRecentDownloadsViewModel = (items) => ({
    totalCount: Array.isArray(items) ? items.length : 0,
    visibleItems: Array.isArray(items) ? items : [],
  }),
  getRecentDownloadModeLabel = (mode) => (mode === 'audio' ? 'Аудио' : 'Видео'),
  normalizeRecentDownloadsPayload = (payload) =>
    Array.isArray(payload?.items) ? payload.items : [],
} = recentDownloadsUi;

const statusNode = document.querySelector('.status');
const summaryNode = document.querySelector('.downloads-summary');
const listNode = document.querySelector('.downloads-list');
const emptyNode = document.querySelector('.downloads-empty');
const refreshButton = document.querySelector('.refresh-button');
const bulkActionsNode = document.querySelector('.bulk-actions');
const deleteAllButton = document.querySelector('#deleteAllButton');
const clearHistoryButton = document.querySelector('#clearHistoryButton');
const openDirButton = document.querySelector('#openDirButton');

void loadRecentDownloads();

refreshButton.addEventListener('click', () => {
  void loadRecentDownloads();
});

openDirButton.addEventListener('click', async () => {
  await sendMessage({ type: 'ytd:open-downloads-directory' });
});

deleteAllButton.addEventListener('click', async () => {
  const confirmed = globalThis.confirm('Удалить все скачанные файлы с диска и очистить историю?');
  if (confirmed) {
    await runBulkAction('ytd:delete-all-recent-downloads', 'Удаляю все файлы...');
  }
});

clearHistoryButton.addEventListener('click', async () => {
  const confirmed = globalThis.confirm('Очистить историю загрузок? Файлы останутся на диске.');
  if (confirmed) {
    await runBulkAction('ytd:clear-recent-downloads-history', 'Очищаю историю...');
  }
});

async function loadRecentDownloads() {
  renderRecentDownloads([]);
  renderSummary(0);
  setStatus('Обновляю список...');

  const response = await sendMessage({
    type: 'ytd:list-recent-downloads',
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось загрузить список файлов.');
    return;
  }

  const allItems = normalizeRecentDownloadsPayload(response?.payload);
  const viewModel = createRecentDownloadsViewModel(allItems);

  renderSummary(viewModel.totalCount);
  renderRecentDownloads(viewModel.visibleItems);
  setStatus('');
}

function renderSummary(totalCount) {
  summaryNode.textContent =
    totalCount > 0 ? `Всего записей: ${totalCount}` : 'Пока пусто';
  bulkActionsNode.hidden = totalCount === 0;
}

function renderRecentDownloads(items) {
  listNode.textContent = '';
  emptyNode.hidden = items.length > 0;

  items.forEach((item) => {
    const card = document.createElement('article');
    card.className = 'download-item';

    const leftCol = document.createElement('div');
    leftCol.className = 'download-col-left';

    const rightCol = document.createElement('div');
    rightCol.className = 'download-col-right';

    const createdAtText = formatCreatedAt(item?.createdAt);
    if (createdAtText !== '') {
      leftCol.appendChild(createMetaBadge(createdAtText));
    } else {
      leftCol.textContent = '-';
    }

    const title = document.createElement('div');
    title.className = 'download-name';
    title.textContent = typeof item?.name === 'string' ? item.name : 'Файл';
    rightCol.appendChild(title);

    const path = document.createElement('div');
    path.className = 'download-path';
    path.textContent = typeof item?.path === 'string' ? item.path : '';
    rightCol.appendChild(path);

    const meta = document.createElement('div');
    meta.className = 'download-meta';
    meta.appendChild(
      createMetaBadge(`Тип: ${getRecentDownloadModeLabel(item?.mode)}`)
    );
    rightCol.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'download-actions';
    appendRecentDownloadActions(actions, item, title.textContent, item?.path);
    rightCol.appendChild(actions);

    card.appendChild(leftCol);
    card.appendChild(rightCol);

    listNode.appendChild(card);
  });
}

function createMetaBadge(text) {
  const badge = document.createElement('span');
  badge.textContent = text;

  return badge;
}

function appendRecentDownloadActions(actionsNode, item, title, filePath) {
  buildRecentDownloadActions(item).forEach((action) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className =
      action.kind === 'delete'
        ? 'download-button download-button-danger'
        : 'download-button';
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

async function playRecentVideo(entryId, title, filePath) {
  if (typeof entryId !== 'string' || entryId === '') {
    setStatus('Файл из списка больше недоступен.');
    return;
  }

  setStatus(`Открываю ${title}...`);
  const response = await sendMessage({
    type: 'ytd:preview-recent-download',
    entryId,
    filePath,
  });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось открыть просмотр.');
    await loadRecentDownloads();
    return;
  }

  setStatus('');
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

async function runBulkAction(type, pendingMessage) {
  setStatus(pendingMessage);
  const response = await sendMessage({ type });

  if (!response?.ok) {
    setStatus(response?.errorMessage || 'Не удалось выполнить действие.');
  } else {
    setStatus('');
  }

  await loadRecentDownloads();
}

function formatCreatedAt(value) {
  if (typeof value !== 'string' || value === '') {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  /** @type {Intl.DateTimeFormatOptions} */
  const formatOptions = {
    dateStyle: 'medium',
    timeStyle: 'short',
  };

  return new Intl.DateTimeFormat('ru-RU', formatOptions).format(date);
}

function setStatus(message) {
  statusNode.textContent = message || '';
}

function sendMessage(message) {
  return chrome.runtime.sendMessage(message);
}