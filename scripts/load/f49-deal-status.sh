#!/usr/bin/env bash
# F-49 · 1.4b (D-119): 50 at once on one deal, three times, then a read-only
# database check. Local only: not committed, not pushed (the owner's ruling).
#
#   TOKEN=<crm.auth.token.v1 from the browser's localStorage> \
#   CUSTOMER_ID=<a test customer> SQ_ITEM_ID=<a priced supplier-offer item> \
#   scripts/load/f49-deal-status.sh
#
# Writes to the dev DB: one deal, 50 quotations, one PO, their audit lines.
# ponytail: the create step uses curl ×50 (each needs its own Idempotency-Key,
# which ab cannot vary); Accept and «شراء» use ab.
set -euo pipefail
: "${TOKEN:?} ${CUSTOMER_ID:?} ${SQ_ITEM_ID:?}"
BASE=${BASE:-http://localhost:8765}/api/v1
N=50
TMP=$(mktemp -d)
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")

call() { # method path [body] [if-match] → prints the body, fails on non-2xx
  local extra=(); [[ -n ${4:-} ]] && extra=(-H "If-Match: $4")
  [[ -n ${3:-} ]] && extra+=(-d "$3")
  curl -sS --fail-with-body -X "$1" "${H[@]}" ${extra[@]+"${extra[@]}"} "$BASE$2"
}
codes() { sort | uniq -c | sed 's/^/    /'; }
# -p before -m: ab refuses "-m PATCH -p" ("Cannot mix POST with other methods").
# ab -v 2 prints each response's status line; that is how a 500 is told from a 409.
ab_run() { # method path bodyfile [if-match]
  local extra=(); [[ -n ${4:-} ]] && extra=(-H "If-Match: $4")
  ab -n $N -c $N -l -v 2 -p "$3" -m "$1" -T application/json \
     -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" ${extra[@]+"${extra[@]}"} "$BASE$2" \
     > "$TMP/ab.out" 2>&1 || true
  grep -E "^(Complete|Failed) requests|^Non-2xx" "$TMP/ab.out" | sed 's/^/    /' || true
  echo "    status lines:"; grep -oE "^HTTP/1\.[01] [0-9]{3}" "$TMP/ab.out" | codes || true
}

echo "0. a fresh deal (D-119: it starts at contacted)"
DEAL=$(call POST /deals "{\"customer_id\":\"$CUSTOMER_ID\",\"title\":\"F-49 load test\"}" | jq -r .data.id)
echo "    deal $DEAL"

echo "1. $N quotation creates at once (each its own Idempotency-Key)"
BODY="{\"deal_id\":\"$DEAL\",\"customer_id\":\"$CUSTOMER_ID\",\"currency\":\"EGP\",\"default_margin\":\"10\",\"discount_percent\":\"0\",\"lines\":[{\"supplier_quotation_item_id\":\"$SQ_ITEM_ID\",\"quantity\":\"1\"}]}"
seq $N | xargs -P $N -I{} sh -c 'curl -sS -o "$0/q{}.json" -w "%{http_code}\n" -X POST \
  -H "Authorization: Bearer $1" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" -d "$2" "$3/quotations"' "$TMP" "$TOKEN" "$BODY" "$BASE" | codes

Q=$(jq -r 'select(.data.id) | .data.id' "$TMP"/q*.json | head -1)
echo "2. submit, approve, send one quotation ($Q), one request each"
ETAG=$(call GET "/quotations/$Q" | jq -r .data.etag)
ETAG=$(call PATCH "/quotations/$Q/submit-for-approval" "" "$ETAG" | jq -r .data.etag)
ETAG=$(call PATCH "/quotations/$Q/approve" "" "$ETAG" | jq -r .data.etag)
ETAG=$(call PATCH "/quotations/$Q/send" "" "$ETAG" | jq -r .data.etag)

echo "3. $N Accepts at once, same If-Match"
echo "{\"response\":\"accepted\",\"customer_po_reference\":\"F49-LOAD\",\"po_date\":\"$(date -u +%F)\"}" > "$TMP/accept.json"
ab_run PATCH "/quotations/$Q/respond" "$TMP/accept.json" "$ETAG"

echo "4. $N manual «شراء» at once"
echo '{"status":"purchasing"}' > "$TMP/status.json"
ab_run PATCH "/deals/$DEAL/status" "$TMP/status.json"

echo "5. the database (read-only)"
docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -c \"
select old_values->>'status' as from_status, new_values->>'status' as to_status, count(*) as lines
  from audit_log where event = 'DEAL_STATUS_CHANGED' and entity_id = '$DEAL'
  group by 1, 2 order by min(created_at);
select d.code, d.status as deal_status, c.customer_status
  from deals d join customers c on c.id = d.customer_id where d.id = '$DEAL';
select (select count(*) from audit_log where event = 'QUOTATION_ACCEPTED' and entity_id = '$Q') as accepted_lines,
       (select count(*) from purchase_orders where quotation_id = '$Q') as purchase_orders;\""
echo "Expected: every status pair once; deal purchasing; customer_status customer; accepted_lines 1; purchase_orders 1."
rm -rf "$TMP"
