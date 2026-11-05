## 1.2.31 — Dashboard compacto y consistencia de tarjetas

- El panel de control reduce el espacio vertical de las tarjetas métricas y de los paneles de orientación/configuración, manteniendo los textos, métricas y acciones. Se ajustan padding, gaps, iconos y tabla de actividad reciente.
- Se establece alineación `stretch` para las cuadrículas principales de tarjetas y paneles en las distintas pantallas (carga, resumen, cuenta, identidad visual, correo, mantenimiento y bloques de diagnóstico), anulando excepciones anteriores que dejaban paneles desiguales.
- El menú lateral cambia el orden de Operación a **Cargar reporte → Historial → Resumen**.
- Se agrega `tests/card-grid-consistency-smoke.js` para proteger el orden del menú, la alineación global y la densidad compacta del dashboard.
- No se modifica lógica funcional, almacenamiento, configuración, autenticación ni el esquema SQLite 6. No requiere migraciones.

### Validación 1.2.31
- Node.js 22.16.0: 35/35 pruebas JavaScript aprobadas.
- PHP CLI 8.4.24: sintaxis aprobada en 63 archivos PHP y `bin/zknet`.
- Pruebas PHP: 9 aprobadas; 4 omitidas por falta de PDO_SQLite/ZIP o por requerir PHP 8.5. No se afirma validación funcional integrada en IIS/PHP 8.5/8.6.
- No se realizaron operaciones remotas en GitHub.

## 1.2.30 — Catálogo de departamentos en modal

- **Configuración → Departamentos** ahora muestra el catálogo a todo el ancho disponible. El formulario lateral se retira para dar espacio a la lista y sus acciones.
- **Agregar departamento** y **Editar** abren un único modal reutilizable, siguiendo el mismo patrón accesible de Gestión de usuarios. Incluye cierre con botón, Escape y clic en el fondo, restauración del foco y contención del foco con Tab.
- El modal conserva los campos y el endpoint `save_department`: etiqueta de origen ZKTimeNet, nombre visible, responsable, lista de destinatarios, orden y habilitación de correo. Mantiene las validaciones, transacción del repositorio, CSRF y capa visual de procesamiento.
- En edición se precargan todos los destinatarios almacenados, incluidos los que pudieran estar deshabilitados, además del estado habilitado y posición. Los departamentos descubiertos automáticamente conservan su advertencia de configuración pendiente hasta guardar.
- Se mantiene la acción de eliminación con su confirmación existente. No se cambia la lógica de distribución, el comportamiento de responsables, las salidas históricas ni el esquema SQLite 6; no requiere migración.

### Validación 1.2.30
- Node.js 22.16.0: 34/34 pruebas JavaScript aprobadas; `tests/department-modal-smoke.js` verifica campos, precarga segura de destinatarios, endpoint original, accesibilidad, apertura de edición heredada y diseño responsive.
- PHP CLI 8.4.24: sintaxis aprobada en 63 archivos PHP y `bin/zknet`. Se añade únicamente un método de lectura por lotes en `DepartmentRepository` para cargar los destinatarios de todas las filas sin consultas individuales.
- Pruebas PHP: 9 aprobadas y 4 omitidas (`account-lockout-smoke.php`, `configuration-backup-smoke.php`, `installation-state-smoke.php`, `smoke.php`) por falta de PDO_SQLite/ZIP o requerimiento de PHP 8.5. Chromium headless no terminó de iniciar; queda pendiente la aceptación visual en IIS. No se declara validación integrada en PHP 8.5/8.6.
- Se actualizan los identificadores de caché de los recursos y el manifiesto SHA-256. No se realizan operaciones remotas en GitHub.

## 1.2.29 — Gestión de usuarios en modal y política de sesión equilibrada

- La lista de usuarios ocupa ahora todo el ancho de la pantalla. **Agregar usuario** y **Editar** abren un único modal reutilizable, por lo que el formulario deja de reducir el espacio de la tabla.
- El modal permite crear y editar cuentas con los mismos campos y conserva el endpoint `save_user`, sus validaciones, permisos administrativos y protección CSRF. Al editar precarga persona, usuario, correo y perfil; la contraseña sigue siendo opcional y vacía conserva la actual.
- El modal incluye cierre mediante botón, Escape y clic sobre el fondo, devuelve el foco al control que lo abrió y mantiene el foco dentro del diálogo al navegar con Tab. Al guardar muestra la capa de procesamiento existente.
- La política de acceso y duración de sesión ahora distribuye sus tres campos en columnas de igual proporción. El botón **Guardar política** ocupa una fila propia alineada a la derecha; en tableta usa dos columnas y en móvil una.
- No se modifican las reglas de negocio, la autenticación ni los valores de la política. SQLite permanece en esquema 6; no se agrega migración ni se alteran datos.

