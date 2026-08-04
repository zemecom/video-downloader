const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const extensionDir = path.join(__dirname, '..', 'extension');
const progressUiSource = fs.readFileSync(
  path.join(extensionDir, 'shared', 'download-progress-ui.js'),
  'utf8'
);
const popupSource = fs.readFileSync(
  path.join(extensionDir, 'pages', 'popup', 'popup.js'),
  'utf8'
);

class FakeElement {
  constructor() {
    this.dataset = {};
    this.disabled = false;
    this.hidden = false;
    this.style = {};
    this.textContent = '';
  }

  addEventListener() {}

  appendChild() {}
}

function createPopupSandbox() {
  const elements = new Map();
  const selectors = [
    '.status',
    '.recent-list',
    '.recent-empty',
    '.recent-open-folder',
    '.recent-open-all',
    '.recent-clear-all',
    '.active-download',
    '.active-download-status',
    '.active-download-fill',
    '.active-download-phase',
    '.active-download-percent',
    '.active-download-cancel',
    '.active-download-close',
  ];
  selectors.forEach((selector) => {
    elements.set(selector, new FakeElement());
  });
  elements.get('.active-download').hidden = true;
  elements.get('.active-download-close').hidden = true;

  const actionButton = new FakeElement();
  actionButton.dataset.mode = 'video';
  let windowCloseCalls = 0;

  const sandbox = {
    Promise,
    console,
    document: {
      createElement: () => new FakeElement(),
      querySelector: (selector) => elements.get(selector) || null,
      querySelectorAll: (selector) =>
        selector === '[data-mode]' ? [actionButton] : [],
    },
    window: {
      close: () => {
        windowCloseCalls += 1;
      },
    },
    chrome: {
      runtime: {
        lastError: null,
        connect: () => ({
          onMessage: {
            addListener: () => {},
          },
        }),
        sendMessage: (message, callback) => {
          const response =
            message.type === 'ytd:start-download'
              ? {
                  ok: true,
                  payload: {
                    jobId: 'job-123',
                    status: 'starting',
                    progressPercent: null,
                    progressText: 'Подготавливаю загрузку...',
                    canCancel: true,
                  },
                }
              : message.type === 'ytd:list-recent-downloads'
                ? { ok: true, payload: { items: [] } }
                : { ok: true, payload: null };
          callback(response);
        },
      },
      tabs: {
        query: async () => [{ id: 7, url: 'https://example.com/video' }],
      },
    },
    globalThis: {},
  };
  const context = vm.createContext(sandbox);

  vm.runInContext(progressUiSource, context, {
    filename: 'download-progress-ui.js',
  });
  vm.runInContext(popupSource, context, {
    filename: 'popup.js',
  });

  return {
    actionButton,
    activeDownload: elements.get('.active-download'),
    activeDownloadCancel: elements.get('.active-download-cancel'),
    activeDownloadFill: elements.get('.active-download-fill'),
    activeDownloadPhase: elements.get('.active-download-phase'),
    getWindowCloseCalls: () => windowCloseCalls,
    context,
  };
}

test('popup remains open and displays the started download', async () => {
  const popup = createPopupSandbox();

  await vm.runInContext('startDownload("video")', popup.context);

  assert.equal(popup.getWindowCloseCalls(), 0);
  assert.equal(popup.activeDownload.hidden, false);
  assert.equal(popup.actionButton.disabled, true);
});

test('popup renders skipped download as a terminal status', () => {
  const popup = createPopupSandbox();

  vm.runInContext(
    `renderActiveDownload({
      jobId: 'job-skipped',
      status: 'skipped',
      progressPercent: null,
      progressText: 'Загрузка пропущена: файл уже существует.',
      canCancel: false,
    })`,
    popup.context
  );

  assert.equal(popup.activeDownload.hidden, false);
  assert.equal(popup.activeDownloadPhase.textContent, 'Пропущено');
  assert.equal(popup.activeDownloadFill.dataset.indeterminate, 'false');
  assert.equal(popup.activeDownloadFill.style.width, '0%');
  assert.equal(popup.activeDownloadCancel.disabled, true);
  assert.equal(popup.actionButton.disabled, false);
});
