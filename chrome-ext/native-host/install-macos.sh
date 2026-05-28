#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
EXTENSION_DIR="$REPO_ROOT/chrome-ext/extension"
HOST_NAME="dev.zemecom.ytd_downloader"
WRAPPER_PATH="$SCRIPT_DIR/ytd-native-host-wrapper"
TEMPLATE_PATH="$SCRIPT_DIR/native-host-manifest.template.json"
TARGET_DIR="$HOME/Library/Application Support/Google/Chrome/NativeMessagingHosts"
TARGET_PATH="$TARGET_DIR/$HOST_NAME.json"

EXTENSION_ID=""

usage() {
  echo "Usage: ./install-macos.sh [--extension-id=EXTENSION_ID]" >&2
}

for arg in "$@"; do
  case "$arg" in
    --extension-id=*)
      EXTENSION_ID=${arg#*=}
      ;;
    *)
      usage
      exit 1
      ;;
  esac
done

if [ -z "$EXTENSION_ID" ]; then
  EXTENSION_ID=$(php -r '
    $manifestPath = $argv[1];
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !is_string($manifest["key"] ?? null) || $manifest["key"] === "") {
        fwrite(STDERR, "Manifest key is missing.\n");
        exit(1);
    }
    $bytes = base64_decode($manifest["key"], true);
    if (!is_string($bytes) || $bytes === "") {
        fwrite(STDERR, "Manifest key is invalid.\n");
        exit(1);
    }
    $hash = hash("sha256", $bytes, true);
    $hex = substr(bin2hex(substr($hash, 0, 16)), 0, 32);
    $id = "";
    foreach (str_split($hex) as $char) {
        $id .= chr(ord("a") + hexdec($char));
    }
    echo $id;
  ' "$EXTENSION_DIR/manifest.json")
fi

EXTENSION_ORIGIN="chrome-extension://$EXTENSION_ID/"

mkdir -p "$TARGET_DIR"
chmod +x "$WRAPPER_PATH" "$REPO_ROOT/bin/ytd-native-host"

php -r '
  [$templatePath, $targetPath, $wrapperPath, $extensionOrigin] = array_slice($argv, 1);
  $template = (string) file_get_contents($templatePath);
  if ($template === "") {
      fwrite(STDERR, "Template is empty.\n");
      exit(1);
  }
  $rendered = str_replace(
      ["__HOST_PATH__", "__EXTENSION_ORIGIN__"],
      [$wrapperPath, $extensionOrigin],
      $template,
  );
  $decoded = json_decode($rendered, true);
  if (!is_array($decoded)) {
      fwrite(STDERR, "Rendered host manifest is invalid JSON.\n");
      exit(1);
  }
  file_put_contents($targetPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
' "$TEMPLATE_PATH" "$TARGET_PATH" "$WRAPPER_PATH" "$EXTENSION_ORIGIN"

echo "Installed native host manifest:"
echo "  $TARGET_PATH"
echo "Allowed origin:"
echo "  $EXTENSION_ORIGIN"
