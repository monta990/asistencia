# Instalación y actualización — ZKTimeNet Personal Framework 1.2.19

## Requisitos mínimos

La versión 1.2.19 utiliza el ancho completo del área principal en todas las pantallas autenticadas. Es un cambio de presentación; no requiere migración ni modifica los datos existentes.

- Windows Server + IIS + PHP **8.5 NTS** mediante FastCGI (también se detectan Apache, Nginx y entornos Linux/macOS).
- Extensiones PHP: **`pdo_sqlite`, `zip`, `dom`, `openssl`**.
- HTTPS y acceso restringido a LAN/VPN.
- No se requiere Composer, acceso a Internet ni instalación de paquetes externos.

No es necesario habilitar `mbstring`, `iconv`, `fileinfo`, `ctype` ni `sqlite3` por separado. `pdo_sqlite` proporciona el controlador PDO utilizado por la aplicación.

## 1. Habilitar PHP y extensiones

En IIS, edita el `php.ini` que utiliza el handler FastCGI (no asumas que es el mismo que usa PHP CLI). En una distribución Windows típica, habilita:

```ini
extension=pdo_sqlite
extension=zip
extension=dom
extension=openssl
```

Reinicia el Application Pool después de modificar `php.ini`. Comprueba que las extensiones estén disponibles en el PHP del servidor web. El asistente también muestra el sistema operativo, servidor web, interfaz PHP y requisitos detectados antes de permitir la instalación.

## 2. Extraer el paquete

Descomprime el ZIP completo, por ejemplo en:

```text
C:\inetpub\wwwroot\zktime\
```

Configura el sitio o aplicación IIS para que su ruta física apunte a esa carpeta. `index.php` es la página predeterminada. No es necesario modificar la estructura del proyecto.

## 3. Preparar la carpeta de datos externa

Los datos persistentes deben quedar fuera del directorio público. La ruta sugerida depende del sistema operativo:

| Sistema | Ruta predeterminada |
|---|---|
| Windows | `C:\ProgramData\CYAlimentos\ZKTimeNet` |
| Linux | `/var/lib/zktimenet` |
| macOS | `/Library/Application Support/ZKTimeNet` |

En Windows, abre PowerShell como administrador y prepara la ruta (cambia `ZKTimeNet` si tu Application Pool tiene otro nombre):

```powershell
$dataPath = 'C:\ProgramData\CYAlimentos\ZKTimeNet'
New-Item -ItemType Directory -Force -Path $dataPath | Out-Null
icacls $dataPath /grant 'IIS AppPool\ZKTimeNet:(OI)(CI)M'
```

La identidad del Application Pool necesita **Modify** sobre la carpeta externa. El asistente prueba la escritura antes de continuar. Si prefieres una ruta distinta o administrada centralmente, configura la variable de entorno FastCGI `ZKTIME_DATA_DIR` con una ruta absoluta y otorga permisos sobre ella. Cuando esta variable existe, tiene prioridad y el campo de ubicación queda bloqueado en el asistente.

## 4. Permisos del código y configuración inicial

Concede solo lectura/ejecución sobre los archivos de aplicación. No concedas escritura permanente sobre el sitio, `src`, `database`, `views` o `vendor`.

Cuando **no** se utiliza `ZKTIME_DATA_DIR`, el asistente guarda la ruta elegida en `config/local.php`. Para permitirlo, concede temporalmente `Modify` a la identidad del Application Pool sobre la carpeta `config`:

```powershell
$configPath = 'C:\inetpub\wwwroot\zktime\config'
icacls $configPath /grant 'IIS AppPool\ZKTimeNet:(OI)(CI)M'
```

Una vez terminada la instalación, retira la concesión explícita que agregaste:

```powershell
icacls $configPath /remove:g 'IIS AppPool\ZKTimeNet'
```

`config/local.php` contiene únicamente la ruta de datos, nunca contraseñas ni hashes administrativos. La carpeta `config` está bloqueada para acceso HTTP por `web.config` y `.htaccess`. Si no deseas dar permiso temporal sobre `config`, define `ZKTIME_DATA_DIR` en el servidor antes de abrir el asistente.

## 5. Configuración PHP para reportes y restauración de respaldos

En el `php.ini` de IIS:

```ini
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 0
max_input_time = 600
memory_limit = 256M
date.timezone = America/Mexico_City
```

