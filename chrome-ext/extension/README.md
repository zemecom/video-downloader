# YTD Chrome Extension

Расширение Chrome на Manifest V3, которое отправляет URL текущей вкладки в локальный native host `dev.zemecom.ytd_downloader`.

## Как загрузить в Chrome

1. Открой `chrome://extensions`.
2. Включи `Developer mode`.
3. Нажми `Load unpacked`.
4. Выбери каталог:
   - `/Users/aleksandrzemlanuhin/Dev/PROJECTS/TOOLS/video-downloader/ytd/chrome-ext/extension`

## Разрешения

- `activeTab` - читать URL текущей вкладки только после явного клика по расширению
- `nativeMessaging` - общаться с локальным native host
- `notifications` - показывать уведомления о запуске и результате
