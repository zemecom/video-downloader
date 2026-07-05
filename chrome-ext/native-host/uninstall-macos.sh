#!/bin/sh
set -eu

HOST_NAME="dev.zemecom.ytd_downloader"
TARGET_PATH="$HOME/Library/Application Support/Google/Chrome/NativeMessagingHosts/$HOST_NAME.json"

if [ -f "$TARGET_PATH" ]; then
  rm "$TARGET_PATH"
  echo "Removed native host manifest:"
  echo "  $TARGET_PATH"
else
  echo "Native host manifest not found:"
  echo "  $TARGET_PATH"
fi
