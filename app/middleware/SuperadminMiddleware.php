<?php

declare(strict_types=1);

namespace App\Middleware;

class SuperadminMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('SUPERADMIN');
    }
}
