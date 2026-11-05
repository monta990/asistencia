const fs = require('fs');
const bootstrap = fs.readFileSync('bootstrap.php', 'utf8');
const index = fs.readFileSync('index.php', 'utf8');
const login = fs.readFileSync('login.php', 'utf8');
const logger = fs.readFileSync('src/Core/ErrorLogger.php', 'utf8');
const mailer = fs.readFileSync('src/Services/MailerService.php', 'utf8');

if (!bootstrap.includes("ini_set('display_errors', '0')")) throw new Error('HTTP display_errors must be disabled');
if (!bootstrap.includes("ini_set('error_log', $privateLogs . DIRECTORY_SEPARATOR . 'php-error.log')")) throw new Error('PHP error log must be private');
if (!bootstrap.includes("ErrorLogger::capture($exception, 'bootstrap-initialization')")) throw new Error('bootstrap errors must be logged privately');
if (bootstrap.includes("htmlspecialchars($exception->getMessage()")) throw new Error('bootstrap must not render exception details');
if (!index.includes('ErrorLogger::publicMessage($exception')) throw new Error('index DB errors must use safe public messages');
if (!login.includes('ErrorLogger::publicMessage($exception')) throw new Error('login errors must use safe public messages');
if (!mailer.includes("ErrorLogger::capture($exception, 'smtp-recipient-delivery')")) throw new Error('SMTP low-level errors must be logged privately');
if (!logger.includes("'storage_private'")) throw new Error('ErrorLogger must use private storage');
console.log('PASS: HTTP errors are sanitized and technical errors logged privately');
