#!/usr/bin/env bash
# Quick Postgres + bulk-import smoke test (local PHP on 8081).
set -euo pipefail

BASE="${SMOKE_BASE_URL:-http://127.0.0.1:8081}"
: "${SMOKE_EMAIL:?Set SMOKE_EMAIL to an existing test account}"
: "${SMOKE_PASSWORD:?Set SMOKE_PASSWORD for that test account}"
COOKIE="/tmp/tienda_el_rey_smoke_cookies.txt"
PDF="/tmp/tienda_el_rey_smoke_test.pdf"
LOGIN_PAYLOAD="$(php -r 'echo json_encode([
  "action" => "login",
  "email" => getenv("SMOKE_EMAIL"),
  "password" => getenv("SMOKE_PASSWORD"),
]);')"

cat >"$PDF" <<'EOF'
%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF
EOF

echo "== health =="
curl -sS "$BASE/api/health"
echo ""

echo "== login =="
CSRF="$(curl -sS -X POST "$BASE/api/auth" \
  -H 'Content-Type: application/json' \
  -d "$LOGIN_PAYLOAD" \
  -c "$COOKIE" | php -r 'echo json_decode(file_get_contents("php://stdin"))->csrf;')"
echo "csrf ok"

AGENCY="SMOKE$(date +%s)"
echo "== bulk_import ($AGENCY) =="
curl -sS -X POST "$BASE/api/barri-reports" \
  -b "$COOKIE" \
  -H "X-CSRF-Token: $CSRF" \
  -F 'action=bulk_import' \
  -F 'skip_duplicates=1' \
  -F 'store_id=1' \
  -F "reports=[{\"agency_name\":\"Smoke Test\",\"agency_number\":\"$AGENCY\",\"report_date_from\":\"2026-03-01\",\"report_date_to\":\"2026-03-07\",\"transactions\":[{\"customer_name\":\"Smoke Client\",\"transaction_date\":\"2026-03-05\",\"principal\":10,\"fee\":1,\"tax\":0,\"total\":11}]}]" \
  -F "pdf_files=@$PDF;type=application/pdf"
echo ""

if rg -q '^SUPABASE_SERVICE_ROLE_KEY=.\+' "$(cd "$(dirname "$0")/.." && pwd)/.env" 2>/dev/null; then
  echo "== storage: SUPABASE_SERVICE_ROLE_KEY is set (uploads should use storage://) =="
else
  echo "== storage: service role missing — PDFs use assets/uploads/ until bootstrap-supabase-env.sh runs =="
fi
