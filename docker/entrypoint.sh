#!/bin/bash
set -euo pipefail

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true
sed -i "s/:8080>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

# Bootstrap Postgres without deleting pre-existing data.
if [ -n "${DATABASE_URL:-}" ] && [ -f /var/www/html/supabase/migrations/001_initial.sql ]; then
  if command -v psql >/dev/null 2>&1; then
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
      echo "[entrypoint] Schema seed complete."
    else
      echo "[entrypoint] Postgres schema already present (users table found)."
    fi

    # Application migrations are additive/idempotent and safe on fresh or existing schemas.
    for migration_path in /var/www/html/supabase/migrations/[0-9]*.sql; do
      migration="$(basename "$migration_path")"
      case "$migration" in
        001_initial.sql|002_storage_buckets.sql)
          continue
          ;;
      esac
      echo "[entrypoint] Applying ${migration}..."
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$migration_path"
    done

    # Supabase Storage is optional; apply its bucket migration only when available.
    storage_table="$(psql "$DATABASE_URL" -tAc "SELECT to_regclass('storage.buckets') IS NOT NULL" 2>/dev/null | tr -d '[:space:]' || true)"
    if [ "$storage_table" = "t" ] && [ -f /var/www/html/supabase/migrations/002_storage_buckets.sql ]; then
      echo "[entrypoint] Applying optional Supabase Storage migration..."
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /var/www/html/supabase/migrations/002_storage_buckets.sql
    fi

    if [ -n "${ADMIN_BOOTSTRAP_EMAIL:-}" ] || [ -n "${ADMIN_BOOTSTRAP_PASSWORD:-}" ]; then
      php /var/www/html/scripts/bootstrap-admin.php
    fi
  else
    echo "[entrypoint] ERROR: psql not found; cannot bootstrap Postgres."
    exit 1
  fi
fi

exec "$@"