El límite interno del XLSX operativo permanece en `config/app.php` (`max_upload_bytes`, 25 MiB). El límite de restauración ZIP es 512 MiB; por eso PHP debe aceptar 512 MiB y el POST 520 MiB. `web.config` establece `maxAllowedContentLength` en aproximadamente 525 MiB para dejar margen al multipart/form-data. Después de editar php.ini, recicla el Application Pool.

## 6. Primera instalación o actualización de cuentas antiguas

Abre:

```text
https://servidor/zktime/install.php
```

También puedes abrir la dirección raíz: una instalación nueva se redirige automáticamente al asistente antes de crear SQLite. El asistente:

1. Detecta sistema operativo, servidor web, interfaz PHP y versión PHP.
2. Comprueba PHP 8.5 y las extensiones requeridas.
3. Muestra y valida una ruta absoluta, escribible y fuera del DocumentRoot.
4. Crea los directorios privados y la llave `private/app.key` sin sobrescribir una llave existente.
5. Solicita el nombre visible del administrador y su contraseña inicial (mínimo 8 caracteres, con confirmación).
6. Ejecuta las migraciones numeradas y crea la cuenta `administrador`. La contraseña se guarda en SQLite mediante `password_hash()`.
7. Registra el cierre de instalación fuera del sitio web y envía al login.

El asistente no tiene una contraseña predeterminada. El hash de la contraseña capturada se mantiene únicamente en memoria durante la migración histórica 003; no se escribe en `config/app.php` ni en `config/local.php`. La base SQLite de una instalación nueva se crea en esquema 5 e incluye la política de bloqueo persistente y la versión de sesión por usuario.

### Actualizaciones existentes

- **Instalaciones en esquema 3, 4 o 5:** no se abre el asistente. Se conservan las cuentas, contraseñas, datos, reportes y llaves. Las migraciones pendientes se aplican desde **Sistema → Actualizaciones**.
- **Instalaciones en esquema 1 o 2:** el asistente de transición solicita establecer una nueva contraseña para `administrador`, porque el hash predefinido de versiones antiguas se elimina del código. Antes de migrar crea un respaldo SQLite; mantiene los usuarios existentes, departamentos, ajustes, historial y archivos. Después entra con `administrador` y la nueva contraseña.
- **Instalaciones nuevas:** se crea la cuenta desde cero. El nombre de usuario inicial es `administrador`; se puede cambiar después desde **Administración → Usuarios**.

No cambies manualmente el nombre de `private/app.key` ni copies una llave distinta a la base existente. Si la base ya existe y falta la llave, restaura la original desde el respaldo; el asistente no genera una nueva en una instalación preexistente.

## 7. Bloqueo de acceso, respaldos y restauración

En **Administración → Usuarios** configura la política global de inicio de sesión. El valor predeterminado es **5 intentos fallidos y 10 minutos de bloqueo**. El panel muestra la cuenta de intentos alcanzada mientras esté bloqueada; el contador se reinicia al desbloquear, al expirar el bloqueo y procesar un nuevo acceso, o al iniciar sesión correctamente. Se puede configurar entre 3 y 20 intentos y de 1 a 1,440 minutos. El bloqueo se almacena en SQLite y no se elimina al cerrar el navegador. Un administrador puede desbloquear cualquier otra cuenta bloqueada, incluso si su perfil es Administrador; el desbloqueo restablece el contador e invalida sesiones anteriores de esa cuenta.

En **Sistema → Actualizaciones** se muestran las rutas físicas de `data_root`, SQLite, `private`, `exports`, `backups`, `config/app.php` y `config/local.php`. Para un respaldo manual conserva junta la carpeta raíz de datos y el directorio de aplicación; nunca separes `private/zktime.sqlite` de `private/app.key`.

El botón **Descargar respaldo completo ZIP** genera un nombre con fecha y hora e incluye SQLite, `app.key`, los archivos privados (incluida la firma de correo), exportaciones permanentes, y `config/app.php`/`config/local.php` como referencias. No incluye sesiones, temporales, logs ni respaldos anteriores. El ZIP temporal se elimina del servidor al completar la descarga; los respaldos automáticos previos a una migración o restauración permanecen en la carpeta `backups` indicada en pantalla. Los dos archivos PHP de configuración se incluyen solo como referencia: no se ejecutan ni se sobrescriben en una restauración.

