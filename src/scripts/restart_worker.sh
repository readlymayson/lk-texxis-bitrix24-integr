#!/bin/bash

# Защита от запуска через sh (dash): скрипт требует bash
if [ -z "$BASH_VERSION" ]; then
    echo "Ошибка: этот скрипт должен запускаться через bash, а не sh." >&2
    echo "Используйте: bash $0 или chmod +x $0 && $0" >&2
    echo "В cron: /bin/bash $(readlink -f "$0" 2>/dev/null || echo "$0")" >&2
    exit 1
fi

# Скрипт для перезапуска воркера process_queue.php
#
# Функционал:
# - Проверяет работу существующего воркера
# - Корректно останавливает запущенный процесс (SIGTERM)
# - Запускает новый экземпляр воркера
# - Проверяет успешный запуск
#
# Использование:
#   ./restart_worker.sh          # Перезапуск воркера
#   ./restart_worker.sh --help   # Показать эту справку
#
# Пример использования в cron:
#   */5 * * * * /path/to/restart_worker.sh

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
WORKER_SCRIPT="$PROJECT_ROOT/src/scripts/process_queue.php"
PID_FILE="$PROJECT_ROOT/src/data/worker.pid"
PHP_BIN="${PHP_BIN:-$(which php 2>/dev/null || echo "/usr/bin/php")}"

# Файл лога этого управляющего скрипта (удобно смотреть, что сделал cron)
LOG_FILE="${LOG_FILE:-$PROJECT_ROOT/src/logs/restart_worker.log}"

# Цвета для вывода (только если есть TTY)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    NC='\033[0m' # No Color
else
    RED=''
    GREEN=''
    YELLOW=''
    NC=''
fi

# Функция для логирования
log() {
    local msg
    msg="$(date '+%Y-%m-%d %H:%M:%S') - $1"
    printf '%b\n' "$msg"
    mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
    printf '%b\n' "$msg" >> "$LOG_FILE" 2>/dev/null || true
}

# Функция для показа справки
show_help() {
    cat << EOF
Скрипт для перезапуска воркера process_queue.php

ФУНКЦИОНАЛ:
- Проверяет работу существующего воркера
- Корректно останавливает запущенный процесс (SIGTERM)
- Запускает новый экземпляр воркера
- Проверяет успешный запуск

ИСПОЛЬЗОВАНИЕ:
  $0              # Перезапуск воркера
  $0 --force      # Принудительный сброс lock и перезапуск
  $0 --help       # Показать эту справку

ПРИМЕР ИСПОЛЬЗОВАНИЯ В CRON:
  */5 * * * * $0

ЛОГИ:
  Скрипт выводит подробную информацию в консоль и пишет в файл:
  $LOG_FILE

EOF
}

# Обработка параметров командной строки
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    show_help
    exit 0
fi

FORCE=0
if [ "$1" = "--force" ] || [ "$1" = "-f" ]; then
    FORCE=1
fi

# --- Защита от параллельного запуска (lock-файл) ---
LOCK_FILE="$PROJECT_ROOT/src/data/restart_worker.lock"

if [ "$FORCE" -eq 1 ]; then
    # Читаем PID прямо из lock-файла
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ]; then
        log "${YELLOW}Принудительный сброс lock'а (PID: $LOCK_PID)...${NC}"
        kill "$LOCK_PID" 2>/dev/null
        sleep 1
    fi
    rm -f "$LOCK_FILE"
fi

# Открываем дескриптор 9 на запись/чтение к lock-файлу
exec 9>>"$LOCK_FILE"

if ! flock -n 9; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        log "${YELLOW}Скрипт уже выполняется (lock занят процессом PID: $LOCK_PID).${NC}"
        log "Используйте: $0 --force  — чтобы принудительно сбросить lock"
    else
        log "${YELLOW}Скрипт уже выполняется (но процесс из lock-файла не отвечает).${NC}"
        log "Запустите с параметром --force для очистки."
    fi
    exit 0
fi

# Очищаем старый PID и записываем PID текущего процесса в lock-файл
truncate -s 0 "$LOCK_FILE" 2>/dev/null || true
echo $$ >&9
# При выходе (любом) lock освободится автоматически — ядро закроет дескриптор 9.

# Функция для проверки существования процесса
is_process_running() {
    local pid=$1
    if [ -z "$pid" ]; then
        return 1
    fi
    if kill -0 "$pid" 2>/dev/null; then
        return 0
    else
        return 1
    fi
}

