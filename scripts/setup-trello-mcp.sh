#!/usr/bin/env bash
# Configure Trello MCP for Cursor (API key + token auth).
set -euo pipefail

SECRETS_DIR="${HOME}/.cursor/secrets"
ENV_FILE="${SECRETS_DIR}/trello.env"
WRAPPER="${HOME}/.cursor/bin/trello-mcp-wrapper.sh"

mkdir -p "${SECRETS_DIR}" "${HOME}/.cursor/bin"
chmod 700 "${SECRETS_DIR}"

if [[ ! -x "${WRAPPER}" ]]; then
  echo "Missing ${WRAPPER} — reinstall Cursor MCP wrapper from project scripts."
  exit 1
fi

if [[ -f "${ENV_FILE}" ]] && python3 - <<'PY' "${ENV_FILE}"
import sys
from pathlib import Path
p = Path(sys.argv[1])
values = {}
for line in p.read_text().splitlines():
    if "=" in line and not line.lstrip().startswith("#"):
        k, v = line.split("=", 1)
        values[k.strip()] = v.strip()
if values.get("TRELLO_API_KEY") and values.get("TRELLO_TOKEN"):
    raise SystemExit(0)
raise SystemExit(1)
PY
then
  echo "Trello MCP credentials already set in ${ENV_FILE}"
  echo "To replace them, delete that file and run this script again."
  exit 0
fi

echo "Get your Trello API key: https://trello.com/app-key"
echo "On that page, click the link to generate a token (read + write access recommended)."
echo
read -rsp "Paste TRELLO_API_KEY (hidden): " api_key
echo
read -rsp "Paste TRELLO_TOKEN (hidden): " token
echo

if [[ -z "${api_key}" || -z "${token}" ]]; then
  echo "Both TRELLO_API_KEY and TRELLO_TOKEN are required. Aborting."
  exit 1
fi

umask 077
{
  printf 'TRELLO_API_KEY=%s\n' "${api_key}"
  printf 'TRELLO_TOKEN=%s\n' "${token}"
} > "${ENV_FILE}"
chmod 600 "${ENV_FILE}"

echo "Saved ${ENV_FILE}"
echo "Restart Cursor (Settings → MCP → reload trello)."
