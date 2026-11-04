# HANDOFF — ZKTimeNet Personal Framework 1.2.20

## Release 1.2.20 — Carga de logotipo en identidad visual

- **Sistema → Identidad visual** permite cargar, reemplazar o quitar el logotipo de empresa; la vista previa se actualiza al elegir una imagen. Las siglas existentes siguen como alternativa cuando no hay imagen configurada.
- Formatos admitidos: PNG, JPG/JPEG, GIF y WebP; límite de 2 MiB, 2400 × 1200 píxeles y 2.5 megapíxeles. SVG se rechaza para no introducir contenido activo.
- `src/Services/BrandLogo.php` valida el contenido real con `getimagesize`, MIME permitido, dimensiones y ubicación del archivo. Usa un nombre aleatorio y conserva el archivo fuera del DocumentRoot en el directorio privado existente.
- `index.php?action=brand_logo` sirve únicamente el logo activo con MIME validado y `nosniff`; la ruta pública es necesaria para mostrar la marca antes de iniciar sesión y no permite al cliente indicar una ruta de archivo.
- La carga, reemplazo y eliminación se procesan en la acción administrativa protegida por sesión y CSRF. El archivo anterior se elimina después de confirmar el cambio en SQLite; no se agrega una tabla ni se requiere migración (esquema continúa en 5).
- El logo se presenta en el menú lateral, la pantalla de acceso y la vista previa de Identidad visual. Las preferencias de texto y las siglas conservan su funcionamiento anterior.
- `ConfigurationBackup` ya copia el contenido privado completo; el logo se incluye en el ZIP junto con SQLite, `app.key` y los demás recursos privados. La restauración también recupera los archivos.
- Se agrega `tests/brand-logo-smoke.php` y se amplía `tests/branding-smoke.js`; las referencias frontend pasan a `1.2.20`.

## Release 1.2.19 — Ancho completo global

- Todas las pantallas autenticadas utilizan el ancho disponible dentro de `.main-area`, igual que lo hacía **Sistema → Actualizaciones**.
- `views/layout.php` utiliza un único `<section class="page-content">` para todas las páginas; ya no agrega una clase condicional solo para Actualizaciones.
- `assets/app.css` elimina el límite `max-width:1600px` y el centrado automático del wrapper global. Las vistas se expanden al área entre el menú lateral y el borde derecho.
- Se conservan los gutters responsive: 34 px escritorio, 22 px hasta 1120 px, y 13 px en móviles. Se mantienen los puntos de quiebre existentes para dashboards, formularios, tablas y grids.
- Los anchos limitados de campos/formularios individuales (por ejemplo, cambio de contraseña) se conservan intencionalmente para legibilidad; el contenedor de la página y sus paneles/grid ya no quedan restringidos por un max-width global.
- Se agregó `tests/all-pages-full-width-smoke.js`; el contrato de backup/restore verifica el wrapper común en lugar de una clase exclusiva de Actualizaciones.
- No hay cambios en SQLite, migraciones, servicios, permisos, autenticación, endpoints, almacenamiento ni comportamiento de backup/restore.

# HANDOFF — ZKTimeNet Personal Framework 1.2.18

## Release 1.2.18 — Organización visual de Actualizaciones

- La página **Sistema → Actualizaciones** se estructura en estado de base de datos y ubicación/respaldo de archivos.
- Plataforma, SAPI, PHP y extensiones se muestran dentro del panel de estado; se elimina la tarjeta inferior duplicada y la segunda aparición de la versión de la aplicación.
- Las rutas se agrupan por datos persistentes y archivos de configuración del sitio, con cuadrícula responsiva.
- Se conservan las acciones y validaciones de migración, descarga ZIP y restauración; no hay cambios de esquema ni de lógica de seguridad.
- Verificación: `php -l` pasa en 55 PHP; pasan las 26 suites JavaScript, incluidas las pruebas de layout y contrato del backup/restore. Pasan las pruebas PHP independientes de logging, proxy/sesión, firma, SMTP y destinatarios. Las pruebas que requieren PDO_SQLite/ZIP hacen SKIP en este entorno y quedan para staging IIS/PHP 8.5. Los formularios mantienen `run_migrations`, `download_config_backup` y `restore_config_backup`; el layout smoke test comprueba la ausencia de la tarjeta técnica separada, versión única y presentación responsiva.

## Estado de entrega

