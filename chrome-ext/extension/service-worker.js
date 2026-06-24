const HOST_NAME = 'dev.zemecom.ytd_downloader';
const ACTIVE_DOWNLOAD_KEY = 'activeDownload';
// Use a data URL for notifications so the notifications backend does not need
// to fetch an extension resource before showing the notification.
const NOTIFICATION_ICON =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAIAAAACACAYAAADDPmHLAAAEA0lEQVR42u2da1JTQRBGZwsuQAkkBBSkXJVrcBsqKD7w/QB8rsbtjJ1YCWLF5N47r56e81WdW/k10+nvUCnQqjinPA9HN33NOIIABAHI0DySJdYMDSIACRJgWxZZMTQYLMAtXzM0GJjHssSaoUEEINZLRg4KR4guOZY3CZuheLAhAiU2LMLxjgwP0aiq/BMZGOJD8aBXBIppWIKTnS0P+VFR/hMZBMqBAAhQd/n+1/2NULRCCVKXjgyKJXg63vJDCCn+X4bOYBnVAsQsHwkKCqCheCQoKIGm8pEgswCnckEfcgkwo+9sllEhQM7ykSCDAKfjke9KifKvJBiBUIUAD+7dmIMAFQjwTA7tQp+yFgLElqDrrJaJL8BEDu7AUAFiitB1VsuoL/9/AsQQAQEiS1BCACRAgCARljMFBgEkz+WwLqQSYIgIy5kC0/W9aySrAEN+SvsK0EcEBDAsQBcJEMCoAHwERBbgxe7IxyC1AH3PXs4VmFj70YoIsO1joem3gOVMwQJsm0a1ACF/B0CAigWI8edgBOgowEt5xCKGADHKvzZTYGLuRyMu9oEa/jUQASoSIMV/CkGAggIM+ShIVT4CdBDgTB6xKVX+ylkCk2I/mnBnU3mRgCICrJojVIBE+9GCS3l46fIRoLAAuSRYez8CrBfg1XTHpyZl+RvvDkyO/ZTE5bqoRPkIoEiAmCL0ug8BdAkQIsKgexBgvQCv5VGadaUHnx0YDftJibP+BhFgkwB78sIwwQIY3w8CtC7AG3lYJjTW9+NaKzR1EAABEKBVCar8CHi7N/Y1oi217rFaATRJUPMOEaB5AfblRcUUL7/y/VUvQEkJLOzOvZOHBXLHyt4QAAHGHgnaLN+cADkksLYv914e1kgVi7tCAAQYeyRos/w/AtyeeKtEK9/wjtwHeVgmNNb3Y16AEAla2A0CIMDEI0Gb5c8F+CiPVuialnbSlABdJGhtHwiAABOPBG2WPxfg052J18aqpL6jtvlj0awAf99T6/yRBNj12li9wHT31Dp/DDp9p4BVAazPH+0bQz7LYTlZldwzWJgfARAAARAAARAgNOdyWE4sJvcOz6MKcCAHZsSkAJl3OCPqN4gjQF0CuNhBgMYFuJBDc2ExOfd3gQAI4FLk4mDqc2BTgGk2XKogQOMC5JIAAZSWP8ulXJIai8mxt8scAswlOJTLEmJSgMQ7m+FyBQEaFyCHBKC4/EW+yMVQHlcyFNBw+QiAAEjQevmLfD3c85APpzEU03D510S4K4NCdFxNobCGy1/kmwwO4bjaQ4mNFo8IFI8IFL853+XNt4gj9uWgwVABjmSRFUODCEBC8uNo39cMDSIAQQCCAGRYfsoSa4YGEUB1fgNSXbeyRDB8+wAAAABJRU5ErkJggg==';

const TITLES = {
  accepted: 'YTD: скачивание запущено',
  invalid_url: 'YTD: некорректный URL',
  host_not_found: 'YTD: host не найден',
  spawn_failed: 'YTD: запуск не удался',
  unsupported_page: 'YTD: страница не поддерживается',
  unexpected_error: 'YTD: ошибка',
};

