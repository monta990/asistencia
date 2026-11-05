# Revisión de seguridad 1.2.31 — Ajustes visuales de dashboard y tarjetas

La versión 1.2.31 solo modifica estilos de presentación y el orden de enlaces existentes en el menú lateral. No añade endpoints, formularios, operaciones de escritura, permisos, datos de entrada ni cambios de almacenamiento. Los identificadores de navegación y las rutas permanecen intactos. No se modifica la superficie de seguridad ni el esquema SQLite.

La versión 1.2.30 solo reorganiza la presentación del catálogo de departamentos. El alta/edición continúa usando `save_department`, el control de acceso de la página, CSRF, validación de `DepartmentRepository::save()` y la transacción existente. No crea endpoints nuevos, no acepta identificadores de destinatario ni rutas desde el cliente y no cambia las operaciones de borrado o distribución. Los valores colocados en atributos `data-department-*` se escapan con `Html::escape`; la lista de destinatarios se serializa con `json_encode` usando `JSON_HEX_TAG`, `JSON_HEX_APOS`, `JSON_HEX_QUOT` y `JSON_HEX_AMP` antes del escape HTML. No se exponen contraseñas, claves SMTP ni otros secretos. No hay cambio de esquema SQLite ni ampliación de privilegios.

La versión 1.2.29 fue una reorganización de presentación. La lista de usuarios conserva el acceso exclusivo a Administradores. El modal de alta/edición reutiliza `save_user`, la autorización existente, las validaciones del repositorio y CSRF; no introduce endpoints ni nuevas operaciones de escritura. Los atributos `data-user-*` se escapan con `Html::escape` y no incluyen hashes ni contraseñas. El campo contraseña permanece vacío al editar y solo se procesa cuando el administrador escribe una nueva. La política de sesión solo cambia de distribución visual; conserva almacenamiento, límites y permisos. No hay cambio de superficie de seguridad ni migración SQLite.

- La duración por inactividad se configura solo mediante la acción `save_login_policy`, incluida en la lista de acciones exclusivas de Administrador y protegida por CSRF. El valor se valida en servidor entre 0 y 10,080 minutos.
- El ajuste se guarda en la configuración `security` de SQLite. El valor ausente en instalaciones anteriores conserva el fallback de 30 minutos.
- Con valor mayor que cero, la sesión se invalida en la primera petición posterior al umbral de inactividad. Con valor 0 se desactiva únicamente ese vencimiento; la cookie es de sesión y se elimina al cerrar el navegador. Los controles `session_version`, estado, bloqueo y revocación no se desactivan.
- Para `0`, el GC nativo recibe `session.gc_maxlifetime = 2,147,483,647`; esto evita la limpieza ordinaria temprana de PHP, pero los administradores deben conservar protegida la carpeta externa `sessions`.
- Riesgo residual: un valor `0` en un equipo compartido o no bloqueado puede dejar la cuenta autenticada mientras el navegador siga abierto. También hace que PHP conserve archivos de sesión abandonados durante un periodo muy amplio, por lo que el directorio privado `sessions` podría crecer con el tiempo. Se recomienda usar un tiempo limitado en equipos compartidos y cerrar sesión al terminar.
- No hay migración SQLite, cambio de rutas ni acceso al parámetro para usuarios Nominista.

---

La versión 1.2.26 no cambia la superficie de seguridad respecto a 1.2.25; únicamente estabiliza el texto informativo de la pantalla de actualizaciones. La versión 1.2.25 incorpora el seguimiento de lotes masivos y un comprobante SMTP dirigido al correo de la cuenta que inició la operación. El endpoint exige sesión autenticada y CSRF, limita el lote al usuario y a la ejecución correspondientes, y solo usa destinatarios resueltos desde la base de datos. El comprobante se genera con eventos identificados por `batch_id`; HTML, nombres, direcciones y motivos se escapan antes de renderizar. El correo de usuario es opcional, se valida en servidor y se puede mantener desde Mi cuenta o Administración → Usuarios. La columna `users.email` es preexistente; la migración 006 crea `bulk_mail_batches` y enlaza eventos de correo sin reescribir las migraciones anteriores. La aceptación SMTP no se presenta como confirmación de entrega final.



## Regeneración de reportes y recursos OOXML

