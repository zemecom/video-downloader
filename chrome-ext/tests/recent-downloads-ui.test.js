const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const scriptSource = fs.readFileSync(
  path.join(__dirname, '..', 'extension', 'shared', 'recent-downloads-ui.js'),
  'utf8'
);
const sandbox = {
  globalThis: {},
};
vm.runInNewContext(scriptSource, sandbox, {
  filename: 'recent-downloads-ui.js',
});

const {
  RECENT_DOWNLOADS_PREVIEW_LIMIT,
  buildRecentDownloadActions,
  createRecentDownloadsViewModel,
  normalizeRecentDownloadsPayload,
} = sandbox.globalThis.YtdRecentDownloadsUi;

function toPlainValue(value) {
  return JSON.parse(JSON.stringify(value));
}

test('popup view model limits the recent downloads preview and exposes hidden items', () => {
  const items = Array.from({ length: 7 }, (_, index) => ({
    id: `download-${index + 1}`,
  }));

  const viewModel = createRecentDownloadsViewModel(items, {
    limit: RECENT_DOWNLOADS_PREVIEW_LIMIT,
  });

  assert.equal(viewModel.totalCount, 7);
  assert.equal(viewModel.visibleItems.length, RECENT_DOWNLOADS_PREVIEW_LIMIT);
  assert.equal(viewModel.visibleItems[0].id, 'download-1');
  assert.equal(viewModel.visibleItems.at(-1).id, 'download-5');
  assert.equal(viewModel.hasHiddenItems, true);
});

test('all downloads view model keeps the full list visible', () => {
  const items = Array.from({ length: 7 }, (_, index) => ({
    id: `download-${index + 1}`,
  }));

  const viewModel = createRecentDownloadsViewModel(items);

  assert.equal(viewModel.totalCount, 7);
  assert.equal(viewModel.visibleItems.length, 7);
  assert.equal(viewModel.hasHiddenItems, false);
});

test('recent download actions expose playback only for video entries', () => {
  assert.deepEqual(
    toPlainValue(
      buildRecentDownloadActions({ mode: 'video' }).map((action) => action.kind)
    ),
    ['play', 'open', 'reveal', 'delete']
  );
  assert.deepEqual(
    toPlainValue(
      buildRecentDownloadActions({ mode: 'audio' }).map((action) => action.kind)
    ),
    ['open', 'reveal', 'delete']
  );
});

test('recent downloads payload normalization falls back to an empty list', () => {
  assert.deepEqual(
    toPlainValue(normalizeRecentDownloadsPayload({ items: [{ id: 'one' }] })),
    [{ id: 'one' }]
  );
  assert.deepEqual(
    toPlainValue(normalizeRecentDownloadsPayload({ items: 'broken' })),
    []
  );
  assert.deepEqual(toPlainValue(normalizeRecentDownloadsPayload(null)), []);
});