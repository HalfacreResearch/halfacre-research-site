#!/usr/bin/env bash
# Fail if client-page Grok prompts contradict /disclaimer.html.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec php "${ROOT}/tests/grok-disclaimer-guard.php"
