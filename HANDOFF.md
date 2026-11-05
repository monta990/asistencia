# HANDOFF — ZKTimeNet Personal Framework 1.2.31

## Release 1.2.31 — Dashboard compacto, tarjetas alineadas y orden del menú

- Base: paquete local `ZKTimeNet-Personal-Framework-1.2.30-IIS-LOCAL.zip`.
- Versión de aplicación: `1.2.31`; SQLite permanece en esquema `6`. No se agrega migración.
- El dashboard usa tarjetas de métricas con menor altura/padding y paneles centrales más compactos. Se mantiene toda la información y las acciones existentes. La tabla de reportes recientes también reduce sus espacios verticales.
- Se fuerza alineación `stretch` en cuadrículas de tarjetas/paneles compartidas, en particular `.content-grid` y `.updates-grid`, para que las tarjetas hermanas terminen en el mismo nivel, aunque algún formulario o texto tenga menos contenido. Se eliminan visualmente los efectos de `align-items:start` heredados en layouts como carga, correo, identidad visual y actualizaciones.
- Menú lateral en Operación: **Cargar reporte → Historial → Resumen**. La ruta e identificador `dashboard` permanecen iguales; solo cambia el orden visual.
- Prueba nueva: `tests/card-grid-consistency-smoke.js` verifica orden del menú, stretch de cuadrículas y tamaños compactos del dashboard.
- Archivos principales: `assets/app.css`, `views/layout.php`, `src/Core/Version.php`, pruebas y documentación.

### Validación local 1.2.31
- Node.js 22.16.0: **35/35 pruebas JavaScript aprobadas**.
- PHP CLI 8.4.24: sintaxis aprobada en **63 archivos PHP** y `bin/zknet`.
- Pruebas PHP: **9 aprobadas**; 4 omitidas (`account-lockout-smoke.php`, `configuration-backup-smoke.php`, `installation-state-smoke.php`, `smoke.php`) por falta de PDO_SQLite/ZIP o requerimiento de PHP 8.5.
- No se completó una prueba visual automatizada con Chromium; revisar la igualdad de alturas y la densidad del dashboard en IIS. No se declara validación integrada en PHP 8.5/8.6.
- Esquema SQLite 6, sin migración. Sin cambios de almacenamiento o rutas de datos.
- No realizar commits, push, PR, merge ni publicaciones remotas sin autorización expresa.

---

## Release 1.2.30 — Departamentos en modal

- Base: paquete local `ZKTimeNet-Personal-Framework-1.2.29-IIS-LOCAL.zip`.
- Versión de aplicación: `1.2.30`; SQLite permanece en esquema `6`. No se agrega migración.
- **Configuración → Departamentos** muestra el catálogo en un panel a ancho completo y traslada el formulario a un modal reutilizable para alta y edición.
- El modal conserva `action=save_department`, token CSRF, `data-operation` y los seis campos funcionales existentes. La edición precarga ID, etiqueta de origen, nombre visible, responsable, destinatarios, orden y estado de envío.
- El controlador carga para la vista todos los destinatarios persistidos del departamento (no solo los activos), de modo que abrir y guardar una edición no descarte entradas que antes estuvieran deshabilitadas. Los datos de `data-department-*` se escapan mediante `Html::escape`; la lista de destinatarios se transporta como JSON con flags `JSON_HEX_*` antes del escape HTML.
- Los registros detectados automáticamente mantienen la advertencia `needs_configuration`; al guardar, el repositorio conserva su comportamiento actual y marca el departamento como revisado.
- El modal replica la accesibilidad del modal de usuarios: Escape, botón de cierre, clic de fondo, retorno del foco y contención con Tab. El modal permanece compatible con el enlace histórico `?edit=<id>` y abre al cargar cuando está presente.
- La eliminación sigue usando el formulario, CSRF y modal de confirmación actuales. No se modifica la distribución, destinatarios responsables, reportes históricos ni reglas SMTP.
- Archivos principales: `views/pages/departments.php`, `assets/app.js`, `assets/app.css`, `index.php`, `src/Repositories/DepartmentRepository.php` (`recipientsByDepartmentIds()`), `src/Core/Version.php`, `views/layout.php`, `tests/department-modal-smoke.js`.

