#!/usr/bin/env bash
# Smoke test: each app route returns HTML with app.js wired.
set -euo pipefail
BASE="${1:-http://localhost:8080}"
pass=0
fail=0
for slug in login dashboard caja clients libro-interno schedule statistics reports analytics reports-center accounting finances inventory import stores events plates; do
  body=$(curl -sL "$BASE/$slug")
  code=$(curl -sL -o /dev/null -w "%{http_code}" "$BASE/$slug")
  has_doctype=$(echo "$body" | head -1 | grep -ci doctype || true)
  has_app=$(echo "$body" | grep -c "assets/js/app.js" || true)
  if [ "$code" = "200" ] && [ "$has_doctype" -ge 1 ] && [ "$has_app" -ge 1 ]; then
    echo "PASS $slug HTTP=$code"
    pass=$((pass + 1))
  else
    echo "FAIL $slug HTTP=$code doctype=$has_doctype app.js=$has_app"
    fail=$((fail + 1))
  fi
done
echo "Browser smoke: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
