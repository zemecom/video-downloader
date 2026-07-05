const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const EXTENSION_DIR = path.join(__dirname, '..', 'extension');

// Игнорируем асинхронные ошибки (например, проблемы с моками DOM API в промисах),
// так как нас интересует исключительно парсинг и коллизии области видимости (SyntaxError).
process.on('unhandledRejection', () => {});

test('HTML pages do not have global scope collisions in their scripts', () => {
  const htmlFiles = [
    'pages/popup/popup.html',
    'pages/downloads/downloads.html',
    'pages/preview/preview.html',
    'content/overlay.html',
  ];

  for (const htmlFile of htmlFiles) {
    const htmlPath = path.join(EXTENSION_DIR, htmlFile);
    if (!fs.existsSync(htmlPath)) continue;
    const htmlContent = fs.readFileSync(htmlPath, 'utf8');

    // Находим все <script src="...">
    const scriptRegex = /<script\s+src="([^"]+)"/g;
    let match;
    const scripts = [];
    while ((match = scriptRegex.exec(htmlContent)) !== null) {
      scripts.push(match[1]);
    }

    if (scripts.length === 0) continue;

    const allCode = scripts
      .map((scriptFile) => {
        // скрипты подключаются относительно HTML-файла
        const scriptPath = path.resolve(path.dirname(htmlPath), scriptFile);
        if (!fs.existsSync(scriptPath)) {
          assert.fail(
            `Script ${scriptFile} referenced in ${htmlFile} does not exist at ${scriptPath}`
          );
        }
        return fs.readFileSync(scriptPath, 'utf8');
      })
      .join('\n;\n');

    try {
      // Компилируем объединенный код без выполнения.
      // Если есть коллизии `const` или `let` (Identifier has already been declared), 
      // V8 выбросит SyntaxError на этапе парсинга.
      new vm.Script(allCode, { filename: htmlFile });
    } catch (error) {
      if (error instanceof SyntaxError) {
        assert.fail(`SyntaxError collision in ${htmlFile} scripts:\n${error.message}`);
      }
    }
  }
});
