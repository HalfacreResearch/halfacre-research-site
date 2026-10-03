#!/usr/bin/env bash
# Local curl checks for Van / client-page token lockdown.
# Uses a throwaway data dir. Never writes into the repo store files.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-van-lockdown.XXXXXX)"
PORT="${HALFACRE_TEST_PORT:-8765}"
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
  local body="$4"
  if [[ "$got" == "$want" ]]; then
    PASS=$((PASS + 1))
    note "PASS  ${name} (HTTP ${got})"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (wanted HTTP ${want}, got ${got})"
    note "      body: ${body}"
  fi
}

expect_body() {
  local name="$1"
  local needle="$2"
  local body="$3"
  if grep -q -- "$needle" <<<"$body"; then
    PASS=$((PASS + 1))
    note "PASS  ${name} (body has ${needle})"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (body missing ${needle})"
    note "      body: ${body}"
  fi
}

mkdir -p "${WORKDIR}/private" "${WORKDIR}/halfacre-private"
php -r '
  file_put_contents($argv[1], "<?php\nconst HALFACRE_SECRETS_KEY = \"" . base64_encode(random_bytes(32)) . "\";\n");
' "${WORKDIR}/halfacre-private/secrets.key.php"
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
printf '{}\n' > "${WORKDIR}/unlocks.store.json"

VAN_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("van");
  '
)"
HEX_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("abcdef1234567890");
  '
)"
BAD_TOKEN="ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"

if [[ ${#VAN_TOKEN} -lt 64 || ${#HEX_TOKEN} -lt 64 ]]; then
  echo "Could not issue test tokens" >&2
  exit 1
fi

note "Test data dir: ${WORKDIR}"
note "Issued local tokens (not committed)."

export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_UNLOCKS_STORE="${WORKDIR}/unlocks.store.json"
export HALFACRE_PRIVATE_DIR="${WORKDIR}/halfacre-private"
export HALFACRE_SECRETS_KEY_FILE="${WORKDIR}/halfacre-private/secrets.key.php"
export HALFACRE_SECRETS_STORE="${WORKDIR}/halfacre-private/client-secrets.store.json"
export HALFACRE_GROK_STUB=1
export HALFACRE_GROK_RATE_MAX=2
export HALFACRE_SFOX_STUB=1

php -S "${HOST}:${PORT}" -t "$ROOT" >"${WORKDIR}/php-server.log" 2>&1 &
SERVER_PID=$!
sleep 0.4
if ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo "php -S failed to start" >&2
  cat "${WORKDIR}/php-server.log" >&2
  exit 1
fi

curl_code() {
  curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" "$@"
}

# --- missing token = 401, bad token = 403 ---
for id in van charley-van-halfacre abcdef1234567890; do
  code="$(curl_code "${BASE}/client.php?c=${id}")"
  expect_code "client.php missing token c=${id}" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code "${BASE}/client.php?c=${id}&t=${BAD_TOKEN}")"
  expect_code "client.php bad token c=${id}" 403 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code "${BASE}/client-memory.php?c=${id}")"
  expect_code "client-memory GET missing token c=${id}" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code "${BASE}/client-memory.php?c=${id}&t=${BAD_TOKEN}")"
  expect_code "client-memory GET bad token c=${id}" 403 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"${id}\",\"action\":\"upload\",\"name\":\"x.txt\"}" \
    "${BASE}/client-memory.php")"
  expect_code "client-memory POST missing token c=${id}" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"${id}\",\"t\":\"${BAD_TOKEN}\",\"action\":\"upload\",\"name\":\"x.txt\"}" \
    "${BASE}/client-memory.php")"
  expect_code "client-memory POST bad token c=${id}" 403 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"${id}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}]}" \
    "${BASE}/van-grok.php")"
  expect_code "van-grok.php missing token c=${id}" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"${id}\",\"t\":\"${BAD_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}]}" \
    "${BASE}/van-grok.php")"
  expect_code "van-grok.php bad token c=${id}" 403 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code "${BASE}/van-sfox.php?c=${id}")"
  expect_code "van-sfox.php missing token c=${id}" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl_code "${BASE}/van-sfox.php?c=${id}&t=${BAD_TOKEN}")"
  expect_code "van-sfox.php bad token c=${id}" 403 "$code" "$(cat "${WORKDIR}/body.json")"
done

