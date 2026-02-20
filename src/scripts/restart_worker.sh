#!/bin/bash

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
PHP_BIN=$(which php)

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция для логирования
log() {
    echo -e "$(date '+%Y-%m-%d %H:%M:%S') - $1"
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
  $0 --help       # Показать эту справку

ПРИМЕР ИСПОЛЬЗОВАНИЯ В CRON:
  */5 * * * * $0

ЛОГИ:
  Скрипт выводит подробную информацию о процессе перезапуска
  в консоль с временными метками.

EOF
}

# Обработка параметров командной строки
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    show_help
    exit 0
fi

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

    # Если PID файл не найден или процесс не работает, ищем процесс в системе
    WORKER_PID=$(pgrep -f "process_queue.php" 2>/dev/null | head -1)
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
    else
        log "${RED}Принудительная остановка воркера...${NC}"
        kill -KILL "$RUNNING_PID" 2>/dev/null
        sleep 2
    fi
else
    log "Воркер не был запущен ранее"
fi

# Удаляем PID файл, если он существует
if [ -f "$PID_FILE" ]; then
    rm -f "$PID_FILE" 2>/dev/null
    log "PID файл удален"
fi

# Запускаем новый экземпляр воркера
log "${GREEN}Запуск нового экземпляра воркера...${NC}"

cd "$PROJECT_ROOT/src/scripts" || {
    log "${RED}Ошибка: Не удалось перейти в директорию $PROJECT_ROOT/src/scripts${NC}"
    exit 1
}

# Запускаем воркер в фоне
"$PHP_BIN" "$WORKER_SCRIPT" &

NEW_PID=$!
log "${GREEN}Воркер запущен с PID $NEW_PID${NC}"

# Ждем немного и проверяем, что процесс запустился
sleep 2

if is_process_running "$NEW_PID"; then
    log "${GREEN}Воркер успешно перезапущен (PID: $NEW_PID)${NC}"

    # Сохраняем PID в файл для совместимости
    # Пробуем записать PID файл, если не получается - создаем с правами
    if echo "$NEW_PID" > "$PID_FILE" 2>/dev/null; then
        log "PID сохранен в файл: $PID_FILE"
    else
        log "${YELLOW}Не удалось сохранить PID в файл, создаем с правами...${NC}"
        # Создаем файл с правильными правами
        touch "$PID_FILE" && chmod 666 "$PID_FILE" && echo "$NEW_PID" > "$PID_FILE" 2>/dev/null
        if [ $? -eq 0 ]; then
            log "PID файл создан успешно: $PID_FILE"
        else
            log "${YELLOW}Предупреждение: Не удалось создать PID файл. Воркер работает, но без PID файла.${NC}"
        fi
    fi
else
    log "${RED}Ошибка: Воркер не запустился${NC}"
    exit 1
fi

log "${GREEN}Перезапуск завершен успешно${NC}"