#!/usr/bin/env bash
# "Logout" from Render MCP: remove local API key (Render has no OAuth session).
set -euo pipefail

ENV_FILE="${HOME}/.cursor/secrets/render.env"

if [[ -f "${ENV_FILE}" ]]; then
  rm -f "${ENV_FILE}"
  echo "Removed ${ENV_FILE}"
else
  echo "No ${ENV_FILE} — already logged out locally."
fi

echo "Disable or reload the render MCP server in Cursor Settings → MCP."
