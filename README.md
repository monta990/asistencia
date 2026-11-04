# ZKTimeNet Personal Framework 1.2.20 — sin Composer

Aplicación interna para Nóminas que procesa el reporte XLSX de ZKTimeNet, separa los registros por departamento, genera Excel individuales y un ZIP general, y opcionalmente distribuye cada Excel por correo. Incluye identidad visual configurable con carga segura del logotipo de la empresa.

## Plataforma

- PHP 8.5 o superior, IIS + FastCGI.
- No requiere Composer, Internet ni instalación de paquetes.
- Extensiones PHP: `pdo_sqlite`, `zip`, `dom`, `openssl`.
- Mantiene datos persistentes fuera del código web (ruta configurable mediante `ZKTIME_DATA_DIR` o `config/local.php`).
- Asistente de primera instalación con detección de servidor, validación de almacenamiento externo y creación segura del administrador.

## Primera instalación

Abre `install.php` o la raíz del sitio. El asistente detecta el sistema operativo, servidor web, interfaz PHP y extensiones; permite confirmar la ubicación de datos fuera del DocumentRoot y solicita el nombre visible y la contraseña inicial del administrador. Si `ZKTIME_DATA_DIR` está definido, esa ruta tiene prioridad. Sin variable de entorno, la ruta elegida se conserva en `config/local.php`, archivo que solo contiene la ubicación de datos y está protegido contra acceso HTTP.

La primera instalación crea `app.key`, SQLite y las migraciones 001–005. La aplicación utiliza el esquema 5; 1.2.19 amplía el contenido a ancho completo en todas las pantallas autenticadas; conserva el bloqueo persistente de cuentas e invalidación de sesiones. Consulta `INSTALL.md` para preparar permisos de IIS, configurar el límite de restauración y conocer el flujo de respaldo/recuperación.

## Funciones

- Carga del reporte desde `index.php`.
- Separación de departamentos, descarga ZIP e historial.
- Catálogo de departamentos y destinatarios.
- SMTP configurable, plantillas, imagen de firma incrustada y envíos individuales.
- Temas claro, oscuro y automático.
- Configuración de identidad visual desde **Configuración → Identidad visual**.
- Enviar por primera vez o reenviar el Excel de un departamento desde el detalle del historial, con auditoría por intento.
- Actualizaciones de esquema versionadas, autenticación individual y permisos por perfil.
- Bloqueo persistente configurable por intentos fallidos (5 intentos y 10 minutos predeterminados), con desbloqueo administrativo e invalidación de sesiones anteriores.
- Respaldo completo ZIP con fecha/hora, rutas físicas agrupadas por datos y archivos del sitio, diagnóstico del servidor junto al estado y restauración validada con respaldo de reversión automático.
- Todas las pantallas autenticadas aprovechan el ancho completo disponible, con márgenes responsivos en tabletas y móviles.
- Ayuda contextual en el menú lateral con guía breve adaptada al rol del usuario.

## Identidad visual configurable

Desde la pantalla Identidad visual se administran:

- Nombre de empresa.
- Texto/siglas del logotipo.
- Lema o descripción.
- Etiqueta de plataforma (por ejemplo, `PLATAFORMA INTERNA`).
- Nombre del módulo (por ejemplo, `NÓMINAS`).
- Nombre de la aplicación.
- Texto del pie de página.

Los valores se guardan en SQLite y se muestran en navegación, encabezado, pantalla de acceso, pie de página y mensajes nuevos. Las plantillas admiten `{{empresa}}`, `{{aplicacion}}`, `{{plataforma}}` y `{{lema}}`, además de las variables de departamento/periodo/archivo.

## Reply-To múltiple (1.0.8)

- El ajuste SMTP **Responder a** acepta múltiples correos separados por coma, punto y coma o saltos de línea (hasta 50 direcciones).
- La casilla **Agregar los correos del departamento a «Responder a»** viene activada por defecto. En envíos y reenvíos de asistencia agrega a Reply-To los correos activos del área, excepto el Responsable del departamento, además de los globales configurados. La dirección del responsable también se excluye del Reply-To general para evitar auto-respuestas.
- Cada destinatario sigue recibiendo su mensaje de forma individual; las direcciones del área aparecen únicamente como direcciones de respuesta, no como destinatarios Para/CC.
- Se puede desactivar la casilla para conservar solo los Reply-To globales.
- Sin migraciones SQLite.

