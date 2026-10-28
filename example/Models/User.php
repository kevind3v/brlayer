<?php

namespace Example\Models;

use BrLayer\DataLayer;

/**
 * Class User
 * @package Example\Models
 */
class User extends DataLayer
{
    protected ?string $createdAt = "created_at";
    protected ?string $updatedAt = "updated_at";
    protected array $fillable = ["first_name", "last_name", "genre"];

    /**
     * User constructor.
     */
    public function __construct()
    {
        parent::__construct("users", ["first_name", "last_name"]);
    }

    public function fullName()
    {
        return "{$this->first_name} {$this->last_name}";
    }
}