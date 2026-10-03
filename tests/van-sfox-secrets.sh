#!/usr/bin/env bash
# Token + encrypted store checks for van-sfox.php / client-secrets.php.
# Throwaway data dir. Never writes repo stores. Never calls live sFOX.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="$(mktemp -d /tmp/halfacre-sfox-secrets.XXXXXX)"
PORT="${HALFACRE_SFOX_TEST_PORT:-8767}"
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
    note "      body: $(cat "${WORKDIR}/body.json")"
  fi
}

expect_body() {
  local name="$1"
  local needle="$2"
  if grep -q -- "$needle" "${WORKDIR}/body.json"; then
    PASS=$((PASS + 1))
    note "PASS  ${name}"
  else
    FAIL=$((FAIL + 1))
    note "FAIL  ${name} (missing ${needle})"
    note "      body: $(cat "${WORKDIR}/body.json")"
  fi
}

mkdir -p "${WORKDIR}/private" "${WORKDIR}/halfacre-private"
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

export HALFACRE_TOKEN_ROOT="${WORKDIR}/private"
export HALFACRE_CLIENTS_STORE="${WORKDIR}/clients.store.json"
export HALFACRE_PRIVATE_DIR="${WORKDIR}/halfacre-private"
export HALFACRE_SECRETS_KEY_FILE="${WORKDIR}/halfacre-private/missing-secrets.key.php"
export HALFACRE_SECRETS_STORE="${WORKDIR}/halfacre-private/client-secrets.store.json"
export HALFACRE_SECRETS_PLAINTEXT_STORE="${WORKDIR}/plaintext-client-secrets.store.json"
export HALFACRE_SFOX_STUB=1
export HALFACRE_SECRETS_TESTDIR="${WORKDIR}/libtest"

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

# --- missing master key is 503 (valid token, valid c) ---
code="$(curl_code "${BASE}/van-sfox.php?c=van&t=${VAN_TOKEN}")"
expect_code "van-sfox missing master key" 503 "$code"
expect_body "van-sfox not configured copy" "not configured"

code="$(curl_code "${BASE}/client-secrets.php?c=charley-van-halfacre")"
expect_code "client-secrets missing master key" 503 "$code"
expect_body "client-secrets not configured copy" "not configured"

# --- install master key and restart ---
php -r '
  $p = $argv[1];
  file_put_contents($p, "<?php\nconst HALFACRE_SECRETS_KEY = \"" . base64_encode(random_bytes(32)) . "\";\n");
' "${WORKDIR}/halfacre-private/secrets.key.php"
export HALFACRE_SECRETS_KEY_FILE="${WORKDIR}/halfacre-private/secrets.key.php"

kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
php -S "${HOST}:${PORT}" -t "$ROOT" >"${WORKDIR}/php-server.log" 2>&1 &
SERVER_PID=$!
sleep 0.4
if ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo "php -S failed to restart" >&2
  cat "${WORKDIR}/php-server.log" >&2
  exit 1
fi

# --- auth: empty c 400, no token 401, bad/cross token 403 ---
code="$(curl_code "${BASE}/van-sfox.php")"
expect_code "van-sfox empty c" 400 "$code"
if grep -Eiq 'charley-van-halfacre' "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  empty c fell back to Charley"
else
  PASS=$((PASS + 1))
  note "PASS  empty c did not fall back to Charley"
fi

code="$(curl_code "${BASE}/van-sfox.php?c=")"
expect_code "van-sfox blank c=" 400 "$code"

code="$(curl_code "${BASE}/van-sfox.php?c=not-a-client")"
expect_code "van-sfox unknown c" 400 "$code"
if grep -Eiq 'charley-van-halfacre' "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  unknown c fell back to Charley"
else
  PASS=$((PASS + 1))
  note "PASS  unknown c did not fall back to Charley"
fi

code="$(curl_code "${BASE}/van-sfox.php?c=van")"
expect_code "van-sfox no token" 401 "$code"

code="$(curl_code "${BASE}/van-sfox.php?c=van&t=${BAD_TOKEN}")"
expect_code "van-sfox bad token" 403 "$code"

code="$(curl_code "${BASE}/van-sfox.php?c=abcdef1234567890&t=${VAN_TOKEN}")"
expect_code "van-sfox cross-client token" 403 "$code"

code="$(curl_code "${BASE}/client-secrets.php")"
expect_code "client-secrets empty c" 400 "$code"
if grep -Eiq 'charley-van-halfacre' "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  client-secrets empty c fell back to Charley"
else
  PASS=$((PASS + 1))
  note "PASS  client-secrets empty c did not fall back to Charley"