## Envío y reenvío desde el historial (1.0.7)

Abre una ejecución en **Historial**. Cada fila de departamento muestra **Enviar correo** cuando todavía no hay intentos registrados y **Reenviar correo** cuando ya existe al menos uno. La aplicación vuelve a consultar los correos habilitados actualmente en la configuración del departamento. Si no hay destinatarios, muestra **Sin correo** y no habilita el envío. El botón envía únicamente el Excel de esa fila, nunca el ZIP completo; todos los intentos se conservan en la auditoría de entrega. Requiere SMTP configurado y que el Excel permanezca en el almacenamiento privado.

## Actualización desde 1.0.7 a 1.0.9

1. Realiza respaldo de la carpeta persistente de datos (SQLite y llave privada juntas).
2. Reemplaza los archivos de la aplicación con el contenido del ZIP; conserva la ruta de datos existente.
3. Abre el historial, entra al detalle de una ejecución y revisa que aparezcan las acciones de envío según los destinatarios vigentes.
4. Haz primero una prueba con un departamento de pruebas. No se requiere migración SQLite.

La actualización no modifica departamentos, correos, SMTP, identidad visual, ejecuciones ni archivos históricos.

## Procesamiento y protección

El procesador inspecciona el paquete OOXML XLSX, identifica bloques de `Departamento`, conserva encabezados/datos/formato, elimina las filas ajenas a cada salida y reduce `sharedStrings.xml` para evitar que un Excel de departamento contenga datos textuales de otras áreas.

El envío SMTP es individual por destinatario. No se revelan las direcciones de los demás receptores.

## Validación

Ejecutar `php tests/smoke.php` y `node tests/theme-smoke.js`, `node tests/branding-smoke.js` y `node tests/history-mail-smoke.js` en PHP 8.5 con las extensiones indicadas. La prueba real de envío requiere parámetros SMTP válidos.

## Corrección SMTP/MIME (1.0.9)

Se corrige la normalización CRLF del transporte SMTP. Los encabezados RFC 5322 y las partes MIME ahora conservan sus separadores, por lo que los clientes de correo pueden interpretar el asunto, el cuerpo y el adjunto XLSX. La prueba `tests/smtp-reply-to-smoke.php` valida el MIME completo y el payload Base64. No requiere migración SQLite ni cambios de configuración.


## Responsable del departamento (1.1.0)

Cada departamento puede tener un correo de **Responsable del departamento** además de su lista de Correos destinatarios. El responsable es opcional y se captura/edita antes de la lista de destinatarios.

En los correos individuales del departamento, `Reply-To` incluye los correos SMTP generales y, cuando la opción SMTP está activada, los correos configurados para el departamento, excepto siempre la dirección del responsable. Si el responsable también está en Correos destinatarios, conserva la recepción de su propio Excel; solo se excluye del encabezado `Reply-To` para evitar que las respuestas se dirijan a sí mismo.

La versión 1.1.0 incorpora la migración SQLite `002_department_responsible.php`; los departamentos preexistentes quedan con responsable vacío y no pierden destinatarios ni historial.

## Responsable y Reply-To (1.1.0)

Cada departamento admite un correo de responsable. Ese correo queda fuera de Reply-To aunque aparezca en la lista de destinatarios o en los Reply-To globales. Si también está configurado como destinatario, sí recibe su Excel individual. Los envíos nuevos y los reenvíos aplican el mismo criterio. La migración `002_department_responsible.php` mantiene intactos los departamentos preexistentes y establece el nuevo campo vacío.

## Envío masivo desde historial

En el detalle de una ejecución, **Enviar a todos** inicia manualmente el envío de un Excel individual por cada departamento con destinatarios vigentes. El modal muestra el progreso y el resultado por departamento; los departamentos sin correo no se incluyen y el ZIP general nunca se adjunta.

## Retención permanente de reportes (1.1.2)

Los Excel por departamento y el ZIP general permanecen en el directorio privado hasta que el administrador los retire mediante mantenimiento explícito del almacenamiento. `php bin/zknet cleanup` no los elimina: únicamente limpia cargas XLSX temporales huérfanas después de un día por defecto. El archivo XLSX original recibido por el formulario se elimina tras procesarlo; se conservan las exportaciones individuales y el ZIP.