Para restaurar, selecciona un ZIP de esta versión y confirma la contraseña del administrador actual. El sistema valida manifiesto, checksums SHA-256, límites de tamaño, rutas ZIP, llave de cifrado e integridad de SQLite antes de cambiar datos. Antes de sustituir la información crea un respaldo automático del estado actual; si falla la aplicación, intenta recuperar ese estado automáticamente. Al completar la restauración se cierra la sesión y debes ingresar con las credenciales contenidas en el respaldo. La restauración requiere la misma versión de aplicación y la misma ruta raíz de datos que generó el ZIP. Mantén una copia independiente del ZIP fuera del servidor.

La recepción de ZIP de respaldo es de hasta 512 MiB comprimidos y el contenido expandido se limita a 2 GiB. Aumenta temporalmente el tiempo/memoria de ejecución según el tamaño real de tus exportaciones.

### Recuperación manual de emergencia

La restauración desde el panel necesita que SQLite permita autenticar a un administrador. Si una falla grave impide abrir el panel, el ZIP también puede recuperarse desde el servidor: detén el Application Pool de IIS; conserva una copia de la carpeta de datos actual; extrae el ZIP en una carpeta temporal y copia su contenido `data/private` a `<data_root>\private` y `data/exports` a `<data_root>\exports`. Antes de copiar, elimina los archivos `zktime.sqlite-wal` y `zktime.sqlite-shm` residuales de la carpeta destino. No copies `metadata/config-app.php` ni `metadata/config-local.php`: son referencias, no archivos ejecutables de restauración. Conserva los permisos NTFS del directorio de datos, vuelve a iniciar el Application Pool y accede con las credenciales que tenía el respaldo. Si la ubicación de datos o el código de aplicación cambió, corrige primero la configuración del servidor; el ZIP no instala ni reemplaza el código PHP. Esta operación manual también reemplaza los datos actuales y debe hacerse únicamente con el sitio detenido.

## 8. Configurar Nóminas

1. En **Departamentos**, revisa las etiquetas del reporte y configura responsables y destinatarios.
2. En **Correo y plantillas**, captura servidor SMTP, puerto, cifrado, autenticación, remitente y contraseña.
3. Guarda y envía un mensaje de prueba.
4. En **Procesar reporte**, carga un XLSX de ZKTimeNet sin activar el envío en la primera prueba.
5. Descarga el ZIP y comprueba las hojas antes de habilitar la distribución.

Cada destinatario recibe un correo independiente y solo su Excel departamental. El ZIP global se descarga desde la sesión autenticada y nunca se adjunta a los correos.

## 9. Seguridad y copias de respaldo

- Publica únicamente con HTTPS y limita el sitio por red interna o VPN. Si IIS está detrás de un proxy TLS, configura `ZKTIME_TRUSTED_PROXIES` con las IP exactas de los proxies confiables; no se confía en `X-Forwarded-Proto` desde clientes directos.
- No expongas el sistema directamente a Internet.
- Concede `Modify` solo a la carpeta de datos y, durante una instalación sin variable de entorno, temporalmente a `config`.
- Respalda juntos `private/zktime.sqlite`, `private/app.key`, los reportes y los directorios necesarios. Protege los respaldos y no los compartas por SMB.
- Si pierdes `app.key`, no podrás descifrar la contraseña SMTP guardada.
- IIS bloquea `storage`, `src`, `database`, `config`, `views`, `tests`, `bin` y `vendor`; no retires esos bloqueos.

## Actualizaciones y mantenimiento

La versión de esquema inicial 5 se aplica durante el asistente o la inicialización controlada. Para siguientes versiones, conserva las migraciones aplicadas y agrega un archivo nuevo con el siguiente número secuencial (`006_*.php`, `007_*.php`, etc.). La pantalla **Actualizaciones** permite crear respaldo y ejecutar migraciones pendientes. No edites migraciones históricas: en particular, `003_user_accounts_and_roles.php` mantiene su checksum y recibe el hash administrativo temporal desde el asistente.

Comandos CLI opcionales, usando el mismo PHP con las extensiones habilitadas:

```powershell
php bin/zknet version
php bin/zknet db:status
php bin/zknet db:migrate
php bin/zknet cleanup
php tests/smoke.php
php tests/account-lockout-smoke.php
php tests/configuration-backup-smoke.php
```