- **Versión de aplicación:** 1.2.20 (`src/Core/Version.php`).
- **Esquema SQLite objetivo:** 5.
- **Plataforma mínima:** PHP 8.5; extensiones `pdo_sqlite`, `zip`, `dom`, `openssl`.
- **Despliegue de referencia:** Windows Server + IIS + PHP NTS/FastCGI.
- **Empaquetado:** autoload PSR-4 local; no requiere Composer, Internet ni dependencias remotas.
- **Estructura:** se conserva la estructura del proyecto; no mover las carpetas existentes.
- **Datos persistentes:** fuera del DocumentRoot, con precedencia `ZKTIME_DATA_DIR` → `config/local.php` → ruta predeterminada por plataforma.

## Resumen heredado de 1.2.18

### S-02 — Bloqueo por intentos fallidos

- Nueva migración `005_account_lockout_and_sessions.php` agrega `failed_login_attempts`, `locked_until`, `session_version` e índice por vencimiento.
- Valores predeterminados: **5 intentos fallidos y 10 minutos de bloqueo**.
- La política se puede cambiar en **Administración → Usuarios**: 3–20 intentos, 1–1,440 minutos.
- El bloqueo es persistente en SQLite. El intento que llega al umbral registra el bloqueo en una sola actualización SQL condicional y conserva el contador en 5 para mostrarlo en Usuarios; durante un bloqueo activo no se admite ni siquiera la contraseña correcta.
- Al expirar el bloqueo, el siguiente intento reinicia el contador. Un administrador puede desbloquear cualquier otra cuenta, incluidos otros administradores; se restablece el contador y se revocan sesiones anteriores.

### S-03 — Sesiones y proxy HTTPS

- `Session` activa modo estricto, cookies solamente, sin IDs en URL, HttpOnly y SameSite=Lax.
- `X-Forwarded-Proto` no se acepta desde clientes directos. Para despliegues detrás de proxy TLS, declarar IP exactas en `ZKTIME_TRUSTED_PROXIES` (se compara con `REMOTE_ADDR`).
- `Auth::refresh()` verifica cada petición el estado, bloqueo y `session_version`. Cambio de contraseña, perfil, estado, bloqueo y desbloqueo revocan sesiones antiguas.
- **Trazabilidad:** el `SECURITY-REVIEW.md` que venía en la base 1.2.16 no describía el texto original del identificador S-03. Se implementó endurecimiento de sesión/proxy a partir de los riesgos visibles en el código. Confirmar el texto original del reporte externo si se requiere certificar que se cubre exactamente el mismo hallazgo.

### Respaldo y recuperación

- **Sistema → Actualizaciones** utiliza `page-content-wide` para tomar todo el ancho disponible del área principal.
- La pantalla enseña las rutas físicas de la raíz de aplicación, raíz de datos, SQLite, `private`, `exports`, `backups`, `config/app.php` y `config/local.php`.
- Descarga ZIP `ZKTimeNet-Backup-YYYYmmdd-His-<id>.zip` mediante POST protegido con CSRF.
- Incluye SQLite consistente (`VACUUM INTO`, `integrity_check` y esquema coincidente), `private/app.key` validado a 32 bytes, instalación/archivos privados (por ejemplo, firma de correo), reportes permanentes y `config/app.php`/`config/local.php` como referencia.
- Excluye sesiones, cargas temporales, logs, archivos WAL/SHM activos, locks y respaldos anteriores.
- Los PHP de configuración dentro de `metadata/` son referencias: nunca se ejecutan ni sobrescriben al restaurar.
- La restauración exige sesión Administrador, CSRF y contraseña actual; acepta respaldo de la misma versión 1.2.18 y misma ruta raíz de datos. Valida manifiesto único, entradas declaradas, rutas, enlaces simbólicos, tamaño comprimido (512 MiB), tamaño expandido (2 GiB), longitud real de streams, SHA-256, `app.key` de 32 bytes e integridad SQLite. La versión SQLite debe coincidir con el manifiesto y estar entre esquema 3 y 5.
- El proceso libera el bloqueo de archivo de sesión PHP antes de esperar el lock exclusivo de mantenimiento, evitando interbloqueos con otras pestañas; el bloqueo exclusivo se conserva durante la sustitución y validación. El ZIP de descarga libera el lock después de crear la instantánea, sin bloquear el sitio durante la transferencia.
- Se crea un backup automático del estado anterior y se prepara staging antes de sustituir. Si falla la restauración, se intenta volver al backup previo. Al completar, incrementa `session_version` de todas las cuentas restauradas (revoca sesiones anteriores tanto del sistema activo como del backup), cierra la sesión actual y redirige al login para autenticar con las credenciales del respaldo.
- El ZIP no usa cifrado de contraseña propio: contiene base de datos, hashes de contraseñas y llave SMTP. Guardarlo en destino cifrado y compartirlo solo con personas autorizadas. La copia descargada es temporal en servidor y se elimina al terminar la transferencia; `backups` conserva las copias previas automáticas. El panel requiere SQLite accesible; ante daño que impida autenticarse, `INSTALL.md` describe recuperación manual con IIS detenido.