- La fuente XLSX se conserva como `source.xlsx` bajo el directorio privado de la ejecución, con permisos restrictivos. `presentation.json` almacena únicamente el nombre visible de la persona y la fecha de elaboración (no credenciales ni tokens), también con permisos restrictivos; ambos archivos se incluyen en backup privado. La acción solo la resuelve dentro de `storage_exports/{runId}` tras validar el ID hexadecimal y verificar `realpath`; no acepta rutas locales enviadas por el navegador.
- La regeneración exige Administrador y CSRF, y utiliza un bloqueo exclusivo por ejecución para impedir regeneraciones simultáneas. Genera primero en una carpeta candidata y persiste los nuevos metadatos en una transacción; solo tras el commit elimina archivos antiguos validados como descendientes de la carpeta de ejecución. Ante error previo al commit limpia solo la candidata.
- Los IDs de `report_department_files` se conservan para departamentos existentes, por lo que la auditoría `mail_events` no se pierde. Las filas obsoletas pueden retirarse con `ON DELETE SET NULL` en su referencia de archivo; el evento de envío no se elimina.
- El logotipo se resuelve desde metadatos de SQLite con `BrandLogo::resolve`, nunca desde una ruta provista por el usuario. Se valida MIME real, tamaño y dimensiones antes de copiarlo a DrawingML. Solo se admiten imágenes raster; SVG no puede introducir scripts ni contenido activo en el libro.
- La manipulación OOXML actualiza las relaciones de hoja/dibujo y `[Content_Types].xml`; el archivo original de ZKTimeNet no se modifica.
- No se añade una migración ni una nueva superficie de escritura pública. `regenerate_run_reports` está en la allowlist de acciones administrativas y usa token CSRF.

# Security review — ZKTimeNet 1.2.18 (baseline)

La versión 1.2.20 agregó carga de logotipo raster con validación de contenido, MIME y dimensiones, nombre de archivo aleatorio y almacenamiento privado fuera del DocumentRoot. La ruta `index.php?action=brand_logo` publica únicamente el recurso de marca activo para mostrarlo en el login sin autenticación; resuelve el archivo exclusivamente desde metadatos de SQLite y `BrandLogo::resolve`, nunca acepta rutas desde el cliente, devuelve MIME validado y `X-Content-Type-Options: nosniff`. La operación de carga/eliminación se mantiene protegida por sesión Administrador y CSRF. No cambia el esquema SQLite.


## Envío masivo y comprobante de soporte (1.2.25)

- `bulk_send_start`, `bulk_send_one` y `bulk_send_summary` exigen sesión autenticada y token CSRF. Cada lote tiene un ID aleatorio de 128 bits y se vincula en SQLite al usuario y a la ejecución; las consultas de lectura comprueban ambos vínculos.
- El navegador solo envía el ID del archivo para seleccionar el registro. La ruta, el nombre del adjunto, la configuración SMTP y las direcciones de envío se vuelven a resolver en el servidor.
- `mail_events.batch_id` separa los resultados de esa operación de intentos individuales o de otras ejecuciones. El comprobante consulta exclusivamente los eventos del lote y no adjunta los Excel ni el ZIP.
- El correo del usuario se valida y normaliza en `UserRepository`; se permite dejarlo vacío. Cada usuario solo puede editar su propio correo; la edición de otras cuentas sigue restringida a Administradores.
- El comprobante escapa los valores dinámicos para HTML y limita motivos externos a 500 caracteres al registrar incidencias comunicadas por el navegador. El mensaje de soporte no afirma que el correo haya llegado o sido leído, solo que el servidor SMTP aceptó o rechazó cada destinatario.
- La migración 006 usa transacciones y claves foráneas. La migración manager crea un respaldo automático de SQLite antes de aplicarla.

## S-02 — Bloqueo persistente de cuentas

- La política de bloqueo se almacena en `settings` y se administra exclusivamente mediante acciones de administrador con CSRF. Valores iniciales: 5 fallos y 10 minutos; los límites configurables son 3–20 intentos y 1–1,440 minutos.
- Los contadores y `locked_until` se guardan en SQLite por usuario. El incremento de fallos y la activación del bloqueo ocurren en una sola sentencia SQL condicional para evitar perder incrementos concurrentes.
- Un bloqueo activo rechaza incluso una contraseña correcta. Al expirar, el siguiente intento reinicia el contador. La actualización de login exitoso comprueba bloqueo y versión en el mismo `UPDATE`, de forma que un login concurrente no pueda borrar el bloqueo recién aplicado. La UI permite que un administrador desbloquee otra cuenta, sin filtrar por rol; también incluye a otros administradores.
- Bloqueo/desbloqueo incrementa `session_version`, por lo que las sesiones previas de esa cuenta dejan de ser válidas.