### Validación 1.2.29
- Node.js 22.16.0: 33/33 pruebas JavaScript aprobadas, incluida `user-modal-smoke.js`.
- PHP CLI 8.4.24: sintaxis aprobada en los 63 archivos PHP y `bin/zknet`.
- La prueba real `session-timeout-smoke.php` y las demás pruebas PHP que no requieren extensiones ausentes pasan. Se omiten las pruebas que requieren `pdo_sqlite`, `zip`, `dom` o PHP 8.5; no se declara una validación integrada IIS/PHP 8.5/8.6.
- La inspección gráfica automática con Chromium no pudo completarse en el entorno; la regresión cubre la estructura modal, teclado, anchos y breakpoints mediante pruebas de contrato.
- El manifiesto SHA-256 se recalcula para todos los archivos del paquete. No se realizaron operaciones remotas en GitHub.

## 1.2.28 — Duración de sesión configurable

- **Administración → Usuarios → Protección de cuentas** incorpora la duración global por inactividad, expresada en minutos y aplicada a todos los usuarios. El valor inicial conserva el comportamiento anterior de 30 minutos; se admiten valores de 0 a 10,080 minutos (7 días).
- El valor `0` desactiva el cierre por inactividad mientras el navegador permanezca abierto. La cookie sigue siendo de sesión (`lifetime = 0`) y termina al cerrar el navegador; continúan funcionando la revocación por cambio de contraseña, rol, estado, bloqueo y restauración.
- La política se guarda dentro del ajuste `security` ya existente en SQLite. No requiere migración ni modifica el esquema 6. Los ajustes de instalaciones existentes sin el nuevo parámetro conservan 30 minutos automáticamente.
- `bootstrap.php` carga la política persistida antes de iniciar PHP Session. `Session::start()` aplica el umbral en cada petición y ajusta `session.gc_maxlifetime`; el valor 0 usa retención muy amplia para impedir que el recolector normal elimine la sesión por inactividad.
- El panel valida 0–10,080 minutos, mantiene la acción protegida para Administradores y aclara que la política es global. Con `0`, los archivos de sesión abandonados se retienen durante un periodo muy amplio; conviene reservar esta opción para equipos controlados.
- Se agregan pruebas estáticas y una prueba PHP con sesiones reales para verificar expiración al superar el umbral y conservación cuando el valor es 0.

## 1.2.27 — Contraste completo de controles en tema claro

- Se amplía la revisión visual a todas las pantallas: botones secundarios, acciones de peligro, selector de tema, salida, menú móvil, acciones de edición/cierre, botones de variables, tarjetas de selección y controles de carga.
- Los botones `btn-outline`, `btn-quiet`, `btn-danger-quiet` y `btn-white` ahora tienen rellenos y bordes con mayor contraste, además de estados hover definidos. Los botones primarios, destructivos y de envío masivo conservan sus colores de acción.
- Se hacen más visibles los controles de icono (`filename-edit-button`, `help-modal-close`, `alert-close` y `mobile-menu`), el acceso a **Mi cuenta** y las acciones de navegación lateral y se mejora la visibilidad del selector de archivos y de la zona de carga.
- Se incorpora un indicador `:focus-visible` consistente para operar con teclado y se distingue visualmente el estado deshabilitado. El tema oscuro no recibe cambios.
- Se agrega una prueba de regresión que comprueba la cobertura de clases de acción utilizadas por las vistas y los estilos específicos del tema claro.
- No se modifica el esquema SQLite (permanece en 6), las rutas de almacenamiento ni la lógica de negocio.

## 1.2.26 — Mensaje estable de actualización pendiente

- La advertencia de **Sistema → Actualizaciones** deja de describir cambios de una migración concreta. Cuando existen migraciones pendientes, siempre muestra el mismo texto: **Hay actualizaciones pendientes. Se creará un respaldo SQLite antes de ejecutar los cambios.** La actualización es obligatoria para poder seguir usando la plataforma.
- Se conservan los indicadores separados de esquema actual/objetivo y cantidad de migraciones pendientes; el aviso no anuncia novedades ni puede quedar desfasado respecto al número de migración.
- No se modifica el esquema SQLite ni se agrega una migración. El esquema objetivo permanece en 6.
- Se agrega una aserción de regresión para impedir que el aviso vuelva a incluir descripciones de migraciones.

## 1.2.25 — Comprobantes de envío masivo y ajustes visuales

