<?php

namespace Example\Models;

use BrLayer\Model;

class Company extends Model
{
    protected ?string $createdAt = "created_at";
    protected ?string $updatedAt = "updated_at";

    public function __construct()
    {
        parent::__construct("companies", ["user_id", "name"]);
    }
}