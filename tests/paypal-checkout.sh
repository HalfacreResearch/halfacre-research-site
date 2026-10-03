#!/usr/bin/env bash
# PayPal checkout + download checks. Mocks PayPal. Never calls the live API.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-paypal.XXXXXX)"
PORT="${HALFACRE_PAYPAL_TEST_PORT:-8766}"
HOST="127.0.0.1"
BASE="http://${HOST}:${PORT}"
PASS=0
FAIL=0
LOG="${WORKDIR}/results.txt"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

note() {
  echo "$1" | tee -a "$LOG"
}

expect_code() {
  local name="$1"
  local want="$2"
  local got="$3"
  if [[ "$got" == "$want" ]]; then
    PASS=$((PASS + 1))
    note "PASS  ${name} (HTTP ${got})"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (wanted HTTP ${want}, got ${got})"
    note "      body: $(cat "${WORKDIR}/body.txt")"
  fi
}

expect_body() {
  local name="$1"
  local needle="$2"
  if grep -q -- "$needle" "${WORKDIR}/body.txt"; then
    PASS=$((PASS + 1))
    note "PASS  ${name}"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (missing ${needle})"
    note "      body: $(cat "${WORKDIR}/body.txt")"
  fi
}

mkdir -p "${WORKDIR}/private"
cat > "${WORKDIR}/clients.store.json" <<'JSON'
[
  {
    "id": "abcdef1234567890",
    "number": 2,
    "name": "Test Client",
    "email": "test-client@example.invalid",
    "phone": "000"
  }
]
JSON
printf '{}\n' > "${WORKDIR}/memory.store.json"
printf '{}\n' > "${WORKDIR}/paypal-captures.store.json"
printf '{}\n' > "${WORKDIR}/paypal-events.store.json"
printf '{}\n' > "${WORKDIR}/paypal-mock.json"

HEX_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("abcdef1234567890");
  '
)"
BAD_TOKEN="ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"

# --- fail closed: no secret file ---
export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_PAYPAL_SECRET="${WORKDIR}/missing-paypal.secret.php"
export HALFACRE_PAYPAL_CAPTURES="${WORKDIR}/paypal-captures.store.json"
export HALFACRE_PAYPAL_LOG="${WORKDIR}/paypal-events.store.json"
export HALFACRE_PAYPAL_MOCK_STORE="${WORKDIR}/paypal-mock.json"
export HALFACRE_PAYPAL_HTTP=mock
export HALFACRE_MAIL_LOG="${WORKDIR}/mail.log"

php -S "${HOST}:${PORT}" -t "$ROOT" >"${WORKDIR}/php-server.log" 2>&1 &
SERVER_PID=$!
sleep 0.4
if ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo "php -S failed to start" >&2
  cat "${WORKDIR}/php-server.log" >&2
  exit 1
fi

curl_code() {
  curl -sS -o "${WORKDIR}/body.txt" -w "%{http_code}" "$@"
}

code="$(curl_code "${BASE}/paypal-status.php")"
expect_code "status without config" 200 "$code"
expect_body "fail-closed status copy" "checkout not available yet"

code="$(curl_code "${BASE}/pay.html")"
expect_code "pay.html is present" 200 "$code"
expect_body "pay.html fail-closed copy" "checkout not available yet"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"sku\":\"macro-indicators-research\"}" \
  "${BASE}/paypal-order.php")"
expect_code "create order without config" 503 "$code"
expect_body "create order fail-closed" "checkout not available yet"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"event_type\":\"PAYMENT.CAPTURE.COMPLETED\"}" \
  "${BASE}/paypal-webhook.php")"
expect_code "webhook without config" 503 "$code"

code="$(curl_code "${BASE}/van-download.php?c=abcdef1234567890&t=${HEX_TOKEN}&sku=macro-indicators-research")"
expect_code "download without purchase" 403 "$code"
expect_body "download denied copy" "Download denied"