- El tema claro da mayor contraste al selector de tema y al botón Salir del encabezado, mediante un relleno más oscuro, borde definido y estado hover/foco visible.
- En el historial, la acción ZIP ahora se muestra como **Descargar ZIP**.
- Se habilita el correo de notificación de cada usuario en Administración → Usuarios y en Mi cuenta. El campo `users.email` ya existía desde la migración inicial; no se duplica ni se altera.
- Se añade la migración SQLite `006_bulk_mail_batches.php` (esquema 6) para asociar cada evento de correo a un lote masivo concreto y registrar el estado del comprobante.
- Al finalizar la distribución masiva, se genera un comprobante HTML y texto al correo del usuario que inició la operación. Incluye departamento, destinatarios aceptados por SMTP, destinatarios fallidos con motivo, omisiones y totales. No adjunta el ZIP ni los Excel.
- Si el usuario no configuró correo, los resultados igualmente quedan auditados y el modal indica cómo agregarlo en Mi cuenta. Si falla el correo del comprobante, el modal informa que el envío departamental quedó registrado pero el comprobante no pudo enviarse.
- El recibo aclara que aceptación SMTP no garantiza entrega final ni lectura. Las direcciones y motivos se escapan para HTML; el resumen se genera exclusivamente con eventos asociados al lote.
- Se agregan pruebas de regresión del comprobante, del flujo de lote, del campo de correo y del esquema 6.

## 1.2.24 — Confirmaciones y estado visual de operaciones

- Sustituye las confirmaciones nativas del navegador por un modal común, con título, texto, botones de cancelar/aceptar, cierre con Escape y estilo de acción destructiva.
- Las confirmaciones se procesan antes de enviar el formulario; cancelar no ejecuta ninguna petición. Al aceptar, se usa `requestSubmit()` para conservar validación HTML y el botón de origen.
- Se incorpora una capa global de operación con fondo oscurecido y desenfoque, spinner y barra animada indeterminada para que el usuario identifique tareas prolongadas.
- La capa aparece durante el procesamiento del XLSX, regeneración de reportes, envío individual, prueba SMTP, restauración y migraciones. El modal de envío masivo conserva su progreso por departamento y su desenfoque propio.
- El overlay se restablece al cargar/volver a la página y no se aplica a descargas de archivos, para evitar dejar la interfaz bloqueada cuando el navegador comienza una descarga.
- Se agrega `tests/operation-feedback-smoke.js`. No cambia la lógica de operaciones ni el esquema SQLite 5.

## 1.2.23 — Corrección al incrustar el logotipo durante regeneración

- Corrige el error `Undefined constant ZipArchive::RDWR` al regenerar reportes con identidad visual configurada.
- `DepartmentSplitter::embedBrandLogo()` abre el XLSX existente con `ZipArchive::open($xlsxPath)` sin flags; ese es el modo de lectura/escritura predeterminado.
- Se añade una aserción de regresión a `tests/report-regeneration-smoke.js` para impedir que vuelva a introducirse la constante inexistente.
- No cambia el esquema SQLite, el mecanismo de relación `uploads/{runId}.xlsx` / `exports/{runId}`, la auditoría de correo ni el proceso de regeneración.

## 1.2.22 — Plantillas Excel de asistencia y regeneración de reportes

- Los Excel individuales ahora muestran el título **Reporte de asistencia** y debajo la semana ISO junto con el rango de fechas calculado desde el propio XLSX.
- Cada hoja agrega al pie **Realizado por** y **Generado**, usando la persona y la fecha registradas en la ejecución. Se conserva una fila de separación y no se alteran los datos de asistencia. La línea de semana queda en la fila 3, debajo del título y sin invadir el espacio reservado al logo.
- Cuando hay un logotipo configurado en Identidad visual, se incrusta en tamaño pequeño dentro del espacio superior izquierdo, con límite de 160 × 44 px de cada Excel departamental, sin tapar título ni subtítulo. Si no hay logo, el reporte conserva su presentación anterior. Se validan contenido, MIME, tamaño y dimensiones; WebP se convierte con GD cuando está disponible; si no, los Excel se generan sin imagen y se muestra un aviso. Para incrustarlo siempre, usa PNG/JPG/GIF.
- Las nuevas ejecuciones conservan una copia privada `source.xlsx` y un `presentation.json` con autor/fecha originales para permitir regeneraciones posteriores y mantener el pie estable.
- **Compatibilidad con ejecuciones anteriores:** el sistema también busca el original histórico en `uploads/{runId}.xlsx`, usando el mismo identificador que la carpeta `exports/{runId}`. Si lo encuentra, habilita la regeneración aunque no exista `source.xlsx`; al regenerar lo copia de forma verificada a la carpeta privada de la ejecución para convertirlo en la fuente permanente.
- `php bin/zknet cleanup` ya no elimina archivos `uploads/{runId}.xlsx` cuando su identificador sigue registrado en `report_runs`; las cargas temporales realmente huérfanas conservan la política de retención configurada. Los respaldos completos también recuperan los XLSX históricos asociados y los guardan en el payload `exports/{runId}/source.xlsx`.
- **Historial → Regenerar reporte** vuelve a leer el XLSX original, prepara un conjunto temporal y, solo al terminar correctamente, cambia las rutas del ZIP y de los Excel. Se limpia la salida anterior tras confirmar el cambio.
- La regeneración conserva los IDs de registros departamentales cuando el área sigue presente y mantiene la auditoría de envíos anteriores. Los nuevos archivos quedan pendientes de envío; los correos no se reenvían automáticamente.
- No cambia el esquema SQLite 5 ni requiere nuevas extensiones o estructura de carpetas. Se amplían las pruebas de splitter, logotipo, respaldo y reemplazo de salidas.