## Validación local 1.2.30

- Node.js 22.16.0: **34/34 pruebas JavaScript aprobadas**, incluida `department-modal-smoke.js`.
- PHP CLI 8.4.24: sintaxis aprobada en **63 archivos PHP** y `bin/zknet`.
- Pruebas PHP: **9 aprobadas**; 4 omitidas (`account-lockout-smoke.php`, `configuration-backup-smoke.php`, `installation-state-smoke.php`, `smoke.php`) por falta de PDO_SQLite/ZIP o requerimiento de PHP 8.5.
- Chromium headless no terminó de arrancar en el entorno. Revisar visualmente el modal en IIS en escritorio, tableta y móvil; probar alta, edición, departamento detectado, envío deshabilitado, destinatarios, Escape y clic fuera.
- No afirmar validación integrada en IIS/PHP 8.5/8.6 sin ejecutarla allí.
- Esquema SQLite 6, sin migración. Sin cambios de almacenamiento o rutas de datos.
- No realizar commits, push, PR, merge ni publicaciones remotas sin autorización expresa.

---

# HANDOFF — ZKTimeNet Personal Framework 1.2.29

## Release 1.2.29 — Gestión de usuarios en modal y ajuste de política de sesión

- Base: paquete local `ZKTimeNet-Personal-Framework-1.2.28-IIS-LOCAL.zip`.
- Versión de aplicación: `1.2.29`; SQLite permanece en esquema `6`. No se agrega migración.
- **Administración → Usuarios** ahora presenta la lista de usuarios en un panel a ancho completo. El botón superior **Agregar usuario** y las acciones **Editar** abren el mismo modal.
- La edición usa atributos `data-user-*` escapados en los botones de cada fila para precargar persona, nombre de usuario, correo y rol. La contraseña no se envía al navegador; al editar sigue siendo opcional y un campo vacío conserva la contraseña guardada. El formulario reutiliza `action=save_user`, el token CSRF y la autorización de administrador existente.
- El modal añade semántica `role=dialog` / `aria-modal`, foco inicial en el nombre, ciclo de foco con Tab/Shift+Tab, cierre con Escape, botón o clic en el backdrop, y restauración del foco al control que lo abrió. El formulario usa el overlay de procesamiento al guardar. La ruta GET `?edit_user=` continúa siendo compatible y abre el modal ya precargado si se visita manualmente.
- La política de intentos y duración de sesión se dispone en tres columnas equilibradas en escritorio; los inputs y textos auxiliares tienen límites de ancho legibles. **Guardar política** ocupa una fila propia alineada a la derecha. En tablet se usan dos columnas y en móvil una.
- No cambia la política persistida, los límites de valores, la expiración de sesión ni la autorización. No se cambia `settings.security`, no se crea migración y se conserva el esquema 6.

## Validación local 1.2.29

- Node.js 22.16.0: **33/33 pruebas** `tests/*.js` aprobadas, incluida la nueva `tests/user-modal-smoke.js` y las pruebas de timeout/RBAC/iconos.
- PHP CLI 8.4.24: `php -l` aprobado en **63 archivos PHP** y `bin/zknet`.
- PHP tests: `session-timeout-smoke.php`, `brand-logo-smoke.php`, `bulk-mail-summary-renderer.php`, `department-delivery-recipients-smoke.php`, `error-logger-smoke.php`, `run-source-resolver-smoke.php`, `session-proxy-smoke.php`, `signature-image-smoke.php` y `smtp-reply-to-smoke.php` aprobados. `account-lockout-smoke.php`, `configuration-backup-smoke.php`, `installation-state-smoke.php` y `smoke.php` fueron omitidos por falta de `pdo_sqlite`, `zip`, `dom` o por requerir PHP 8.5.
- No se completó inspección gráfica headless con Chromium en este contenedor; la ejecución del navegador se quedó bloqueada durante el arranque. Se requiere aceptación visual en IIS para escritorio/tableta/móvil, incluyendo abrir y editar una cuenta y guardar política.
- El entorno disponible usa PHP 8.4.24, no PHP 8.5/8.6. La sintaxis está aprobada, pero esto no demuestra runtime integrado ni procesamiento XLSX en la versión objetivo.
- No se realizaron commits, push, PR, merge ni operaciones remotas en GitHub.

