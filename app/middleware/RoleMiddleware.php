<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(string ...$roles)
    {
        $this->allowedRoles = $roles;
    }

    public function handle(): bool
    {
        $userType = $_SESSION['user_type'] ?? null;

        if (!$userType || !in_array($userType, $this->allowedRoles, true)) {
            if ($this->isAjax()) {
                Response::error('Access denied. Insufficient permissions.', 403);
            }
            http_response_code(403);
            header('Location: /dashboard');
            exit;
        }

        return true;
    }

    private function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

// SUPERADMIN is granted access to every role-scoped area: the requirement is
// that the superadmin has every permission from every other role.
class AdminMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('ADMIN', 'SUPERADMIN');
    }
}

class SuperadminMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('SUPERADMIN');
    }
}

class DoctorMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('DOCTOR', 'DOCTOR_OWNER', 'SUPERADMIN');
    }
}

class ParentMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('PARENT', 'SUPERADMIN');
    }
}
