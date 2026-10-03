#!/usr/bin/env bash
# BTCTreasuryBot planner: lock, unlock after mocked capture, no uncleared data fetches.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-treasury.XXXXXX)"
PORT="${HALFACRE_TREASURY_TEST_PORT:-8767}"
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

expect_file() {
  local name="$1"
  local file="$2"
  local needle="$3"
  if grep -q -- "$needle" "$file"; then
    PASS=$((PASS + 1))
    note "PASS  ${name}"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (missing ${needle})"
  fi
}

expect_absent() {
  local name="$1"
  local file="$2"
  local needle="$3"
  if grep -qiE -- "$needle" "$file"; then
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (found ${needle})"
  else
    PASS=$((PASS + 1))
    note "PASS  ${name}"
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

HEX_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("abcdef1234567890");
  '
)"

export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_PAYPAL_SECRET="${WORKDIR}/paypal.secret.php"
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

expect_file "page has #btctreasury" "${ROOT}/page.html" 'id="btctreasury"'
expect_file "page locked pay link" "${ROOT}/page.html" "pay.html?sku=btc-treasury-bot"
expect_file "page slogan exact" "${ROOT}/page.html" "Know more. Bank less."
expect_file "page not-adviser copy" "${ROOT}/page.html" "not an investment adviser"
expect_file "planner uses client-typed price" "${ROOT}/page.html" "Paste your own number"
expect_file "disclaimer link" "${ROOT}/page.html" "/disclaimer.html"
expect_absent "plan js has no CoinGecko" "${ROOT}/van-btctreasury-plan.js" "coingecko|cryptocompare|yahoo|fred|alternative\\.me|fear.?greed"
expect_absent "ui js has no uncleared hosts" "${ROOT}/van-btctreasury.js" "coingecko|cryptocompare|yahoo|stlouisfed|fred\\.|alternative\\.me|binance|kraken|sfox\\.com"
expect_absent "plan js has no fetch" "${ROOT}/van-btctreasury-plan.js" "\\bfetch\\s*\\("
expect_absent "ui js has no live order words" "${ROOT}/van-btctreasury.js" "place.?order|exchange api key|custody|you should buy"

php "${ROOT}/tests/btc-treasury-plan-test.php"
PLAN_RC=$?
if [[ "$PLAN_RC" -eq 0 ]]; then
  PASS=$((PASS + 1))
  note "PASS  btc-treasury-plan-test.php"
else
  FAIL=$((FAIL + 1))
  note "FAIL  btc-treasury-plan-test.php"
fi

code="$(curl_code "${BASE}/van-btctreasury.php")"
expect_code "status without token" 401 "$code"

code="$(curl_code "${BASE}/van-btctreasury.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "status without purchase" 200 "$code"
expect_body "locked flag" '"unlocked":false'
expect_body "pay path" "pay.html?sku=btc-treasury-bot"

code="$(curl_code "${BASE}/client-memory.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "memory without purchase" 200 "$code"
if grep -q "btc-treasury-bot" "${WORKDIR}/body.txt"; then
  FAIL=$((FAIL + 1))
  note "FAIL  memory listed bot without a capture"
else
  PASS=$((PASS + 1))
  note "PASS  memory omitted bot without a capture"
fi

code="$(curl_code "${BASE}/van-download.php?c=abcdef1234567890&t=${HEX_TOKEN}&sku=btc-treasury-bot")"
expect_code "download without purchase" 403 "$code"

# catalog $149 capture (mocked)
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"sku\":\"btc-treasury-bot\"}" \
  "${BASE}/paypal-order.php")"
expect_code "create bot order" 200 "$code"
expect_body "bot catalog amount" '"149.00"'
ORDER_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_id"] ?? "";' "${WORKDIR}/body.txt")"

# mock store defaults unknown ORDER-* to $4.99 research sku. Record via webhook custom_id instead.
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -H "PayPal-Auth-Algo: SHA256withRSA" \
  -H "PayPal-Cert-Url: https://api.sandbox.paypal.com/cert" \
  -H "PayPal-Transmission-Id: treasury-sig" \
  -H "PayPal-Transmission-Sig: x" \
  -H "PayPal-Transmission-Time: now" \
  -d '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-TREASURY","amount":{"value":"149.00","currency_code":"USD"},"status":"COMPLETED","payer":{"email_address":"test-client@example.invalid"},"custom_id":"abcdef1234567890|btc-treasury-bot"}}' \
  "${BASE}/paypal-webhook.php")"
expect_code "verified bot capture recorded" 200 "$code"

code="$(curl_code "${BASE}/van-btctreasury.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "status after capture" 200 "$code"
expect_body "unlocked flag" '"unlocked":true'

code="$(curl_code "${BASE}/client-memory.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "memory after capture" 200 "$code"
expect_body "purchased sku" "btc-treasury-bot"

code="$(curl_code "${BASE}/van-download.php?c=abcdef1234567890&t=${HEX_TOKEN}&sku=btc-treasury-bot")"
expect_code "bot has no pack file" 403 "$code"
expect_body "bot not a zip" "not ready"

if [[ -s "${WORKDIR}/mail.log" ]] && grep -qi "download" "${WORKDIR}/mail.log"; then
  FAIL=$((FAIL + 1))
  note "FAIL  bot capture emailed a download"
else
  PASS=$((PASS + 1))
  note "PASS  bot capture did not email a download"
fi

code="$(curl_code "${BASE}/page.html")"
expect_code "page.html serves planner markup" 200 "$code"
expect_body "served #btctreasury" 'id="btctreasury"'
expect_body "served locked checkout link" "pay.html?sku=btc-treasury-bot"

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
note "Log: ${LOG}"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
