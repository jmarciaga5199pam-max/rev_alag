<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Allow ADMIN and SUPERADMIN to access /admin/* routes.
 *
 * SUPERADMIN is intentionally included so superadmins have every
 * permission an admin has without needing a separate role check.
 */
class AdminMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('ADMIN', 'SUPERADMIN');
    }
}
