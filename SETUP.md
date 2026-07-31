# Configuración segura

## Desarrollo local (Supabase Postgres)

Production and local development both use **Supabase Postgres** via `DATABASE_URL`.

1. Copy `.env.example` to `.env` and set:
   - `DATABASE_URL=postgresql://...` (Supabase connection string)
   - `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` for file uploads
2. Apply migrations (or let Docker entrypoint apply them on first boot):

   ```bash
   for f in supabase/migrations/[0-9]*.sql; do psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"; done
   ```

3. Create the first admin:

   ```bash
   read -rsp "Contraseña inicial: " ADMIN_BOOTSTRAP_PASSWORD && echo
   export ADMIN_BOOTSTRAP_PASSWORD
   ADMIN_BOOTSTRAP_NAME="Administrador" \
   ADMIN_BOOTSTRAP_EMAIL="tu-correo@dominio.com" \
   php scripts/bootstrap-admin.php
   unset ADMIN_BOOTSTRAP_PASSWORD
   ```

4. Start the app:

   ```bash
   php -S 127.0.0.1:8080 index.php
   ```

## PostgreSQL en contenedor (Render)

The entrypoint seeds `001_initial.sql` only on an empty database, backfills
`schema_migrations` on existing schemas, then applies any new numbered migrations
once. For the first deploy, set `ADMIN_BOOTSTRAP_*` temporarily; remove the
password from the service environment after bootstrap.
