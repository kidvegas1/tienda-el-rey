#!/usr/bin/env bash
# Smoke test: each app route returns HTML with app.js wired (or redirects for retired routes).
set -euo pipefail
BASE="${1:-http://localhost:8080}"
pass=0
fail=0

check_page() {
  local slug="$1"
  local body code has_doctype has_app
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
}

check_redirect() {
  local slug="$1"
  local expect="$2"
  local location code
  code=$(curl -sL -o /dev/null -w "%{http_code}" "$BASE/$slug")
  location=$(curl -sI "$BASE/$slug" | awk -F': ' 'tolower($1)=="location"{print $2}' | tr -d '\r')
  if [ "$code" = "302" ] || [ "$code" = "301" ]; then
    if echo "$location" | grep -q "$expect"; then
      echo "PASS $slug redirect -> $expect"
      pass=$((pass + 1))
    else
      echo "FAIL $slug redirect location=$location expected=$expect"
      fail=$((fail + 1))
    fi
  else
    echo "FAIL $slug expected redirect got HTTP=$code"
    fail=$((fail + 1))
  fi
}

for slug in login dashboard caja clients company-verification libro-interno schedule employees statistics reports analytics metas security reports-center accounting finances receipts inventory sales-log import stores; do
  check_page "$slug"
done

for slug in events plates suly-ledger; do
  check_redirect "$slug" "/inventory"
done

echo "Browser smoke: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