## 1.2.20 — Logotipo configurable en identidad visual

- **Sistema → Identidad visual** permite cargar, reemplazar y quitar el logotipo de la empresa, con vista previa inmediata antes de guardar.
- Se aceptan PNG, JPG/JPEG, GIF y WebP de hasta 2 MiB, 2400 × 1200 píxeles y 2.5 megapíxeles. SVG y otros formatos se rechazan.
- Los archivos se validan por contenido real y se guardan con nombre aleatorio en el directorio privado externo. El acceso de lectura sirve únicamente la imagen de marca configurada, con MIME validado y `nosniff`.
- El logotipo aparece en la navegación lateral y la pantalla de acceso. Las siglas configuradas continúan como alternativa cuando no hay imagen.
- El recurso se conserva en los respaldos completos porque estos copian el directorio privado. No requiere extensión PHP, permiso IIS adicional ni migración SQLite; el esquema permanece en 5.
- Se agrega `tests/brand-logo-smoke.php` y se amplía `tests/branding-smoke.js`.

## 1.2.19 — Ancho completo en todas las pantallas

- El contenedor principal autenticado ahora ocupa todo el ancho disponible del área de contenido, igual que la pantalla **Sistema → Actualizaciones**. Se elimina el límite global de 1,600 px que dejaba márgenes grandes en monitores amplios.
- Se aplica de forma general a Cargar reporte, Resumen, Historial, Departamentos, Correo y plantillas, Identidad visual, Usuarios, Mi cuenta y Actualizaciones. No depende de la página seleccionada.
- Se conservan los gutters internos responsivos: 34 px en escritorio, 22 px en tabletas y 13 px en móviles; las cuadrículas existentes mantienen sus puntos de quiebre y los formularios individuales siguen limitando el ancho de campos cuando conviene para legibilidad.
- Se elimina la clase condicional `page-content-wide`; la estructura usa un único contenedor compartido.
- No cambian datos, permisos, autenticación, respaldo/restauración, endpoints ni el esquema SQLite (permanece en 5).

### Validación
- `php -l`: aprobado en los 55 archivos PHP (PHP CLI 8.4.24; validación sintáctica).
- 27 suites JavaScript `tests/*smoke.js` aprobadas con Node.js 22.16.0, incluida `all-pages-full-width-smoke.js` para las nueve plantillas autenticadas y los márgenes responsivos.
- Pruebas PHP independientes de registro privado, proxy HTTPS, firma, SMTP/MIME y política de destinatarios aprobadas.
- Las pruebas de bloqueo, instalación y backup/restore que requieren PDO_SQLite/ZIP hacen SKIP en este entorno y deben ejecutarse en staging IIS/PHP 8.5.

## 1.2.18 — Organización de la pantalla de actualizaciones y respaldos

- Se reorganiza **Sistema → Actualizaciones** en dos áreas principales: estado de la base de datos y ubicación/respaldo de archivos.
- La información del entorno (plataforma, SAPI, versión PHP y extensiones) se integra dentro del panel de estado; se elimina la tarjeta técnica inferior y se evita repetir la versión de la aplicación.
- Las rutas se agrupan en **Datos persistentes** y **Archivos de configuración del sitio**, con una disposición compacta de dos columnas en escritorio y una columna en móvil.
- Se separan visualmente la descarga de respaldo, el aviso de confidencialidad y la restauración destructiva; se conserva la validación, CSRF, confirmación de contraseña y reversión automática existentes.
- Se ajustan proporciones, espaciado y puntos de quiebre para mantener jerarquía visual en pantallas amplias, tabletas y móviles.
- No cambian el flujo de respaldo/restauración, autenticación, datos, migraciones ni el esquema SQLite (permanece en 5).

### Validación
- `php -l`: sintaxis correcta en los 55 archivos PHP.
- Las 26 suites JavaScript pasan, incluida `tests/updates-page-layout-smoke.js` y la prueba de contrato de respaldo/restauración.
- Pasan las pruebas PHP independientes de errores, sesiones/proxy, firma, Reply-To y política de destinatarios.
- Se omitieron las pruebas funcionales de bloqueo, instalación y backup/restore porque este entorno no dispone de `pdo_sqlite` ni `zip`; deben ejecutarse en staging IIS con PHP 8.5.
- El cambio de este release es de presentación; conserva las acciones, validaciones y endpoints existentes.

## 1.2.17 — Bloqueo de cuentas, seguridad de sesión y respaldo completo