## Pantalla principal simplificada (1.1.2)

La pantalla principal solo solicita seleccionar el Excel y pulsar **Procesar reporte**. Los correos se envían manualmente desde el historial, ya sea por departamento o a todos los departamentos con destinatarios configurados.


## Responsable de departamento (1.1.3)

El correo del Responsable del departamento siempre recibe el Excel individual, aunque no aparezca en la lista Correos destinatarios. Si también aparece en esa lista, se deduplica y recibe un solo mensaje. Su dirección queda excluida de Reply-To, tanto de las direcciones generales SMTP como de las direcciones del departamento. Esta regla aplica a procesamiento, reenvío individual y distribución manual masiva.

Los detalles técnicos (versión de aplicación, versión de PHP y extensiones requeridas) solo se muestran en Sistema → Actualizaciones; se retiraron del pie de página, barra lateral y pantalla de acceso.

## Ajustes de la versión 1.1.4

- En la página principal se informa cuántos departamentos activos no tienen correo de responsable (prioridad) y cuántos no tienen ningún correo efectivo de entrega.
- El ancho de la columna `Turno` del Excel se calcula por departamento usando la cadena más larga encontrada, con un límite de seguridad para evitar ensanchar excesivamente la hoja.


## Usuarios y permisos (1.2.0–1.2.2)

El acceso es individual mediante **nombre de usuario y contraseña**. En instalaciones nuevas, el asistente `install.php` crea la cuenta inicial `administrador` y solicita su contraseña; no existe una credencial administrativa predeterminada ni un hash de administrador en el código. El hash se guarda en SQLite mediante `password_hash()`.

Las instalaciones existentes con esquema 3 o 4 conservan sus usuarios y contraseñas y aplican la migración 005 desde Actualizaciones; las instalaciones en esquema 5 ya están actualizadas. Las instalaciones históricas con esquema 1 o 2 pasan por un asistente de transición que crea un respaldo y solicita establecer una nueva contraseña administrativa, porque se elimina el hash predefinido antiguo. Desde **Administración → Usuarios**, únicamente el perfil Administrador puede crear, editar, activar y desactivar cuentas. Para desactivar se conserva el registro y su historial de autoría; la aplicación impide desactivar al último administrador activo.

**Administrador** conserva todas las funciones: Operación, Departamentos, Correo y plantillas, Identidad visual, Usuarios y Actualizaciones. **Nominista** solo puede acceder a Operación (Procesar reporte, Resumen e Historial), Configuración → Departamentos y **Mi cuenta** para cambiar su propia contraseña. Las restricciones se aplican tanto a la navegación como a las acciones POST del servidor.

Cada ejecución registra el ID de la cuenta que la creó y el historial muestra `Realizado por` con el nombre de la persona. En el detalle, el rótulo `Generado del archivo` acompaña al nombre original. El botón de lápiz permite modificar el nombre base; la extensión `.xlsx` se conserva y no se cambia el contenido de los reportes ya generados ni los nombres de los Excel departamentales/ZIP.

### Procedimiento histórico de actualización 1.1.4 → 1.2.0 (solo referencia)

Estas instrucciones describen el comportamiento de aquella versión. Para instalar o actualizar con 1.2.18, sigue `INSTALL.md`; las bases de esquema 1/2 usan ahora `install.php` para crear un respaldo y establecer una nueva contraseña.

1. Haz una copia de seguridad conjunta de `private/zktime.sqlite` y `private/app.key`, además de los reportes.
2. Sustituye los archivos de aplicación por los del ZIP 1.2.2, sin borrar el directorio de datos externo.
3. Abre el acceso e introduce `administrador` y la contraseña administrativa que ya utilizabas. La aplicación permitirá el acceso legado exclusivamente para entrar en **Sistema → Actualizaciones**.
4. En **Actualizaciones**, revisa el respaldo automático y pulsa **Aplicar migraciones**. Se ejecutará la migración 003, que crea la cuenta administrador y atribuye las ejecuciones históricas que no tenían autor a esa cuenta anterior.
5. Al terminar, la sesión se convierte en la nueva cuenta `administrador`. Cierra sesión y vuelve a entrar con usuario y contraseña.
6. Abre **Usuarios** y crea las cuentas noministas con el nombre de cada persona, nombre de usuario y contraseña.

