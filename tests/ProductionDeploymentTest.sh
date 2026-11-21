#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
TEST_DIR=$(mktemp -d)
COMPOSE_PROJECT_NAME="sitekit-production-test-$$"
SITEKIT_HOST_CONFIG="$TEST_DIR/host.env"
SITEKIT_TEST_OVERRIDE_FILE="$TEST_DIR/compose.override.yml"
SITEKIT_TEST_OBJECT_STORE="$TEST_DIR/objects"
APP_IMAGE="sitekit-app:$COMPOSE_PROJECT_NAME"
CADDY_IMAGE="sitekit-caddy:$COMPOSE_PROJECT_NAME"
SCRIPT="$PROJECT_DIR/deploy/production/sitekit.sh"
subnet_octet=$(( $$ % 200 + 20 ))

export COMPOSE_PROJECT_NAME SITEKIT_HOST_CONFIG SITEKIT_TEST_OVERRIDE_FILE SITEKIT_TEST_OBJECT_STORE

mkdir -p "$TEST_DIR/data" "$SITEKIT_TEST_OBJECT_STORE"
chmod 0755 "$TEST_DIR" "$TEST_DIR/data"
printf '%s\n' \
    'AWS_REGION=us-east-1' 'SITEKIT_SSM_PATH=/sitekit/production' \
    'SITEKIT_DOMAIN=localhost' 'APP_URL=https://localhost' \
    "SITEKIT_DATA_DIR=$TEST_DIR/data" "SITEKIT_RUNTIME_DIR=$TEST_DIR/runtime" \
    'SITEKIT_BACKUP_BUCKET=sitekit-test-bucket' 'SITEKIT_BACKUP_PREFIX=control-plane' \
    "SITEKIT_PROXY_SUBNET=172.29.$subnet_octet.0/24" "SITEKIT_PROXY_IP=172.29.$subnet_octet.2" \
    "SITEKIT_CONTAINER_IP_RANGE=172.29.$subnet_octet.128/25" \
    "SITEKIT_APP_IMAGE=$APP_IMAGE" "SITEKIT_CADDY_IMAGE=$CADDY_IMAGE" \
    "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" 'MAIL_MAILER=log' >"$SITEKIT_HOST_CONFIG"
printf '%s\n' 'services:' '    caddy:' '        ports: !override' \
    "            - '127.0.0.1::443'" >"$SITEKIT_TEST_OVERRIDE_FILE"

id() {
    printf '0\n'
}

uname() {
    printf 'Linux\n'
}

mountpoint() {
    [[ ${SITEKIT_TEST_MOUNTED:-1} == 1 ]]
}

flock() {
    return 0
}

install() {
    local arguments=() path
    while (( $# )); do
        case "$1" in
            -o|-g) shift 2 ;;
            *) arguments+=("$1"); shift ;;
        esac
    done
    command install "${arguments[@]}"
    for path in "${arguments[@]}"; do
        if [[ "$path" == "$TEST_DIR/data/storage"* ]]; then
            chmod 0777 "$path"
        fi
    done
}

chown() {
    chmod -R a+rwX "${@: -1}"
}