---

# HANDOFF — ZKTimeNet Personal Framework 1.2.28

## Release 1.2.28 — Duración de sesión configurable

- Base: paquete local 1.2.27.
- Versión de aplicación: `1.2.28`; SQLite permanece en esquema `6`. No se agrega migración.
- Comportamiento anterior: vencimiento por inactividad de 1,800 segundos (30 minutos), definido en `config/app.php` como fallback.
- Nuevo ajuste: **Administración → Usuarios → Protección de cuentas → Duración de sesión por inactividad (minutos)**, guardado en `settings.security.session_idle_timeout_minutes` y aplicado globalmente a todos los usuarios. Valores admitidos: `0`–`10,080` minutos (7 días).
- Compatibilidad: instalaciones cuyo registro `security` no contiene el parámetro siguen usando 30 minutos. La configuración se carga en `bootstrap.php` después de abrir SQLite pero antes de `Session::start()`.
- `0` desactiva el vencimiento por inactividad. La cookie sigue con `lifetime = 0` (cookie de sesión), por lo que termina al cerrar el navegador. Los mecanismos de revocación por `session_version`, desactivación, cambio de contraseña/rol, bloqueo y restauración permanecen activos.
- `Session::start()` aplica la comprobación solo cuando el tiempo es mayor que cero. Para `0`, `session.gc_maxlifetime` se fija en `2,147,483,647` segundos para que el GC normal de PHP no borre sesiones inactivas. Los archivos huérfanos pueden acumularse; el directorio `sessions` debe permanecer privado y se recomienda utilizar 0 solo en equipos controlados.
- No requiere reinicio de IIS ni cambio de permisos; la política se vuelve a leer en cada petición. Las sesiones ya eliminadas anteriormente por el GC no se pueden recuperar.

## Validación local 1.2.28

- Se agregan `tests/session-timeout-smoke.js` y `tests/session-timeout-smoke.php`. La prueba PHP usa el manejador de sesiones nativo y valida expiración tras el umbral y retención con valor 0. Las 32 suites JavaScript pasan con Node.js 22; `php -l` pasa en los 62 archivos PHP y `bin/zknet`.
- La sintaxis PHP se valida con el CLI disponible. La app declara PHP 8.5 mínimo; este entorno puede tener un CLI inferior, así que `php -l` no certifica compatibilidad de runtime 8.5/8.6.
- Node.js 22.16.0: 32/32 pruebas JavaScript aprobadas. PHP CLI 8.4.24: 62 archivos PHP y `bin/zknet` superan `php -l`; la nueva prueba PHP de sesión pasa con el manejador nativo.
- En este entorno faltan `pdo_sqlite`, `zip` y `dom`; no se ejecutaron las pruebas funcionales integradas que dependen de esas extensiones. La aplicación declara PHP 8.5 mínimo, por lo que la validación integrada bajo IIS/PHP 8.5/8.6 todavía debe ejecutarse en staging.

---

## Release 1.2.27 — Auditoría global de controles en tema claro

- Se revisaron todas las clases de botones y controles accionables encontradas en las vistas PHP: acciones `btn-*`, selector de tema, salir, acceso a Mi cuenta, navegación lateral, menú móvil, acciones de edición y cierre, variables de plantilla, tarjetas y checks, zona de carga y selector de logotipo.
- Los botones secundarios ahora tienen un relleno gris azulado más oscuro y borde visible en todo el sitio; las acciones destructivas mantienen una paleta roja suave, pero distinguible.
- El anillo de foco se aplica a controles interactivos para teclado. Estados hover y disabled quedan diferenciados.
- No se modificaron reglas del tema oscuro; no cambia la estructura, el esquema SQLite (6), ni el backend.

## Validación local 1.2.27