- Se agrega la migración 005 para registrar intentos de acceso fallidos, bloqueos temporales y versión de sesión por cuenta. Las migraciones históricas 001–004 permanecen intactas.
- La política inicial es 5 intentos fallidos y 10 minutos de bloqueo; se puede configurar en **Administración → Usuarios**. El panel conserva el número de intentos alcanzado mientras la cuenta sigue bloqueada.
- Se incorpora la acción administrativa para desbloquear cualquier otra cuenta bloqueada, incluidos otros administradores. El desbloqueo reinicia el contador e invalida las sesiones anteriores de esa cuenta.
- La sesión valida en cada petición el estado y la versión de la cuenta. Los cambios de contraseña, rol, estado, bloqueo y desbloqueo revocan sesiones anteriores.
- Las cookies de sesión usan modo estricto, solo cookies, HttpOnly y SameSite=Lax. `X-Forwarded-Proto` solo se toma en cuenta si `REMOTE_ADDR` coincide con una IP declarada en `ZKTIME_TRUSTED_PROXIES`.
- La pantalla **Sistema → Actualizaciones** ahora utiliza todo el ancho disponible, muestra las rutas físicas de la aplicación, datos y archivos de configuración, y permite descargar un ZIP con fecha/hora mediante POST protegido con CSRF.
- El respaldo incluye SQLite, `app.key`, configuración privada, firmas, reportes permanentes y `config/app.php`/`config/local.php` como referencias; excluye sesiones, temporales, logs y respaldos anteriores.
- Se agrega restauración con validación previa de manifiesto, SHA-256, límites de extracción, rutas ZIP, llave e integridad SQLite. La generación del ZIP también verifica que `app.key` tenga 32 bytes y que la instantánea SQLite pase `integrity_check` antes de descargarla. Antes de reemplazar datos crea un respaldo completo de reversión; al terminar incrementa la versión de sesión de todas las cuentas para revocar sesiones anteriores y cierra la sesión actual.
- Se coordinan backup/restauración con un lock de mantenimiento y se libera el lock de sesión PHP antes de esperar el lock exclusivo, evitando bloqueos con otras pestañas. La descarga libera mantenimiento una vez creada la instantánea.
- Se advierte que el ZIP contiene datos sensibles y no está cifrado con contraseña propia; debe conservarse en un almacenamiento cifrado.
- Se agregan pruebas de humo estáticas y pruebas funcionales PHP para bloqueo y backup/restore.
- No se modifican `001_initial.php`–`004_department_discovery.php`; el esquema objetivo pasa a 5.

## 1.2.16 — Asistente seguro de primera instalación y credenciales iniciales

- Se agrega `install.php`: detecta sistema operativo, servidor web, interfaz PHP, versión y extensiones requeridas antes de inicializar el sistema.
- La primera instalación permite seleccionar/confirmar una ruta absoluta de datos fuera del DocumentRoot, comprueba permisos reales de escritura y prepara directorios privados.
- La ruta elegida se guarda en `config/local.php`, que solo contiene `data_root`; `ZKTIME_DATA_DIR` del servidor tiene prioridad cuando está definida.
- La cuenta inicial `administrador` se crea con contraseña capturada en el asistente. El hash se genera mediante `password_hash()` y se entrega a la migración histórica 003 únicamente en memoria durante esa petición.
- Se elimina de `config/app.php` el hash administrativo predeterminado. No se guardan contraseñas ni hashes en archivos de configuración.
- Las instalaciones de esquema 3/4 conservan credenciales, base de datos, llaves, reportes y demás datos. Las instalaciones de esquema 1/2 reciben un asistente de transición con respaldo SQLite y nueva contraseña para el administrador.
- `bootstrap.php` ya no inicializa automáticamente SQLite en una instalación vacía: redirige al asistente y mantiene intacto el flujo normal de instalaciones existentes.
- Se permite reanudar una instalación parcial cuando SQLite solo contiene metadatos de migración en versión 0; se agrega un bloqueo exclusivo para evitar dos instalaciones simultáneas.
- Se agrega `InstallationState` para inspeccionar el esquema sin crear ni modificar SQLite, `assets/install.css` y `tests/installer-smoke.js`.
- No se modifican las migraciones históricas ni el esquema SQLite 4. No se implementan enlaces anónimos de descarga.
- Validación disponible en este entorno: sintaxis PHP de todos los archivos y suites JavaScript. No se pudo ejecutar el smoke integral PHP 8.5/IIS porque el entorno de validación dispone de PHP 8.4.24 y carece de las extensiones SQLite/ZIP/DOM requeridas.

## 1.2.15 — Panel principal con instrucciones claras

- Se retira la tarjeta decorativa que repetía el acceso **Cargar reporte**; la acción principal permanece en el encabezado como **Procesar reporte**.
- La tarjeta ahora explica el formato requerido: archivo Excel `.xlsx` exportado desde ZKTimeNet; aclara que PDF, CSV y el formato antiguo `.xls` no se pueden procesar.
- Se explica el calendario ISO con lenguaje sencillo: la semana empieza el lunes y termina el domingo, y el número/año se calcula a partir de las fechas del reporte. Se incluye el ejemplo 26 de octubre–1 de noviembre de 2026 = semana 44 de 2026.
- Se reemplaza el arte decorativo y las notas ambiguas por dos bloques informativos con iconos, espaciado adaptable y soporte para temas claro/oscuro.
- No cambia el procesamiento, permisos ni el esquema SQLite (permanece en 4).