# Функция для ожидания завершения процесса
wait_for_process_exit() {
    local pid=$1
    local timeout=${2:-30}  # таймаут по умолчанию 30 секунд
    local count=0

    log "Ожидание завершения процесса PID $pid..."

    while [ $count -lt $timeout ]; do
        if ! is_process_running "$pid"; then
            log "Процесс $pid завершился"
            return 0
        fi
        sleep 1
        ((count++))
    done

    log "${RED}Таймаут ожидания завершения процесса $pid${NC}"
    return 1
}

# Проверяем наличие PHP
if [ -z "$PHP_BIN" ]; then
    log "${RED}Ошибка: PHP не найден в PATH${NC}"
    exit 1
fi

# Проверяем наличие скрипта воркера
if [ ! -f "$WORKER_SCRIPT" ]; then
    log "${RED}Ошибка: Скрипт воркера не найден: $WORKER_SCRIPT${NC}"
    exit 1
fi

log "Перезапуск воркера..."
log "Скрипт воркера: $WORKER_SCRIPT"
log "PID файл воркера: $PID_FILE"
log "Лог управляющего скрипта: $LOG_FILE"

# Функция для поиска работающего воркера
find_running_worker() {
    # Сначала проверяем PID файл
    if [ -f "$PID_FILE" ]; then
        EXISTING_PID=$(cat "$PID_FILE" 2>/dev/null)
        if [ -n "$EXISTING_PID" ] && is_process_running "$EXISTING_PID"; then
            echo "$EXISTING_PID"
            return 0
        fi
    fi

    # Поиск по имени скрипта в командной строке
    WORKER_PID=$(pgrep -f "process_queue\.php" 2>/dev/null | head -1)
    if [ -n "$WORKER_PID" ]; then
        echo "$WORKER_PID"
        return 0
    fi

    # Воркер не найден
    return 1
}

# Проверяем, работает ли уже воркер
RUNNING_PID=$(find_running_worker)

if [ -n "$RUNNING_PID" ]; then
    log "${YELLOW}Найден работающий воркер с PID $RUNNING_PID${NC}"

    # Отправляем сигнал завершения
    log "Отправка сигнала завершения процессу $RUNNING_PID..."
    kill -TERM "$RUNNING_PID"

    # Ждем завершения
    if wait_for_process_exit "$RUNNING_PID" 30; then
        log "${GREEN}Воркер успешно остановлен${NC}"
        rm -f "$PID_FILE"
    else
        log "${RED}Принудительная остановка воркера...${NC}"
        kill -KILL "$RUNNING_PID" 2>/dev/null
        sleep 2
        rm -f "$PID_FILE"
    fi
else
    log "Воркер не был запущен ранее"
fi

# Запускаем новый экземпляр воркера
log "${GREEN}Запуск нового экземпляра воркера...${NC}"

cd "$PROJECT_ROOT/src/scripts" || {
    log "${RED}Ошибка: Не удалось перейти в директорию $PROJECT_ROOT/src/scripts${NC}"
    exit 1
}

# Запускаем воркер в фоне (как отдельный процесс), вывод воркера глушим:
# сам воркер пишет свои логи через Logger.
nohup "$PHP_BIN" "$WORKER_SCRIPT" >/dev/null 2>&1 &

NEW_PID=$!
log "${GREEN}PHP-процесс воркера запущен (PID: $NEW_PID). Ожидание PID-файла воркера...${NC}"

# Ждём, пока воркер сам создаст PID файл (он это делает в checkAndSetPidFile()).
for i in $(seq 1 30); do
    if [ -f "$PID_FILE" ]; then
        WORKER_PID_FROM_FILE="$(cat "$PID_FILE" 2>/dev/null)"
        if [ -n "$WORKER_PID_FROM_FILE" ] && is_process_running "$WORKER_PID_FROM_FILE"; then
            log "${GREEN}Воркер подтверждён: PID $WORKER_PID_FROM_FILE (из $PID_FILE)${NC}"
            log "${GREEN}Перезапуск завершен успешно${NC}"
            exit 0
        fi
    fi
    # Если PHP-процесс уже умер, дальше ждать бессмысленно
    if ! is_process_running "$NEW_PID"; then
        log "${RED}Ошибка: PHP-процесс воркера завершился до создания PID-файла (PID: $NEW_PID)${NC}"
        exit 1
    fi
    sleep 1
done

log "${RED}Ошибка: воркер не создал/не подтвердил PID-файл за 30 секунд${NC}"
exit 1