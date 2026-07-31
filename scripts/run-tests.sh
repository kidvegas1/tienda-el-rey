#!/usr/bin/env bash
# Run automated tests (TDD gate before deploy).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== PHP syntax =="
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null
done < <(find api includes -name '*.php' -print0)

echo "== gemini sanitizer =="
php scripts/test-gemini-sanitize.php

echo "== gemini api live (optional) =="
php scripts/test-gemini-api.php || true

echo "== intermex giros enviados pdf =="
node scripts/test-intermex-giros-enviados-pdf.mjs

echo "== personal transfer xlsx =="
node scripts/test-personal-transfer-xlsx.mjs

echo "== viamericas pdf parse =="
node scripts/test-viamericas-pdf-parse.mjs

echo "== viamericas estado de cuenta pdf =="
node scripts/test-viamericas-estado-cuenta-pdf.mjs
node scripts/test-viamericas-junio2026-pdf.mjs

echo "== barri agency activity pdf =="
node scripts/test-barri-agency-pdf.mjs

echo "== txn money flow colors =="
node scripts/test-txn-money-flow.mjs

echo "== barri agency activity totals =="
php scripts/test-barri-agency-totals.php

echo "== barri auto-match helpers =="
php scripts/test-barri-auto-match.php

echo "== store filter bindings =="
php scripts/test-store-filter-bindings.php

echo "== caja update guards =="
php scripts/test-caja-update.php

echo "== client security + txn analytics =="
php scripts/test-client-security.php

echo "== suly ledger mark paid =="
php scripts/test-libro-interno-paid.php

echo "== browser page smoke (curl) =="
bash scripts/browser-page-smoke.sh http://localhost:8080 || true

echo "== All tests passed =="