- Node.js 22.16.0: **31/31 suites** `tests/*.js` aprobadas; `node --check assets/app.js` y `node --check assets/theme.js` aprobados.
- PHP CLI 8.4.24: `php -l` aprobado en **62 archivos PHP** y `bin/zknet`.
- `tinycss2`: `assets/app.css` y `assets/theme.css` se analizaron sin errores de sintaxis (567 y 126 reglas de nivel superior, respectivamente).
- `theme-light-controls-smoke.js` verifica los estilos de todas las familias de controles detectadas, la cobertura de foco/hover, el aislamiento del tema oscuro y relaciones de contraste de texto >= 4.5:1 para la paleta de botones.
- No se pudo completar una inspección gráfica de aceptación en navegador ni una prueba interactiva sobre IIS; Chromium no llegó a completar el arranque headless en este entorno. Se requiere una revisión visual de escritorio y móvil en staging antes de producción.
- El runtime disponible continúa en PHP 8.4.24; esta entrega no cambia lógica PHP ni requiere `pdo_sqlite`, `zip` o `dom`, pero tampoco demuestra compatibilidad funcional PHP 8.5/8.6.
- No se realizaron operaciones remotas en GitHub.

## Base funcional conservada

La versión 1.2.27 parte del paquete local 1.2.26. Se conserva el esquema 6, las funciones de comprobante de envío masivo, correo de usuario, regeneración, backups, migraciones y mensaje genérico de actualizaciones pendientes.

---

## Release 1.2.26 — Mensaje estable de actualizaciones

- La alerta de migraciones pendientes en `views/pages/updates.php` utiliza un texto fijo: **Hay actualizaciones pendientes. Se creará un respaldo SQLite antes de ejecutar los cambios.** La actualización es obligatoria para poder seguir usando la plataforma.
- Ya no se describe una migración individual en el texto de advertencia, evitando que una referencia histórica quede incorrecta cuando cambia el esquema objetivo. Los indicadores de esquema actual, esquema objetivo y cantidad pendiente permanecen visibles por separado.
- No se agrega migración ni se modifica SQLite; el esquema objetivo continúa en 6.
- Se agregó una aserción en `tests/updates-page-layout-smoke.js` para fijar el mensaje y rechazar descripciones específicas de migraciones.

## Validación local 1.2.26

- Node.js 22.16.0: **30/30 suites** `tests/*.js` aprobadas; `node --check assets/app.js` aprobado. La suite de actualizaciones exige el texto fijo completo y comprueba que no vuelva a describirse una migración concreta.
- PHP CLI 8.4.24: `php -l` aprobado en **61 archivos PHP** y `bin/zknet`.
- La versión PHP disponible no es la versión objetivo (8.5/8.6). No están disponibles `pdo_sqlite`, `zip` ni `dom`; no se ejecutó una prueba funcional IIS/FastCGI ni se aplicaron migraciones. Esta entrega no cambia el esquema SQLite.
- El manifiesto SHA-256 contiene **117 entradas** y todas coinciden; el ZIP contiene 135 entradas (118 archivos y 17 directorios) y supera la prueba CRC/integridad.
- No se realizaron operaciones remotas en GitHub.


## Release 1.2.25 — Comprobante de envío masivo y ajustes visuales

- En tema claro, los botones del encabezado **Tema** y **Salir** usan un fondo gris azulado más oscuro, borde y estados hover/foco para evitar que se pierdan frente al topbar blanco.
- En el historial, la acción de descarga se etiqueta **Descargar ZIP**.
- El correo de usuario ya existía en `users.email` desde `001_initial.php`; ahora se captura/edita en **Administración → Usuarios** y cada usuario puede actualizarlo en **Mi cuenta**. No se agrega una columna duplicada.
- La migración `006_bulk_mail_batches.php` crea `bulk_mail_batches` y agrega `mail_events.batch_id` para identificar inequívocamente cada envío masivo; el esquema objetivo sube de 5 a 6.
- Al terminar la distribución masiva, `BulkMailSummaryRenderer` genera HTML y texto y `MailerService::sendBulkSummary()` envía el comprobante por el SMTP configurado a la cuenta que inició el lote. El detalle por departamento muestra direcciones aceptadas por SMTP, direcciones fallidas con motivo y omisiones. No adjunta archivos.
- Se registran los lotes con usuario y ejecución; el endpoint JSON exige sesión y CSRF, verifica propiedad del lote y cierra el comprobante una sola vez. Las incidencias de comunicación se incorporan sin duplicar eventos que el servidor ya guardó.
- Si la cuenta no tiene email, se conserva la auditoría y el modal indica que se configure en **Mi cuenta**. Si el correo de soporte falla, se comunica sin revertir los envíos departamentales ya realizados.
- El comprobante aclara que aceptación SMTP no garantiza entrega en bandeja ni lectura.

