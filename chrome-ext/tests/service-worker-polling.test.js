const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const serviceWorkerSource = fs.readFileSync(
  path.join(__dirname, '..', 'extension', 'background', 'service-worker.js'),
  'utf8'
);

function createServiceWorkerSandbox(statusPayload) {
  const storage = {};
  const scheduledTimers = [];
  const nativeMessages = [];
  const createdTabs = [];
  const sandbox = {
    Promise,
    Uint8Array,
    crypto: {
      getRandomValues: (bytes) => bytes,
      randomUUID: () => 'test-preview-id',
    },
    console: {
      log: () => {},
      warn: () => {},
    },
    self: {
      addEventListener: () => {},
    },
    setTimeout: (callback, delay) => {
      scheduledTimers.push({ callback, delay });

      return scheduledTimers.length;
    },
    clearTimeout: () => {},
    chrome: {
      runtime: {
        lastError: null,
        getURL: (path) => `chrome-extension://test/${path}`,
        onConnect: {
          addListener: () => {},
        },
        onMessage: {
          addListener: () => {},
        },
        sendNativeMessage: (_hostName, payload, callback) => {
          nativeMessages.push(payload);
          callback(statusPayload);
        },
      },
      tabs: {
        create: async (params) => {
          createdTabs.push(params);
        },
        sendMessage: async (_tabId, message, callback) => {
          const res =
            message?.type === 'ytd-get-page-video-time'
              ? { currentTime: 75.5 }
              : null;
          if (typeof callback === 'function') {
            callback(res);
          }

          return res;
        },
      },
      storage: {
        session: {
          get: async (key) => ({ [key]: storage[key] }),
          set: async (items) => Object.assign(storage, items),
          remove: async (key) => {
            delete storage[key];
          },
        },
      },
    },
  };
  const context = vm.createContext(sandbox);

  vm.runInContext(serviceWorkerSource, context, {
    filename: 'service-worker.js',
  });

  return { context, nativeMessages, scheduledTimers, storage, createdTabs };
}

test('polling stops after a skipped task status', async () => {
  const worker = createServiceWorkerSandbox({
    ok: true,
    code: 'job_status',
    jobId: 'job-skipped',
    status: 'skipped',
    progressPercent: null,
    progressText: 'Загрузка пропущена: файл уже существует.',
    canCancel: false,
    recentDownloadId: 'download-existing',
  });

  vm.runInContext(
    'globalThis.testManager = new DownloadManager(); testManager.activeJobId = "job-skipped"; testManager.pollGeneration = 1;',
    worker.context
  );

  const result = vm.runInContext('testManager.doPoll(1)', worker.context);
  await result;

  assert.deepEqual(JSON.parse(JSON.stringify(worker.nativeMessages)), [
    {
      action: 'get_job_status',
      jobId: 'job-skipped',
    },
  ]);
  assert.equal(worker.scheduledTimers.length, 0);
  assert.equal(
    vm.runInContext('testManager.pollGeneration', worker.context),
    2
  );
  assert.equal(vm.runInContext('testManager.activeJobId', worker.context), null);
});

test('openPreviewPage stores explicit startTime in session storage', async () => {
  const worker = createServiceWorkerSandbox({ ok: true });

  const result = await vm.runInContext(
    'openPreviewPage({ previewUrl: "http://127.0.0.1:8765/stream", recentDownloadId: "dl-1", startTime: 124.5 })',
    worker.context
  );

  assert.equal(result.ok, true);
  const previewPayload = worker.storage['preview:test-preview-id'];
  assert.ok(previewPayload);
  assert.equal(previewPayload.previewUrl, 'http://127.0.0.1:8765/stream');
  assert.equal(previewPayload.startTime, 124.5);
  assert.equal(worker.createdTabs.length, 1);
  assert.equal(
    worker.createdTabs[0].url,
    'chrome-extension://test/pages/preview/preview.html?id=test-preview-id'
  );
});

test('openPreviewPage fetches currentTime from originTabId when startTime is omitted', async () => {
  const worker = createServiceWorkerSandbox({ ok: true });

  const result = await vm.runInContext(
    'openPreviewPage({ previewUrl: "http://127.0.0.1:8765/stream", recentDownloadId: "dl-1", originTabId: 42 })',
    worker.context
  );

  assert.equal(result.ok, true);
  const previewPayload = worker.storage['preview:test-preview-id'];
  assert.ok(previewPayload);
  assert.equal(previewPayload.previewUrl, 'http://127.0.0.1:8765/stream');
  assert.equal(previewPayload.startTime, 75.5);
});

test('openPreviewPage prefers chrome.scripting.executeScript over messaging when available', async () => {
  const worker = createServiceWorkerSandbox({ ok: true });
  worker.context.chrome.scripting = {
    executeScript: async () => [{ result: 112.5 }],
  };

  const result = await vm.runInContext(
    'openPreviewPage({ previewUrl: "http://127.0.0.1:8765/stream", recentDownloadId: "dl-1", originTabId: 42 })',
    worker.context
  );

  assert.equal(result.ok, true);
  const previewPayload = worker.storage['preview:test-preview-id'];
  assert.ok(previewPayload);
  assert.equal(previewPayload.previewUrl, 'http://127.0.0.1:8765/stream');
  assert.equal(previewPayload.startTime, 112.5);
});

