#!/usr/bin/env bash
# Generic (non-Charley) client Grok prompts + nudge flags.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-generic-grok.XXXXXX)"
SITE_PORT="${HALFACRE_GENERIC_SITE_PORT:-8772}"
MOCK_PORT="${HALFACRE_GENERIC_MOCK_PORT:-8773}"
HOST="127.0.0.1"
BASE="http://${HOST}:${SITE_PORT}"
MOCK="http://${HOST}:${MOCK_PORT}"
PASS=0
FAIL=0
SITE_PID=""
MOCK_PID=""

cleanup() {
  if [[ -n "${SITE_PID}" ]] && kill -0 "$SITE_PID" 2>/dev/null; then
    kill "$SITE_PID" 2>/dev/null || true
    wait "$SITE_PID" 2>/dev/null || true
  fi
  if [[ -n "${MOCK_PID}" ]] && kill -0 "$MOCK_PID" 2>/dev/null; then
    kill "$MOCK_PID" 2>/dev/null || true
    wait "$MOCK_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

note() { echo "$1"; }

check() {
  local name="$1"
  local ok="$2"
  local detail="${3:-}"
  if [[ "$ok" == "1" ]]; then
    PASS=$((PASS + 1))
    note "PASS  ${name}"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name}"
    if [[ -n "$detail" ]]; then
      note "      ${detail}"
    fi
  fi
}

expect_code() {
  local name="$1"
  local want="$2"
  local got="$3"
  local body="$4"
  if [[ "$got" == "$want" ]]; then
    check "$name (HTTP ${got})" 1
  else
    check "$name (wanted HTTP ${want}, got ${got})" 0 "$body"
  fi
}

mkdir -p "${WORKDIR}/private"
cat > "${WORKDIR}/clients.store.json" <<'JSON'
[
  {
    "id": "abcdef1234567890",
    "number": 2,
    "name": "Jordan Lee",
    "email": "jordan-lee@example.invalid",
    "phone": "000"
  }
]
JSON
printf '{}\n' > "${WORKDIR}/memory.store.json"
printf '{}\n' > "${WORKDIR}/unlocks.store.json"

export HALFACRE_GENERIC_WORKDIR="${WORKDIR}"
export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_UNLOCKS_STORE="${WORKDIR}/unlocks.store.json"
export HALFACRE_GROK_RATE_MAX=50
export HALFACRE_SAFETY_SALT="generic-test-salt"
export XAI_API_KEY="test-xai-key-not-real"
export HALFACRE_XAI_URL="$MOCK"
unset HALFACRE_GROK_STUB || true
unset HALFACRE_XAI_ZDR || true
unset HALFACRE_GROK_NUDGE_UPLOAD || true
unset HALFACRE_GROK_NUDGE_POWERUP || true

php "${ROOT}/tests/grok-generic-client.php"
UNIT_RC=$?
if [[ "$UNIT_RC" != "0" ]]; then
  exit "$UNIT_RC"
fi

HEX_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("abcdef1234567890");
  '
)"

start_site() {
  if [[ -n "${SITE_PID}" ]] && kill -0 "$SITE_PID" 2>/dev/null; then
    kill "$SITE_PID" 2>/dev/null || true
    wait "$SITE_PID" 2>/dev/null || true
  fi
  SITE_PID=""
  php -S "${HOST}:${SITE_PORT}" -t "$ROOT" >"${WORKDIR}/site.log" 2>&1 &
  SITE_PID=$!
  local ready=0
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    if ! kill -0 "$SITE_PID" 2>/dev/null; then
      echo "site php -S failed" >&2
      cat "${WORKDIR}/site.log" >&2
      exit 1
    fi
    if curl -sS -o /dev/null --max-time 1 -X POST -H "Content-Type: application/json" -d '{}' "${BASE}/client-grok.php" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 0.15
  done
  if [[ "$ready" != "1" ]]; then
    echo "site did not become ready" >&2
    cat "${WORKDIR}/site.log" >&2
    exit 1
  fi
}

