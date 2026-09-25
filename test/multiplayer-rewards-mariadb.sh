#!/usr/bin/env bash
set -euo pipefail

container="pimpopom-mp29-rewards-mariadb-$$"
cleanup() { docker rm -f -v "$container" >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM
command -v docker >/dev/null 2>&1 || { echo 'Docker is required.' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Docker daemon is required.' >&2; exit 1; }
docker run -d --name "$container" -e MARIADB_ROOT_PASSWORD=root \
    -e MARIADB_DATABASE=speedytapper_mp29_rewards -p 127.0.0.1::3306 mariadb:11.4 >/dev/null
for _ in $(seq 1 40); do
    if docker exec "$container" mariadb -h127.0.0.1 -uroot -proot -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$container" mariadb -h127.0.0.1 -uroot -proot -e 'SELECT 1' >/dev/null
port="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}' "$container")"
SPEEDYTAPPER_MP29_DSN="mysql:host=127.0.0.1;port=${port};dbname=speedytapper_mp29_rewards;charset=utf8mb4" \
    php "$(dirname "$0")/multiplayer-rewards-persistence.test.php"
