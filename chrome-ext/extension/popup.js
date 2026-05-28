const buttons = Array.from(document.querySelectorAll('[data-mode]'));
const statusNode = document.querySelector('.status');
const recentListNode = document.querySelector('.recent-list');
const recentEmptyNode = document.querySelector('.recent-empty');

loadRecentDownloads();

buttons.forEach((button) => {
  button.addEventListener('click', () => {
    startDownload(button.dataset.mode === 'audio' ? 'audio' : 'video');
  });
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

    row.appendChild(actions);
    recentListNode.appendChild(row);
  });
}

async function runRecentAction(type, entryId, pendingMessage) {
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