## Validación local 1.2.25

- Node.js 22.16.0: **30/30 suites** `tests/*.js` aprobadas; `node --check assets/app.js` aprobado.
- PHP CLI 8.4.24: `php -l` aprobado en los **62 archivos PHP** del proyecto y `bin/zknet`.
- `php tests/bulk-mail-summary-renderer.php`: aprobado; valida los totales, direcciones, motivos de rechazo y escape HTML del comprobante.
- `php tests/smtp-reply-to-smoke.php`: aprobado.
- `php tests/smoke.php`: omitido por el propio requisito del test (PHP 8.5 o superior). El runtime disponible es PHP 8.4.24.
- El entorno no dispone de `pdo_sqlite`, `zip` ni `dom`; la migración 006 se ensayó con SQLite de Python sobre esquema equivalente a 5: eventos históricos sin lote permanecen con `batch_id=NULL`, el enlace del nuevo lote funciona, `foreign_key_check` queda vacío y `integrity_check` devuelve `ok`. Esta prueba no reemplaza la ejecución PHP PDO.
- Pendiente en staging IIS/PHP 8.5/8.6: aplicar migración 006 sobre copia de esquema 5, procesar un XLSX, enviar masivo con mezcla de destinatarios aceptados/rechazados/omitidos, comprobar recepción del comprobante y probar cuenta sin email / SMTP no disponible.
- No se realizaron commits, push, PR, merge ni operaciones remotas de GitHub.

---

## Release 1.2.24 — Confirmaciones y progreso visual

- Las confirmaciones `data-confirm` ahora usan un modal común del sistema; se elimina el uso de confirmaciones nativas del navegador. Cancelar no envía el formulario; aceptar usa `requestSubmit()` y mantiene validación HTML.
- Se agrega una capa de operación con blur, spinner y animación indeterminada. Se usa para procesar XLSX, regenerar, correo individual, prueba SMTP, migraciones y restauración.
- El modal de correo masivo conserva sus filas y barra de progreso, ya que muestra el avance real por departamento.
- Las descargas no activan el overlay para evitar dejar la pantalla bloqueada durante una respuesta `Content-Disposition: attachment`.
- `pageshow` restablece el overlay si el navegador restaura la página desde su caché.
- No se modifican endpoints ni la lógica del backend; el esquema SQLite continúa en 5.

## Validación local 1.2.24 (histórica)

- 29 suites JavaScript `tests/*.js`: aprobadas con Node.js 22.
- `node --check assets/app.js` y prueba de regresión `tests/operation-feedback-smoke.js`: aprobadas.
- `php -l` en todos los archivos PHP y `php -l bin/zknet`: aprobados en el PHP CLI disponible.
- La validación interactiva en navegador debe confirmar Cancelar/Aceptar, Escape, clic en el fondo, envío con ratón/teclado y retiro del overlay al cargar la respuesta.
- En IIS/PHP 8.5, probar XLSX nuevo, regeneración, correo individual/masivo, migraciones y restauración. No se debe activar overlay en descargas ZIP/respaldo.
- Este entorno no ejecuta pruebas gráficas de navegador ni se considera una validación end-to-end en IIS.

---

## Release 1.2.23 — Hotfix de ZipArchive al incrustar logotipo

- Se corrige `src/Services/DepartmentSplitter.php::embedBrandLogo()`: el código usaba `ZipArchive::RDWR`, constante que no existe en PHP y provocaba `Undefined constant ZipArchive::RDWR` cuando la regeneración intentaba añadir el logo.
- Los XLSX existentes se abren mediante `$zip->open($xlsxPath)` sin flags. `ZipArchive::open()` usa lectura/escritura por defecto cuando se omiten flags.
- `tests/report-regeneration-smoke.js` comprueba que se use la apertura predeterminada y que no vuelva a aparecer `ZipArchive::RDWR`.
- La corrección conserva intactos el XLSX fuente, el flujo de regeneración, la carpeta de trabajo, los metadatos del historial y el registro de correos. No requiere migración SQLite; el esquema permanece en 5.
- Versión de aplicación en esa entrega: `1.2.23`. Se renovaron los parámetros de caché de CSS/JavaScript.