const MESSAGES = {
  accepted: 'Ссылка отправлена в локальный downloader.',
  invalid_url: 'Chrome передал некорректный URL вкладки.',
  host_not_found: 'Установи native host или переустанови его с правильным extension ID.',
  spawn_failed: 'Локальный downloader не удалось запустить.',
  unsupported_page: 'Работают только обычные http/https-вкладки.',
  unexpected_error: 'Native host вернул неожиданный ответ.',
};

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'ytd:start-download') {
    startDownload(message).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:list-recent-downloads') {
    callNativeHost({
      action: 'list_recent_downloads',
    }).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:open-recent-download') {
    callNativeHost({
      action: 'open_recent_download',
      entryId: message.entryId,
    }).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:get-recent-download-path') {
    getRecentDownloadPath(message).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:preview-recent-download') {
    previewRecentDownload(message).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:open-preview-page') {
    openPreviewPage(message, sender).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:pause-origin-video') {
    pauseOriginVideo(message).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:reveal-recent-download') {
    callNativeHost({
      action: 'reveal_recent_download',
      entryId: message.entryId,
    }).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:delete-recent-download') {
    callNativeHost({
      action: 'delete_recent_download',
      entryId: message.entryId,
    }).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:get-job-status') {
    callNativeHost({
      action: 'get_job_status',
      jobId: message.jobId,
    }).then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:get-active-download') {
    getActiveDownload().then(sendResponse);

    return true;
  }

  if (message?.type === 'ytd:cancel-download') {
    cancelDownload(message).then(sendResponse);

    return true;
  }

  return false;
});

async function startDownload(message) {
  const tabId = Number.isInteger(message?.tabId) ? message.tabId : null;
  const url = typeof message?.url === 'string' ? message.url : '';
  const mode = message?.mode === 'audio' ? 'audio' : 'video';

  if (!Number.isInteger(tabId) || !isSupportedTabUrl(url)) {
    notify('unsupported_page');

    return {
      ok: false,
      errorCode: 'unsupported_page',
      errorMessage: MESSAGES.unsupported_page,
    };
  }

  await ensureOverlay(tabId);
  await sendOverlayMessage(tabId, {
    type: 'ytd-overlay-show',
    status: 'starting',
    progressPercent: null,
    progressText: mode === 'audio' ? 'Подготавливаю загрузку аудио...' : 'Подготавливаю загрузку...',
    canCancel: false,
  });

  const response = await callNativeHost({
    action: 'start_download',
    url,
    mode,
  });

  if (!response.ok) {
    const code = response.errorCode || 'unexpected_error';
    const messageText = code === 'unexpected_error'
      ? (response.errorMessage || MESSAGES.unexpected_error)
      : (MESSAGES[code] || response.errorMessage || MESSAGES.unexpected_error);
    notify(code, messageText);
    await sendOverlayMessage(tabId, {
      type: 'ytd-overlay-update',
      status: 'failed',
      progressPercent: null,
      progressText: messageText,
      canCancel: false,
    });

    return {
      ok: false,
      errorCode: code,
      errorMessage: messageText,
    };
  }

  await sendOverlayMessage(tabId, {
    type: 'ytd-overlay-bind-job',
    ...response.payload,
  });
  await rememberActiveDownload({
    ...response.payload,
    mode,
    url,
  });

  return {
    ok: true,
    payload: response.payload,
  };
}

async function getActiveDownload() {
  const activeDownload = await readActiveDownload();
  const jobId = normalizeText(activeDownload?.jobId);

  if (jobId === '') {
    return {
      ok: true,
      payload: null,
    };
  }

  const response = await callNativeHost({
    action: 'get_job_status',
    jobId,
  });

  if (!response.ok) {
    if (response.errorCode === 'job_not_found') {
      await forgetActiveDownload(jobId);

      return {
        ok: true,
        payload: null,
      };
    }

    return response;
  }

  await rememberActiveDownload(response.payload);

  return response;
}

async function cancelDownload(message) {
  const jobId = normalizeText(message?.jobId);
  const response = await callNativeHost({
    action: 'cancel_download',
    jobId,
  });

  if (!response.ok) {
    if (response.errorCode === 'job_not_found') {
      await forgetActiveDownload(jobId);
    }

    return response;
  }

  await rememberActiveDownload(response.payload);

  return response;
}

