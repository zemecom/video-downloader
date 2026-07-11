# Интеграция с Chrome Extension и Native Messaging

Интеграция между браузерным расширением и PHP CLI-приложением реализована через официальный протокол **Chrome Native Messaging**. Это позволяет запускать скачивание прямо из браузера в один клик.

---

## 1. Архитектура взаимодействия

Общение происходит асинхронно с помощью передачи JSON-сообщений через стандартные потоки ввода-вывода (`stdin`/`stdout`).

### Жизненный цикл запроса

1. Пользователь нажимает кнопку в popup расширения или на странице предпросмотра (`preview.html`).
2. Фон расширения (`service-worker.js`) отправляет запрос методом `chrome.runtime.sendNativeMessage('dev.zemecom.ytd_downloader', payload, callback)`.
3. Chrome запускает локальный скрипт-обертку ([ytd-native-host-wrapper](../chrome-ext/native-host/ytd-native-host-wrapper)), который настраивает окружение `PATH`, находит системный PHP и пробрасывает вызов к PHP-скрипту [ytd-native-host](../bin/ytd-native-host).
4. Процесс PHP считывает сообщение из `stdin`. Каждое сообщение начинается с 4-байтового префикса, содержащего длину JSON-тела в байтах.
5. PHP парсит запрос с помощью [NativeMessagingProtocolService](../src/NativeHost/Protocol/NativeMessagingProtocolService.php), обрабатывает команду и пишет ответ в `stdout` (также с 4-байтовым заголовком длины), после чего процесс Native Host завершается.

### Асинхронный запуск задач (фоновые процессы)

Поскольку загрузка видео через `yt-dlp` может занимать длительное время, а процесс Native Host должен быстро вернуть ответ и закрыться, скачивание происходит в фоновом режиме:

1. Запрос `start_download` инициализирует задачу, создает JSON-файл состояния в `logs/native-host-jobs/` и запускает фоновый воркер [ytd-native-job](../bin/ytd-native-job) через `proc_open` в фоновом режиме.
2. Native Host сразу возвращает расширению статус `accepted` и уникальный `jobId` задачи.
3. Фоновый воркер запускает `bin/ytd`, парсит вывод прогресса и непрерывно обновляет файл состояния `logs/native-host-jobs/<jobId>.json`.
4. Расширение по таймеру делает запросы `get_job_status` с этим `jobId`. При каждом запросе Chrome снова запускает кратковременный процесс Native Host, который считывает JSON-файл состояния и возвращает расширению текущий прогресс.

---

## 2. Структура расширения (`chrome-ext/`)

- **`extension/manifest.json`**: Манифест расширения (Manifest V3). Задает разрешения (`permissions`, включая `nativeMessaging`) и регистрирует `service-worker.js`.
- **`extension/pages/popup/*`**: Интерфейс расширения при клике на иконку в панели браузера.
- **`extension/pages/preview/*`**: Вкладка предпросмотра скачанных видеофайлов.
- **`native-host/native-host-manifest.template.json`**: Шаблон манифеста хоста. При установке из него генерируется манифест хоста для Chrome, где указывается путь к обертке и разрешенный origin расширения.
- **`native-host/ytd-native-host-wrapper`**: Shell-скрипт запуска PHP, который запускается непосредственно браузером Chrome.

---

## 3. Отладка и дебаг

Отлаживать Native Messaging может быть непросто, так как Chrome запускает хост в фоновом режиме без вывода в терминал.

### Отладка JS-кода в Chrome

- **Popup**: Кликни правой кнопкой на иконку расширения -> «Исследовать всплывающее окно» (Inspect Popup).
- **Service Worker**: Перейди на страницу `chrome://extensions`, найди расширение YTD и нажми на ссылку «Фоновый сервис-воркер» (service worker) в блоке «Активировать правила». Откроется отдельное окно DevTools для фонового скрипта.
- **Preview Page**: Открывается как обычная вкладка, дебажится через `F12`.

### Отладка PHP-хоста на стороне OS

Поскольку хост работает в фоне, стандартные выводы ошибок `echo` или `var_dump` нарушат бинарный протокол общения и Chrome мгновенно разорвет соединение.

Для диагностики используй логи:

- Все логи хоста пишутся в `logs/native-host.log` (путь можно переопределить через `YTD_NATIVE_HOST_LOG_FILE`).
- Логи HTTP-сервера предпросмотра пишутся в `logs/native-host-preview-server.log`.
- Необработанные PHP-исключения пишутся в общий лог `logs/errors.log`.

---

## 4. Возможные проблемы в macOS

Если расширение выдает ошибку `Specified native messaging host not found`:

1. Проверь правильность пути к `ytd-native-host-wrapper` в файле `/Users/твой_юзер/Library/Application Support/Google/Chrome/NativeMessagingHosts/dev.zemecom.ytd_downloader.json`. Установщик (`make chrome-ext-install`) должен прописать туда абсолютный путь.
2. Убедись, что скрипт `ytd-native-host-wrapper` имеет права на исполнение (`chmod +x chrome-ext/native-host/ytd-native-host-wrapper`).
3. Проверь права macOS на запуск PHP CLI: при первом запуске macOS может заблокировать запуск локального скрипта из песочницы Chrome. Запусти `ytd-native-host-wrapper` один раз вручную в терминале, чтобы подтвердить разрешение на запуск в системе.
