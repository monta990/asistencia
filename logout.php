<?php
declare(strict_types=1);

use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Core\Csrf;

if (!defined('ZKTIME_BOOTSTRAP_ALLOWED')) {
    define('ZKTIME_BOOTSTRAP_ALLOWED', true);
}
require __DIR__ . '/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
    Auth::logout();
}
Html::redirect('login.php');
