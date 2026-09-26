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

  return { context, nativeMessages, scheduledTimers };
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
