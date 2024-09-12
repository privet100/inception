<?php
$dotenv = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($dotenv as $line) {
    list($key, $value) = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim($value));
    echo "*******************************************";
    echo $key;
    echo $value;
}

define( 'DB_NAME',     getenv('DB_NAME') );
define( 'DB_USER',     getenv('DB_USER') );
define( 'DB_PASSWORD', getenv('DB_PASS') );
define( 'DB_HOST',     'mariadb' );
$table_prefix = 'myprefix_';
if ( ! defined( 'ABSPATH' ) ) {
define( 'ABSPATH', __DIR__ . '/' );}
require_once ABSPATH . 'wp-settings.php';