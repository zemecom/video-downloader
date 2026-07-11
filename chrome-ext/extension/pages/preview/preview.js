const player = document.querySelector('.player');
const filePathNode = document.querySelector('.file-path');
const statusNode = document.querySelector('.status');
const playButton = document.querySelector('.play-action');
const openButton = document.querySelector('.open-action');
const revealButton = document.querySelector('.reveal-action');

let previewUrl = null;
let recentDownloadId = null;
let originTabId = null;

initPreview();

playButton.addEventListener('click', async () => {
  await attemptPlayback(true);
});

openButton.addEventListener('click', async () => {
  await runRecentAction(
    'ytd:open-recent-download',
    'Открываю файл...',
    'Не удалось открыть файл.'
  );
});

revealButton.addEventListener('click', async () => {
  await runRecentAction(
    'ytd:reveal-recent-download',
    'Показываю в Finder...',
    'Не удалось показать файл.'
  );
});

player.addEventListener('play', () => {
  void pauseOriginVideo();
  playButton.classList.add('button-hidden');
  statusNode.textContent = '';
});

player.addEventListener('error', () => {
  playButton.classList.add('button-hidden');
  statusNode.textContent =
    'Браузер не смог воспроизвести файл. Можно открыть его отдельным приложением.';
});

async function initPreview() {
  const previewId =
    new URL(globalThis.location.href).searchParams.get('id') || '';
  if (previewId === '') {
    renderFatalError('Не удалось найти данные для просмотра.');
    return;
  }

  const storageKey = `preview:${previewId}`;
  const result = await chrome.storage.session.get(storageKey);
  await chrome.storage.session.remove(storageKey);

  const payload = result?.[storageKey];
  if (
    !payload ||
    typeof payload.previewUrl !== 'string' ||
    payload.previewUrl === ''
  ) {
    renderFatalError('Ссылка для просмотра больше недоступна.');
    return;
  }

  previewUrl = payload.previewUrl;
  recentDownloadId =
    typeof payload.recentDownloadId === 'string'
      ? payload.recentDownloadId
      : '';
  originTabId = Number.isInteger(payload.originTabId)
    ? payload.originTabId
    : null;
  renderFilePath(resolveFilePath(payload));
  void hydrateFilePath();
  openButton.disabled = recentDownloadId === '';
  revealButton.disabled = recentDownloadId === '';

  player.src = previewUrl;
  player.load();
  await attemptPlayback(false);
}

async function attemptPlayback(fromUserGesture) {
  if (typeof previewUrl !== 'string' || previewUrl === '') {
    return;
  }

  playButton.classList.add('button-hidden');
  statusNode.textContent = fromUserGesture
    ? 'Запускаю видео...'
    : 'Открываю видео...';

  try {
    await player.play();
    if (player.muted) {
      statusNode.textContent =
        'Видео запущено без звука. Звук можно включить в плеере.';
    }
  } catch {
    if (!fromUserGesture) {
      try {
        player.muted = true;
        await player.play();
        statusNode.textContent =
          'Видео запущено без звука. Звук можно включить в плеере.';
        return;
      } catch {
        player.muted = false;
      }
    }

    playButton.classList.remove('button-hidden');
    player.muted = false;
    statusNode.textContent = fromUserGesture
      ? 'Браузер не смог начать воспроизведение.'
      : 'Нажми "Воспроизвести", если браузер заблокировал автозапуск.';
  }
}

async function runRecentAction(type, pendingMessage, fallbackErrorMessage) {
  if (typeof recentDownloadId !== 'string' || recentDownloadId === '') {
    statusNode.textContent =
      'Файл больше недоступен в списке recent downloads.';
    return;
  }

  statusNode.textContent = pendingMessage;
  const response = await sendRuntimeMessage({
    type,
    entryId: recentDownloadId,
  });

  statusNode.textContent = response?.ok
    ? ''
    : response?.errorMessage || fallbackErrorMessage;
}

async function pauseOriginVideo() {
  if (!Number.isInteger(originTabId)) {
    return;
  }

  await sendRuntimeMessage({
    type: 'ytd:pause-origin-video',
    originTabId,
  });
}

function renderFatalError(message) {
  renderFilePath('');
  statusNode.textContent = message;
  player.removeAttribute('src');
  player.load();
  playButton.classList.add('button-hidden');
  openButton.disabled = true;
  revealButton.disabled = true;
}

function renderFilePath(filePath) {
  const normalizedPath = typeof filePath === 'string' ? filePath : '';
  filePathNode.textContent = normalizedPath;
  filePathNode.title = normalizedPath;
  filePathNode.hidden = normalizedPath === '';
}

async function hydrateFilePath() {
  if (
    !filePathNode.hidden ||
    typeof recentDownloadId !== 'string' ||
    recentDownloadId === ''
  ) {
    return;
  }

  const response = await sendRuntimeMessage({
    type: 'ytd:get-recent-download-path',
    entryId: recentDownloadId,
  });

  renderFilePath(resolveFilePath(response?.payload));
}

function resolveFilePath(payload) {
  if (typeof payload?.filePath === 'string' && payload.filePath !== '') {
    return payload.filePath;
  }

  if (typeof payload?.outputPath === 'string' && payload.outputPath !== '') {
    return payload.outputPath;
  }

  if (typeof payload?.path === 'string' && payload.path !== '') {
    return payload.path;
  }

  return '';
}

function sendRuntimeMessage(message) {
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
