# Convenciones de migraciones SQLite

1. **No edites ni renombres una migración aplicada.** `001_initial.php` es inmutable una vez instalada la aplicación.
2. Para una ampliación, crea el siguiente archivo secuencial, por ejemplo `002_add_delivery_metadata.php`.
3. El archivo debe devolver una función que reciba `PDO` y aplique únicamente los cambios necesarios:

   ```php
   <?php
   declare(strict_types=1);

   return static function (PDO $pdo): void {
       $pdo->exec('ALTER TABLE report_runs ADD COLUMN source_checksum TEXT NULL');
   };
   ```

4. Sube `Version::SCHEMA_TARGET` al nuevo número y actualiza la versión de la aplicación y el `CHANGELOG.md` en el mismo cambio.
5. Mantén el código compatible con el esquema anterior hasta que la migración se haya ejecutado correctamente; evita desplegar código que requiera una columna todavía inexistente.
6. El actualizador calcula SHA-256, valida continuidad y metadatos, bloquea la ejecución concurrente y genera un respaldo SQLite antes de aplicar migraciones pendientes.
7. Si el checksum de una migración aplicada cambia, falta un archivo histórico o hay un salto de numeración, la aplicación detiene el inicio. Restaura el archivo original y crea una migración nueva; no intentes corregir el historial a mano.
8. No se ejecutan migraciones de reversión automáticas. Para revertir un cambio, prepara una migración nueva y compatible o restaura el respaldo previo después de evaluar los datos generados desde la actualización.

La garantía de atomicidad aplica a las operaciones SQLite transaccionales. Conserva siempre un respaldo externo adicional de la base y de `private/app.key` dentro de `C:\ProgramData\CYAlimentos\ZKTimeNet` antes de una actualización de producción.


## Catálogo de migraciones

- `001_initial.php`: tablas base.
- `002_department_responsible.php`: correo del responsable del departamento.
- `003_user_accounts_and_roles.php`: nombre de persona, rol y estado de cuenta; usuario administrador inicial y atribución de ejecuciones históricas sin autor.
- `004_department_discovery.php`: bandera de configuración pendiente para registrar departamentos nuevos sin bloquear el procesamiento; quedan deshabilitados para correo hasta que un administrador los revise.

- `005_account_lockout_and_sessions.php`: contador persistente de intentos fallidos, fecha de desbloqueo y versión de sesión por usuario.
- `006_bulk_mail_batches.php`: identifica cada envío masivo, asocia los eventos SMTP y conserva el estado del comprobante enviado al usuario que inició la operación.

El esquema objetivo de 1.2.26 es 6. La siguiente migración disponible es `007_*.php`. La migración `006_bulk_mail_batches.php` asocia eventos SMTP a una distribución masiva y registra el estado de su comprobante; reutiliza `users.email`, columna existente desde la migración 001. Las migraciones 001 a 006 no deben editarse ni renombrarse después de aplicarse.