IIS no necesita PHP CLI para funcionar; los comandos anteriores son exclusivamente para mantenimiento o pruebas. La ejecución CLI del bootstrap requiere una instalación ya configurada.

## Diagnóstico de errores frecuentes

- **El asistente no permite continuar:** revisa PHP 8.5, `pdo_sqlite`, `zip`, `dom` y `openssl` en el PHP real del servidor web.
- **No se puede guardar `config/local.php`:** concede temporalmente `Modify` a la carpeta `config` o configura `ZKTIME_DATA_DIR` en el servidor.
- **No se puede crear SQLite o `app.key`:** confirma la ruta externa y los permisos `Modify` de la identidad exacta del Application Pool.
- **La ruta aparece bloqueada:** `ZKTIME_DATA_DIR` tiene prioridad; cambia la variable de entorno en IIS/PHP.
- **La base existe pero no se reconoce:** no la sobrescribas. Comprueba el esquema y restaura un respaldo válido antes de seguir.
- **La carga excede el límite:** revisa `upload_max_filesize`, `post_max_size`, `maxAllowedContentLength` y `max_upload_bytes`.
- **SMTP falla:** verifica DNS/firewall saliente, puerto, STARTTLS/SSL, usuario y autorización de envío.

## Por qué no se usa solo RAM

PHP sobre IIS/FastCGI procesa cada petición por separado. La memoria de una petición se libera al terminar y el Application Pool puede reciclarse. La base SQLite, la configuración de departamentos/destinatarios/SMTP, la llave de cifrado, las sesiones y los archivos del historial deben persistir en disco. El XLSX se procesa durante la petición y los archivos de trabajo se guardan en el directorio privado externo; no se necesita escritura en el sitio web.

## IIS: restricciones y seguridad

`web.config` bloquea por segmentos las rutas `storage`, `src`, `database`, `config`, `views`, `tests`, `bin` y `vendor`, desactiva la exploración de directorios y limita el tamaño de la solicitud. No deshabilites esos bloqueos.

- Publica únicamente con HTTPS.
- Limita el sitio por IP, red interna o VPN en IIS.
- No expongas el sistema a Internet. Utiliza LAN/VPN y HTTPS.
- Crea cuentas individuales desde **Usuarios** y asigna el perfil mínimo necesario.
- Usa una cuenta SMTP institucional con privilegios mínimos.

## Respaldo manual alternativo

Además del ZIP descargable desde **Sistema → Actualizaciones**, conserva copias periódicas en un destino cifrado y separado del servidor. Como mínimo, respalda juntos `private/zktime.sqlite` y `private/app.key`, así como `exports/` y los archivos de aplicación. La contraseña SMTP se cifra con AES-256-GCM y no puede recuperarse sin `app.key`.

La pantalla Actualizaciones muestra las rutas físicas reales de la instalación para que no dependas de la ruta predeterminada documentada en este archivo.

## Tema claro / oscuro

No requiere extensiones PHP, almacenamiento SQLite ni permisos adicionales. El navegador consulta `prefers-color-scheme` para seleccionar automáticamente el tema cuando la cookie `zk_personal_theme` no contiene una elección manual. El botón del encabezado recorre Sistema → Claro → Oscuro → Sistema. La preferencia se conserva un año con SameSite=Lax, Secure cuando se usa HTTPS y Path ajustado al subdirectorio IIS (`/nominas/`, por ejemplo). Los scripts `theme.js` y `app.js` llevan versión en su URL para invalidar las cachés de la versión anterior.


## Identidad visual configurable (1.0.6)

Después de iniciar sesión abre **Configuración → Identidad visual**. Desde ahí puedes editar el nombre de la empresa, siglas/monograma de marca, lema, etiqueta de plataforma, nombre del módulo, nombre de la aplicación y texto del pie de página. Los valores se guardan en la tabla `settings` de SQLite; no se requiere una migración adicional ni permisos nuevos.

Los cambios se reflejan en el menú lateral, el encabezado, la pantalla de acceso, el pie de página y los mensajes nuevos. También se pueden utilizar `{{empresa}}`, `{{aplicacion}}`, `{{plataforma}}` y `{{lema}}` en las plantillas de correo. La página de configuración se encuentra bajo el grupo **Configuración**.

## Actualización de identidad visual (1.0.6)