# --- turn config on (same php-S process reads env already set; rewrite secret path) ---
cat > "${WORKDIR}/paypal.secret.php" <<'PHP'
<?php
return [
  "PAYPAL_CLIENT_ID" => "test-client-id",
  "PAYPAL_CLIENT_SECRET" => "test-client-secret",
  "PAYPAL_WEBHOOK_ID" => "test-webhook-id",
  "PAYPAL_ENV" => "sandbox",
  "PACK_DELIVERY_ENABLED" => false
];
PHP
export HALFACRE_PAYPAL_SECRET="${WORKDIR}/paypal.secret.php"
export HALFACRE_PAYPAL_TESTDIR="$WORKDIR"

php "${ROOT}/tests/paypal-lib-test.php"
LIB_RC=$?
if [[ "$LIB_RC" -eq 0 ]]; then
  PASS=$((PASS + 1))
  note "PASS  paypal-lib-test.php"
else
  FAIL=$((FAIL + 1))
  note "FAIL  paypal-lib-test.php"
fi

# Restart server so later HTTP tests see a clean mock store from lib test leftovers.
kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
printf '{}\n' > "${WORKDIR}/paypal-captures.store.json"
printf '{}\n' > "${WORKDIR}/paypal-events.store.json"
printf '{}\n' > "${WORKDIR}/paypal-mock.json"
: > "${WORKDIR}/mail.log"

export HALFACRE_PAYPAL_TESTDIR="$WORKDIR"
php -S "${HOST}:${PORT}" -t "$ROOT" >"${WORKDIR}/php-server.log" 2>&1 &
SERVER_PID=$!
sleep 0.4

code="$(curl_code "${BASE}/paypal-status.php?sku=macro-indicators-research")"
expect_code "status with config" 200 "$code"
expect_body "status available" '"available":true'
expect_body "status catalog price" '"4.99"'
if grep -q "test-client-secret" "${WORKDIR}/body.txt"; then
  FAIL=$((FAIL + 1))
  note "FAIL  status leaked the secret"
else
  PASS=$((PASS + 1))
  note "PASS  status did not leak the secret"
fi

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"action\":\"purchase\",\"id\":\"macro-indicators-research\"}" \
  "${BASE}/client-memory.php")"
expect_code "no-pay purchase denied with valid token" 403 "$code"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${BAD_TOKEN}\",\"sku\":\"macro-indicators-research\",\"amount\":\"0.01\"}" \
  "${BASE}/paypal-order.php")"
expect_code "create order bad token" 403 "$code"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"sku\":\"macro-indicators-research\",\"amount\":\"0.01\"}" \
  "${BASE}/paypal-order.php")"
expect_code "create order ignores client amount" 200 "$code"
expect_body "order amount is catalog 4.99" '"4.99"'
ORDER_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_id"] ?? "";' "${WORKDIR}/body.txt")"
if [[ -z "$ORDER_ID" ]]; then
  FAIL=$((FAIL + 1))
  note "FAIL  no order id from create"
else
  PASS=$((PASS + 1))
  note "PASS  create returned order id"
fi

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"orderID\":\"ORDER-WRONGAMT\"}" \
  "${BASE}/paypal-capture.php")"
expect_code "tampered capture amount rejected" 400 "$code"
expect_body "tamper reason" "Amount mismatch"

code="$(curl_code "${BASE}/client-memory.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "memory after rejected capture" 200 "$code"
if grep -q "macro-indicators-research" "${WORKDIR}/body.txt"; then
  FAIL=$((FAIL + 1))
  note "FAIL  rejected capture still marked bought"
else
  PASS=$((PASS + 1))
  note "PASS  rejected capture did not mark bought"
fi

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"orderID\":\"${ORDER_ID}\"}" \
  "${BASE}/paypal-capture.php")"
expect_code "good capture records purchase" 200 "$code"
expect_body "capture sku" "macro-indicators-research"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"orderID\":\"${ORDER_ID}\"}" \
  "${BASE}/paypal-capture.php")"