La migración no elimina departamentos, responsables, destinatarios, SMTP, branding, ejecuciones ni reportes. No edites ni reemplaces las migraciones 001/002; 003 se agrega como migración nueva.


## Departamentos nuevos y deshabilitados (1.2.2)

El procesamiento ya no se detiene por una etiqueta desconocida ni por un departamento deshabilitado. Cuando se detecta una etiqueta nueva, se crea automáticamente en el catálogo con estado **Pendiente de configuración** y deshabilitado para distribución. El sistema conserva su Excel en el ZIP general, pero no ofrece ni ejecuta envíos hasta que un administrador revise el nombre, el responsable, los destinatarios y lo habilite.

Los departamentos que ya existen pero están deshabilitados también se separan y se incluyen en el ZIP; solamente se omite su correo. Los activos configurados continúan con el flujo normal. El historial indica el motivo de omisión.

## Mi cuenta y cambio de contraseña (1.2.2)

Haz clic en el nombre/avatar del usuario en el encabezado para abrir **Mi cuenta**. Cada persona puede cambiar su contraseña indicando la actual, escribiendo la nueva y confirmándola. La nueva contraseña debe tener al menos 8 caracteres. El permiso es individual: no habilita el acceso del nominista a SMTP, identidad visual, usuarios ni actualizaciones.

## Actualización 1.2.1 → 1.2.2

1. Respalda juntos `private/zktime.sqlite` y `private/app.key`; conserva también `exports/`.
2. Sustituye el código por el ZIP 1.2.2 sin modificar el directorio persistente externo.
3. Entra con una cuenta **Administrador**. El sistema dirige a **Sistema → Actualizaciones** mientras la migración 004 esté pendiente; los noministas verán una pantalla de mantenimiento hasta que termine.
4. Revisa el respaldo automático y aplica `004_department_discovery.php`. No edites las migraciones 001, 002 ni 003.
5. Después del cambio, procesa un XLSX de prueba con una etiqueta nueva y otra área deshabilitada: ambas deben aparecer en el ZIP; ninguna debe habilitar correo automáticamente.

No se agregan extensiones PHP, Composer ni permisos adicionales de IIS. El esquema SQLite pasa de 3 a 4.

## Imagen de firma en los correos (1.2.3)

Desde **Configuración → Correo y plantillas** puedes cargar una imagen de firma PNG, JPG, GIF o WebP (máximo 2 MB y 2400 × 1200 px). Se agrega automáticamente al final del cuerpo HTML, después del pie de correo. El transporte la envía como recurso MIME `inline` con `Content-ID` dentro de `multipart/related`; no se presenta como un adjunto independiente. El Excel de asistencia permanece como el adjunto normal del mensaje. La prueba SMTP también utiliza la firma configurada.

La imagen y la base de datos permanecen en el directorio privado de datos del servidor. Solo el administrador puede ver la vista previa o cambiar/eliminar la firma. No se habilita acceso público al archivo y no se agregan extensiones PHP ni dependencias externas. No requiere migración SQLite: los metadatos se guardan en la configuración existente.

## Ayuda por perfil (1.2.3)

El botón **Ayuda**, al final del menú lateral, abre una guía rápida en un modal. El contenido se adapta al rol autenticado: Administrador recibe instrucciones de configuración, usuarios y actualizaciones; Nominista recibe instrucciones de operación, departamentos, historial y cuenta personal. El modal se puede cerrar con el botón, haciendo clic fuera o pulsando Escape.



### Seguridad de errores y bootstrap (1.2.14)

- Los detalles de excepción y errores técnicos se guardan en `storage_private/logs/` fuera de la raíz web; las respuestas HTTP muestran mensajes generales y referencias.
- `display_errors` se desactiva para peticiones web. Los errores nativos de PHP se registran en el directorio privado.
- Las peticiones HTTP directas a `bootstrap.php` se rechazan; solo se usa como include interno de los puntos de entrada autorizados.


## Panel principal (1.2.15)

El panel principal evita repetir el botón de carga y muestra instrucciones sencillas: solo se aceptan libros de Excel `.xlsx` exportados desde ZKTimeNet. La semana y el año se calculan con el calendario ISO; las semanas comienzan el lunes y terminan el domingo.
