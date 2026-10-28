<?php

namespace Example\Models;

use BrLayer\DataLayer;

/**
 * Class Address
 * @package Example\Models
 */
class Address extends DataLayer
{
    protected ?string $createdAt = "created_at";
    protected ?string $updatedAt = "updated_at";

    /**
     * Address constructor.
     */
    public function __construct()
    {
        parent::__construct("adresses", ["user_id"]);
    }

    /**
     * @return $this
     */
    public function getUser(): Address
    {
        $this->user = (new User())->findById($this->user_id)->data();
        return $this;
    }
}