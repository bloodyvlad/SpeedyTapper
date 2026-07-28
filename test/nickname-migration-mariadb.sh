#!/usr/bin/env bash
set -euo pipefail

container="pimpopom-nickname-mariadb-$$"
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || {
    echo "Docker is required for the MariaDB nickname migration test." >&2
    exit 1
}
docker info >/dev/null 2>&1 || {
    echo "Docker is installed but its daemon is not running." >&2
    exit 1
}

docker run --rm -d \
    --name "$container" \
    -e MARIADB_ROOT_PASSWORD=root \
    -e MARIADB_DATABASE=speedytapper \
    -p 127.0.0.1::3306 \
    mariadb:11.4 >/dev/null

for _ in $(seq 1 60); do
    if docker exec "$container" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$container" mariadb-admin ping -uroot -proot --silent >/dev/null

port="$(
    docker inspect \
        --format '{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}' \
        "$container"
)"
SPEEDYTAPPER_TEST_MARIADB_DSN="mysql:host=127.0.0.1;port=${port};dbname=speedytapper;charset=utf8mb4" \
SPEEDYTAPPER_TEST_MARIADB_USER=root \
SPEEDYTAPPER_TEST_MARIADB_PASSWORD=root \
php "$(dirname "$0")/nickname-migration-mariadb.php"