## Validación local 1.2.23

- `php -l` en todos los archivos PHP.
- Las 28 suites JavaScript con Node 22.
- Smoke de resolver de fuentes, logo privado y prueba estática de regeneración.
- Limitación: el entorno local disponible usa PHP 8.4.24 y no tiene `zip`, `dom` ni `pdo_sqlite`; por eso no se ejecuta aquí la integración real de manipulación XLSX. Validar en IIS/PHP 8.5 con esas extensiones antes de usarlo en producción.

---

## Release 1.2.22 — Compatibilidad de fuentes históricas para regeneración

- `RunSourceResolver` reconoce `exports/{runId}/source.xlsx` y, para ejecuciones anteriores, `uploads/{runId}.xlsx`. La relación se basa en el mismo ID hexadecimal que ya se guarda en SQLite y se usa para nombrar ambas carpetas; no se compara con `original_filename`, que el usuario puede renombrar desde el historial.
- Las capturas de validación muestran el mismo identificador hexadecimal en el nombre del XLSX de `uploads` y en la carpeta correspondiente de `exports`. Por ello esa ejecución histórica puede regenerarse sin volver a cargar el libro, siempre que el archivo siga presente.
- Al iniciar una regeneración desde `uploads`, el controlador crea `exports/{runId}/source.xlsx` mediante copia temporal, verificación SHA-256 y renombrado atómico. Si falla la copia o el hash no coincide, se aborta antes de sustituir ningún Excel/ZIP.
- `php bin/zknet cleanup` consulta los IDs de `report_runs` y protege cualquier `uploads/{runId}.xlsx` asociado a una ejecución registrada. Solo elimina cargas XLSX huérfanas que superan el periodo de retención. `ConfigurationBackup::copyLegacyRunSources()` también recupera esas fuentes históricas al crear el respaldo y las materializa como `data/exports/{runId}/source.xlsx`, para que la restauración conserve la capacidad de regenerar.
- Se añade `src/Services/RunSourceResolver.php` y `tests/run-source-resolver-smoke.php`; el test de regeneración también valida el fallback y la retención.
- No se modifica SQLite ni se añade migración: el esquema objetivo continúa en 5.

## Release 1.2.21 — Excel de asistencia y regeneración desde el XLSX original

- `DepartmentSplitter::split()` recibe metadatos de presentación (`created_by`, `generated_on`, `logo`). Para cada departamento, renombra el título a **Reporte de asistencia**, añade `Semana {ISO} · {dd/mm/yyyy} al {dd/mm/yyyy}` en la fila 3 y coloca al pie el autor/fecha de la ejecución. La fila de pie se inserta después de una fila espaciadora.
- Cuando `BrandLogo::resolve()` obtiene un logo, `embedBrandLogo()` lo agrega como DrawingML en la posición superior izquierda A1 con límite de 160 × 44 px, correspondiente al espacio vacío superior izquierdo de la plantilla, sin cambiar la geometría de la tabla ni cubrir el título/subtítulo. PNG/JPEG/GIF se incrustan; WebP se convierte con GD si está disponible. El logo se limita a 2 MiB, 2400×1200 y 2.5 MP. No se agrega al XLSX original ni al layout general, únicamente a los Excel por departamento.
- `index.php` conserva el XLSX original como `exports/{runId}/source.xlsx` (modo 0600) antes de procesarlo; las subidas temporales siguen eliminándose. También guarda `presentation.json` (modo 0600) con `created_by` y `generated_on` para que las regeneraciones respeten exactamente la identidad/fecha originales del pie.
- `views/pages/history.php` muestra **Regenerar reporte** solo a Administradores cuando existe `source.xlsx` o la carga histórica `uploads/{runId}.xlsx` asociada al ID de la ejecución.
- La regeneración utiliza `exports/{runId}/.regeneration.lock` para impedir dos procesos simultáneos sobre la misma ejecución; produce ZIP/Excel dentro de `exports/{runId}/regen-*`, actualiza SQLite en una transacción y después borra archivos anteriores dentro de la carpeta de esa ejecución. Un error previo al commit elimina la carpeta candidata y conserva las salidas vigentes.
- `ReportRepository::replaceRunOutputs()` conserva los IDs de departamentos presentes para mantener relaciones de auditoría, actualiza las rutas y snapshots, agrega departamentos nuevos y elimina filas que ya no están en el XLSX. Los `mail_events` permanecen, los archivos regenerados quedan no enviados/pendientes según configuración y no se manda correo automáticamente.
- El pie del Excel regenerado conserva el autor y fecha originales de la ejecución para coincidir con el historial. No se altera `created_by`/`created_at` del registro histórico.
- No se requiere migración: `Version::SCHEMA_TARGET` continúa en 5. La versión de aplicación pasa a `1.2.22`.

