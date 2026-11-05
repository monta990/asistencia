## Reparación de GitHub Actions / recuperación de pruebas — PR #4 (2026-11-05)

- Rama: `fix/restore-github-actions-cli-tests`; PR #4 permanece abierto y no debe integrarse hasta que cada job esté verde.
- Referencia: ZIP `ZKTimeNet-Personal-Framework-1.2.31-IIS-LOCAL.zip`. Se recuperó la batería original completa de 48 pruebas en `tests/`. Las 12 ausentes fueron recuperadas de sus blobs originales: brand-logo, bulk-mail-summary-renderer, bulk-mail-summary, card-grid-consistency, department-modal, operation-feedback, report-regeneration, run-source-resolver, session-timeout (PHP/JS), theme-light-controls y user-modal.
- Se normalizó la estructura del árbol: todas las pruebas quedaron dentro de `tests/`, junto con `tests/.htaccess`; los 36 archivos del inventario previo ya no quedan sueltos en la raíz. Esto permite que los globs del workflow ejecuten la suite completa.
- Se restituyó el contenido de producción/documentación que faltaba en el árbol previo del PR. La rama debe conservar el paquete completo, no limitarse a workflows sueltos.
- Los smokes de presentación/frontend ahora comprueban la versión `1.2.31`; los tests de RBAC/departamentos validan `SCHEMA_TARGET = 6`. El test de bloqueo conserva `LOCKOUT_SCHEMA = 5` (subesquema de bloqueo), con objetivo general 6.
- `tests/smoke.php` valida migraciones 001–006 y esquema final 6.
- `tests/configuration-backup-smoke.php` usa el `MigrationManager` y las migraciones reales 001–006 en la DB temporal. Crea una ejecución histórica en `report_runs`, simula el XLSX legado en uploads y comprueba que el ZIP de backup incluya la fuente recuperada byte por byte bajo `data/exports/<run-id>/source.xlsx`. No hay DB real ni datos persistentes.
- `.github/scripts/cli-smoke.php` debe verificar `cleanup`: conserva un XLSX antiguo cuyo ID está en `report_runs`, elimina un XLSX huérfano y confirma el conteo de preservados. El workflow CLI sigue siendo independiente de CI.
- Reponer `.gitignore`, el `.htaccess` de raíz y las protecciones por subdirectorio del paquete IIS. Mantener fuera del repositorio DB reales, credenciales y llaves privadas.
- El manifiesto `SHA256SUMS.txt` debe listar contenido del paquete IIS, recalculado con SHA-256 del contenido exacto (no el hash Git del blob). Los archivos de pruebas recuperados deben quedar listados y verificarse todos; los workflows pueden quedar fuera del manifest de la distribución.
- Versión fija: **1.2.31**. SQLite fijo: **6**. No añadir migraciones ni tocar la lógica productiva sin una justificación demostrable.
- Estado anterior observado en Actions: CLI smoke PHP 8.5/8.6 pasó; CI falló en backup por tabla `report_runs` ausente en fixture, tests frontend anclados a 1.2.19, smoke integrado anclado a esquema 5 y checksum de archivos editados. No desactivar suites para resolver fallos.
- Resultado de Actions de la cabeza reparada y SHA final: pendiente de registrar tras validar los workflows PHP 8.5/8.6, JavaScript Node 22, integridad y CLI PHP 8.5/8.6.

---