## 1.2.14 — Ocultar errores técnicos y proteger bootstrap.php

- Los errores de inicialización y conexión ya no exponen mensajes de excepción, rutas físicas ni detalles internos en páginas HTTP. Se muestra un aviso sencillo y una referencia de incidente cuando el registro privado está disponible.
- Se agrega `src/Core/ErrorLogger.php`: registra tipo, mensaje, archivo, línea y traza fuera de la raíz web, en `storage_private/logs/application.log`, con rotación de 5 MiB, bloqueo de escritura y redacción básica de secretos.
- Los errores nativos de PHP se configuran para no mostrarse en pantalla y se envían al registro privado `php-error.log`. Los mensajes operativos de validación se conservan para que el personal pueda corregir sus datos.
- Los fallos SMTP registrados en el historial muestran un mensaje seguro y una referencia, no la respuesta técnica del servidor de correo.
- `bootstrap.php` rechaza peticiones HTTP directas (404) salvo cuando se incluye desde `index.php`, `login.php` o `logout.php`; IIS (`web.config`) y Apache (`.htaccess`) también bloquean el acceso directo. La ejecución CLI continúa permitida.
- No cambia SQLite (esquema 4), los datos, los reportes, las credenciales ni la lógica de negocio.

## 1.2.13 — Iconografía coherente en botones y acciones

- Se incorporó un conjunto de iconos SVG ligeros, locales y accesibles, sin dependencias externas.
- Se asignaron iconos relacionados con cada acción: procesar, cargar, guardar, editar, descargar, enviar, reenviar, configurar, eliminar, cambiar contraseña, iniciar sesión, salir, ayuda, cerrar y actualizar.
- Se agregaron iconos también a enlaces que funcionan visualmente como botones, incluidos los accesos del historial, tablas y paneles.
- Se conservaron los textos de las acciones, los colores semánticos y el comportamiento de cada control.
- Se actualizaron las referencias de caché de CSS/JavaScript a 1.2.13.
- No cambia SQLite ni requiere migraciones, Composer o extensiones PHP adicionales.

## 1.2.12 — Colores y alineación de controles de envío masivo

- El botón **Enviar correo a todos (N)** cambia a verde para identificar la acción de distribución.
- El botón cuadrado de cierre del modal (X) cambia a rojo, con fondo y contraste visibles en temas claro y oscuro.
- La X se centra mediante cuadrícula y un contenedor interno para corregir la alineación óptica dentro del cuadrado.
- Se mantienen **Iniciar envío** y el modal como única confirmación; no cambian destinatarios ni transporte SMTP.
- Sin cambios en SQLite, datos, permisos o requisitos de IIS.

## 1.2.12 — Claridad de envío masivo y alineación del modal

- El botón del historial cambia a **Enviar correo a todos (N)** y utiliza el color rojo para identificar claramente la acción de envío.
- El encabezado del modal se reorganiza: la X comparte una fila con el título y queda centrada verticalmente.
- Se conserva el modal como única confirmación; el envío inicia únicamente al pulsar **Iniciar envío**.
- Se actualizan versión y referencias de caché CSS/JS. Sin cambios en SQLite, permisos, destinatarios, SMTP ni reportes.

## 1.2.10 — Confirmación única para envío masivo

- Se elimina el `window.confirm()` del botón **Enviar a todos**. Al pulsarlo se abre directamente el modal de distribución; ese modal es la única confirmación antes de enviar.
- El resumen del modal indica cuántos Excel individuales se enviarán, que el ZIP general no se adjunta y permite revisar la lista de departamentos antes de pulsar **Iniciar envío**.
- Se conserva el flujo de progreso por departamento, auditoría, control de errores y recuperación de botones al terminar.
- Se actualizan la versión de aplicación y las referencias de caché estática.
- Sin cambios en SQLite, SMTP, permisos, usuarios, archivos históricos ni requisitos de IIS.

## 1.2.9 — Vista de detalle de ejecución simplificada

- Se oculta la sección **Todos los reportes** cuando se abre una ejecución específica (`?page=history&run=...`).
- La lista general de ejecuciones permanece visible al entrar al Historial sin seleccionar un reporte.
- El detalle conserva departamentos, destinatarios, estados, acciones, auditoría y acceso **Volver al historial**.
- No cambia el esquema SQLite, archivos históricos, correo ni permisos.

## 1.2.8 — Ajuste de la tarjeta principal y lenguaje sencillo