fi

# --- lib round-trip ---
if php "${ROOT}/tests/client-secrets-lib-test.php"; then
  PASS=$((PASS + 1))
  note "PASS  client-secrets-lib-test.php"
else
  FAIL=$((FAIL + 1))
  note "FAIL  client-secrets-lib-test.php"
fi

# --- HTTP write encrypts; read never returns the key ---
SFOX_KEY="sfox-test-secret-key-ABCDEF123456"
code="$(curl_code -X POST -H "Content-Type: application/json" \
  -d "{\"c\":\"charley-van-halfacre\",\"key\":\"${SFOX_KEY}\"}" \
  "${BASE}/client-secrets.php")"
expect_code "client-secrets POST encrypts" 200 "$code"
expect_body "saved flag" '"saved":true'
expect_body "hint last-4" "3456"
if grep -q -- "$SFOX_KEY" "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  client-secrets POST leaked the key"
else
  PASS=$((PASS + 1))
  note "PASS  client-secrets POST did not return the key"
fi

STORE_FILE="${WORKDIR}/halfacre-private/client-secrets.store.json"
if [[ -f "$STORE_FILE" ]] && ! grep -q -- "$SFOX_KEY" "$STORE_FILE"; then
  PASS=$((PASS + 1))
  note "PASS  plaintext key absent from encrypted store file"
else
  FAIL=$((FAIL + 1))
  note "FAIL  plaintext key found in store (or store missing)"
  note "      store: $(cat "$STORE_FILE" 2>/dev/null || echo missing)"
fi

code="$(curl_code "${BASE}/van-sfox.php?c=van&t=${VAN_TOKEN}")"
expect_code "van-sfox decrypts and stubs balances" 200 "$code"
expect_body "connected after save" '"connected":true'
expect_body "stub USD holding" '"USD"'
if grep -q -- "$SFOX_KEY" "${WORKDIR}/body.json"; then
  FAIL=$((FAIL + 1))
  note "FAIL  van-sfox leaked the key"
else
  PASS=$((PASS + 1))
  note "PASS  van-sfox did not return the key"
fi

# --- CLI-only migration ---
cat > "${WORKDIR}/plaintext-client-secrets.store.json" <<JSON
{
  "charley-van-halfacre": {
    "sfox": {
      "hint": "••••9999",
      "savedAt": 1,
      "key": "plaintext-migrate-key-9999XXXX"
    }
  },
  "abcdef1234567890": {
    "sfox": {
      "hint": "••••abcd",
      "savedAt": 2,
      "key": "plaintext-migrate-hex-key-abcd"
    }
  }
}
JSON
MIGRATE_STORE="${WORKDIR}/halfacre-private/migrated.store.json"
export HALFACRE_SECRETS_STORE="${MIGRATE_STORE}"
MIG_OUT="$(
  HALFACRE_SECRETS_KEY_FILE="${WORKDIR}/halfacre-private/secrets.key.php" \
  HALFACRE_SECRETS_STORE="${MIGRATE_STORE}" \
  HALFACRE_SECRETS_PLAINTEXT_STORE="${WORKDIR}/plaintext-client-secrets.store.json" \
  php "${ROOT}/scripts/migrate-client-secrets.php"
)"
echo "$MIG_OUT" >> "$LOG"
if grep -q "migrated clients: 2" <<<"$MIG_OUT" && grep -q "migrated keys: 2" <<<"$MIG_OUT"; then
  PASS=$((PASS + 1))
  note "PASS  migration printed counts"
else
  FAIL=$((FAIL + 1))
  note "FAIL  migration counts"
  note "      ${MIG_OUT}"
fi
if grep -Eiq 'plaintext-migrate-key|9999XXXX|hex-key-abcd' <<<"$MIG_OUT"; then
  FAIL=$((FAIL + 1))
  note "FAIL  migration printed a key value"
else
  PASS=$((PASS + 1))
  note "PASS  migration did not print key values"
fi
if [[ -f "$MIGRATE_STORE" ]] && ! grep -Eiq 'plaintext-migrate-key|9999XXXX|hex-key-abcd' "$MIGRATE_STORE"; then
  PASS=$((PASS + 1))
  note "PASS  migrated store has no plaintext keys"
else
  FAIL=$((FAIL + 1))
  note "FAIL  migrated store leaked plaintext or missing"
fi

code="$(curl_code "${BASE}/scripts/migrate-client-secrets.php")"
expect_code "migration refuses web" 403 "$code"
expect_body "migration web copy" "CLI only"

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
note "Log: ${LOG}"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