## S-03 — Endurecimiento de sesión y confianza de proxy

- Se habilita `session.use_strict_mode`, `session.use_only_cookies`, `session.use_trans_sid=0`, cookies HttpOnly y SameSite=Lax; el ID se regenera al autenticar y al elevar una sesión heredada.
- `Auth::refresh()` consulta en cada petición el usuario activo, el bloqueo y `session_version`. Los cambios de contraseña, rol, estado, bloqueo o desbloqueo revocan las sesiones anteriores.
- No se confía en `X-Forwarded-Proto` de clientes directos. Solo se considera HTTPS reenviado cuando `REMOTE_ADDR` coincide exactamente con una IP válida de `ZKTIME_TRUSTED_PROXIES`; el operador debe declarar las IP reales de los proxies TLS.
- **Nota de trazabilidad:** el archivo `SECURITY-REVIEW.md` incluido en la base 1.2.16 no contenía la descripción original del hallazgo identificado como S-03. Esta entrega corrige los riesgos de sesión y proxy identificados en el código; confirmar el texto original del hallazgo sigue siendo necesario para certificar equivalencia exacta con el reporte externo.

## S-05 — Credencial administrativa predefinida

- Se conserva la corrección de 1.2.16: `config/app.php` no contiene `admin_password_hash` ni usuario/contraseña predeterminados para instalaciones nuevas. El instalador solicita la contraseña y genera el hash con `password_hash()`; solo lo entrega en memoria a la migración histórica 003.
- Las instalaciones existentes con esquema 3/4 conservan sus credenciales y datos; las bases en esquema 5 aplican la migración 006 de lotes de correo. 1.2.25 reutiliza `users.email`, ya existente desde la migración 001, y no reescribe las migraciones históricas.

## Respaldo y restauración

- Los archivos ZIP se validan antes de extraer: cantidad de entradas, límite comprimido/expandido, rutas relativas seguras, rechazo de enlaces simbólicos, manifiesto único, tamaño real de cada stream y SHA-256.
- La base incluida debe pasar `PRAGMA integrity_check`, coincidir con el esquema del manifiesto y contener al menos un administrador activo. `app.key` debe ser exactamente 32 bytes.
- La restauración exige administrador, CSRF y confirmación de su contraseña actual; libera el lock de sesión PHP antes de adquirir el bloqueo exclusivo de mantenimiento y crea un backup previo automático para rollback. Al terminar incrementa `session_version` en todas las cuentas restauradas para invalidar sesiones del estado anterior y del propio backup.
- La descarga del ZIP exige POST con CSRF. El ZIP contiene `app.key` y la base con hashes de contraseñas; no se cifra con contraseña propia. El sistema muestra esta advertencia para que el archivo se guarde en un volumen/destino cifrado y solo con acceso autorizado.
- `config/app.php` y `config/local.php` se adjuntan como referencias y no se ejecutan ni sobrescriben al restaurar. Se limita la restauración a la misma versión de aplicación y ruta raíz de datos.

## Alcance de validación 1.2.18

- `php -l` en los 55 archivos PHP.
- 25 suites JavaScript smoke aprobadas.
- Prueba PHP de confianza de proxy HTTPS aprobada; también smoke de errores privados, firma y SMTP.
- Se agregan `tests/account-lockout-smoke.php` y `tests/configuration-backup-smoke.php` para ejecutar funcionalmente con PDO_SQLite y ZIP en el servidor objetivo.
- En este contenedor PHP es 8.4.24 (la aplicación requiere 8.5+) y faltan `pdo_sqlite`, `zip` y `dom`; por esa razón no se pudieron ejecutar aquí los tests funcionales SQLite/ZIP ni validar IIS/FastCGI real. Una comprobación independiente con SQLite de Python validó la lógica SQL del bloqueo y la revocación de sesiones, pero no reemplaza la prueba PHP.

---

# Security review — ZKTimeNet 1.2.14 (histórico)

## Correcciones S-06 y S-07