# --- valid Van token ---
code="$(curl_code "${BASE}/client.php?c=van&t=${VAN_TOKEN}")"
expect_code "client.php valid token c=van" 200 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "client.php van name" "Charley Van Halfacre" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code "${BASE}/client.php?c=charley-van-halfacre&t=${VAN_TOKEN}")"
expect_code "client.php valid token c=charley-van-halfacre" 200 "$code" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"action\":\"upload\",\"name\":\"1040-2024.pdf\",\"kind\":\"tax\"}" \
  "${BASE}/client-memory.php")"
expect_code "client-memory POST valid token c=van" 200 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "memory write stored upload" "1040-2024.pdf" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code "${BASE}/client-memory.php?c=van&t=${VAN_TOKEN}")"
expect_code "client-memory GET valid token c=van" 200 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "memory read has upload" "1040-2024.pdf" "$(cat "${WORKDIR}/body.json")"

# Prove browser-sent uploads/catalog are ignored: send fake owned + uploads.
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}],\"catalog\":{\"live\":[{\"name\":\"FAKE-FROM-BROWSER\"}]},\"owned\":[\"browser-owned\"],\"uploads\":[{\"name\":\"browser-upload.pdf\",\"kind\":\"tax\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "van-grok.php valid token reaches xAI stub" 200 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "van-grok stub marker" "reached xAI-call step" "$(cat "${WORKDIR}/body.json")"
expect_body "van-grok reached field" "xai-call" "$(cat "${WORKDIR}/body.json")"

# hex client also works
code="$(curl_code "${BASE}/client.php?c=abcdef1234567890&t=${HEX_TOKEN}")"
expect_code "client.php valid hex token" 200 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "hex client name" "Test Client" "$(cat "${WORKDIR}/body.json")"

# Van token must not open the hex client
code="$(curl_code "${BASE}/client.php?c=abcdef1234567890&t=${VAN_TOKEN}")"
expect_code "van token cannot open hex client" 403 "$code" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code "${BASE}/van-sfox.php")"
expect_code "van-sfox.php empty c" 400 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -Eiq 'charley-van-halfacre' "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  van-sfox.php empty c fell back to Charley"
else
  PASS=$((PASS + 1))
  note "PASS  van-sfox.php empty c did not fall back to Charley"
fi

code="$(curl_code "${BASE}/van-sfox.php?c=van&t=${VAN_TOKEN}")"
expect_code "van-sfox.php valid token c=van" 200 "$code" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code "${BASE}/van-sfox.php?c=abcdef1234567890&t=${VAN_TOKEN}")"
expect_code "van token cannot open hex van-sfox" 403 "$code" "$(cat "${WORKDIR}/body.json")"

# --- rate limit (HALFACRE_GROK_RATE_MAX=2) ---
# Two successful stub calls already used the IP/token buckets (the valid van-grok above
# plus we fire one more, then the next should 429).
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Again.\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "van-grok second allowed call" 200 "$code" "$(cat "${WORKDIR}/body.json")"

code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Third.\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "van-grok rate limit" 429 "$code" "$(cat "${WORKDIR}/body.json")"
expect_body "rate limit copy" "Try again later." "$(cat "${WORKDIR}/body.json")"

# --- pages.html must not list Van or other private client links ---
pages="$(curl -sS "${BASE}/pages.html")"
if grep -Eiq 'van\.html|c=charley-van-halfacre|c=4cef09e440f4fe1c|c=99eff3186764860c' <<<"$pages"; then
  FAIL=$((FAIL + 1))
  note "FAIL  pages.html still lists a private client link"
else
  PASS=$((PASS + 1))
  note "PASS  pages.html has no Van / private client links"
fi

# --- grep: no isVanId token bypass, no van.html in talk/return ---
if git -C "$ROOT" grep -n "isVanId" -- client-session.js | grep -Eiq 'fetchClient|talkUrl|Promise.resolve\(setClient\(VAN\)\)|return Promise.resolve\(asClient'; then
  FAIL=$((FAIL + 1))
  note "FAIL  isVanId still used as a token bypass in client-session.js"
else
  PASS=$((PASS + 1))
  note "PASS  no isVanId token bypass in client-session.js"
fi

if git -C "$ROOT" grep -n "van\\.html" -- client-session.js van-owned.js van-shop.js client-desk.js; then
  FAIL=$((FAIL + 1))
  note "FAIL  van.html still referenced in talk/return code"
else
  PASS=$((PASS + 1))
  note "PASS  no van.html in talk/return code"
fi

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
note "Log: ${LOG}"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
