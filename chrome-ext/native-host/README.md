# Native Host

Устанавливает пользовательский Google Chrome Native Messaging host на macOS для локального проекта `YTD`.

Из корня проекта можно использовать готовые команды:

```bash
make chrome-ext-install
make chrome-ext-uninstall
```

Если нужен override `Extension ID`:

```bash
make chrome-ext-install CHROME_EXT_ID=YOUR_EXTENSION_ID
```

## Установка

```bash
cd chrome-ext/native-host
./install-macos.sh
```

## Переопределить Extension ID

```bash
./install-macos.sh --extension-id=YOUR_EXTENSION_ID
```

## Удаление

```bash
./uninstall-macos.sh
```
