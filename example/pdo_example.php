<?php

require 'db_config.php';
require '../vendor/autoload.php';

use BrLayer\Connect;

/*
 * GET PDO instance AND errors
 */
try {
    $connect = Connect::getInstance();
} catch (PDOException $exception) {
    echo $exception->getMessage();
    exit;
}

/*
 * FETCH DATA
 */
$users = $connect->query("SELECT * FROM users LIMIT 5");
var_dump($users->fetchAll());