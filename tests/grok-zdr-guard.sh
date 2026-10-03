#!/usr/bin/env bash
# Fail-closed ZDR: client content must never reach xAI unless the header is exactly true.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-zdr.XXXXXX)"
SITE_PORT="${HALFACRE_ZDR_SITE_PORT:-8768}"
MOCK_PORT="${HALFACRE_ZDR_MOCK_PORT:-8769}"
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

start_site() {
  if [[ -n "${SITE_PID}" ]] && kill -0 "$SITE_PID" 2>/dev/null; then
    kill "$SITE_PID" 2>/dev/null || true
    wait "$SITE_PID" 2>/dev/null || true
  fi
  SITE_PID=""
  local i
  for i in 1 2 3 4 5 6 7 8; do
    if ! curl -sS -o /dev/null --max-time 1 "${BASE}/van-grok.php" >/dev/null 2>&1; then
      break
    fi
    sleep 0.15
  done
  php -S "${HOST}:${SITE_PORT}" -t "$ROOT" >"${WORKDIR}/site.log" 2>&1 &
  SITE_PID=$!
  local ready=0
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    if ! kill -0 "$SITE_PID" 2>/dev/null; then
      echo "site php -S failed" >&2
      cat "${WORKDIR}/site.log" >&2
      exit 1
    fi
    if curl -sS -o /dev/null --max-time 1 -X POST -H "Content-Type: application/json" -d '{}' "${BASE}/van-grok.php" >/dev/null 2>&1; then
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
printf '{}\n' > "${WORKDIR}/unlocks.store.json"

export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_UNLOCKS_STORE="${WORKDIR}/unlocks.store.json"
export HALFACRE_GROK_RATE_MAX=50
export HALFACRE_SAFETY_SALT="zdr-test-salt"
export XAI_API_KEY="test-xai-key-not-real"
export HALFACRE_XAI_URL="$MOCK"
unset HALFACRE_GROK_STUB || true
unset HALFACRE_XAI_ZDR || true

VAN_TOKEN="$(
  HALFACRE_TOKEN_ROOT="${WORKDIR}/private" php -r '
    require "'"${ROOT}"'/client-token.php";
    echo client_token_issue("van");
  '
)"

# php -S inherits env at start; restart after every HALFACRE_* change the handler reads.
export HALFACRE_GROK_STUB=1
start_site

# --- stub path: existing suite still reaches xAI-call when ZDR mock defaults true ---
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-secret-name\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "stub default ZDR true reaches xAI-call" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "reached xAI-call step" "${WORKDIR}/body.json"; then
  check "stub default still xai-call" 1
else
  check "stub default still xai-call" 0 "$(cat "${WORKDIR}/body.json")"
fi

# --- stub path: false / missing never claim xai-call ---
for mode in false missing; do
  export HALFACRE_XAI_ZDR="$mode"
  start_site
  rm -f "${WORKDIR}/private/grok-zdr.store.json"
  code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"1040-2024.pdf tax return\"}]}" \
    "${BASE}/van-grok.php")"
  expect_code "stub ZDR ${mode} is HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
  if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
    check "stub ZDR ${mode} coming-soon copy" 1
  else
    check "stub ZDR ${mode} coming-soon copy" 0 "$(cat "${WORKDIR}/body.json")"
  fi
  if grep -q "xai-call" "${WORKDIR}/body.json"; then
    check "stub ZDR ${mode} did not reach xai-call" 0 "$(cat "${WORKDIR}/body.json")"
  else
    check "stub ZDR ${mode} did not reach xai-call" 1
  fi
done
unset HALFACRE_XAI_ZDR || true
unset HALFACRE_GROK_STUB || true
export HALFACRE_XAI_ZDR_CONFIRMED=true
start_site

# Seed a stored filename so we can prove uploads never go to xAI unless ZDR is true.
printf '%s\n' '{"charley-van-halfacre":{"uploads":[{"name":"Secret-1040-name.pdf","kind":"tax"}],"purchases":[]}}' > "${WORKDIR}/memory.store.json"

# --- two-key gate: operator flag off blocks even if the canary header is true ---
export HALFACRE_XAI_ZDR_CONFIRMED=false
start_site
start_mock true
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-no-operator\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "operator ZDR flag false HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
  check "operator ZDR flag false keeps coming-soon copy" 1
else
  check "operator ZDR flag false keeps coming-soon copy" 0 "$(cat "${WORKDIR}/body.json")"
