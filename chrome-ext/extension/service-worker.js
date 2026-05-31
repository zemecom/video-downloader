const HOST_NAME = 'dev.zemecom.ytd_downloader';
// Use a data URL for notifications so the notifications backend does not need
// to fetch an extension resource before showing the popup.
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

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
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

  if (message?.type === 'ytd:preview-recent-download') {
    previewRecentDownload(message).then(sendResponse);

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

  if (message?.type === 'ytd:cancel-download') {
    callNativeHost({
      action: 'cancel_download',
      jobId: message.jobId,
    }).then(sendResponse);

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

  return {
    ok: true,
    payload: response.payload,
  };
}

async function previewRecentDownload(message) {
  const tabId = Number.isInteger(message?.tabId) ? message.tabId : null;
  const url = typeof message?.url === 'string' ? message.url : '';
  const entryId = typeof message?.entryId === 'string' ? message.entryId : '';

  if (!Number.isInteger(tabId) || !isSupportedTabUrl(url)) {
    return {
      ok: false,
      errorCode: 'unsupported_page',
      errorMessage: MESSAGES.unsupported_page,
    };
  }

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

  await ensureOverlay(tabId);
  await sendOverlayMessage(tabId, {
    type: 'ytd-overlay-open-preview',
    previewUrl: response.payload.previewUrl,
    recentDownloadId: response.payload?.recentDownloadId ?? entryId,
  });

  return {
    ok: true,
    payload: response.payload,
  };
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
    await chrome.tabs.sendMessage(tabId, payload);
  } catch (_error) {
    // Ignore message delivery failures for tabs that navigated away.
  }
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
