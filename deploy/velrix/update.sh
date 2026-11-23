#!/usr/bin/env bash
# =============================================================================
# Kisauni Result System - auto-deploy for the Velrix panel.
#
# Checks GitHub for a new commit on main and, only if there is one, downloads it,
# installs dependencies and clears caches. Meant to run from the panel's
# Scheduled tasks (cron) every few minutes, so a `git push` reaches the live
# site by itself - the same behaviour the old server had.
#
#   Schedule (panel -> site -> Scheduled tasks), every 5 minutes:
#     bash /srv/site/deploy/velrix/update.sh >> /srv/site/storage/logs/auto-deploy.log 2>&1
#
# It does NOT run database migrations (the cron/terminal cannot reach the DB on
# this panel). When a release adds a migration, run it once from the Developer
# dashboard: Database & Backup -> Database Overview -> "Back up, then run pending
# migrations". The .env file and storage/app/uploads are never touched.
# =============================================================================
set -euo pipefail

APP_DIR="/srv/site"
REPO="otthnachia6-ship-it/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL"
BRANCH="main"
SHA_FILE="${APP_DIR}/.deployed_sha"
LOCK_DIR="${APP_DIR}/.deploy.lock"

cd "${APP_DIR}"

# One run at a time (mkdir is atomic). Stale lock older than 30 min is cleared.
if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
    if [ -d "${LOCK_DIR}" ] && [ "$(find "${LOCK_DIR}" -maxdepth 0 -mmin +30 2>/dev/null)" ]; then
        rmdir "${LOCK_DIR}" 2>/dev/null || true
        mkdir "${LOCK_DIR}" 2>/dev/null || exit 0
    else
        exit 0
    fi
fi
trap 'rmdir "${LOCK_DIR}" 2>/dev/null || true' EXIT

# The API returns minified JSON; take the FIRST "sha" (the commit sha), not the last.
remote_sha=$(curl -fsSL -H 'User-Agent: kisauni-deploy' -H 'Accept: application/vnd.github+json' \
    "https://api.github.com/repos/${REPO}/commits/${BRANCH}" \
    | grep -oE '"sha"[[:space:]]*:[[:space:]]*"[0-9a-f]{40}"' | head -1 | grep -oE '[0-9a-f]{40}' || true)

if ! printf '%s' "${remote_sha}" | grep -Eq '^[0-9a-f]{7,40}$'; then
    echo "$(date '+%F %T') could not read the latest commit from GitHub - skipping"
    exit 0
fi

local_sha=$(cat "${SHA_FILE}" 2>/dev/null || echo none)
if [ "${remote_sha}" = "${local_sha}" ]; then
    exit 0   # already up to date, nothing to do
fi

echo "$(date '+%F %T') new commit ${remote_sha} (was ${local_sha}) - deploying"

rm -rf .src && mkdir .src
curl -fsSL -H 'User-Agent: kisauni-deploy' \
    "https://codeload.github.com/${REPO}/tar.gz/${remote_sha}" | tar -xz -C .src --strip-components=1
cp -a .src/. "${APP_DIR}/"
rm -rf .src

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear

echo "${remote_sha}" > "${SHA_FILE}"
echo "$(date '+%F %T') deployed ${remote_sha} OK"
