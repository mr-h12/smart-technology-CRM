#!/usr/bin/env bash
# Cold-boot verification for the local stack.
#
# ST-09 asks for a written startup checklist "executed and recorded after each
# boot", and ST-04 for a health check after startup "with the result logged".
# A checklist a human reads and ticks is neither, so this is the checklist as a
# script: it runs the boot, asserts each requirement it can, and appends a dated
# record to runbooks/boot-log.md.
#
# It asserts only what a container stack can actually prove. Everything it
# cannot — the host starting services at power-on, missed-job catch-up, the
# application's /health — is listed at the end as still owed, so a green run is
# never mistaken for ST-01…ST-09 being satisfied.
#
#   ./scripts/verify-boot.sh          verify a cold boot
#   ./scripts/verify-boot.sh --keep   leave the stack running afterwards
set -uo pipefail
cd "$(dirname "$0")/.."

PASS=0 FAIL=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
note() { printf '  \033[33m•\033[0m %s\n' "$1"; }

ALL_PROFILES="--profile search --profile workers --profile verify"

echo "== tearing down completely =="
# Every profile named: `docker compose down` ignores services whose profile is
# inactive, and a survivor looks exactly like a freshly started container.
docker compose $ALL_PROFILES down --remove-orphans >/dev/null 2>&1
[ "$(docker ps -aq --filter label=com.docker.compose.project=crm | wc -l | tr -d ' ')" = "0" ] \
  && ok "no containers survived teardown" \
  || bad "containers survived teardown"

echo
echo "== ST-02 / ST-03: cold boot, ordered, gated on health =="
BOOT_LOG=$(mktemp)
docker compose --profile verify up --abort-on-container-exit \
  --exit-code-from boot-order-probe boot-order-probe >"$BOOT_LOG" 2>&1
PROBE_RC=$?

# ST-02 order: each dependency must report Healthy before the dependant starts.
if grep -q "crm-redis.*Healthy" "$BOOT_LOG" && grep -q "crm-postgres.*Healthy" "$BOOT_LOG"; then
    ok "ST-03 dependencies reported Healthy before the dependant started"
else
    bad "ST-03 could not confirm health gating in the boot output"
fi
if [ $PROBE_RC -eq 0 ] && grep -q "all three dependencies were healthy" "$BOOT_LOG"; then
    ok "ST-02 probe reached postgres, redis and meilisearch in order"
else
    bad "ST-02 boot-order probe failed (exit $PROBE_RC)"
fi
rm -f "$BOOT_LOG"

echo
echo "== the serving stack =="
docker compose $ALL_PROFILES down >/dev/null 2>&1
docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 40); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' crm-nginx 2>/dev/null)" = healthy ] && break
    sleep 3
done
for c in crm-postgres crm-redis crm-php crm-nginx; do
    st=$(docker inspect -f '{{.State.Health.Status}}' "$c" 2>/dev/null || echo missing)
    [ "$st" = healthy ] && ok "$c healthy" || bad "$c is $st"
done

code=$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:"${HTTPS_PORT:-8443}"/ 2>/dev/null)
[ "$code" = "200" ] && ok "HTTPS serves the application (200)" || bad "HTTPS returned $code"
rcode=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:"${HTTP_PORT:-8080}"/ 2>/dev/null)
[ "$rcode" = "301" ] && ok "SEC-14 plain HTTP redirects to HTTPS" || bad "HTTP returned $rcode"

echo
echo "== D-54 / ST-06: does state survive a restart? =="
docker compose exec -T postgres psql -U "${POSTGRES_USER:-crm}" -d "${POSTGRES_DB:-crm}" \
  -qc "create table if not exists _boot_probe(id int); truncate _boot_probe; insert into _boot_probe values (42);" >/dev/null 2>&1
docker compose exec -T redis redis-cli set _boot_probe 42 >/dev/null 2>&1

docker compose restart postgres redis >/dev/null 2>&1
sleep 10
for _ in $(seq 1 30); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' crm-postgres 2>/dev/null)" = healthy ] && break
    sleep 2
done

pg=$(docker compose exec -T postgres psql -U "${POSTGRES_USER:-crm}" -d "${POSTGRES_DB:-crm}" -tAc "select id from _boot_probe;" 2>/dev/null | tr -d '[:space:]')
[ "$pg" = "42" ] && ok "PostgreSQL data survived a restart" || bad "PostgreSQL lost data (got '$pg')"
rd=$(docker compose exec -T redis redis-cli get _boot_probe 2>/dev/null | tr -d '[:space:]')
[ "$rd" = "42" ] && ok "Redis data survived a restart (AOF)" || bad "Redis lost data (got '$rd')"
docker compose exec -T postgres psql -U "${POSTGRES_USER:-crm}" -d "${POSTGRES_DB:-crm}" -qc "drop table _boot_probe;" >/dev/null 2>&1
docker compose exec -T redis redis-cli del _boot_probe >/dev/null 2>&1

echo
echo "== still owed, and not asserted above =="
note "ST-01 services enabled at host power-on — needs the real server"
note "ST-04 the health result logged by the host, not by this script"
note "ST-05 missed-job catch-up (D-55) — needs the application scheduler"
note "ST-07 no half-written data — application transactions, not infrastructure"
note "ST-08 /health reporting each service — belongs to the application"

echo
STAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
RESULT=$([ $FAIL -eq 0 ] && echo PASS || echo FAIL)
printf '%s  %s  %d passed, %d failed  (%s)\n' \
  "$STAMP" "$RESULT" "$PASS" "$FAIL" "$(uname -m)" >> runbooks/boot-log.md
echo "== $RESULT — $PASS passed, $FAIL failed; recorded in runbooks/boot-log.md =="

if [ "${1:-}" != "--keep" ]; then
    docker compose $ALL_PROFILES down >/dev/null 2>&1
fi
exit $FAIL