start_mock() {
  local mode="$1"
  if [[ -n "${MOCK_PID}" ]] && kill -0 "$MOCK_PID" 2>/dev/null; then
    kill "$MOCK_PID" 2>/dev/null || true
    wait "$MOCK_PID" 2>/dev/null || true
  fi
  : > "${WORKDIR}/mock.log"
  rm -f "${WORKDIR}/mock.log.count"
  HALFACRE_XAI_MOCK_LOG="${WORKDIR}/mock.log" \
  HALFACRE_XAI_MOCK_ZDR="$mode" \
    php -S "${HOST}:${MOCK_PORT}" "${ROOT}/tests/grok-zdr-mock-server.php" >"${WORKDIR}/mock-server.log" 2>&1 &
  MOCK_PID=$!
  sleep 0.3
  if ! kill -0 "$MOCK_PID" 2>/dev/null; then
    echo "mock php -S failed" >&2
    cat "${WORKDIR}/mock-server.log" >&2
    exit 1
  fi
}

export HALFACRE_GROK_STUB=1
start_site

for endpoint in client-grok.php van-grok.php; do
  code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"abcdef1234567890\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}]}" \
    "${BASE}/${endpoint}")"
  expect_code "${endpoint} missing token" 401 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"abcdef1234567890\",\"t\":\"ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}]}" \
    "${BASE}/${endpoint}")"
  expect_code "${endpoint} bad token" 403 "$code" "$(cat "${WORKDIR}/body.json")"

  code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"open\":true,\"messages\":[{\"role\":\"user\",\"content\":\"Hello.\"}]}" \
    "${BASE}/${endpoint}")"
  expect_code "${endpoint} valid hex token stub" 200 "$code" "$(cat "${WORKDIR}/body.json")"
  if grep -q "reached xAI-call step" "${WORKDIR}/body.json"; then
    check "${endpoint} stub reaches xai-call" 1
  else
    check "${endpoint} stub reaches xai-call" 0 "$(cat "${WORKDIR}/body.json")"
  fi
done

unset HALFACRE_GROK_STUB || true
export HALFACRE_XAI_ZDR_CONFIRMED=true
start_site
start_mock true
rm -f "${WORKDIR}/private/grok-zdr.store.json"

code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"open\":true,\"messages\":[{\"role\":\"user\",\"content\":\"Hello-from-jordan\"}]}" \
  "${BASE}/client-grok.php")"
expect_code "generic client ZDR true HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"

if grep -q "Hello-from-jordan" "${WORKDIR}/mock.log"; then
  check "generic client chat forwarded" 1
else
  check "generic client chat forwarded" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -Eqi 'Charley|Van Halfacre|charley-van-halfacre|Jordan Lee|jordan-lee@example.invalid' "${WORKDIR}/mock.log"; then
  check "generic client prompt has no name/email/Van/Charley" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "generic client prompt has no name/email/Van/Charley" 1
fi
if grep -q "UPLOADS (friendly, optional, never pushy)" "${WORKDIR}/mock.log"; then
  check "generic open prompt includes upload block" 1
else
  check "generic open prompt includes upload block" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -q "HARD STOPS (these override everything above)" "${WORKDIR}/mock.log"; then
  check "generic open prompt includes hard stops" 1
else
  check "generic open prompt includes hard stops" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -q "safety_identifier" "${WORKDIR}/mock.log"; then
  check "generic client sent hashed safety_identifier" 1
else
  check "generic client sent hashed safety_identifier" 0 "$(cat "${WORKDIR}/mock.log")"
fi

start_mock false
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${HEX_TOKEN}\",\"open\":true,\"messages\":[{\"role\":\"user\",\"content\":\"Hello-blocked\"}]}" \
  "${BASE}/client-grok.php")"
expect_code "generic client ZDR false HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "Hello-blocked" "${WORKDIR}/mock.log"; then
  check "generic client ZDR false sent no prompt" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "generic client ZDR false sent no prompt" 1
fi
if grep -q "UPLOADS (friendly, optional, never pushy)" "${WORKDIR}/mock.log"; then
  check "generic client ZDR false sent no upload block" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "generic client ZDR false sent no upload block" 1
fi

note ""
note "HTTP extra: ${PASS} passed, ${FAIL} failed"
if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
