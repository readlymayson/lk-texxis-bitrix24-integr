#!/bin/bash
set -euo pipefail

LOG_URL="https://texxis.ru/local/php_interface/lk/src/logs/bitrix24_webhooks.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_DESTINATION="$SCRIPT_DIR/../src/logs/texxis.log"
DESTINATION="${1:-$DEFAULT_DESTINATION}"

# Убедимся, что директория назначения существует
mkdir -p "$(dirname "$DESTINATION")"

# Скачиваем актуальную копию логов из внешнего источника
curl -fsSL "$LOG_URL" -o "$DESTINATION"