expect_code "second capture is idempotent" 200 "$code"
expect_body "idempotent flag" '"already":true'

code="$(curl_code "${BASE}/client-memory.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "memory lists verified purchase" 200 "$code"
expect_body "purchased sku on page" "macro-indicators-research"

code="$(curl_code "${BASE}/van-download.php?c=abcdef1234567890&t=${HEX_TOKEN}&sku=macro-indicators-research")"
expect_code "research sku has no file" 403 "$code"
expect_body "research not ready" "not ready"

code="$(curl_code "${BASE}/van-download.php?c=abcdef1234567890&t=${HEX_TOKEN}&sku=pack-etf-mf")"
expect_code "pack download without that purchase" 403 "$code"

# webhook: unverified signature
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -H "PayPal-Auth-Algo: SHA256withRSA" \
  -H "PayPal-Cert-Url: https://api.paypal.com/cert" \
  -H "PayPal-Transmission-Id: bad-sig" \
  -H "PayPal-Transmission-Sig: x" \
  -H "PayPal-Transmission-Time: now" \
  -d '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-WEBHOOK-BAD","amount":{"value":"4.99","currency_code":"USD"},"custom_id":"abcdef1234567890|social-sentiment-research","status":"COMPLETED"}}' \
  "${BASE}/paypal-webhook.php")"
expect_code "unverified webhook rejected" 400 "$code"

# webhook: verified no-code ETF $149
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -H "PayPal-Auth-Algo: SHA256withRSA" \
  -H "PayPal-Cert-Url: https://api.paypal.com/cert" \
  -H "PayPal-Transmission-Id: good-sig" \
  -H "PayPal-Transmission-Sig: x" \
  -H "PayPal-Transmission-Time: now" \
  -d '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-NOCODE-ETF","amount":{"value":"149.00","currency_code":"USD"},"status":"COMPLETED","payer":{"email_address":"buyer@example.invalid"},"supplementary_data":{"related_ids":{"order_id":"ORDER-NOCODE"}},"invoice_id":"PLB-DN2KVZRLCUML"}}' \
  "${BASE}/paypal-webhook.php")"
expect_code "verified no-code webhook recorded" 200 "$code"

if grep -q "buyer@example.invalid" "${WORKDIR}/mail.log"; then
  PASS=$((PASS + 1))
  note "PASS  no-code buyer was emailed"
else
  FAIL=$((FAIL + 1))
  note "FAIL  no-code buyer was not emailed"
  note "      mail: $(cat "${WORKDIR}/mail.log")"
fi

# pack delivery still held
SIGNED="$(php -r '
  putenv("HALFACRE_PAYPAL_SECRET=" . $argv[1]);
  require $argv[2] . "/paypal-lib.php";
  $exp = time() + 3600;
  echo "sku=pack-etf-mf&email=buyer@example.invalid&exp=" . $exp . "&sig=" . paypal_sign_download("pack-etf-mf", "buyer@example.invalid", $exp);
' "${WORKDIR}/paypal.secret.php" "$ROOT")"
code="$(curl_code "${BASE}/van-download.php?${SIGNED}")"
expect_code "pack download held while flag false" 403 "$code"
expect_body "sales hold copy" "not ready"

# enable pack delivery and retry
cat > "${WORKDIR}/paypal.secret.php" <<'PHP'
<?php
return [
  "PAYPAL_CLIENT_ID" => "test-client-id",
  "PAYPAL_CLIENT_SECRET" => "test-client-secret",
  "PAYPAL_WEBHOOK_ID" => "test-webhook-id",
  "PAYPAL_ENV" => "sandbox",
  "PACK_DELIVERY_ENABLED" => true
];
PHP
code="$(curl_code "${BASE}/van-download.php?${SIGNED}")"
expect_code "pack download streams after flag true" 200 "$code"

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
note "Log: ${LOG}"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
