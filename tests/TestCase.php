<?php

namespace BrLayer\Tests;

use BrLayer\Connect;
use PDO;

require_once __DIR__ . '/Models.php';

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Connect::getInstance();
        $this->pdo->exec("DROP TABLE IF EXISTS users, logs, menus, tokens, products, roles");
        $this->pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NULL,
            email VARCHAR(150) NULL UNIQUE,
            is_admin TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) CHARACTER SET utf8mb4");
        $this->pdo->exec("CREATE TABLE logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message VARCHAR(255) NOT NULL,
            created_at DATETIME NULL
        )");
        $this->pdo->exec("CREATE TABLE menus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            `order` INT NULL,
            data DATE NULL
        )");
        $this->pdo->exec("CREATE TABLE tokens (
            id CHAR(36) PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            stock INT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        )");
        $this->pdo->exec("CREATE TABLE roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(50) NOT NULL
        )");
        $this->pdo->exec("INSERT INTO roles (user_id, name) VALUES
            (1, 'admin'), (1, 'editor'), (2, 'editor'), (3, 'viewer')");
        $this->pdo->exec("INSERT INTO users (first_name, last_name, email) VALUES
            ('Ana', 'Silva', 'ana@x.com'),
            ('Bruno', 'Souza', 'bruno@x.com'),
            ('Carla', 'Lima', 'carla+teste@x.com'),
            ('Daniel', 'Rocha', 'daniel@x.com')");
    }

    protected function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }
}
