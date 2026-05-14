<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Response;

abstract class Controller
{
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Render a view with data.
     */
    protected function view(string $view, array $data = []): void
    {
        extract($data);

        $viewFile = dirname(__DIR__, 2) . '/views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View not found: $view");
        }

        // Start output buffering for layout support
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        // If the view set a layout, render it
        if (isset($layout)) {
            $layoutFile = dirname(__DIR__, 2) . '/views/layouts/' . $layout . '.php';
            if (file_exists($layoutFile)) {
                require $layoutFile;
                return;
            }
        }

        echo $content;
    }

    /**
     * Redirect to a URL.
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Get the currently authenticated user.
     */
    protected function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, first_name, last_name, email, user_type, status, profile_picture FROM users WHERE id = ?',
            [$_SESSION['user_id']]
        );
    }

    /**
     * Get current user ID.
     */
    protected function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Get current user type.
     */
    protected function userType(): ?string
    {
        return $_SESSION['user_type'] ?? null;
    }

    /**
     * Validate request input against a uniform rule schema.
     *
     * Supported rules (pipe- or array-separated):
     *   required, email, phone, url, alpha, alpha_num, alpha_dash,
     *   numeric, integer, boolean, date, time, datetime,
     *   min:N, max:N, between:MIN,MAX,
     *   in:a,b,c, not_in:a,b,c, regex:/pattern/,
     *   confirmed (matches {field}_confirmation),
     *   same:other, different:other,
     *   nullable (skip remaining rules if blank).
     *
     * Returns ['data' => sanitized, 'errors' => map<field, string[]>].
     */
    protected function validate(array $rules): array
    {
        $errors = [];
        $data = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $_POST[$field] ?? $_GET[$field] ?? null;
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            $label = ucfirst(str_replace('_', ' ', $field));
            $nullable = in_array('nullable', $fieldRules, true);

            foreach ($fieldRules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }
                $ruleParts = explode(':', $rule, 2);
                $ruleName = $ruleParts[0];
                $ruleParam = $ruleParts[1] ?? null;

                // Skip non-required rules when the value is blank and the
                // field is marked nullable. "required" still runs.
                $isBlank = ($value === null || $value === '');
                if ($nullable && $isBlank && $ruleName !== 'required') {
                    continue;
                }

                switch ($ruleName) {
                    case 'required':
                        if ($isBlank && $value !== '0') {
                            $errors[$field][] = "{$label} is required.";
                        }
                        break;
                    case 'email':
                        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = 'Invalid email address.';
                        }
                        break;
                    case 'phone':
                        if ($value && !preg_match('/^\+?[0-9 ()\-]{6,20}$/', (string) $value)) {
                            $errors[$field][] = "{$label} must be a valid phone number.";
                        }
                        break;
                    case 'url':
                        if ($value && !filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field][] = "{$label} must be a valid URL.";
                        }
                        break;
                    case 'alpha':
                        if ($value && !preg_match('/^[A-Za-z]+$/', (string) $value)) {
                            $errors[$field][] = "{$label} may only contain letters.";
                        }
                        break;
                    case 'alpha_num':
                        if ($value && !preg_match('/^[A-Za-z0-9]+$/', (string) $value)) {
                            $errors[$field][] = "{$label} may only contain letters and numbers.";
                        }
                        break;
                    case 'alpha_dash':
                        if ($value && !preg_match('/^[A-Za-z0-9_\- ]+$/', (string) $value)) {
                            $errors[$field][] = "{$label} may only contain letters, numbers, dashes and spaces.";
                        }
                        break;
                    case 'min':
                        if ($value !== null && $value !== '' && strlen((string) $value) < (int) $ruleParam) {
                            $errors[$field][] = "{$label} must be at least {$ruleParam} characters.";
                        }
                        break;
                    case 'max':
                        if ($value !== null && strlen((string) $value) > (int) $ruleParam) {
                            $errors[$field][] = "{$label} must be at most {$ruleParam} characters.";
                        }
                        break;
                    case 'between':
                        [$min, $max] = array_pad(explode(',', (string) $ruleParam, 2), 2, 0);
                        if ($value !== null && $value !== '' && is_numeric($value)) {
                            $n = (float) $value;
                            if ($n < (float) $min || $n > (float) $max) {
                                $errors[$field][] = "{$label} must be between {$min} and {$max}.";
                            }
                        }
                        break;
                    case 'numeric':
                        if ($value !== null && $value !== '' && !is_numeric($value)) {
                            $errors[$field][] = "{$label} must be a number.";
                        }
                        break;
                    case 'integer':
                        if ($value !== null && $value !== '' && !preg_match('/^-?\d+$/', (string) $value)) {
                            $errors[$field][] = "{$label} must be an integer.";
                        }
                        break;
                    case 'boolean':
                        if ($value !== null && !in_array((string) $value, ['0','1','true','false','on','off',''], true)) {
                            $errors[$field][] = "{$label} must be true or false.";
                        }
                        break;
                    case 'date':
                        if ($value && !strtotime((string) $value)) {
                            $errors[$field][] = "{$label} must be a valid date.";
                        }
                        break;
                    case 'time':
                        if ($value && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)) {
                            $errors[$field][] = "{$label} must be a valid time (HH:MM).";
                        }
                        break;
                    case 'datetime':
                        if ($value && !strtotime((string) $value)) {
                            $errors[$field][] = "{$label} must be a valid date/time.";
                        }
                        break;
                    case 'in':
                        $allowed = explode(',', (string) $ruleParam);
                        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
                            $errors[$field][] = "{$label} is invalid.";
                        }
                        break;
                    case 'not_in':
                        $forbidden = explode(',', (string) $ruleParam);
                        if ($value !== null && in_array((string) $value, $forbidden, true)) {
                            $errors[$field][] = "{$label} is invalid.";
                        }
                        break;
                    case 'regex':
                        if ($value && !@preg_match((string) $ruleParam, (string) $value)) {
                            $errors[$field][] = "{$label} has an invalid format.";
                        }
                        break;
                    case 'confirmed':
                        $confirm = $_POST[$field . '_confirmation'] ?? null;
                        if ($value !== $confirm) {
                            $errors[$field][] = "{$label} confirmation does not match.";
                        }
                        break;
                    case 'same':
                        $other = $_POST[$ruleParam] ?? null;
                        if ($value !== $other) {
                            $errors[$field][] = "{$label} must match {$ruleParam}.";
                        }
                        break;
                    case 'different':
                        $other = $_POST[$ruleParam] ?? null;
                        if ($value !== null && $value === $other) {
                            $errors[$field][] = "{$label} must be different from {$ruleParam}.";
                        }
                        break;
                }
            }

            if ($value !== null) {
                $data[$field] = is_string($value) ? trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) : $value;
            }
        }

        if (!empty($errors)) {
            if ($this->isAjax()) {
                Response::validationError($errors);
                exit;
            }
            $_SESSION['validation_errors'] = $errors;
            $_SESSION['old_input'] = $_POST;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Check if request is AJAX.
     */
    protected function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Get sanitized input value.
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        if (is_string($value)) {
            return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
        return $value;
    }

    /**
     * Log an activity.
     */
    protected function logActivity(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
    {
        $this->db->insert('activity_logs', [
            'user_id' => $this->userId(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }
}
