#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CONTAINER="pimpopom-reset-mariadb-test-$$"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || {
    printf '%s\n' 'Docker is required for the disposable MariaDB reset test.' >&2
    exit 1
}

docker run --rm -d \
    --name "$CONTAINER" \
    -e MARIADB_ROOT_PASSWORD=root \
    -e MARIADB_DATABASE=speedytapper \
    mariadb:11.4 \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci >/dev/null

ready=0
attempt=0
while [ "$attempt" -lt 30 ]; do
    if docker exec "$CONTAINER" mariadb -uroot -proot \
        --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1
    then
        ready=1
        break
    fi
    attempt=$((attempt + 1))
    sleep 1
done
test "$ready" -eq 1

for migration in "$ROOT"/server/migrations/0[0-1][0-9]_*.sql; do
    docker exec -i "$CONTAINER" mariadb -uroot -proot speedytapper < "$migration"
done

docker exec -i "$CONTAINER" mariadb -uroot -proot speedytapper \
    < "$ROOT/test/fixtures/internal-alpha-reset-seed.sql"
docker exec -i "$CONTAINER" mariadb -uroot -proot speedytapper \
    < "$ROOT/server/migrations/020_reset_internal_alpha_player_data.sql"

query() {
    docker exec "$CONTAINER" mariadb -uroot -proot \
        --batch --skip-column-names speedytapper -e "$1"
}

live_tables='players player_identities player_sessions player_roles
leaderboard_entries completed_runs run_attempts run_proofs run_trace_claims
leaderboard_moderation_events account_reward_resets coin_ledger
player_achievements player_pets player_pet_selection player_themes
player_theme_selection player_storekit_bindings player_game_center_bindings
game_center_publication_outbox game_center_assertion_uses'
for table in $live_tables; do
    test "$(query "SELECT COUNT(*) FROM $table")" = "0"
done

test "$(query 'SELECT COUNT(*) FROM storekit_transactions')" = "2"
test "$(query 'SELECT COUNT(*) FROM storekit_transactions WHERE player_id IS NULL AND app_transaction_id IS NULL AND account_deleted_at IS NOT NULL')" = "2"
test "$(query 'SELECT COUNT(*) FROM purchased_coin_lots WHERE player_id IS NULL')" = "1"
test "$(query 'SELECT COUNT(*) FROM player_entitlement_sources WHERE player_id IS NULL')" = "1"
test "$(query "SELECT COUNT(*) FROM coin_spend_allocations WHERE source = 'earned'")" = "0"
test "$(query "SELECT COUNT(*) FROM coin_spend_allocations WHERE source = 'purchased' AND player_id IS NULL AND spend_event_id IS NULL AND spend_reference_pseudonym = UNHEX(REPEAT('66', 32))")" = "1"
test "$(query "SELECT COUNT(*) FROM storekit_refund_debt_allocations WHERE player_id IS NULL AND source_reference = REPEAT('66', 32)")" = "1"
test "$(query 'SELECT COUNT(*) FROM player_storekit_family_bindings WHERE player_id IS NULL AND account_deleted_at IS NOT NULL')" = "1"
test "$(query 'SELECT COUNT(*) FROM storekit_transaction_observations')" = "1"
test "$(query 'SELECT COUNT(*) FROM storekit_notifications')" = "1"
test "$(query "SELECT COUNT(*) FROM storekit_reconciliation_state WHERE environment = 'Sandbox' AND last_transaction_cursor = 'cursor-1'")" = "1"
test "$(query "SELECT COUNT(*) FROM migration_data_markers WHERE marker = '020-internal-alpha-player-data-reset-20260727-v1'")" = "1"
test "$(query 'SELECT COUNT(*) FROM pets')" = "5"
test "$(query 'SELECT COUNT(*) FROM themes')" = "4"
test "$(query 'SELECT COUNT(*) FROM seasons')" = "1"

query "
    INSERT INTO players (id, nickname, nickname_confirmed)
    VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'AfterReset', 1)
" >/dev/null
docker exec -i "$CONTAINER" mariadb -uroot -proot speedytapper \
    < "$ROOT/server/migrations/020_reset_internal_alpha_player_data.sql"
test "$(query "SELECT COUNT(*) FROM players WHERE id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'")" = "1"

printf '%s\n' 'Internal-alpha MariaDB reset integration test passed.'
