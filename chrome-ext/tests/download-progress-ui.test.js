const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const scriptSource = fs.readFileSync(
  path.join(__dirname, '..', 'extension', 'shared', 'download-progress-ui.js'),
  'utf8'
);
const sandbox = {
  globalThis: {},
};
vm.runInNewContext(scriptSource, sandbox, {
  filename: 'download-progress-ui.js',
});

const { getDownloadProgressPresentation } =
  sandbox.globalThis.YtdDownloadProgressUi;

function toPlainValue(value) {
  return JSON.parse(JSON.stringify(value));
}

test('cancelled download does not keep an indeterminate progress animation', () => {
  assert.deepEqual(
    toPlainValue(getDownloadProgressPresentation('cancelled', null)),
    {
      isIndeterminate: false,
      widthPercent: 0,
    }
  );
});

test('completed download uses a filled static progress bar', () => {
  assert.deepEqual(
    toPlainValue(getDownloadProgressPresentation('completed', null)),
    {
      isIndeterminate: false,
      widthPercent: 100,
    }
  );
});

test('active download preserves determinate and indeterminate states', () => {
  assert.deepEqual(
    toPlainValue(getDownloadProgressPresentation('starting', null)),
    {
      isIndeterminate: true,
      widthPercent: 38,
    }
  );
  assert.deepEqual(
    toPlainValue(getDownloadProgressPresentation('downloading', 48.5)),
    {
      isIndeterminate: false,
      widthPercent: 48.5,
    }
  );
});