La versión 1.0.6 agregó la página **Configuración → Identidad visual**. Los valores se guardan en SQLite y no necesitan migración adicional. Los recursos del frontend se versionan en sus URL para evitar la caché de IIS.


## Enviar o reenviar archivos desde el historial (1.0.7)

Abre **Historial → Detalle** de una ejecución. Para cada departamento que tenga destinatarios habilitados actualmente en **Configuración → Departamentos**, el sistema ofrece **Enviar correo** (sin intentos previos) o **Reenviar correo** (con uno o más intentos previos). Si no existen destinatarios, muestra **Sin correo** y no permite el envío.

El envío vuelve a utilizar el XLSX guardado en el directorio privado de exportaciones y no regenera el reporte ni adjunta el ZIP general. Debe existir una configuración SMTP válida y el archivo debe estar disponible en el almacenamiento permanente. Cada operación se registra como un nuevo evento de auditoría, conservando los intentos anteriores. Esta función no modifica el esquema SQLite.


## Actualizar desde 1.0.6 a 1.0.7

1. Respalda SQLite y `private/app.key` en la carpeta de datos externa.
2. Sustituye los archivos de la aplicación por los del paquete 1.0.7. No sobrescribas ni borres el directorio de datos persistentes.
3. No se requiere migración de SQLite; se reutiliza la tabla `mail_events` que ya existe desde el esquema inicial.
4. Abre **Historial**, selecciona una ejecución y revisa las nuevas acciones por departamento.
5. Verifica primero un envío con un área de prueba y confirma que queda registrado en **Auditoría de entrega**.

La acción utiliza los destinatarios habilitados actualmente en el catálogo. Los destinatarios que se utilizaron en cada intento previo permanecen registrados en el evento de auditoría; modificar el catálogo no cambia esos eventos históricos.


## Reply-To múltiple (1.0.8)

En **Correo y plantillas → Servidor SMTP**, el campo Responder a acepta hasta 50 direcciones separadas por coma, punto y coma o saltos de línea. La casilla para agregar las direcciones configuradas en el departamento viene activada por defecto. El envío principal sigue siendo individual; todos los integrantes del área quedan disponibles al responder. No se requiere migración SQLite.

Para actualizar desde 1.0.7, sustituye el código de la aplicación y conserva intactos SQLite, `app.key` y los archivos de reportes.

## Corrección SMTP/MIME (1.0.9)

Se corrige la normalización CRLF del transporte SMTP. Los encabezados RFC 5322 y las partes MIME ahora conservan sus separadores, por lo que los clientes de correo pueden interpretar el asunto, el cuerpo y el adjunto XLSX. La prueba `tests/smtp-reply-to-smoke.php` valida el MIME completo y el payload Base64. No requiere migración SQLite ni cambios de configuración.

Para comprobar el formato MIME en el servidor, ejecuta `php tests/smtp-reply-to-smoke.php`. Esta prueba local no envía correo; realiza un envío de confirmación desde **Correo y plantillas → Probar SMTP** para validar la entrega.


## Actualización desde 1.0.9 a 1.1.0

1. Realiza el respaldo habitual de la carpeta privada de datos (SQLite y su llave) antes de actualizar.
2. Sustituye los archivos de aplicación por los de la versión 1.1.0, sin sobrescribir ni eliminar la ubicación privada de datos.
3. Inicia sesión y abre **Sistema → Actualizaciones** para ejecutar la migración `002_department_responsible.php`.
4. La migración agrega `responsible_email` con cadena vacía por defecto. No modifica los departamentos, destinatarios, configuraciones SMTP ni archivos históricos existentes.
5. En **Configuración → Departamentos**, edita cada área para capturar su responsable. Es opcional.

El nuevo Reply-To utiliza los correos generales configurados en SMTP más los del departamento (si la casilla de SMTP está activa), excluyendo la dirección del responsable. El responsable puede seguir incluido en Correos destinatarios y recibirá su archivo individual.

## Retención y envío masivo

Los reportes procesados (Excel por departamento y ZIP general) son permanentes y no se eliminan con `php bin/zknet cleanup`. Ese comando limpia únicamente cargas temporales huérfanas, con el plazo `temporary_upload_retention_days` (1 día por defecto). En el detalle de cada ejecución, el botón **Enviar a todos** distribuye manualmente los Excel individuales a los departamentos que tengan destinatarios vigentes y muestra avance por área.


## Actualización desde 1.1.0 a 1.1.2

