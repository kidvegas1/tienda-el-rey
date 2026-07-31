#!/usr/bin/env bash
# Configure Render MCP for Cursor (API key auth — no OAuth login in Cursor).
set -euo pipefail

SECRETS_DIR="${HOME}/.cursor/secrets"
ENV_FILE="${SECRETS_DIR}/render.env"
MCP_BIN="${HOME}/.local/bin/render-mcp-server"
WRAPPER="${HOME}/.cursor/bin/render-mcp-wrapper.sh"

mkdir -p "${SECRETS_DIR}" "${HOME}/.cursor/bin"
chmod 700 "${SECRETS_DIR}"

if [[ ! -x "${MCP_BIN}" ]]; then
  echo "Installing render-mcp-server..."
  curl -fsSL https://raw.githubusercontent.com/render-oss/render-mcp-server/refs/heads/main/bin/install.sh | sh
fi

if [[ ! -x "${WRAPPER}" ]]; then
  echo "Missing ${WRAPPER} — reinstall Cursor MCP wrapper from project scripts."
  exit 1
fi

if [[ -f "${ENV_FILE}" ]] && python3 - <<'PY' "${ENV_FILE}"
import sys
from pathlib import Path
p = Path(sys.argv[1])
for line in p.read_text().splitlines():
    if line.startswith("RENDER_API_KEY=") and line.split("=", 1)[1].strip().startswith("rnd_"):
        raise SystemExit(0)
raise SystemExit(1)
PY
then
  echo "Render MCP key already set in ${ENV_FILE}"
  echo "To replace it, delete that file and run this script again."
  exit 0
fi

echo "Get a Render API key: https://dashboard.render.com/u/settings#api-keys"
read -rsp "Paste RENDER_API_KEY (hidden): " key
echo

if [[ -z "${key}" ]]; then
  echo "No key entered. Aborting."
  exit 1
fi

if [[ ! "${key}" =~ ^rnd_ ]]; then
  echo "Warning: key does not start with rnd_ — continuing anyway."
fi

umask 077
printf 'RENDER_API_KEY=%s\n' "${key}" > "${ENV_FILE}"
chmod 600 "${ENV_FILE}"

echo "Saved ${ENV_FILE}"
echo "Restart Cursor (Settings → MCP → reload render)."
