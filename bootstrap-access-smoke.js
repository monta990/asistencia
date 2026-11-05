'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');

const bootstrap = fs.readFileSync('bootstrap.php', 'utf8');
const web = fs.readFileSync('web.config', 'utf8');

for (const entry of ['index.php', 'login.php', 'logout.php']) {
  const source = fs.readFileSync(entry, 'utf8');
  const defineAt = source.indexOf("define('ZKTIME_BOOTSTRAP_ALLOWED', true)");
  const includeAt = source.indexOf("require __DIR__ . '/bootstrap.php'");
  assert(defineAt >= 0 && includeAt >= 0 && defineAt < includeAt,
    `${entry} must authorize bootstrap before including it`);
}

assert(bootstrap.includes("PHP_SAPI !== 'cli' && !defined('ZKTIME_BOOTSTRAP_ALLOWED')"),
  'bootstrap must reject direct HTTP requests');
assert(web.includes('<add segment="bootstrap.php" />'),
  'IIS Request Filtering must hide bootstrap.php');

console.log('PASS: direct HTTP bootstrap requests are denied; authorized entry points and CLI remain available');
