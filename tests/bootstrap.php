<?php

require __DIR__ . '/../vendor/autoload.php';

/**
 * Configuração dos testes vinda do phpunit.xml / variáveis de ambiente.
 */
function testDatabase(array $override = []): array
{
    return array_merge([
        "driver" => "mysql",
        "host" => getenv("DB_HOST"),
        "port" => getenv("DB_PORT"),
        "dbname" => getenv("DB_NAME"),
        "username" => getenv("DB_USER"),
        "passwd" => getenv("DB_PASS"),
        "charset" => "utf8mb4",
        "options" => [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
        ]
    ], $override);
}

define("DATA_LAYER_CONFIG", testDatabase());