### S-06 — Mensajes de error técnicos
- Los fallos de inicialización, conexión a SQLite, login, envío SMTP, endpoints JSON y acciones POST se registran con una referencia y no exponen rutas físicas ni trazas al navegador.
- Los mensajes de validación deliberados y CSRF continúan siendo visibles para que el usuario pueda corregir su operación.
- Se agrega `ErrorLogger`, con almacenamiento bajo `storage_private/logs`, rotación de 5 MiB y redacción de patrones comunes de secretos. Si la ruta privada no está disponible o apunta al DocumentRoot, no crea el log en una ubicación pública y recurre al error log del servidor sin volcar el mensaje original.
- `display_errors` se desactiva para peticiones HTTP y `log_errors` se activa; PHP dirige los errores nativos a `php-error.log` privado cuando el directorio de datos está disponible.

### S-07 — Acceso HTTP directo a bootstrap.php
- `bootstrap.php` comprueba un marcador de inclusión establecido únicamente por los puntos de entrada autorizados `index.php`, `login.php` y `logout.php`; las peticiones HTTP directas reciben 404 sin contenido. CLI permanece disponible.
- IIS bloquea el segmento `bootstrap.php` mediante Request Filtering; Apache lo bloquea con una regla explícita. Las inclusiones PHP internas no son afectadas por esos filtros HTTP.

## Alcance de validación
- Validación estática PHP/JavaScript, pruebas unitarias de ErrorLogger, comprobaciones de guardia, autoload y reglas IIS/Apache.
- No se sustituye la prueba de despliegue IIS/PHP 8.5 ni una prueba de penetración dinámica.

---

## Release 1.2.13 — Iconografía local

- Iconos SVG generados por helper con lista blanca estática; no se interpolan valores del usuario en SVG.
- No se cargan fuentes, scripts ni iconos desde CDN.
- SVG inline declarativo, sin scripts ni controladores de eventos.
- La salida de iconos es constante para los nombres permitidos y usa `currentColor`.

## Release 1.2.13

- Se elimina el confirm dialog redundante del botón de envío masivo. El modal existente conserva la confirmación explícita mediante **Iniciar envío**; no se debilitan CSRF, sesión ni autorización del endpoint `bulk_send_one`.
- No hay cambios en endpoints, permisos, destinatarios ni manejo de archivos.

## Release 1.2.9

- El listado completo de ejecuciones se omite en la vista de detalle para reducir confusión; no cambia autorización ni disponibilidad de descargas.
- No se modifican rutas públicas, tokens ni almacenamiento.

## Revisión 1.2.8 — Etiqueta de tema

- Cambio exclusivamente de presentación: agrega una etiqueta visible junto al selector de tema en el encabezado y login.
- No modifica autenticación, permisos, cookies, almacenamiento, endpoints ni procesamiento de reportes.
- La preferencia continúa almacenándose en la cookie `zk_personal_theme` con el mismo alcance y atributos existentes.

## Revisión 1.2.6 — Visibilidad de configuración por rol

- El panel renderiza enlaces SMTP y esquema SQLite solamente cuando `Auth::isAdmin()` es verdadero.
- La autorización del backend permanece en vigor; ocultar los enlaces no sustituye los controles de acceso.
- El acceso de Nominista al catálogo de departamentos y destinatarios se conserva.

## Revisión 1.2.5 — Texto de instrucciones

- Cambio exclusivamente de texto estático en la vista autenticada de carga.
- No se incorporan enlaces, parámetros, HTML externo ni nuevas rutas.
- No se modifican controles de acceso, procesamiento de archivos, almacenamiento ni SMTP.

## Revisión 1.1.4

- La alerta principal solo presenta conteos de configuración; no expone direcciones de correo ni datos personales.
- El ajuste de columnas solo modifica metadatos OOXML de presentación de la copia de cada departamento; no cambia valores de celdas, fórmulas ni relaciones.
- Sin nuevos endpoints ni cambios de permisos, autenticación o almacenamiento.

# Revisión de seguridad — ZKTimeNet 1.2.5

- Los datos de branding se validan por campos, no vacíos y límites de longitud; se eliminan caracteres de control antes de persistir.
- Todos los valores de branding que se muestran en HTML pasan por `Html::escape()`.
- Las modificaciones requieren sesión autenticada y token CSRF.
- La identidad visual se guarda como JSON en la tabla `settings`; no se usa para construir rutas, consultas SQL dinámicas ni atributos sin escapar.
- El archivo de datos SQLite, llave SMTP y reportes permanecen fuera de la raíz web.
- Se conservan los controles de versión de esquema, checksum de migración, ACL y protección de sesión de 1.0.5.
- Esta recomendación correspondía al mecanismo anterior de contraseña compartida. En 1.2.16 el asistente crea una cuenta individual con contraseña elegida por el administrador; se mantienen HTTPS y la restricción LAN/VPN.