- Se elimina el posicionamiento absoluto de las notas inferiores de la tarjeta **Procesa un reporte semanal**. La tarjeta utiliza un flujo vertical flexible: el botón, el formato admitido y la indicación de semana ocupan sus propias líneas y no se solapan en pantallas estrechas o con zoom.
- Se permite que las notas inferiores se ajusten a varias líneas cuando no hay ancho suficiente.
- El indicador de envíos cambia **Entregas confirmadas por SMTP** por **Entregas confirmadas por servidor de correo**, evitando siglas técnicas en el panel.
- No cambia el procesamiento XLSX, envío de correos, permisos ni el esquema SQLite (permanece en 4).

## 1.2.7 — Etiqueta visible del selector de tema

- Se agrega la etiqueta **Tema** inmediatamente a la izquierda del selector en el encabezado de la aplicación y en la pantalla de inicio de sesión.
- La etiqueta permanece visible en pantallas estrechas; el selector conserva el ciclo Sistema → Claro → Oscuro y la cookie de preferencia existente.
- Se actualizan versión y referencias de caché estática. No cambia SQLite, usuarios, permisos ni configuración.

## 1.2.6 — Resumen adaptado al perfil Nominista

- El panel de control oculta los apartados **Conexión SMTP** y **Base de datos local** a usuarios Noministas.
- Administradores conservan ambos apartados y sus accesos de configuración.
- El catálogo y los destinatarios por departamento continúan visibles para ambos perfiles.
- El indicador de preparación del sistema para Noministas ya no depende del estado SMTP, que es una configuración administrativa.
- No cambia el esquema SQLite (permanece en 4), los permisos del servidor ni el procesamiento.

## 1.2.5 — Instrucción de descarga y distribución en la pantalla principal

- Se aclara que, al terminar el procesamiento, el usuario puede abrir el historial para descargar los Excel individuales por departamento, descargar un ZIP general o enviar los reportes a los responsables, de manera individual o masiva.
- Se renuevan la versión de aplicación y los parámetros de caché estática para publicar el cambio sin conservar texto anterior en el navegador.
- No cambia el comportamiento funcional, permisos, SMTP, reportes ni esquema SQLite (permanece en 4).

## 1.2.4 — Acciones de usuarios y alineación de tablas

- Se mantienen las acciones de estado para las cuentas ajenas a la sesión actual y se hacen explícitas como **Habilitar** / **Deshabilitar**; la cuenta conectada no presenta ese control.
- La protección contra habilitar/deshabilitar la propia cuenta se aplica tanto en el controlador como en `UserRepository::setActive()`. La regla de conservar al menos un Administrador activo continúa vigente.
- Se corrige la causa del descuadre de renglones: `.actions-cell` ya no utiliza `display:flex`, porque eso reemplazaba el comportamiento nativo `table-cell` del `<td>`. Los controles se distribuyen con flexbox dentro de `.table-actions`.
- Se aplica la misma estructura a tablas de usuarios, departamentos y todas las tablas del historial (detalle, ejecuciones recientes y auditoría). Los bordes inferiores de cada fila vuelven a recorrer todas las columnas de forma continua.
- Se agrega una prueba de regresión para impedir que una celda de tabla vuelva a convertirse en contenedor flex y verificar que todas las vistas usen el wrapper compartido.
- No cambia el esquema SQLite (permanece en 4), no requiere Composer ni nuevas extensiones.

## 1.2.3 — Firma gráfica de correo y ayuda contextual

- Se agrega carga de imagen de firma desde **Correo y plantillas**. Acepta PNG/JPG/GIF/WebP, máximo 2 MB y 2400 × 1200 px; valida contenido y dimensiones con `getimagesize()`.
- La firma se conserva en el directorio privado de datos y se almacena como metadato en la configuración SMTP existente. No se crea una tabla nueva ni se requiere migración SQLite.
- `MailerService` incorpora la imagen al final del HTML mediante `cid:zktime-signature@local`; `SmtpTransport` arma `multipart/related` anidado dentro de `multipart/mixed`. La imagen se marca `Content-Disposition: inline` y el XLSX sigue siendo `attachment`.
- La prueba SMTP también incluye la firma configurada. Se permite reemplazarla o quitarla desde configuración; la vista previa requiere sesión Administrador.
- Se agrega **Ayuda** al final del menú, con modal contextual para Administrador y Nominista, cierre por botón, backdrop y Escape.
- Se renuevan los parámetros de caché de CSS/JS. No cambia el esquema 4, los permisos, los reportes ni el comportamiento de destinatarios.
- Validación: 40+ PHP con `php -l`, suites JavaScript, MIME completo parseado externamente (firma inline + XLSX attachment), tests SMTP y prueba específica de ayuda/roles. La ejecución integral de la aplicación sigue requiriendo PHP 8.5 + extensiones en IIS.

## 1.2.2 — Descubrimiento de departamentos y cuenta personal