## Release 1.2.20 — Carga de logotipo en identidad visual

- **Sistema → Identidad visual** permite cargar, reemplazar o quitar el logotipo de empresa; la vista previa se actualiza al elegir una imagen. Las siglas existentes siguen como alternativa cuando no hay imagen configurada.
- Formatos admitidos: PNG, JPG/JPEG, GIF y WebP; límite de 2 MiB, 2400 × 1200 píxeles y 2.5 megapíxeles. SVG se rechaza para no introducir contenido activo.
- `src/Services/BrandLogo.php` valida el contenido real con `getimagesize`, MIME permitido, dimensiones y ubicación del archivo. Usa un nombre aleatorio y conserva el archivo fuera del DocumentRoot en el directorio privado existente.
- `index.php?action=brand_logo` sirve únicamente el logo activo con MIME validado y `nosniff`; la ruta pública es necesaria para mostrar la marca antes de iniciar sesión y no permite al cliente indicar una ruta de archivo.
- La carga, reemplazo y eliminación se procesan en la acción administrativa protegida por sesión y CSRF. El archivo anterior se elimina después de confirmar el cambio en SQLite; no se agrega una tabla ni se requiere migración (esquema continúa en 5).
- El logotipo se presenta en el menú lateral, la pantalla de acceso y la vista previa de Identidad visual.
- `ConfigurationBackup` copia el contenido privado completo; el logotipo queda incluido en el ZIP junto con SQLite, `app.key` y los demás recursos privados.
- Se agrega `tests/brand-logo-smoke.php` y se amplía la cobertura de branding y backup. La versión de aplicación pasa a `1.2.20`.


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

- **Versión de aplicación en la entrega histórica:** 1.2.18.
- **Esquema SQLite de esa entrega:** 5.
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
- Instalaciones con esquema 3/4 actualizan desde **Sistema → Actualizaciones** y conservan credenciales/datos; la migración 005 añade el bloqueo y el control de sesiones. Una base ya en esquema 5 permanecía actualizada. Se agregó 005 sin modificar 001–004.
- Instalaciones nuevas llegaban a esquema 5. Instalaciones parciales/desconocidas siguen la protección del asistente; no crear nueva `app.key` cuando ya existe SQLite.

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

## Validación local 1.2.22 (histórica)

- Las 28 pruebas JavaScript de `tests/*.js` pasaron con Node.js 22; también pasó `node --check` para todas las suites y los recursos frontend.
- `php -l` pasó para todos los archivos PHP y `bin/zknet` con PHP CLI 8.4.24.
- `tests/brand-logo-smoke.php` pasó: almacenamiento privado, allowlist MIME, resolución, traversal y eliminación.
- La prueba funcional integrada `tests/smoke.php` y la prueba completa de backup/restauración no se ejecutaron en este entorno: PHP CLI disponible 8.4.24 (la aplicación requiere 8.5+) y faltan `pdo_sqlite`, `zip` y `dom`. Deben ejecutarse en staging IIS o CI con PHP 8.5 y esas extensiones.
- `SHA256SUMS.txt` se recalculó para los 110 archivos presentes y se verificó con `sha256sum --check`; entradas heredadas de `.htaccess` ausentes del paquete IIS permanecen como entradas no aplicables.

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
