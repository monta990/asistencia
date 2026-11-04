## 1.2.20 — Logotipo configurable en identidad visual

- **Sistema → Identidad visual** ahora permite cargar, reemplazar y quitar el logotipo de la empresa, con vista previa inmediata antes de guardar.
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