## Revisión 1.0.7 — Envío desde historial

- La acción de envío se procesa exclusivamente por POST autenticado y protegido por CSRF.
- El identificador de archivo es validado como entero y el registro se recupera desde SQLite; no se acepta una ruta ni una lista de correos desde el cliente.
- Los destinatarios se obtienen desde el repositorio del servidor y se revalidan al momento del envío.
- La ruta relativa del XLSX se resuelve con `realpath()` y se exige que quede bajo la raíz privada de exportaciones.
- Solo se adjunta el archivo del departamento. No se permite elegir adjuntos desde la petición.
- Los errores de destinatario y los resultados parciales se escriben en `mail_events`; no se sobrescriben eventos anteriores.
- El envío depende de los límites SMTP y sigue requiriendo pruebas operativas con el servidor de correo real.


## Reply-To múltiple (1.0.8)

- Lista limitada a 50 direcciones, cada una validada con `FILTER_VALIDATE_EMAIL` y longitud máxima de 254 bytes.
- Los valores se separan por coma, punto y coma y espacios/saltos de línea; se deduplican en minúsculas.
- El transporte SMTP vuelve a validar los valores antes de generar encabezados y rechaza CR/LF en entradas individuales.
- El encabezado se pliega entre direcciones; los correos nunca se concatenan sin validación.
- Los destinatarios de departamento se agregan solo a Reply-To, nunca a To/Cc/Bcc. Cada correo sigue enviándose de manera individual.
- La casilla viene activada por defecto incluso en configuraciones antiguas donde todavía no existe la clave.

## Corrección SMTP/MIME (1.0.9)

Se corrige la normalización CRLF del transporte SMTP. Los encabezados RFC 5322 y las partes MIME ahora conservan sus separadores, por lo que los clientes de correo pueden interpretar el asunto, el cuerpo y el adjunto XLSX. La prueba `tests/smtp-reply-to-smoke.php` valida el MIME completo y el payload Base64. No requiere migración SQLite ni cambios de configuración.

La corrección elimina la alteración de line endings. Mantener pruebas de envío real en staging al cambiar servidor SMTP, TLS o filtros del proveedor.

## 1.1.2 — Retención y lote manual
- El endpoint AJAX `bulk_send_one` exige sesión autenticada y token CSRF. El ID del archivo se vuelve a resolver en SQLite; no acepta ruta, dirección ni destinatarios enviados por el navegador.
- El servicio obtiene destinatarios activos del catálogo, valida ruta del XLSX contra la raíz privada de exportaciones y envía un adjunto por departamento.
- El lote opera secuencialmente; no expone destinatarios a otros departamentos y no entrega el ZIP general por correo.
- La retención automática de exports fue desactivada; `cleanup` se limita a archivos temporales de carga.


## 1.1.2 — Interfaz de distribución manual

- El formulario principal ya no acepta una bandera de envío automático; el envío se inicia únicamente desde acciones autenticadas del historial.
- El envío masivo sigue validando CSRF y sesión por cada departamento.
- El atributo HTML `hidden` se hace efectivo con una regla de mayor prioridad para prevenir controles de envío con estados visuales contradictorios.


## Revisión 1.1.3

- Destinatarios de entrega deduplicados por dirección normalizada antes de generar mensajes individuales.
- Responsable incluido explícitamente como destinatario del adjunto; excluido de Reply-To para evitar auto-respuesta.
- No se agregan destinatarios mediante encabezados compartidos: cada destinatario recibe transacción individual.
- Información técnica de runtime removida de chrome público/autenticado ordinario y disponible únicamente en el área administrativa de Actualizaciones.


## Revisión 1.2.0 — Identidad y control de acceso

