#!/usr/bin/env bash
# The three public legal pages exist, are indexable, and keep AttorneyBot
# article text byte-for-byte inside the <article> tag.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FIX="${ROOT}/tests/legal-fragments"
PASS=0
FAIL=0

note() {
  echo "$1"
}

pass() {
  PASS=$((PASS + 1))
  note "PASS  $1"
}

fail() {
  FAIL=$((FAIL + 1))
  note "FAIL  $1"
}

article_bytes() {
  python3 - "$1" <<'PY'
from pathlib import Path
import sys
raw = Path(sys.argv[1]).read_bytes()
start = raw.find(b"<article")
end = raw.find(b"</article>") + len(b"</article>")
if start < 0 or end < len(b"</article>"):
    sys.stderr.write("no article tag in %s\n" % sys.argv[1])
    sys.exit(2)
sys.stdout.buffer.write(raw[start:end])
PY
}

for name in privacy terms refunds; do
  page="${ROOT}/${name}.html"
  want="${FIX}/${name}.html"
  if [[ ! -f "$page" ]]; then
    fail "${name}.html exists"
    continue
  fi
  pass "${name}.html exists"

  if grep -qi 'noindex' "$page"; then
    fail "${name}.html is indexable (no noindex)"
  else
    pass "${name}.html is indexable (no noindex)"
  fi

  if [[ ! -f "$want" ]]; then
    fail "${name} fixture exists"
    continue
  fi

  if cmp -s <(article_bytes "$page") <(article_bytes "$want"); then
    pass "${name}.html article matches AttorneyBot fragment byte-for-byte"
  else
    fail "${name}.html article does not match fixture"
  fi
done

note ""
note "Results: ${PASS} passed, ${FAIL} failed"
if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