fi
if grep -q "Hello-no-operator" "${WORKDIR}/mock.log"; then
  check "operator ZDR flag false sent no client chat" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "operator ZDR flag false sent no client chat" 1
fi
export HALFACRE_XAI_ZDR_CONFIRMED=true
start_site

# --- live mock: header true sends client content after probe ---
start_mock true
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-from-client\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "mock ZDR true HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "mock-reply" "${WORKDIR}/body.json"; then
  check "mock ZDR true returns model text" 1
else
  check "mock ZDR true returns model text" 0 "$(cat "${WORKDIR}/body.json")"
fi
if grep -q "zdr-probe" "${WORKDIR}/mock.log"; then
  check "mock ZDR true sent content-free probe" 1
else
  check "mock ZDR true sent content-free probe" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -q "Hello-from-client" "${WORKDIR}/mock.log"; then
  check "mock ZDR true forwarded client chat" 1
else
  check "mock ZDR true forwarded client chat" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -q "Secret-1040-name.pdf" "${WORKDIR}/mock.log"; then
  check "mock ZDR true may forward stored filename" 1
else
  check "mock ZDR true may forward stored filename" 0 "$(cat "${WORKDIR}/mock.log")"
fi
if grep -q "Charley Van Halfacre" "${WORKDIR}/mock.log" || grep -q "test-client@example.invalid" "${WORKDIR}/mock.log"; then
  check "mock ZDR true did not send name or email" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR true did not send name or email" 1
fi
if grep -q "abcdef1234567890" "${WORKDIR}/mock.log"; then
  check "mock ZDR true did not send raw client id" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR true did not send raw client id" 1
fi
if grep -q "safety_identifier" "${WORKDIR}/mock.log"; then
  check "mock ZDR true sent hashed safety_identifier" 1
else
  check "mock ZDR true sent hashed safety_identifier" 0 "$(cat "${WORKDIR}/mock.log")"
fi

# --- live mock: header false ---
start_mock false
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-blocked-false\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "mock ZDR false HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
  check "mock ZDR false coming-soon copy" 1
else
  check "mock ZDR false coming-soon copy" 0 "$(cat "${WORKDIR}/body.json")"
fi
if grep -q "Hello-blocked-false" "${WORKDIR}/mock.log"; then
  check "mock ZDR false never forwarded client chat" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR false never forwarded client chat" 1
fi
if grep -q "Secret-1040-name.pdf" "${WORKDIR}/mock.log"; then
  check "mock ZDR false never forwarded stored filename" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR false never forwarded stored filename" 1
fi
if grep -q "zdr-probe" "${WORKDIR}/mock.log"; then
  check "mock ZDR false probed without client data" 1
else
  check "mock ZDR false probed without client data" 0 "$(cat "${WORKDIR}/mock.log")"
fi

# --- live mock: header missing ---
start_mock missing
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-blocked-missing\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "mock ZDR missing HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
  check "mock ZDR missing coming-soon copy" 1
else
  check "mock ZDR missing coming-soon copy" 0 "$(cat "${WORKDIR}/body.json")"
fi
if grep -q "Hello-blocked-missing" "${WORKDIR}/mock.log"; then
  check "mock ZDR missing never forwarded client chat" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR missing never forwarded client chat" 1
fi
if grep -q "Secret-1040-name.pdf" "${WORKDIR}/mock.log"; then
  check "mock ZDR missing never forwarded stored filename" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "mock ZDR missing never forwarded stored filename" 1
fi

# --- cache false blocks a second send with no new client body ---
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Second-blocked\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "cached ZDR false still 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "Second-blocked" "${WORKDIR}/mock.log"; then
  check "cached ZDR false sent no further client content" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "cached ZDR false sent no further client content" 1
fi

# --- real response header flips cache to false ---
start_mock true-then-false
rm -f "${WORKDIR}/private/grok-zdr.store.json"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"First-while-true\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "flip first call HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"van\",\"t\":\"${VAN_TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"After-flip\"}]}" \
  "${BASE}/van-grok.php")"
expect_code "flip second call HTTP 200" 200 "$code" "$(cat "${WORKDIR}/body.json")"
if grep -q "After-flip" "${WORKDIR}/mock.log"; then
  check "after false header, further client content is blocked" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "after false header, further client content is blocked" 1
fi
if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
  check "after flip, client sees coming soon" 1
else
  check "after flip, client sees coming soon" 0 "$(cat "${WORKDIR}/body.json")"
fi

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
