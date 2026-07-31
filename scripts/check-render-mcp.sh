#!/usr/bin/env bash
# Verify Render MCP local config before restarting Cursor.
set -euo pipefail

ENV_FILE="${HOME}/.cursor/secrets/render.env"
WRAPPER="${HOME}/.cursor/bin/render-mcp-wrapper.sh"
BIN="${HOME}/.local/bin/render-mcp-server"

ok=0
fail() { echo "FAIL: $*" >&2; ok=1; }

[[ -x "${BIN}" ]] || fail "render-mcp-server not installed"
[[ -x "${WRAPPER}" ]] || fail "wrapper missing at ${WRAPPER}"

if [[ ! -f "${ENV_FILE}" ]]; then
  fail "missing ${ENV_FILE} — run scripts/setup-render-mcp.sh"
else
  python3 - <<'PY' "${ENV_FILE}" || fail "RENDER_API_KEY empty or invalid in ${ENV_FILE}"
import sys
from pathlib import Path
p = Path(sys.argv[1])
for line in p.read_text().splitlines():
    if line.startswith("RENDER_API_KEY="):
        val = line.split("=", 1)[1].strip()
        if not val:
            raise SystemExit(1)
        if not val.startswith("rnd_"):
            print(f"WARN: key length {len(val)} but does not start with rnd_")
        print(f"OK: RENDER_API_KEY set ({len(val)} chars)")
        raise SystemExit(0)
raise SystemExit(1)
PY
fi

if [[ "${ok}" -eq 0 ]]; then
  echo "Render MCP config looks good. Reload render in Cursor Settings → MCP."
else
  exit 1
fi