- Contraseñas de usuarios protegidas con `password_hash()`/`password_verify()`; nunca se devuelven ni muestran desde el repositorio.
- Usuario normalizado y validado mediante allowlist; rol limitado a `admin` o `nominista`.
- `Auth::refresh()` vuelve a leer rol, nombre y estado activo desde SQLite en cada petición; las cuentas desactivadas no conservan acceso.
- `Auth::canAccessPage()` limita noministas a Operación y Departamentos. Las operaciones administrativas se verifican de nuevo en el backend POST; la navegación oculta no es el único control.
- Acciones de gestión de usuarios y migraciones exigen sesión de administrador y CSRF. No se permite desactivar al último administrador activo ni la propia cuenta desde el formulario de activación.
- Las cuentas se desactivan en lugar de borrarse para mantener integridad de `report_runs.created_by` y trazabilidad histórica.
- En versiones anteriores a 1.2.16 la transición desde v2 aceptaba un hash predefinido temporal. En 1.2.16 las bases esquema 1/2 pasan por el asistente, que respalda SQLite y solicita una nueva contraseña antes de aplicar la migración 003.
- El cambio de nombre de archivo valida caracteres reservados y extensión `.xlsx`; solo actualiza metadato del reporte.
- Pruebas de seguridad pendiente: prueba de integración en PHP 8.5 + PDO_SQLite en IIS, incluyendo intentos de acceso directo nominista a cada acción administrativa.


## Revisión 1.2.2 — Descubrimiento seguro y contraseña propia

- Las etiquetas descubiertas proceden del XLSX y se validan (no vacías, máximo 160 bytes), se insertan mediante parámetros PDO y se almacenan deshabilitadas y pendientes. No se habilita distribución de una etiqueta recibida desde el archivo sin aprobación del administrador.
- Los departamentos deshabilitados y pendientes generan Excel para preservar la información, pero el envío individual y masivo revalidan `enabled` y `needs_configuration` en el servidor antes de leer o adjuntar el archivo.
- Las acciones de envío no confían en banderas de habilitación proporcionadas por el navegador; vuelven a resolver el estado del departamento desde SQLite.
- El cambio de contraseña propio es POST con CSRF, requiere usuario activo, verifica la contraseña actual mediante `password_verify()`, valida longitud/confirmación, rechaza reutilizar la contraseña actual y persiste un hash.
- El perfil Nominista recibe acceso a `account` para cambiar su propia contraseña; la allowlist de páginas y la lista de acciones administrativas siguen restringiendo SMTP, identidad, usuarios y migraciones al Administrador.
- La migración 004 agrega `needs_configuration` con `DEFAULT 0`; conserva las áreas ya existentes.
- Pruebas end-to-end de IIS/PHP 8.5 y SMTP real siguen pendientes de ejecución en el servidor destino.

## Revisión 1.2.3 — Firma de correo y ayuda

- La imagen de firma solo acepta PNG/JPEG/GIF/WebP detectados por `getimagesize`, con límite de 2 MB y dimensiones máximas; SVG y HTML se rechazan para evitar contenido activo.
- El nombre de archivo de almacenamiento es aleatorio y se resuelve mediante allowlist estricta dentro de `storage_private`; la ruta no se acepta desde la petición ni se publica directamente.
- La vista previa exige autenticación Administrador, valida el archivo mediante `SignatureImage::resolve`, emite `X-Content-Type-Options: nosniff` y transmite el MIME reconocido.
- La imagen va codificada en Base64 como parte MIME `inline` con CID fijo y está anidada en `multipart/related`; el Excel sigue siendo un adjunto separado dentro de `multipart/mixed`.
- La ayuda es contenido estático de la vista, dividido por rol de sesión. No concede permisos ni abre endpoints nuevos.
- No se añadieron extensiones PHP, librerías ni servicios externos.

Pruebas de la versión 1.2.3: `tests/signature-image-smoke.php` valida la resolución privada y rechaza traversal/formatos no permitidos; `tests/smtp-reply-to-smoke.php` valida la estructura MIME de imagen CID inline y XLSX adjunto. El mensaje también fue parseado con un parser MIME independiente.



## Revisión 1.2.4 — Acciones de usuarios y tablas

- Se conserva el control Habilitar/Deshabilitar únicamente para cuentas distintas de la sesión autenticada. El controlador y el repositorio validan que el usuario actual no pueda cambiar su propio estado.
- Se conserva el control para no deshabilitar al último Administrador activo.
- Los `<td>` con acciones mantienen `display:table-cell`; los controles se distribuyen dentro de un contenedor interno. Esto evita romper el algoritmo de tabla y no cambia autorización ni datos.
- No se incorporan dependencias, rutas nuevas, cambios de esquema ni permisos de almacenamiento.