## Primera instalación (heredada de 1.2.16)

- `install.php` detecta sistema operativo, servidor web, interfaz PHP y versión; valida almacenamiento externo y solicita la contraseña inicial del administrador.
- `config/app.php` no contiene usuario/hash de administrador predeterminado. El hash lo genera `password_hash()` y se pasa a la migración histórica 003 en memoria.
- Sin `ZKTIME_DATA_DIR`, la ruta elegida se guarda en `config/local.php` (solo contiene `data_root`).
- Instalaciones con esquema 3/4 actualizan desde **Sistema → Actualizaciones** y conservan credenciales/datos; la migración 005 añade el bloqueo y el control de sesiones. Una base ya en esquema 5 permanece actualizada. Se agrega 005 sin modificar 001–004.
- Instalaciones nuevas llegan a esquema 5. Instalaciones parciales/desconocidas siguen la protección del asistente; no crear nueva `app.key` cuando ya existe SQLite.

## Componentes principales modificados

- `database/migrations/005_account_lockout_and_sessions.php`: nueva migración; no modificar después de publicada.
- `src/Core/Auth.php`: autenticación, bloqueo persistente, política y actualizaciones SQL condicionadas para evitar carreras de inicio de sesión.
- `src/Core/Session.php`: atributos de cookie y confianza explícita de proxy.
- `src/Core/MaintenanceGate.php`: lock compartido/exclusivo de peticiones durante restauración.
- `src/Core/Database.php`: `disconnect()` para liberar PDO antes de sustituir SQLite en Windows/IIS.
- `src/Repositories/UserRepository.php`: campos de bloqueo, incrementos de versión y método `unlock()` sin restricción por rol del usuario objetivo.
- `src/Services/ConfigurationBackup.php`: generación, inventario y restauración ZIP validada.
- `index.php`: acciones de backup/restore, liberación/reapertura controlada de sesión, guardia de mantenimiento, revocación de todas las sesiones restauradas, configuración de política y desbloqueo.
- `views/pages/users.php`: panel de política y desbloqueo por fila.
- `views/pages/updates.php`: pantalla amplia, paths, descarga y restauración.
- `views/layout.php`, `assets/app.css`: ancho completo global de las pantallas autenticadas; caché frontend 1.2.19.
- `web.config`: límite de request IIS de 550,502,400 bytes para upload de respaldo de hasta 512 MiB.

## Migraciones

- `001_initial.php` a `004_department_discovery.php`: históricas e inmutables.
- `005_account_lockout_and_sessions.php`: versión 5.
- `Version::SCHEMA_TARGET = 5`.
- Próxima migración: `006_*.php`.
- Antes de desplegar 1.2.19 sobre esquema 3/4, respaldar datos externos; después, iniciar sesión como administrador y aplicar migraciones en **Sistema → Actualizaciones**.

## Configuración IIS/PHP

En el `php.ini` real de FastCGI:

```ini
extension=pdo_sqlite
extension=zip
extension=dom
extension=openssl
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 0
max_input_time = 600
memory_limit = 256M
date.timezone = America/Mexico_City
```

- Reciclar Application Pool después de cambios de `php.ini`/variables de entorno.
- Application Pool requiere Modify sobre la ruta externa de datos.
- Si hay proxy TLS, declarar IP de proxy en `ZKTIME_TRUSTED_PROXIES`; no aceptar cualquier `X-Forwarded-Proto`.
- Mantener HTTPS y restricción LAN/VPN. ZIP de backup es sensible; guardar destino cifrado.

## Validación 1.2.19 (release anterior)

- `node tests/all-pages-full-width-smoke.js`: aprobado; las nueve páginas autenticadas comparten el contenedor sin límite de ancho y conservan gutters responsivos.
- Las 27 suites JavaScript `tests/*smoke.js`: aprobadas con Node.js 22.16.0.
- `php -l`: aprobado en 55 archivos PHP con PHP CLI 8.4.24 (solo validación sintáctica).
- Pruebas PHP independientes de logging, proxy HTTPS, firma, SMTP/MIME y destinatarios: aprobadas.
- Bloqueo, instalación y backup/restore requieren PDO_SQLite/ZIP ausentes en este entorno; ejecutar esas pruebas en staging IIS con PHP 8.5. El cambio 1.2.19 es exclusivamente de presentación.

