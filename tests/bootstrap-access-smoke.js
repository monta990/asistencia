const fs = require('fs');
const bootstrap = fs.readFileSync('bootstrap.php', 'utf8');
const web = fs.readFileSync('web.config', 'utf8');
const htaccess = fs.readFileSync('.htaccess', 'utf8');
for (const entry of ['index.php', 'login.php', 'logout.php']) {
  const source = fs.readFileSync(entry, 'utf8');
  const defineAt = source.indexOf("define('ZKTIME_BOOTSTRAP_ALLOWED', true)");
  const includeAt = source.indexOf("require __DIR__ . '/bootstrap.php'");
  if (defineAt < 0 || includeAt < 0 || defineAt > includeAt) throw new Error(`${entry} must authorize bootstrap before include`);
}
if (!bootstrap.includes("PHP_SAPI !== 'cli' && !defined('ZKTIME_BOOTSTRAP_ALLOWED')")) throw new Error('bootstrap direct request guard missing');
if (!web.includes('<add segment="bootstrap.php" />')) throw new Error('IIS Request Filtering must hide bootstrap.php');
if (!htaccess.includes('RewriteRule ^bootstrap\\.php$ - [F,L,NC]')) throw new Error('Apache must deny direct bootstrap.php');
console.log('PASS: direct HTTP access to bootstrap is denied; authorized entry points and CLI remain available');