- Los departamentos desconocidos en un XLSX se registran automáticamente en el catálogo con su etiqueta original, nombre visible inicial, `enabled = 0` y `needs_configuration = 1`. Su Excel sí se genera y entra al ZIP; no se habilita el envío hasta que un administrador complete la configuración y lo active.
- Los departamentos ya deshabilitados tampoco detienen el procesamiento: su información se separa y conserva en el ZIP, pero los envíos individuales y masivos los omiten con estado explícito.
- El historial distingue departamentos pendientes de configuración y departamentos deshabilitados; ofrece el acceso a editar la configuración cuando corresponde.
- La página principal presenta un aviso con los departamentos nuevos detectados, los departamentos activos sin correo de responsable, los que no tienen destinatarios efectivos y los que están deshabilitados para distribución.
- El perfil del usuario en el encabezado enlaza a **Mi cuenta**. Administradores y noministas pueden cambiar su propia contraseña verificando la actual, confirmando la nueva y usando un mínimo de 8 caracteres.
- La migración `004_department_discovery.php` agrega `needs_configuration` sin alterar áreas existentes, destinatarios ni reportes.
- Esquema SQLite objetivo: 4. No requiere extensiones ni dependencias nuevas.

# Changelog

## 1.2.1 — Inicio de sesión sin usuario sugerido

- Se elimina el placeholder `administrador` del campo Nombre de usuario. El campo aparece vacío, sin sugerir ni precargar una cuenta.
- Se conserva la autenticación individual y la transición de migración para instalaciones que aún tengan pendiente el esquema 003.
- Se agrega una prueba de regresión para impedir que reaparezca el usuario sugerido.
- No requiere migración SQLite ni cambios de permisos en IIS.


## 1.2.0 — Usuarios, permisos y autoría de ejecuciones

- Se reemplaza el acceso por contraseña compartida por autenticación individual con nombre de usuario y contraseña.
- La migración `003_user_accounts_and_roles.php` agrega nombre de persona, perfil y estado a `users`; crea la cuenta `administrador` reutilizando el hash de la contraseña existente y atribuye las ejecuciones históricas sin autor a esa cuenta.
- Nuevo módulo **Administración → Usuarios**, exclusivo del Administrador: crear, editar, activar y desactivar cuentas. La contraseña se guarda con `password_hash()`; al editar, dejarla vacía mantiene la contraseña actual.
- Perfiles: Administrador (acceso completo) y Nominista (Operación y Configuración → Departamentos). Las restricciones se validan en navegación y backend.
- Las sesiones actualizan el nombre y el perfil desde SQLite; las cuentas desactivadas pierden acceso. No se puede desactivar al último administrador activo.
- El historial muestra **Realizado por** con el nombre de persona de la cuenta que procesó la ejecución. Las ejecuciones creadas con el acceso compartido anterior se atribuyen a Administrador.
- El título del bloque de origen pasa a **Generado del archivo** y permite modificar el nombre base con un botón de lápiz, conservando `.xlsx`. Solo cambia el nombre de referencia en historial; no se modifican los archivos ya generados.
- Esquema SQLite objetivo: 3. Se conserva el contenido de usuarios/áreas/destinatarios/SMTP/plantillas/branding/reportes y se crea respaldo antes de migrar.

## 1.1.4 — Aviso de configuración y columna Turno adaptable

- Se restauró en la pantalla principal el aviso de departamentos sin correo de entrega. La alerta da prioridad a departamentos sin correo del responsable y distingue los que no tienen ningún destinatario efectivo.
- El procesador OOXML adapta el ancho de la columna D (Turno) al texto más largo del departamento, evitando que horarios como `8:00 A 5:00(08:00-17:00)` queden cortados en los XLSX enviados. El ajuste se aplica únicamente al libro generado y conserva los demás anchos.
- No requiere migración SQLite.

## 1.1.3 — Responsable siempre recibe el Excel y datos técnicos centralizados

- El responsable del departamento recibe siempre una copia individual del Excel, aunque no figure en la lista de Correos destinatarios.
- Se deduplican las direcciones cuando el responsable también está en la lista del departamento.
- La dirección del responsable se excluye únicamente de Reply-To, incluso si fue agregada a los Reply-To generales.
- El envío individual, el reenvío del historial y el envío manual masivo comparten la misma regla.
- El historial muestra al responsable dentro de la lista efectiva de personas que reciben el archivo.
- Se retiran PHP, versión y texto de acceso restringido de pie, barra lateral y login; la información técnica queda únicamente en Actualizaciones.
- No requiere migración SQLite.

## 1.1.2 — Interfaz simplificada y cierre correcto del envío masivo

- Corregida la regla CSS del atributo `[hidden]`: los botones ocultos ya no se muestran por reglas `display:flex`. El botón «Enviando…» desaparece al terminar y queda visible «Actualizar historial».
- El ciclo de envío masivo libera el estado y habilita el cierre del modal desde `finally`, incluso ante errores inesperados.
- Retirada de la página principal la sección de distribución y el checkbox de envío automático. Los correos se gestionan manualmente desde el historial.
- El botón principal ahora se denomina «Procesar reporte».
- Simplificadas las instrucciones para usuarios con poca experiencia tecnológica.
- No requiere migración SQLite ni cambios en el almacenamiento persistente.

