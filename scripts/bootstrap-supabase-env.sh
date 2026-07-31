#!/usr/bin/env bash
# Pull Supabase API keys into .env (Suly Multiservicios project).
# Option A: export SUPABASE_ACCESS_TOKEN=sbp_... (Dashboard → Account → Access Tokens)
# Option B: supabase login  (must list project anylxnmqcuggylugkipn)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
REF="anylxnmqcuggylugkipn"

cd "$ROOT"

SERVICE=""
ANON=""

if [[ -n "${SUPABASE_ACCESS_TOKEN:-}" ]]; then
  echo "Fetching API keys via Supabase Management API..."
  JSON="$(curl -sS -H "Authorization: Bearer ${SUPABASE_ACCESS_TOKEN}" \
    "https://api.supabase.com/v1/projects/${REF}/api-keys?reveal=true")"
  SERVICE="$(python3 -c 'import json,sys; d=json.load(sys.stdin); print(next((k["api_key"] for k in d if k.get("name")=="service_role"), ""))' <<<"$JSON")"
  ANON="$(python3 -c 'import json,sys; d=json.load(sys.stdin); print(next((k["api_key"] for k in d if k.get("name")=="anon"), ""))' <<<"$JSON")"
elif command -v supabase >/dev/null 2>&1 && supabase projects list 2>/dev/null | rg -q "$REF"; then
  echo "Fetching API keys via Supabase CLI..."
  TMP="$(mktemp)"
  supabase projects api-keys --project-ref "$REF" -o env >"$TMP"
  SERVICE="$(rg -o 'SUPABASE_SERVICE_ROLE_KEY=.*' "$TMP" | head -1 | cut -d= -f2- || true)"
  ANON="$(rg -o 'SUPABASE_ANON_KEY=.*' "$TMP" | head -1 | cut -d= -f2- || true)"
  rm -f "$TMP"
else
  echo "Cannot fetch service_role key automatically." >&2
  echo "Either:" >&2
  echo "  1) export SUPABASE_ACCESS_TOKEN=sbp_... && $0" >&2
  echo "  2) supabase login  (Suly Multiservicios) && $0" >&2
  exit 1
fi

if [[ -z "$SERVICE" ]]; then
  echo "Could not read service_role key." >&2
  exit 1
fi

python3 - "$ENV_FILE" "$SERVICE" "$ANON" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
service = sys.argv[2]
anon = sys.argv[3] if len(sys.argv) > 3 else ""

lines = path.read_text().splitlines() if path.is_file() else []
out, seen = [], set()
for line in lines:
    if line.startswith("SUPABASE_SERVICE_ROLE_KEY="):
        out.append(f"SUPABASE_SERVICE_ROLE_KEY={service}")
        seen.add("SUPABASE_SERVICE_ROLE_KEY")
    elif line.startswith("SUPABASE_ANON_KEY=") and anon:
        out.append(f"SUPABASE_ANON_KEY={anon}")
        seen.add("SUPABASE_ANON_KEY")
    else:
        out.append(line)
if "SUPABASE_SERVICE_ROLE_KEY" not in seen:
    out.append(f"SUPABASE_SERVICE_ROLE_KEY={service}")
path.write_text("\n".join(out) + "\n")
print(f"Updated {path} (SUPABASE_SERVICE_ROLE_KEY set, len={len(service)})")
PY

echo "Done. Restart PHP if running."
