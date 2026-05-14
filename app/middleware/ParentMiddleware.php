<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Allow parents plus SUPERADMIN to access /parent/* routes — including
 * the file upload endpoint at /parent/files/upload.
 */
class ParentMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('PARENT', 'SUPERADMIN');
    }
}