1. Realiza una copia de seguridad de SQLite y de la carpeta privada de exportaciones.
2. Sustituye los archivos de aplicación por los de 1.1.2.
3. Conserva la ubicación de datos y no sobrescribas `zktime.sqlite` ni `app.key`.
4. No hay migraciones SQLite en esta versión. Los reportes XLSX y ZIP dejan de tener caducidad automática; el comando `php bin/zknet cleanup` solo elimina cargas temporales huérfanas.
5. Abre el detalle de una ejecución y usa **Enviar a todos** para distribuir manualmente los Excel de los departamentos con destinatarios configurados.


## Actualización desde 1.1.1 a 1.1.2

1. Conserva la carpeta de datos persistentes, SQLite, `app.key` y exportaciones.
2. Sustituye los archivos de aplicación por los de 1.1.2.
3. No se requieren migraciones ni cambios de configuración.


## Actualización 1.1.4

Reemplaza los archivos de aplicación y conserva el almacenamiento persistente. No hay migración SQLite. Desde esta versión el responsable recibe su Excel aunque no esté duplicado en Correos destinatarios; si se encuentra en ambas fuentes solo recibe un correo. Su dirección queda excluida de todos los encabezados Reply-To. La información técnica de PHP y versión se consulta en Sistema → Actualizaciones.


## Usuarios y permisos (1.2.0)

Nota histórica de 1.2.2: aquella versión introdujo el inicio de sesión individual y reutilizaba la contraseña administrativa anterior. En 1.2.18 el procedimiento vigente para instalaciones nuevas o bases de esquema 1/2 es el asistente `install.php`, descrito al inicio de este documento. Las instalaciones de esquema 3/4/5 conservan sus credenciales.

- **Administrador:** acceso completo a Operación, Departamentos, Correo y plantillas, Identidad visual, Usuarios y Actualizaciones.
- **Nominista:** acceso a Procesar reporte, Resumen, Historial, Configuración → Departamentos y Mi cuenta para cambiar su propia contraseña. No puede abrir los ajustes SMTP, identidad visual, usuarios o actualizaciones.

### Procedimiento histórico de actualización 1.1.4 (versiones anteriores a 1.2.16)

Las instrucciones siguientes documentan el mecanismo anterior. No las utilices para una actualización con 1.2.18: consulta el apartado **Primera instalación o actualización de cuentas antiguas** al inicio de este archivo.

1. Haz un respaldo de `private/zktime.sqlite` y `private/app.key` juntos; conserva `exports/`.
2. Sustituye el código por el ZIP 1.2.2 sin borrar la carpeta de datos externa.
3. Entra con usuario `administrador` y la contraseña que ya utilizabas. Si el esquema está en versión 2, el sistema permite la autenticación anterior únicamente para acceder a Actualizaciones.
4. Abre **Sistema → Actualizaciones** y aplica la migración 003. Se crea automáticamente la cuenta Administrador y las ejecuciones históricas sin autor se atribuyen a esa cuenta.
5. Cierra sesión y vuelve a ingresar con `administrador` y la misma contraseña.
6. Desde **Usuarios**, crea las cuentas noministas con nombre de persona, usuario y contraseña.

La migración genera un respaldo SQLite antes de cambiar el esquema. No borra SMTP, departamentos, responsables, destinatarios, identidad visual, archivos ni historial.


## 8. Actualización desde 1.2.1 a 1.2.2

1. Respalda conjuntamente `private/zktime.sqlite` y `private/app.key`. Conserva también la carpeta `exports/`, porque contiene los Excel y ZIP permanentes.
2. Sustituye el código del sitio por el ZIP 1.2.2. No sobrescribas ni borres el directorio persistente externo.
3. Inicia sesión con un usuario de perfil **Administrador**. Si la migración 004 está pendiente, la aplicación redirige al apartado **Sistema → Actualizaciones**. Las cuentas Nominista no podrán continuar hasta que el administrador termine.
4. En Actualizaciones, comprueba el respaldo automático y aplica la migración `004_department_discovery.php`. La migración agrega `departments.needs_configuration` con valor `0` para todas las áreas existentes.
5. Cierra sesión y vuelve a entrar. Confirma que el encabezado abre **Mi cuenta** y que cada usuario puede cambiar su propia contraseña indicando la actual.

### Nuevo comportamiento al procesar departamentos