## Validación ejecutada en esta entrega

- `php -l` en los 55 archivos PHP: aprobado (PHP CLI 8.4.24; validación de sintaxis).
- Las 25 suites JavaScript `tests/*smoke.js` pasaron con Node.js 22.16.0.
- Pasaron los smoke PHP que no dependen de extensiones ausentes: proxy HTTPS, destinatarios de departamento, registro privado de errores, imagen de firma y respuesta SMTP/MIME.
- Se agregaron `tests/login-lockout-smoke.js`, `tests/configuration-backup-smoke.js` y `tests/session-hardening-smoke.js`.
- Se agregaron pruebas funcionales `tests/account-lockout-smoke.php` y `tests/configuration-backup-smoke.php`; requieren PDO_SQLite y ZIP. `tests/installation-state-smoke.php` y `tests/smoke.php` también se omitieron por faltantes/runtime PHP.
- **Limitación del entorno de desarrollo:** solo hay PHP 8.4.24 (la aplicación requiere 8.5+) y no están instaladas las extensiones `pdo_sqlite`, `zip` ni `dom`. Por ello no se ejecutaron pruebas funcionales PHP SQLite/ZIP ni despliegue real IIS/FastCGI. Como contraste, una prueba independiente con SQLite de Python validó el DDL de la migración 005, bloqueo al quinto fallo, bloqueo activo, desbloqueo de administrador a administrador y revocación de sesiones tras restaurar; no sustituye ejecutar los tests PHP en IIS/PHP 8.5.
- No se validó envío SMTP real.

## Pruebas a ejecutar antes de producción

1. En staging IIS/PHP 8.5, correr `php tests/account-lockout-smoke.php`, `php tests/configuration-backup-smoke.php`, `php tests/smoke.php` y suites JavaScript.
2. Probar inicio de sesión con 4 contraseñas fallidas, quinto fallo, espera/expiración y rechazo de la contraseña correcta mientras hay bloqueo.
3. Abrir otra cuenta de Administrador y desbloquear la cuenta anterior desde **Usuarios**; validar que la sesión previa quede revocada.
4. Descargar ZIP y abrirlo con 7-Zip. Verificar manifiesto y que contiene `private/zktime.sqlite`, `private/app.key`, firma/reportes y metadata config; comprobar que no contiene sesiones/logs.
5. Restaurar en una copia de staging usando un backup de la misma instalación; confirmar que SQLite, SMTP, branding, departamentos, usuarios y reportes regresan al estado guardado, que el login se cierra y que se usan las credenciales del backup.
6. Probar un ZIP manipulado, checksum alterado, archivo demasiado grande y ruta ZIP traversal; todos deben rechazarse sin tocar datos activos.
7. Probar rollback con una copia desechable y revisar el backup `pre-restore` que queda en `backups`.
8. Confirmar la ubicación real que aparece en **Actualizaciones** y que el administrador puede acceder/respaldar esa ruta de forma independiente.

## Limitaciones conocidas / siguientes pasos

- No se probó restauración real sobre Windows/IIS; Windows mantiene bloqueos de archivos más estrictos que Linux y requiere liberar referencias PDO, por eso `Database::disconnect()` y recolección de ciclos antes de sustituir SQLite son intencionales.
- La restauración requiere exactamente la versión de aplicación que generó el ZIP y la misma raíz de datos; no es una herramienta de actualización entre versiones. El ZIP solicitado se entrega al navegador y la copia temporal del servidor se elimina; los respaldos automáticos previos a migración/restauración sí permanecen en `backups`.
- El ZIP no está cifrado con una contraseña propia. Debe guardarse en volumen/servicio cifrado y con ACL estricta.
- S-03 original no venía descrito en el paquete base 1.2.16. Ver `SECURITY-REVIEW.md` para la aclaración de alcance.
- Confirmar texto original de S-03 si proviene de un scanner/reporte externo y hacer revisión cruzada.

## Reglas de mantenimiento

- No cambiar estructura de carpetas ni introducir Composer/dependencias remotas sin aprobación.
- No alterar migraciones históricas 001–005; agregar migraciones nuevas con secuencia y checksum.
- Preservar `private/zktime.sqlite`, `private/app.key`, reportes, datos de usuario y `config/local.php` al actualizar.
- No implementar descargas anónimas hasta que el usuario lo solicite expresamente.
- Mantener este `HANDOFF.md` actualizado en cada versión.
