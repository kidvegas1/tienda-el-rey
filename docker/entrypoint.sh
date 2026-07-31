#!/bin/bash
set -euo pipefail

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true
sed -i "s/:8080>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

apply_migration() {
  local migration_path="$1"
  local migration
  migration="$(basename "$migration_path")"
  local applied
  applied="$(psql "$DATABASE_URL" -tAc "SELECT 1 FROM schema_migrations WHERE version = '${migration}' LIMIT 1" 2>/dev/null | tr -d '[:space:]' || true)"
  if [ "$applied" = "1" ]; then
    return 0
  fi
  echo "[entrypoint] Applying ${migration}..."
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$migration_path"
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c "INSERT INTO schema_migrations (version) VALUES ('${migration}') ON CONFLICT (version) DO NOTHING;"
}

# Bootstrap Postgres without deleting pre-existing data.
if [ -n "${DATABASE_URL:-}" ] && [ -f /var/www/html/supabase/migrations/001_initial.sql ]; then
  if command -v psql >/dev/null 2>&1; then
    psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c "CREATE TABLE IF NOT EXISTS schema_migrations (
      version VARCHAR(255) PRIMARY KEY,
      applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );"

    users_count="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users'" 2>/dev/null || echo 0)"
    users_count="$(echo "$users_count" | tr -d '[:space:]')"
    if [ "${users_count:-0}" = "0" ]; then
      table_count="$(psql "$DATABASE_URL" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'" 2>/dev/null || echo 0)"
      table_count="$(echo "$table_count" | tr -d '[:space:]')"
      if [ "${table_count:-0}" != "0" ]; then
        echo "[entrypoint] ERROR: Partial public schema detected (${table_count} tables) without users."
        echo "[entrypoint] Refusing destructive reset; repair or migrate the database manually."
        exit 1
      fi
      echo "[entrypoint] Seeding Postgres schema (001_initial.sql)..."
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/supabase/migrations/001_initial.sql
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c "INSERT INTO schema_migrations (version) VALUES ('001_initial.sql') ON CONFLICT (version) DO NOTHING;"
      echo "[entrypoint] Schema seed complete."
    else
      ledger_count="$(psql "$DATABASE_URL" -tAc 'SELECT COUNT(*) FROM schema_migrations' 2>/dev/null | tr -d '[:space:]' || echo 0)"
      if [ "${ledger_count:-0}" = "0" ]; then
        echo "[entrypoint] Backfilling migration ledger for existing schema..."
        for migration_path in /var/www/html/supabase/migrations/[0-9]*.sql; do
          migration="$(basename "$migration_path")"
          case "$migration" in
            015_schema_migrations.sql|016_revoke_public_api_grants.sql)
              continue
              ;;
          esac
          psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c "INSERT INTO schema_migrations (version) VALUES ('${migration}') ON CONFLICT (version) DO NOTHING;"
        done
      fi
    fi

    for migration_path in /var/www/html/supabase/migrations/[0-9]*.sql; do
      migration="$(basename "$migration_path")"
      case "$migration" in
        001_initial.sql)
          continue
          ;;
      esac
      apply_migration "$migration_path"
    done

    if [ -n "${ADMIN_BOOTSTRAP_EMAIL:-}" ] || [ -n "${ADMIN_BOOTSTRAP_PASSWORD:-}" ]; then
      php /var/www/html/scripts/bootstrap-admin.php
    fi
  else
    echo "[entrypoint] ERROR: psql not found; cannot bootstrap Postgres."
    exit 1
  fi
fi

exec "$@"
