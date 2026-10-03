#!/usr/bin/env bash
# AttorneyBot controller: caps, opt-out, ZDR-off nudges, both-nudge, redaction.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-attorneybot.XXXXXX)"
SITE_PORT="${HALFACRE_ATTORNEY_SITE_PORT:-8774}"
MOCK_PORT="${HALFACRE_ATTORNEY_MOCK_PORT:-8775}"
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

php "${ROOT}/tests/grok-attorneybot.php"
UNIT_RC=$?
if [[ "$UNIT_RC" != "0" ]]; then
  exit "$UNIT_RC"
fi

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
NOW="$(date +%s)"
python3 - <<PY
import json, os
now = int("${NOW}")
path = "${WORKDIR}/memory.store.json"
data = {
  "abcdef1234567890": {
    "uploads": [],
    "purchases": [],
    "adult_confirmed": True,
    "welcome_done": True,
    "last_message_at": now - 60,
    "session_started_at": now - 120,
    "upload_reminders_log": [now - 86400, now - 172800],
    "product_suggestions_log": [],
    "upload_reminders_off": False,
    "product_suggestions_off": False
  }
}
open(path, "w").write(json.dumps(data) + "\\n")
PY
printf '{}\n' > "${WORKDIR}/unlocks.store.json"

export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_MEMORY_STORE="${WORKDIR}/memory.store.json"
export HALFACRE_UNLOCKS_STORE="${WORKDIR}/unlocks.store.json"
export HALFACRE_GROK_RATE_MAX=50
export HALFACRE_SAFETY_SALT="attorney-test-salt"
export XAI_API_KEY="test-xai-key-not-real"
export HALFACRE_XAI_URL="$MOCK"
export HALFACRE_XAI_ZDR_CONFIRMED=true
unset HALFACRE_GROK_STUB || true
unset HALFACRE_XAI_ZDR || true

TOKEN="$(
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
  local i ready=0
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
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
  HALFACRE_XAI_MOCK_LOG="${WORKDIR}/mock.log" \
  HALFACRE_XAI_MOCK_ZDR="$mode" \
    php -S "${HOST}:${MOCK_PORT}" "${ROOT}/tests/grok-zdr-mock-server.php" >"${WORKDIR}/mock-server.log" 2>&1 &
  MOCK_PID=$!
  sleep 0.3
}

start_site
start_mock true
rm -f "${WORKDIR}/private/grok-zdr.store.json"

code="$(curl -sS -o "${WORKDIR}/body.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"How do dividends work?\"},{\"role\":\"assistant\",\"content\":\"Generally.\"},{\"role\":\"user\",\"content\":\"Tell me more about dividends.\"}]}" \
  "${BASE}/client-grok.php")"
check "third-reminder HTTP ${code}" "$([[ "$code" == "200" ]] && echo 1 || echo 0)" "$(cat "${WORKDIR}/body.json")"
if grep -q "upload_reminder_allowed_this_turn: yes" "${WORKDIR}/mock.log"; then
  check "HTTP third upload reminder in 7 days is impossible" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "HTTP third upload reminder in 7 days is impossible" 1
fi

python3 - <<PY
import json
now = int("${NOW}")
path = "${WORKDIR}/memory.store.json"
data = {
  "abcdef1234567890": {
    "uploads": [],
    "purchases": [],
    "adult_confirmed": True,
    "welcome_done": True,
    "last_message_at": now - 60,
    "session_started_at": now - 120,
    "upload_reminders_off": True,
    "product_suggestions_off": False
  }
}
open(path, "w").write(json.dumps(data) + "\\n")
PY
rm -f "${WORKDIR}/private/grok-zdr.store.json"
: > "${WORKDIR}/mock.log"

curl -sS -o "${WORKDIR}/body.json" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello again about dividends\"}]}" \
  "${BASE}/client-grok.php" >/dev/null
if grep -q "upload_reminder_allowed_this_turn: yes" "${WORKDIR}/mock.log"; then
  check "HTTP reminder after opt-out is impossible" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "HTTP reminder after opt-out is impossible" 1
fi

export HALFACRE_XAI_ZDR_CONFIRMED=false
start_site
rm -f "${WORKDIR}/private/grok-zdr.store.json"
: > "${WORKDIR}/mock.log"
curl -sS -o "${WORKDIR}/body.json" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello-zdr-off\"}]}" \
  "${BASE}/client-grok.php" >/dev/null
if grep -q "Hello-zdr-off" "${WORKDIR}/mock.log"; then
  check "HTTP no nudge payload when zdr_on=no" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "HTTP no nudge payload when zdr_on=no" 1
fi
if grep -q "Private AI chat and uploads are coming soon" "${WORKDIR}/body.json"; then
  check "HTTP zdr_on=no keeps coming-soon copy" 1
else
  check "HTTP zdr_on=no keeps coming-soon copy" 0 "$(cat "${WORKDIR}/body.json")"
fi

export HALFACRE_XAI_ZDR_CONFIRMED=true
start_site
python3 - <<PY
import json
now = int("${NOW}")
path = "${WORKDIR}/memory.store.json"
data = {
  "abcdef1234567890": {
    "uploads": [],
    "purchases": [],
    "adult_confirmed": True,
    "welcome_done": True,
    "last_message_at": now - 60,
    "session_started_at": now - 120,
    "upload_reminders_log": [],
    "product_suggestions_log": [],
    "upload_reminders_off": False,
    "product_suggestions_off": False
  }
}
open(path, "w").write(json.dumps(data) + "\\n")
PY
export HALFACRE_CHECKOUT_OPEN=true
start_site
rm -f "${WORKDIR}/private/grok-zdr.store.json"
: > "${WORKDIR}/mock.log"
curl -sS -o "${WORKDIR}/body.json" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"Hello\"},{\"role\":\"assistant\",\"content\":\"Hi\"},{\"role\":\"user\",\"content\":\"Tell me about dividends\"}]}" \
  "${BASE}/client-grok.php" >/dev/null
if grep -q "upload_reminder_allowed_this_turn: yes" "${WORKDIR}/mock.log" && grep -q "product_suggestion_allowed_this_turn: yes" "${WORKDIR}/mock.log"; then
  check "HTTP both nudges in one turn is impossible" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "HTTP both nudges in one turn is impossible" 1
fi

: > "${WORKDIR}/mock.log"
curl -sS -o "${WORKDIR}/body.json" -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"abcdef1234567890\",\"t\":\"${TOKEN}\",\"messages\":[{\"role\":\"user\",\"content\":\"SSN 123-45-6789\"}]}" \
  "${BASE}/client-grok.php" >/dev/null
if grep -q "123-45-6789" "${WORKDIR}/mock.log"; then
  check "HTTP redacts SSN before send" 0 "$(cat "${WORKDIR}/mock.log")"
else
  check "HTTP redacts SSN before send" 1
fi
if grep -q "looked like a number" "${WORKDIR}/body.json"; then
  check "HTTP tells client about redaction" 1
else
  check "HTTP tells client about redaction" 0 "$(cat "${WORKDIR}/body.json")"
fi

note ""
note "HTTP extra: ${PASS} passed, ${FAIL} failed"
if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