- **Departamento configurado y habilitado:** se genera su Excel, se incluye en el ZIP y queda elegible para envío desde el historial.
- **Departamento existente pero deshabilitado:** se genera su Excel y se incluye en el ZIP; el estado queda como `Deshabilitado · correo omitido`. No se puede enviar individualmente ni por lote hasta que se habilite.
- **Etiqueta nueva encontrada en el XLSX:** se registra automáticamente en Departamentos con la etiqueta exacta del reporte y estado `Pendiente de configuración` (deshabilitado). Se genera su Excel y se incluye en el ZIP. Debe editarse para asignar responsable y destinatarios y luego habilitarse.
- **Departamento activo sin responsable o sin correos efectivos:** el archivo se genera normalmente; el aviso de la pantalla principal indica qué configuración falta.

Ninguno de los dos primeros casos detiene el resto del reporte ni elimina registros. Los envíos masivos solo consideran departamentos habilitados, configurados y con destinatarios efectivos.

### Cambio de contraseña del usuario

Cada persona puede hacer clic en su nombre/avatar del encabezado para abrir **Mi cuenta**. La contraseña actual es obligatoria; la nueva debe tener al menos 8 caracteres y debe coincidir con su confirmación. El acceso está disponible para Administrador y Nominista, pero no concede privilegios adicionales.

## Imagen de firma de correo (1.2.3)

En **Configuración → Correo y plantillas**, el Administrador puede cargar una imagen de firma que se insertará al final del mensaje HTML. Se admiten PNG, JPG/JPEG, GIF y WebP; máximo 2 MB, ancho 2400 px, alto 1200 px y 2.5 megapíxeles. No se admiten SVG ni HTML externo.

La imagen se guarda en el directorio privado externo de datos, no en el sitio IIS. La configuración queda asociada a `smtp.signature_image` dentro de SQLite; el esquema se mantiene en versión 4. Para reemplazarla, carga otra imagen y guarda. Para quitarla, marca **Quitar imagen actual** y guarda. La vista previa solo se sirve a un Administrador autenticado.

No se requieren extensiones PHP nuevas, Composer, CDN ni permisos adicionales fuera del directorio privado de datos ya asignado a la identidad del Application Pool. Al hacer copia de seguridad, conserva SQLite, `app.key` y los archivos del directorio privado juntos.

La imagen se envía como parte inline MIME (`Content-ID`) de modo que el cliente de correo pueda mostrarla dentro del cuerpo, similar a una firma gráfica. El Excel de asistencia sigue siendo un adjunto independiente. Recomendable enviar una prueba SMTP a Outlook antes de activar el uso operativo.

## Ayuda del sistema (1.2.3)

El botón **Ayuda**, al final del menú lateral, abre instrucciones rápidas. El contenido se selecciona por perfil; Administrador y Nominista ven tareas acordes a sus permisos. No necesita configuración ni migración.

## Actualización 1.2.2 → 1.2.3

1. Respalda SQLite (`private/zktime.sqlite`), `private/app.key` y el directorio privado de datos completo.
2. Sustituye únicamente los archivos del sitio por el ZIP 1.2.4; no borres la carpeta de datos externa.
3. Inicia sesión como Administrador y abre **Correo y plantillas**.
4. Si deseas una firma gráfica, selecciona una imagen admitida y guarda la configuración. No hace falta migración SQLite.
5. Abre **Ayuda** desde el final del menú y comprueba que el contenido corresponde al perfil actual.
6. Envía una prueba SMTP a una cuenta de Outlook y confirma que la firma aparece dentro del mensaje y que el XLSX continúa como adjunto.

La versión 1.2.4 conserva el esquema SQLite 4. No se cambian cuentas, roles, departamentos, destinatarios, historial ni reportes.



## Actualización 1.2.3 → 1.2.4

1. Sustituye los archivos del sitio por el paquete 1.2.4.
2. Conserva intactos SQLite, `app.key`, `exports/` y la carpeta de datos privada.
3. No ejecutes migraciones: el esquema permanece en 4.
4. No cambian los permisos del Application Pool ni se agregan extensiones.

Esta versión corrige el layout de las celdas de acciones en las tablas y hace explícitas las acciones **Habilitar/Deshabilitar** para usuarios distintos del usuario conectado.


## Actualización 1.2.4 → 1.2.5