async function previewRecentDownload(message) {
  const entryId = typeof message?.entryId === 'string' ? message.entryId : '';
  const originTabId = resolveYoutubeOriginTabId(message);

  if (entryId === '') {
    return {
      ok: false,
      errorCode: 'unexpected_error',
      errorMessage: 'Не удалось определить файл для воспроизведения.',
    };
  }

  const response = await callNativeHost({
    action: 'preview_recent_download',
    entryId,
  });

  if (!response.ok) {
    return response;
  }

  if (typeof response.payload?.previewUrl !== 'string' || response.payload.previewUrl === '') {
    return {
      ok: false,
      errorCode: 'unexpected_error',
      errorMessage: 'Native host не подготовил ссылку для воспроизведения.',
    };
  }

  return openPreviewPage({
    previewUrl: response.payload.previewUrl,
    recentDownloadId: response.payload?.recentDownloadId ?? entryId,
    filePath: normalizeText(response.payload?.filePath) || normalizeText(message?.filePath),
    originTabId,
  });
}

async function openPreviewPage(message, sender = null) {
  const previewUrl = typeof message?.previewUrl === 'string' ? message.previewUrl : '';
  const recentDownloadId = typeof message?.recentDownloadId === 'string' ? message.recentDownloadId : '';
  const filePath = normalizeText(message?.filePath) || await findRecentDownloadPath(recentDownloadId);
  const originTabId = resolveYoutubeOriginTabId(message, sender);
  if (previewUrl === '') {
    return {
      ok: false,
      errorCode: 'unexpected_error',
      errorMessage: 'Native host не подготовил ссылку для воспроизведения.',
    };
  }

  const previewId = createPreviewId();
  await chrome.storage.session.set({
    [`preview:${previewId}`]: {
      previewUrl,
      recentDownloadId,
      filePath,
      originTabId,
      createdAt: Date.now(),
    },
  });

  await chrome.tabs.create({
    url: chrome.runtime.getURL(`preview.html?id=${encodeURIComponent(previewId)}`),
    active: true,
  });

  return {
    ok: true,
    payload: {
      previewId,
    },
  };
}

async function findRecentDownloadPath(recentDownloadId) {
  if (typeof recentDownloadId !== 'string' || recentDownloadId === '') {
    return '';
  }

  const response = await callNativeHost({
    action: 'list_recent_downloads',
  });
  if (!response.ok) {
    return '';
  }

  const items = Array.isArray(response.payload?.items) ? response.payload.items : [];
  const entry = items.find((item) => item?.id === recentDownloadId);

  return normalizeText(entry?.path);
}

async function getRecentDownloadPath(message) {
  const recentDownloadId = typeof message?.entryId === 'string' ? message.entryId : '';
  const filePath = await findRecentDownloadPath(recentDownloadId);

  return {
    ok: filePath !== '',
    payload: {
      filePath,
    },
  };
}

async function pauseOriginVideo(message) {
  const originTabId = Number.isInteger(message?.originTabId) ? message.originTabId : null;
  if (!Number.isInteger(originTabId)) {
    return {
      ok: true,
      payload: {
        paused: false,
      },
    };
  }

  try {
    let response = null;
    try {
      response = await sendTabMessage(originTabId, {
        type: 'ytd-pause-page-video',
      });
    } catch (_error) {
      await ensureOverlay(originTabId);
      response = await sendTabMessage(originTabId, {
        type: 'ytd-pause-page-video',
      });
    }

    return {
      ok: true,
      payload: {
        paused: response?.paused === true,
      },
    };
  } catch (_error) {
    return {
      ok: true,
      payload: {
        paused: false,
      },
    };
  }
}

function resolveYoutubeOriginTabId(message, sender = null) {
  if (Number.isInteger(message?.originTabId)) {
    return message.originTabId;
  }

  const tabId = Number.isInteger(message?.tabId)
    ? message.tabId
    : (Number.isInteger(sender?.tab?.id) ? sender.tab.id : null);
  const url = typeof message?.url === 'string'
    ? message.url
    : (typeof sender?.tab?.url === 'string' ? sender.tab.url : '');

  return Number.isInteger(tabId) && isYoutubeUrl(url) ? tabId : null;
}

