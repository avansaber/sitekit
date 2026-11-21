#!/usr/bin/env bash
set -euo pipefail
umask 077

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
PROJECT_DIR=$(cd -- "$SCRIPT_DIR/../.." && pwd)
HOST_CONFIG=${SITEKIT_HOST_CONFIG:-/etc/sitekit/host.env}
scratch_dir=''
backup_recovery=false
clear_maintenance=false
resume_services=()

fail() {
    printf 'SiteKit: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    local exit_code=$?
    trap - EXIT
    if [[ "$backup_recovery" == true ]] && ! resume_backup; then
        printf 'SiteKit: Failed to resume services after backup; check the stack manually.\n' >&2
        exit_code=1
    fi
    if [[ -n "$scratch_dir" ]]; then
        rm -rf -- "$scratch_dir" || exit_code=1
    fi
    exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ $(id -u) == 0 ]] || fail 'Run this script as root on the EC2 host.'
[[ $(uname -s) == Linux ]] || fail 'The production host must run Linux.'
[[ -r "$HOST_CONFIG" ]] || fail "Missing host configuration: $HOST_CONFIG"

set -a
source "$HOST_CONFIG"
set +a

: "${AWS_REGION:?Set AWS_REGION}"
: "${SITEKIT_SSM_PATH:?Set SITEKIT_SSM_PATH}"
: "${SITEKIT_DOMAIN:?Set SITEKIT_DOMAIN}"
: "${APP_URL:?Set APP_URL}"
: "${SITEKIT_DATA_DIR:?Set SITEKIT_DATA_DIR}"
: "${SITEKIT_BACKUP_BUCKET:?Set SITEKIT_BACKUP_BUCKET}"
SITEKIT_RUNTIME_DIR=${SITEKIT_RUNTIME_DIR:-/run/sitekit}
SITEKIT_BACKUP_PREFIX=${SITEKIT_BACKUP_PREFIX:-control-plane}
SITEKIT_DB_DATABASE=${SITEKIT_DB_DATABASE:-sitekit}
SITEKIT_DB_USERNAME=${SITEKIT_DB_USERNAME:-sitekit}
SITEKIT_SSM_PATH=${SITEKIT_SSM_PATH%/}

