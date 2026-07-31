# Configuración segura

## Desarrollo local con MySQL

1. Copia `.env.example` como `.env` y configura la conexión.
2. Crea el esquema:

   ```bash
   mysql -u root -p < schema.sql
   ```

3. Crea el primer administrador con credenciales propias:

   ```bash
   read -rsp "Contraseña inicial: " ADMIN_BOOTSTRAP_PASSWORD && echo
   export ADMIN_BOOTSTRAP_PASSWORD
   ADMIN_BOOTSTRAP_NAME="Administrador" \
   ADMIN_BOOTSTRAP_EMAIL="tu-correo@dominio.com" \
   php scripts/bootstrap-admin.php
   unset ADMIN_BOOTSTRAP_PASSWORD
   ```

   La contraseña debe tener al menos 14 caracteres e incluir mayúscula, minúscula,
   número y símbolo. El comando no reemplaza administradores existentes.

4. Borra `ADMIN_BOOTSTRAP_PASSWORD` del entorno y arranca la aplicación:

   ```bash
   php -S 127.0.0.1:8080 index.php
   ```

## PostgreSQL en contenedor

El punto de entrada crea el esquema únicamente cuando la base está vacía y aplica
las migraciones numeradas hasta `007_reconciliation.sql`. Nunca elimina un esquema
parcial. Para el primer despliegue, configura temporalmente los tres valores
`ADMIN_BOOTSTRAP_*`; después de crear el administrador, elimina la contraseña del
entorno del servicio.
