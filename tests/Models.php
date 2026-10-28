<?php

namespace BrLayer\Tests\Models;

use BrLayer\DataLayer;

class User extends DataLayer
{
    protected ?string $createdAt = "created_at";
    protected ?string $updatedAt = "updated_at";
    protected array $fillable = ["id", "first_name", "last_name", "email"];

    public function __construct()
    {
        parent::__construct("users", ["first_name"]);
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function greet(string $greeting): string
    {
        return "{$greeting} {$this->first_name}";
    }

    public function stats(): array
    {
        return $this->rows(
            "SELECT is_admin, COUNT(*) AS total FROM users WHERE first_name <> :ignore GROUP BY is_admin ORDER BY is_admin",
            ["ignore" => "Nobody"]
        );
    }

    public function firstByIds(array $ids): ?array
    {
        [$in, $params] = static::inList($ids, "uid");
        return $this->row("SELECT * FROM users WHERE id IN ({$in}) ORDER BY id", $params);
    }

    public function idsIn(array $ids): array
    {
        [$in, $params] = static::inList($ids, "uid");
        return array_column($this->rows("SELECT id FROM users WHERE id IN ({$in}) ORDER BY id", $params), "id");
    }

    public function promoteAll(): int
    {
        return $this->execute("UPDATE users SET is_admin = :admin", ["admin" => 1]);
    }

    public function rawFails(): array
    {
        return $this->rows("SELECT * FROM table_that_does_not_exist");
    }
}

class StrictUser extends User
{
    protected bool $throwOnFail = true;
}

class Log extends DataLayer
{
    protected ?string $createdAt = "created_at";

    public function __construct()
    {
        parent::__construct("logs", ["message"]);
    }
}

class Menu extends DataLayer
{
    public function __construct()
    {
        parent::__construct("menus", ["name"]);
    }
}

class Token extends DataLayer
{
    public function __construct()
    {
        parent::__construct("tokens", ["name"]);
    }
}

class Product extends DataLayer
{
    public function __construct()
    {
        parent::__construct("products", ["name", "stock"]);
    }
}

class BrokenUser extends DataLayer
{
    public function __construct()
    {
        parent::__construct("users", ["first_name"], "id", testDatabase(["username" => "brlayer_nobody", "passwd" => "senha-errada"]));
    }
}

class StrictBrokenUser extends BrokenUser
{
    protected bool $throwOnFail = true;
}