aws() {
    [[ ${SITEKIT_TEST_AWS_FAILURE:-0} == 0 ]] || return 1
    if [[ "$1 $2" == 'ssm get-parameters-by-path' ]]; then
        jq -n --arg problem "${SITEKIT_TEST_SECRET_ERROR:-}" --arg wrong_key "${SITEKIT_TEST_WRONG_KEY:-0}" '
            {Parameters: [
                {Name: "/sitekit/production/APP_KEY", Value: ("base64:" + ((if $wrong_key == "1" then "b" else "a" end) * 32 | @base64))},
                {Name: "/sitekit/production/DB_PASSWORD", Value: "db-fixture-password-$#!!"},
                {Name: "/sitekit/production/MYSQL_ROOT_PASSWORD", Value: "root-fixture-password-456789"},
                {Name: "/sitekit/production/GITHUB_CLIENT_SECRET", Value: "literal-$VALUE-#-quotes-\""}
            ]}
            | if $problem == "missing" then .Parameters |= map(select(.Name | endswith("/APP_KEY") | not))
              elif $problem == "default" then .Parameters |= map(if .Name | endswith("/DB_PASSWORD") then .Value = "password" else . end)
              elif $problem == "multiline" then .Parameters[1].Value += "\nINJECTED=value"
              elif $problem == "db-quoting" then .Parameters[1].Value += "\u0027\\"
              elif $problem == "nested" then .Parameters += [{Name: "/sitekit/production/nested/SECRET", Value: "value"}]
              elif $problem == "duplicate" then .Parameters += [.Parameters[0]]
              else . end'
        return
    fi
    [[ "$1 $2" == 's3 cp' ]] || return 1
    local source=$3 destination=$4
    if [[ "$destination" == s3://* ]]; then
        [[ ${SITEKIT_TEST_UPLOAD_FAILURE:-0} == 0 ]] || return 1
        [[ " $* " == *' --sse AES256 '* ]] || return 1
        destination="$SITEKIT_TEST_OBJECT_STORE/${destination#s3://}"
        mkdir -p "$(dirname -- "$destination")"
    else
        source="$SITEKIT_TEST_OBJECT_STORE/${source#s3://}"
    fi
    command cp "$source" "$destination"
}

docker() {
    if [[ "$1" != compose ]]; then
        command docker "$@"
        return
    fi
    shift
    local arguments=()
    while (( $# )); do
        case "$1" in
            ps|stop|up|run|exec|build|down|port|restart|logs) break ;;
            *) arguments+=("$1"); shift ;;
        esac
    done
    command docker compose "${arguments[@]}" -f "$SITEKIT_TEST_OVERRIDE_FILE" "$@"
}

if ! command -v sha256sum >/dev/null; then
    sha256sum() {
        shasum -a 256 "$@"
    }
    export -f sha256sum
fi

export TEST_DIR
export -f id uname mountpoint flock install chown aws docker

compose() {
    docker compose --project-directory "$PROJECT_DIR" --env-file "$SITEKIT_HOST_CONFIG" \
        -f "$PROJECT_DIR/docker-compose.production.yml" "$@"
}

cleanup() {
    local exit_code=$?
    trap - EXIT
    if (( exit_code != 0 )) && [[ -s "$TEST_DIR/runtime/app.env" ]]; then
        compose logs --tail 40 mysql app queue scheduler caddy >&2 || true
    fi
    if [[ -s "$TEST_DIR/runtime/app.env" ]]; then
        compose down --remove-orphans >/dev/null 2>&1 || true
    fi
    if command docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
        command docker run --rm --user 0 --entrypoint /usr/bin/find \
            --volume "$TEST_DIR:/test" "$APP_IMAGE" /test -mindepth 1 -delete >/dev/null 2>&1 || true
    fi
    rm -rf -- "$TEST_DIR"
    exit "$exit_code"
}

trap cleanup EXIT
trap 'printf "FAIL: line %s: %s\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

expect_failure() {
    if "$@" >"$TEST_DIR/expected-failure.log" 2>&1; then
        printf 'Expected failure: %s\n' "$*" >&2
        exit 1
    fi
}

expect_failure env SITEKIT_TEST_MOUNTED=0 bash "$SCRIPT" secrets
[[ ! -e "$TEST_DIR/runtime/app.env" ]]
bash "$SCRIPT" secrets
! grep -q '^MYSQL_ROOT_PASSWORD=' "$TEST_DIR/runtime/app.env"
grep -Fqx 'GITHUB_CLIENT_SECRET=literal-$VALUE-#-quotes-"' "$TEST_DIR/runtime/app.env"
secret_hash=$(sha256sum "$TEST_DIR/runtime/app.env" | cut -d ' ' -f 1)
for problem in missing default multiline nested duplicate db-quoting; do
    expect_failure env "SITEKIT_TEST_SECRET_ERROR=$problem" bash "$SCRIPT" secrets
    [[ "$(sha256sum "$TEST_DIR/runtime/app.env" | cut -d ' ' -f 1)" == "$secret_hash" ]]
done
expect_failure env SITEKIT_TEST_AWS_FAILURE=1 bash "$SCRIPT" secrets
printf 'PASS: mount guard and fail-closed SSM rendering\n'

bash "$SCRIPT" deploy
base_url="https://localhost:$(compose port caddy 443 | sed 's/.*://')"
curl --insecure --fail --silent --show-error --retry 5 --retry-all-errors "$base_url/up" >/dev/null
curl --insecure --silent --show-error --dump-header "$TEST_DIR/headers" "$base_url/" >/dev/null
! grep -Ei '^location: http://' "$TEST_DIR/headers"
[[ $(curl --insecure --silent --output /dev/null --write-out '%{http_code}' "$base_url/.env") == 404 ]]
printf '<?php echo "must-not-run";' >"$TEST_DIR/data/storage/app/public/probe.php"
[[ $(curl --insecure --silent --output /dev/null --write-out '%{http_code}' "$base_url/storage/probe.php") == 404 ]]
printf 'stored-before-backup\n' >"$TEST_DIR/data/storage/app/public/probe.txt"
[[ $(curl --insecure --fail --silent "$base_url/storage/probe.txt") == stored-before-backup ]]
printf 'PASS: TLS routing, assets, database migrations, and private service ports\n'

compose exec -T mysql sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -u root "$MYSQL_DATABASE" -e "CREATE TABLE backup_probe (value VARCHAR(64)); INSERT INTO backup_probe VALUES (\"before-backup\");"'
expect_failure env SITEKIT_TEST_UPLOAD_FAILURE=1 bash "$SCRIPT" backup
[[ ! -e "$TEST_DIR/data/storage/framework/down" ]]
[[ $(compose ps --status running --services | wc -l | tr -d ' ') == 5 ]]
curl --insecure --fail --silent "$base_url/up" >/dev/null
printf 'PASS: failed S3 upload resumes application writers\n'

backup_output=$(bash "$SCRIPT" backup)
backup_url=$(printf '%s\n' "$backup_output" | sed -n 's/^SiteKit backup complete: //p')
[[ -n "$backup_url" ]]
object_dir="$SITEKIT_TEST_OBJECT_STORE/${backup_url#s3://}"
[[ -f "$object_dir/manifest.json" ]]
compose exec -T mysql sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -u root "$MYSQL_DATABASE" -e "UPDATE backup_probe SET value = \"after-backup\";"'
printf 'stored-after-backup\n' >"$TEST_DIR/data/storage/app/public/probe.txt"

expect_failure bash "$SCRIPT" restore "$backup_url"
expect_failure env SITEKIT_TEST_WRONG_KEY=1 bash "$SCRIPT" restore "$backup_url" --confirm
cp "$object_dir/database.sql.gz" "$TEST_DIR/original-dump.gz"
printf 'corrupt' >>"$object_dir/database.sql.gz"
expect_failure bash "$SCRIPT" restore "$backup_url" --confirm
[[ $(compose ps --status running --services | wc -l | tr -d ' ') == 5 ]]
cp "$TEST_DIR/original-dump.gz" "$object_dir/database.sql.gz"
printf 'PASS: restore confirmation, APP_KEY identity, and checksum guards\n'

bash "$SCRIPT" restore "$backup_url" --confirm
restored_value=$(compose exec -T mysql sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -u root -N "$MYSQL_DATABASE" -e "SELECT value FROM backup_probe;"')
[[ "$restored_value" == before-backup ]]
[[ $(<"$TEST_DIR/data/storage/app/public/probe.txt") == stored-before-backup ]]
base_url="https://localhost:$(compose port caddy 443 | sed 's/.*://')"
curl --insecure --fail --silent --show-error --retry 5 --retry-all-errors "$base_url/up" >/dev/null
compose restart
compose up -d --no-build --wait --wait-timeout 180
base_url="https://localhost:$(compose port caddy 443 | sed 's/.*://')"
curl --insecure --fail --silent --show-error --retry 5 --retry-all-errors "$base_url/up" >/dev/null
printf 'PASS: real MySQL and storage restore, followed by container restart\n'