#!/bin/bash
# Generate a slim backup (without cache/log data)
# Usage: ./scripts/backup-slim.sh [filename]

set -e

# Project root directory (the parent of this script's directory)
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUPS_DIR="${ROOT_DIR}/backups"
PROJECT_NAME="${PROJECT_NAME:-labdoo3}"
ENVIRONMENT="${ENVIRONMENT:-dev}"
TIMESTAMP=$(date +%Y%m%d_%H%M)
OUTPUT_FILE="${1:-${BACKUPS_DIR}/${PROJECT_NAME}_${ENVIRONMENT}_slim_${TIMESTAMP}.sql}"

mkdir -p "${BACKUPS_DIR}"

echo "📉 Generando backup slim en: ${OUTPUT_FILE}.gz"

EXCLUDES="cache_*,watchdog,history,sessions,search_*,webprofiler"

# Run Drush using an absolute path to avoid issues when changing directories to /web
if "${ROOT_DIR}/vendor/bin/drush" sql-dump --gzip \
    --structure-tables-list="${EXCLUDES}" \
    --result-file="${OUTPUT_FILE}" \
    --extra-dump="--single-transaction=false --no-tablespaces" 2>/dev/null; then
    echo "✅ Backup generado con Drush."
else
    echo "⚠️ Drush falló, intentando con mysqldump nativo..."
    # Load variables from .env if it exists for the fallback
    if [ -f "${ROOT_DIR}/.env" ]; then
        # Extract the required variables from .env
        DB_NAME=$(grep "^DB_NAME=" "${ROOT_DIR}/.env" | cut -d'=' -f2-)
        DB_USER=$(grep "^DB_USER=" "${ROOT_DIR}/.env" | cut -d'=' -f2-)
        DB_PASSWORD=$(grep "^DB_PASSWORD=" "${ROOT_DIR}/.env" | cut -d'=' -f2-)
        DB_HOST=$(grep "^DB_HOST=" "${ROOT_DIR}/.env" | cut -d'=' -f2-)
        DB_PORT=$(grep "^DB_PORT=" "${ROOT_DIR}/.env" | cut -d'=' -f2-)
    fi

    BACKUP_FILE="${OUTPUT_FILE}.gz"

    # Simplified exclusion attempt for native mysqldump (exact table names only)
    MYSQL_EXCLUDES=""
    # Note: mysqldump does not support wildcards such as cache_*, so Drush is preferred.
    # Only handle names without wildcards here; otherwise the exclusion will silently fail.

    mysqldump -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "${DB_USER:-root}" -p"${DB_PASSWORD}" "${DB_NAME}" \
        --single-transaction=false --no-tablespaces | gzip > "${BACKUP_FILE}"

    echo "✅ Backup generado con mysqldump nativo: ${BACKUP_FILE}"
fi