function normalizeText(value) {
  return typeof value === 'string' ? value : '';
}

async function readActiveDownload() {
  const items = await chrome.storage.session.get(ACTIVE_DOWNLOAD_KEY);
  const activeDownload = items[ACTIVE_DOWNLOAD_KEY];

  return activeDownload && typeof activeDownload === 'object' ? activeDownload : null;
}

async function rememberActiveDownload(payload) {
  const jobId = normalizeText(payload?.jobId);

  if (jobId === '') {
    return;
  }

  const existing = await readActiveDownload();
  const isExistingDownload = normalizeText(existing?.jobId) === jobId;
  await chrome.storage.session.set({
    [ACTIVE_DOWNLOAD_KEY]: {
      jobId,
      mode: payload?.mode === 'audio' ? 'audio' : 'video',
      status: normalizeText(payload?.status) || 'starting',
      url: normalizeText(payload?.url) || (isExistingDownload ? normalizeText(existing?.url) : ''),
      startedAt: isExistingDownload && Number.isFinite(existing?.startedAt) ? existing.startedAt : Date.now(),
      updatedAt: Date.now(),
    },
  });
}

async function forgetActiveDownload(jobId) {
  const activeDownload = await readActiveDownload();

  if (normalizeText(activeDownload?.jobId) !== jobId) {
    return;
  }

  await chrome.storage.session.remove(ACTIVE_DOWNLOAD_KEY);
}

function createPreviewId() {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }

  const bytes = new Uint8Array(16);
  globalThis.crypto.getRandomValues(bytes);

  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function isSupportedTabUrl(url) {
  if (!url) {
    return false;
  }

  try {
    const parsed = new URL(url);

    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

function isYoutubeUrl(url) {
  if (!url) {
    return false;
  }

  try {
    const hostname = new URL(url).hostname.toLowerCase();

    return hostname === 'youtube.com'
      || hostname === 'youtu.be'
      || hostname.endsWith('.youtube.com')
      || hostname.endsWith('.youtu.be');
  } catch {
    return false;
  }
}

function mapRuntimeError(message) {
  const normalized = String(message || '').toLowerCase();

  if (normalized.includes('specified native messaging host not found')) {
    return 'host_not_found';
  }

  if (normalized.includes('access to the specified native messaging host is forbidden')) {
    return 'host_not_found';
  }

  return 'unexpected_error';
}

function notify(code, messageOverride) {
  const title = TITLES[code] || TITLES.unexpected_error;
  const message = messageOverride || MESSAGES[code] || MESSAGES.unexpected_error;

  chrome.notifications.create({
    type: 'basic',
    iconUrl: NOTIFICATION_ICON,
    title,
    message,
  });
}

async function ensureOverlay(tabId) {
  await chrome.scripting.executeScript({
    target: { tabId },
    files: ['content-script.js'],
  });
}

async function sendOverlayMessage(tabId, payload) {
  try {
    await sendTabMessage(tabId, payload);
  } catch (_error) {
    // Ignore message delivery failures for tabs that navigated away.
  }
}

function sendTabMessage(tabId, payload) {
  return chrome.tabs.sendMessage(tabId, payload);
}

function callNativeHost(payload) {
  return new Promise((resolve) => {
    chrome.runtime.sendNativeMessage(HOST_NAME, payload, (response) => {
      const lastError = chrome.runtime.lastError;
      if (lastError) {
        resolve({
          ok: false,
          errorCode: mapRuntimeError(lastError.message),
          errorMessage: lastError.message,
        });

        return;
      }

      if (!response || typeof response !== 'object') {
        resolve({
          ok: false,
          errorCode: 'unexpected_error',
          errorMessage: MESSAGES.unexpected_error,
        });

        return;
      }

      if (response.ok === false) {
        resolve({
          ok: false,
          errorCode: typeof response.code === 'string' ? response.code : 'unexpected_error',
          errorMessage: typeof response.message === 'string' ? response.message : MESSAGES.unexpected_error,
          payload: response,
        });

        return;
      }

      resolve({
        ok: true,
        payload: response,
      });
    });
  });
}
