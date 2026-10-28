<?php

namespace Example\Models;

use BrLayer\DataLayer;

class Company extends DataLayer
{
    protected ?string $createdAt = "created_at";
    protected ?string $updatedAt = "updated_at";

    public function __construct()
    {
        parent::__construct("companies", ["user_id", "name"]);
    }
}