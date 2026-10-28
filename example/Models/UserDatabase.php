<?php

namespace Example\Models;

use BrLayer\Model;

/**
 * Class User
 * @package Example\Models
 */
class UserDatabase extends Model
{
    /**
     * User constructor.
     */
    public function __construct()
    {
        parent::__construct("ws_users", ["user_name", "user_lastname"], "user_id", DATABASE);
    }
}