1. Sustituye los archivos del sitio por el paquete 1.2.5.
2. Conserva SQLite, `app.key`, reportes permanentes y el directorio privado de datos.
3. No ejecutes migraciones: el esquema permanece en 4.
4. Se actualiza únicamente el texto de instrucciones del paso 3 en la pantalla principal; también se renuevan las referencias de caché estática.


## Actualización 1.2.5 → 1.2.6

1. Sustituye los archivos de aplicación por los del paquete 1.2.6.
2. Conserva SQLite, `app.key`, exportaciones y el almacenamiento privado.
3. No se requiere migración ni cambio de permisos IIS.
4. Noministas verán solamente la preparación de departamentos/destinatarios; SMTP y esquema local permanecen exclusivos del Administrador.


## Actualización 1.2.8

Reemplaza el código de aplicación. No requiere migración SQLite ni cambios en IIS. Conserva la carpeta privada de datos, la base de datos, `app.key` y los reportes históricos.


## Actualización 1.2.6 → 1.2.8

Esta actualización agrega la etiqueta visible **Tema** a la izquierda del selector de apariencia en el encabezado y en el inicio de sesión. Reemplaza los archivos de aplicación; no requiere migración SQLite ni cambios en IIS. Conserva SQLite, `app.key`, datos persistentes y reportes. Se renuevan las referencias de caché estática a `1.2.8`.


## Actualización 1.2.7 → 1.2.8

Esta actualización corrige el acomodo de la tarjeta de procesamiento en el panel: el botón **Cargar reporte**, el formato admitido y la indicación de semana permanecen en el flujo normal de la tarjeta y no se enciman. También cambia el texto del indicador a **Entregas confirmadas por servidor de correo**. Reemplaza los archivos de aplicación; no requiere migración SQLite ni cambios en IIS. Conserva SQLite, `app.key`, datos persistentes y reportes.


## Actualización 1.2.8 → 1.2.9

Esta actualización elimina la lista **Todos los reportes** de la vista de detalle de una ejecución. El listado general permanece en la página de Historial cuando no se selecciona un reporte. Los departamentos, los envíos, la auditoría y el enlace **Volver al historial** siguen disponibles dentro del detalle. Reemplaza los archivos de aplicación; no requiere migración SQLite ni cambios en IIS. Conserva la base de datos, `app.key`, el almacenamiento privado y los reportes permanentes.


## Actualización 1.2.9 → 1.2.12

Esta versión elimina la confirmación emergente del navegador al pulsar **Enviar a todos**. El modal de distribución abre directamente y muestra cuántos Excel individuales se enviarán, qué departamentos recibirán el reporte y que el ZIP general no se adjunta. El usuario confirma una sola vez pulsando **Iniciar envío**.

Reemplaza los archivos de aplicación y conserva SQLite, `app.key`, usuarios, configuraciones y reportes. No requiere migración de base de datos ni cambios en los permisos de IIS.


## Actualización 1.2.10 → 1.2.12

El botón de envío masivo ahora se identifica como **Enviar correo a todos** y utiliza un color rojo. La X del modal queda alineada con el título. Sustituye los archivos de aplicación; no requiere migración SQLite ni cambios en IIS. Conserva la base de datos, `app.key`, configuraciones y reportes.


## Actualización 1.2.11 → 1.2.12

Esta versión corrige únicamente la presentación del envío masivo: **Enviar correo a todos** es verde y la X de cierre del modal es roja, con el símbolo centrado. No requiere migración SQLite ni cambios en IIS. Sustituye los archivos de aplicación y conserva SQLite, `app.key` y los reportes.


## Actualización 1.2.12 → 1.2.14

1. Reemplaza los archivos de aplicación con el contenido del ZIP.
2. Conserva la carpeta privada de datos, SQLite, `app.key`, configuraciones y reportes permanentes.
3. No se requiere migración SQLite ni modificación de permisos IIS.
4. La versión 1.2.14 añade iconos SVG locales en los botones y acciones; no descarga recursos externos.


## Actualización 1.2.14 → 1.2.15

- Sustituye los archivos de aplicación por el paquete 1.2.15.
- No requiere migración SQLite ni cambios en permisos de IIS.
- Conserva el directorio de datos privado, SQLite, `app.key` y los reportes permanentes.
- El panel explica el formato `.xlsx` y el calendario ISO con un ejemplo; la acción **Procesar reporte** se mantiene en el encabezado.
