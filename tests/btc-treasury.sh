#!/usr/bin/env bash
# BTCTreasuryBot practice mode: lock, unlock after mocked capture, no keys, no sFOX.
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
expect_file "8.1 banner on page" "${ROOT}/page.html" "PRACTICE MODE: SIMULATED. No real money. No exchange connection. No real trades."
expect_file "8.1 second sentence" "${ROOT}/page.html" "Every balance, trade, and gain or loss on this screen is hypothetical."
expect_file "8.2 legend on page" "${ROOT}/page.html" "These results are based on simulated or hypothetical performance results that have certain inherent limitations."
expect_file "8.2 next to cash figure" "${ROOT}/page.html" 'id="simCash"'
expect_file "8.2 cash legend id" "${ROOT}/page.html" 'id="simCashLegend"'
expect_file "8.2 next to btc figure" "${ROOT}/page.html" 'id="simBtcLegend"'
expect_file "8.2 next to equity figure" "${ROOT}/page.html" 'id="simEquityLegend"'
expect_file "8.2 next to gain figure" "${ROOT}/page.html" 'id="simGainLegend"'
expect_file "8.2 next to drawdown figure" "${ROOT}/page.html" 'id="simDrawdownLegend"'
expect_file "8.2 next to ledger" "${ROOT}/page.html" 'id="simLedgerLegend"'
expect_file "8.3 disclaimer on page" "${ROOT}/page.html" "It is general and impersonal: every user gets the same signals"
expect_file "client-typed price" "${ROOT}/page.html" "Type the price yourself"
expect_file "disclaimer link" "${ROOT}/page.html" "/disclaimer.html"
expect_file "terms link" "${ROOT}/page.html" "/terms.html"
expect_file "privacy link" "${ROOT}/page.html" "/privacy.html"
expect_file "refunds link" "${ROOT}/page.html" "/refunds.html"
expect_file "signal TODO on page" "${ROOT}/page.html" "TODO — signal module not installed"
expect_absent "practice js has no CoinGecko" "${ROOT}/van-btctreasury-practice.js" "coingecko|cryptocompare|yahoo|fred|alternative\\.me|fear.?greed"
expect_absent "ui js has no uncleared hosts" "${ROOT}/van-btctreasury.js" "coingecko|cryptocompare|yahoo|stlouisfed|fred\\.|alternative\\.me|binance|kraken|sfox\\.com"
expect_absent "practice js has no fetch" "${ROOT}/van-btctreasury-practice.js" "\\bfetch\\s*\\("
expect_absent "practice js has no van-sfox" "${ROOT}/van-btctreasury-practice.js" "van-sfox|sfox\\.php|api key|secret key|exchange key"
expect_absent "ui js has no van-sfox call" "${ROOT}/van-btctreasury.js" "van-sfox|sfox\\.php|api key|secret key|exchange key"
expect_absent "ui js has no live order words" "${ROOT}/van-btctreasury.js" "place.?order|custody|you should buy"
expect_absent "page practice has no key request" "${ROOT}/page.html" "api key|secret key|exchange key|sFOX API"
expect_absent "page does not call van-sfox from feature" "${ROOT}/page.html" "van-sfox\\.php"

# 8.2 must sit inside each simulated figure (adjacent legend).
if HALFACRE_PAGE="${ROOT}/page.html" python3 - <<'PY'
from pathlib import Path
import os
import re
html = Path(os.environ["HALFACRE_PAGE"]).read_text()
block = html.split('id="btctreasuryOpen"',1)[1]
figs = re.findall(r'<figure class="sim-figure".*?</figure>', block, re.S)
ok = True
if len(figs) < 6:
    print("only", len(figs), "sim figures")
    ok = False
needle = "These results are based on simulated or hypothetical performance results"
for i, fig in enumerate(figs):
    if 'class="sim-number"' in fig or 'id="btctreasuryLedger"' in fig:
        if needle not in fig:
            print("figure", i, "missing 8.2")
            ok = False
    else:
        print("figure", i, "has no simulated number")
        ok = False
raise SystemExit(0 if ok else 1)
PY
then
  PASS=$((PASS + 1))
  note "PASS  8.2 adjacent to each simulated figure"
else
  FAIL=$((FAIL + 1))
  note "FAIL  8.2 adjacent to each simulated figure"
fi

# Banned-phrase grep on practice-mode files after stripping required legal text.
BANNED_DIR="${WORKDIR}/banned"
mkdir -p "$BANNED_DIR"
strip_legal() {
  python3 - "$1" "$2" <<'PY'
import sys
from pathlib import Path
src = Path(sys.argv[1]).read_text()
for blob in [
    "PRACTICE MODE: SIMULATED. No real money. No exchange connection. No real trades.",
    "Every balance, trade, and gain or loss on this screen is hypothetical.",
    "These results are based on simulated or hypothetical performance results that have certain inherent limitations. Unlike the results shown in an actual performance record, these results do not represent actual trading. Also, because these trades have not actually been executed, these results may have under- or over-compensated for the impact, if any, of certain market factors, such as lack of liquidity. Simulated or hypothetical trading programs in general are also subject to the fact that they are designed with the benefit of hindsight. No representation is being made that any account will or is likely to achieve profits or losses similar to those being shown.",
    "BTCTreasuryBot is software and research published by Halfacre Research (a trade name of Halfacre Research Institute LLC, an Alabama LLC). It is general and impersonal: every user gets the same signals, and nothing is tailored to your finances, goals, or holdings. It is not investment, financial, tax, or legal advice, and not a recommendation to buy or sell any asset. Halfacre Research is not a registered investment adviser, broker-dealer, commodity trading advisor, or money transmitter. Bitcoin and other crypto assets are highly volatile, and you can lose some or all of your money. Automated trading adds risks such as software bugs, bad data, exchange outages, and fast losses. Past and simulated performance do not guarantee future results. There is no guarantee of profit and no \"risk-free\" trading.",
    'There is no guarantee of profit and no "risk-free" trading.',
]:
    src = src.replace(blob, "")
Path(sys.argv[2]).write_text(src)
PY
}
for f in page.html van.html van-btctreasury.js van-btctreasury-practice.js van-shop-copy.js; do
  strip_legal "${ROOT}/${f}" "${BANNED_DIR}/${f}"
done
BANNED_HIT="$(grep -nEi 'passive income|beats the s&p|ai that predicts|\\bcagr\\b|no risk|guaranteed|go live|upgrade to live|live trading' "${BANNED_DIR}"/* || true)"
if [[ -n "$BANNED_HIT" ]]; then
  FAIL=$((FAIL + 1))
  note "FAIL  banned-phrase grep"
  note "      ${BANNED_HIT}"
else
  PASS=$((PASS + 1))
  note "PASS  banned-phrase grep"
fi

php "${ROOT}/tests/btc-treasury-practice-test.php"
PLAN_RC=$?
if [[ "$PLAN_RC" -eq 0 ]]; then
  PASS=$((PASS + 1))
  note "PASS  btc-treasury-practice-test.php"
else
  FAIL=$((FAIL + 1))
  note "FAIL  btc-treasury-practice-test.php"
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
expect_code "page.html serves practice markup" 200 "$code"
expect_body "served 8.1 banner" "PRACTICE MODE: SIMULATED"
expect_body "served #btctreasury" 'id="btctreasury"'
expect_body "served locked checkout link" "pay.html?sku=btc-treasury-bot"

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
note "Log: ${LOG}"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
