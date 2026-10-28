<?php

// Valores vindos do ambiente (ex.: DB_PASS=senha php -S ...), com padrão para uso local
define("DATA_LAYER_CONFIG", [
    "driver" => "mysql",
    "host" => getenv("DB_HOST") ?: "127.0.0.1",
    "port" => getenv("DB_PORT") ?: "3306",
    "dbname" => getenv("DB_NAME") ?: "brlayer_example",
    "username" => getenv("DB_USER") ?: "root",
    "passwd" => getenv("DB_PASS") ?: "",
    "charset" => "utf8mb4",
    "options" => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_CASE => PDO::CASE_NATURAL
    ]
]);

//const DATA_LAYER_CONFIG = [
//    "driver" => "pgsql",
//    "host" => "localhost",
//    "port" => "5432",
//    "dbname" => "datalayer",
//    "username" => "postgres",
//    "passwd" => "",
//    "options" => [
//        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
//        PDO::ATTR_CASE => PDO::CASE_NATURAL
//    ],
//];