[[ "$APP_URL" == "https://$SITEKIT_DOMAIN" ]] || fail 'APP_URL must be https://SITEKIT_DOMAIN with no trailing slash.'
[[ "$SITEKIT_SSM_PATH" == /* && "$SITEKIT_SSM_PATH" != / ]] || fail 'Use a dedicated non-root SSM path.'
[[ "$SITEKIT_DB_DATABASE" =~ ^[a-zA-Z0-9_]+$ ]] || fail 'Invalid database name.'
[[ "$SITEKIT_DB_USERNAME" =~ ^[a-zA-Z0-9_]+$ && "$SITEKIT_DB_USERNAME" != root ]] || fail 'Invalid application database user.'
[[ "$SITEKIT_DATA_DIR" == /* && "$SITEKIT_DATA_DIR" != / ]] || fail 'Use an absolute retained-volume mount path, not /.'
[[ "$SITEKIT_RUNTIME_DIR" == /* && "$SITEKIT_RUNTIME_DIR" != / ]] || fail 'Use an absolute private runtime directory, not /.'
[[ "$SITEKIT_RUNTIME_DIR" != "$SITEKIT_DATA_DIR" && "$SITEKIT_RUNTIME_DIR" != "$SITEKIT_DATA_DIR/"* ]] || fail 'Do not store runtime secrets on the data volume.'

for tool in aws jq docker mountpoint flock; do
    command -v "$tool" >/dev/null || fail "Missing required tool: $tool"
done

mountpoint -q "$SITEKIT_DATA_DIR" || fail 'The retained data volume is not mounted; refusing to write to the root disk.'

install -d -m 0700 "$SITEKIT_RUNTIME_DIR"
exec 9>"$SITEKIT_RUNTIME_DIR/operation.lock"
flock -w 900 9 || fail 'Another SiteKit deployment or backup is still running.'

compose() {
    docker compose --project-directory "$PROJECT_DIR" --env-file "$HOST_CONFIG" \
        -f "$PROJECT_DIR/docker-compose.production.yml" "$@"
}

prepare_storage() {
    install -d -m 0755 "$SITEKIT_DATA_DIR/mysql"
    install -d -m 0700 "$SITEKIT_DATA_DIR/caddy/data" "$SITEKIT_DATA_DIR/caddy/config"
    install -d -m 0755 -o 33 -g 33 "$SITEKIT_DATA_DIR/storage" \
        "$SITEKIT_DATA_DIR/storage/app" "$SITEKIT_DATA_DIR/storage/app/public" \
        "$SITEKIT_DATA_DIR/storage/framework" "$SITEKIT_DATA_DIR/storage/framework/cache" \
        "$SITEKIT_DATA_DIR/storage/framework/cache/data" \
        "$SITEKIT_DATA_DIR/storage/framework/sessions" \
        "$SITEKIT_DATA_DIR/storage/framework/views" "$SITEKIT_DATA_DIR/storage/logs"
    install -d -m 0700 -o 33 -g 33 "$SITEKIT_DATA_DIR/storage/app/private"
}

load_secrets() {
    prepare_storage
    scratch_dir=$(mktemp -d "$SITEKIT_RUNTIME_DIR/secrets.XXXXXX")

    aws ssm get-parameters-by-path --region "$AWS_REGION" --path "$SITEKIT_SSM_PATH" \
        --recursive --with-decryption --output json >"$scratch_dir/ssm.json"

    jq -n \
        --arg name "${APP_NAME:-SiteKit}" \
        --arg mailer "${MAIL_MAILER:-log}" \
        --arg host "${MAIL_HOST:-}" \
        --arg port "${MAIL_PORT:-587}" \
        --arg scheme "${MAIL_SCHEME:-smtp}" \
        --arg address "${MAIL_FROM_ADDRESS:-}" \
        --arg from "${MAIL_FROM_NAME:-SiteKit}" \
        --arg region "$AWS_REGION" \
        '{APP_NAME: $name, MAIL_MAILER: $mailer, MAIL_HOST: $host, MAIL_PORT: $port,
          MAIL_SCHEME: $scheme, MAIL_FROM_ADDRESS: $address, MAIL_FROM_NAME: $from,
          AWS_DEFAULT_REGION: $region}' >"$scratch_dir/public.json"

    for target in app mysql; do
        jq -er --arg prefix "$SITEKIT_SSM_PATH/" --arg target "$target" \
            --arg database "$SITEKIT_DB_DATABASE" --arg username "$SITEKIT_DB_USERNAME" \
            --slurpfile public "$scratch_dir/public.json" \
            -f "$SCRIPT_DIR/secrets.jq" "$scratch_dir/ssm.json" >"$scratch_dir/$target.env"
    done

    chmod 0600 "$scratch_dir/app.env" "$scratch_dir/mysql.env"
    mv -f "$scratch_dir/app.env" "$SITEKIT_RUNTIME_DIR/app.env"
    mv -f "$scratch_dir/mysql.env" "$SITEKIT_RUNTIME_DIR/mysql.env"
    rm -rf -- "$scratch_dir"
    scratch_dir=''
    printf 'SiteKit secrets loaded from SSM.\n'
}

require_secrets() {
    [[ -s "$SITEKIT_RUNTIME_DIR/app.env" && -s "$SITEKIT_RUNTIME_DIR/mysql.env" ]] \
        || fail 'Runtime secrets are missing. Run the secrets command first.'
}

service_running() {
    compose ps --status running --services | grep -Fxq "$1"
}

start_stack() {
    require_secrets
    compose stop queue scheduler app
    compose up -d --no-build --wait --wait-timeout 180 mysql
    compose run --rm --no-deps app php artisan migrate --force --no-ansi
    compose up -d --no-build --no-deps --force-recreate --wait --wait-timeout 180 app queue scheduler caddy
}

deploy() {
    load_secrets
    compose build app caddy
    if service_running app; then
        compose exec -T app php artisan down --retry=60 --no-ansi
    fi
    start_stack
    compose exec -T app php artisan up --no-ansi
}

key_fingerprint() {
    sed -n 's/^APP_KEY=//p' "$SITEKIT_RUNTIME_DIR/app.env" | sha256sum | cut -d ' ' -f 1
}

require_backup_tools() {
    for tool in gzip tar sha256sum; do
        command -v "$tool" >/dev/null || fail "Missing backup tool: $tool"
    done
    [[ "$SITEKIT_BACKUP_BUCKET" != replace-with-your-backup-bucket ]] || fail 'Set the real backup bucket name.'
}

resume_backup() {
    compose up -d --no-build --no-deps --wait --wait-timeout 180 "${resume_services[@]}" || return 1
    if [[ "$clear_maintenance" == true ]]; then
        compose exec -T app php artisan up --no-ansi || return 1
    fi
    backup_recovery=false
}

backup() {
    require_secrets
    require_backup_tools
    service_running app || fail 'The application must be running to take a backup.'
    service_running mysql || fail 'MySQL must be running to take a backup.'

    local running_services created_at backup_id backup_url database_hash storage_hash
    running_services=$(compose ps --status running --services)
    for service in app queue scheduler; do
        if grep -Fxq "$service" <<<"$running_services"; then
            resume_services+=("$service")
        fi
    done
    [[ -f "$SITEKIT_DATA_DIR/storage/framework/down" ]] || clear_maintenance=true
    backup_recovery=true

    compose exec -T app php artisan down --retry=60 --no-ansi
    compose stop queue scheduler app

    install -d -m 0700 "$SITEKIT_DATA_DIR/backup-tmp"
    scratch_dir=$(mktemp -d "$SITEKIT_DATA_DIR/backup-tmp/backup.XXXXXX")
    created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    backup_id="$(date -u +%Y%m%dT%H%M%SZ)-${scratch_dir##*.}"
    backup_url="s3://$SITEKIT_BACKUP_BUCKET/${SITEKIT_BACKUP_PREFIX%/}/$backup_id"

    compose exec -T mysql sh -c \
        'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump -u root --single-transaction --quick --no-tablespaces --routines --events --triggers --set-gtid-purged=OFF --databases "$MYSQL_DATABASE"' \
        | gzip >"$scratch_dir/database.sql.gz"
    tar -czf "$scratch_dir/storage.tar.gz" --exclude=./framework/down \
        --exclude=./framework/cache --exclude=./framework/views --exclude=./framework/sessions \
        -C "$SITEKIT_DATA_DIR/storage" .

    database_hash=$(sha256sum "$scratch_dir/database.sql.gz" | cut -d ' ' -f 1)
    storage_hash=$(sha256sum "$scratch_dir/storage.tar.gz" | cut -d ' ' -f 1)
    jq -n --arg database "$SITEKIT_DB_DATABASE" --arg created "$created_at" \
        --arg path "$SITEKIT_SSM_PATH" --arg key "$(key_fingerprint)" \
        --arg database_hash "$database_hash" --arg storage_hash "$storage_hash" \
        '{version: 1, database: $database, created_at: $created, ssm_path: $path,
          app_key_sha256: $key, sha256: {"database.sql.gz": $database_hash, "storage.tar.gz": $storage_hash}}' \
        >"$scratch_dir/manifest.json"

    for artifact in database.sql.gz storage.tar.gz manifest.json; do
        aws s3 cp "$scratch_dir/$artifact" "$backup_url/$artifact" \
            --region "$AWS_REGION" --sse AES256 --only-show-errors
    done

    resume_backup
    printf 'SiteKit backup complete: %s\n' "$backup_url"
}

restore() {
    local backup_url=${1%/}
    local database_hash storage_hash staged_storage previous_storage
    [[ "$backup_url" == "s3://$SITEKIT_BACKUP_BUCKET/${SITEKIT_BACKUP_PREFIX%/}/"* ]] \
        || fail 'Restore only from the configured control-plane backup prefix.'
    require_backup_tools
    load_secrets
    install -d -m 0700 "$SITEKIT_DATA_DIR/backup-tmp"
    scratch_dir=$(mktemp -d "$SITEKIT_DATA_DIR/backup-tmp/restore.XXXXXX")

    for artifact in manifest.json database.sql.gz storage.tar.gz; do
        aws s3 cp "$backup_url/$artifact" "$scratch_dir/$artifact" \
            --region "$AWS_REGION" --only-show-errors
    done
    jq -e --arg database "$SITEKIT_DB_DATABASE" --arg key "$(key_fingerprint)" \
        '.version == 1 and .database == $database and .app_key_sha256 == $key' \
        "$scratch_dir/manifest.json" >/dev/null \
        || fail 'Backup version, database name, or persistent APP_KEY does not match.'

    database_hash=$(jq -er '.sha256["database.sql.gz"] | select(test("^[a-f0-9]{64}$"))' "$scratch_dir/manifest.json")
    storage_hash=$(jq -er '.sha256["storage.tar.gz"] | select(test("^[a-f0-9]{64}$"))' "$scratch_dir/manifest.json")
    [[ "$(sha256sum "$scratch_dir/database.sql.gz" | cut -d ' ' -f 1)" == "$database_hash" \
        && "$(sha256sum "$scratch_dir/storage.tar.gz" | cut -d ' ' -f 1)" == "$storage_hash" ]] \
        || fail 'Backup checksum validation failed; the running application was not changed.'
    gzip -t "$scratch_dir/database.sql.gz"
    tar -tzf "$scratch_dir/storage.tar.gz" \
        | awk '/(^\/|(^|\/)\.\.(\/|$))/ { exit 1 }' \
        || fail 'Unsafe path in storage archive.'
    tar -tvzf "$scratch_dir/storage.tar.gz" \
        | awk 'substr($0, 1, 1) != "-" && substr($0, 1, 1) != "d" { exit 1 }' \
        || fail 'Storage archives may contain only regular files and directories.'

    staged_storage="$scratch_dir/restored-storage"
    install -d -m 0700 "$staged_storage"
    tar -xzf "$scratch_dir/storage.tar.gz" --no-same-owner --no-same-permissions -C "$staged_storage"

    if service_running app; then
        compose exec -T app php artisan down --retry=60 --no-ansi
    fi
    compose stop caddy queue scheduler app
    compose up -d --no-build --wait --wait-timeout 180 mysql
    compose exec -T mysql sh -c \
        'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -u root -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
    gzip -dc "$scratch_dir/database.sql.gz" \
        | compose exec -T mysql sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -u root'

    previous_storage="$SITEKIT_DATA_DIR/storage.before-restore-$(date -u +%Y%m%dT%H%M%SZ)-${scratch_dir##*.}"
    mv "$SITEKIT_DATA_DIR/storage" "$previous_storage"
    mv "$staged_storage" "$SITEKIT_DATA_DIR/storage"
    chown -R 33:33 "$SITEKIT_DATA_DIR/storage"
    prepare_storage
    compose run --rm --no-deps app php artisan down --retry=60 --no-ansi
    start_stack
    compose exec -T app php artisan up --no-ansi
    printf 'SiteKit restored. Previous storage retained at: %s\n' "$previous_storage"
}

install_units() {
    for path in "$PROJECT_DIR" "$HOST_CONFIG" "$SITEKIT_DATA_DIR"; do
        [[ "$path" != *[!a-zA-Z0-9_./-]* ]] || fail 'Systemd paths may not contain spaces or special characters.'
    done

    install -d -m 0755 /etc/systemd/system/docker.service.d
    for unit in sitekit-secrets.service sitekit.service sitekit-backup.service sitekit-backup.timer; do
        sed -e "s|@PROJECT_DIR@|$PROJECT_DIR|g" \
            -e "s|@HOST_CONFIG@|$HOST_CONFIG|g" \
            -e "s|@DATA_DIR@|$SITEKIT_DATA_DIR|g" \
            "$SCRIPT_DIR/systemd/$unit" >"/etc/systemd/system/$unit"
        chmod 0644 "/etc/systemd/system/$unit"
    done
    sed -e "s|@DATA_DIR@|$SITEKIT_DATA_DIR|g" "$SCRIPT_DIR/systemd/docker.conf" \
        >/etc/systemd/system/docker.service.d/sitekit.conf
    chmod 0644 /etc/systemd/system/docker.service.d/sitekit.conf

    systemctl daemon-reload
    systemctl enable docker.service sitekit.service sitekit-backup.timer
    printf 'SiteKit boot and backup units installed. Deploy the images, then start the units.\n'
}

case "${1:-}" in
    secrets) load_secrets ;;
    start) load_secrets; start_stack ;;
    deploy) deploy ;;
    stop) require_secrets; compose stop ;;
    backup) backup ;;
    restore)
        [[ $# == 3 && "$3" == --confirm ]] || fail 'Usage: sitekit.sh restore s3://bucket/prefix/backup-id --confirm (replaces the database and storage)'
        restore "$2"
        ;;
    install) install_units ;;
    *) fail 'Usage: sitekit.sh {secrets|start|deploy|stop|backup|restore|install}' ;;
esac