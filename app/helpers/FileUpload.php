<?php

declare(strict_types=1);

namespace App\Helpers;

class FileUpload
{
    private array $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    private int $maxSize; // in bytes
    private string $uploadPath;

    public function __construct(?string $uploadPath = null, ?int $maxSize = null)
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->uploadPath = $uploadPath ?? $config['upload']['path'] . '/patient_files';
        $this->maxSize = $maxSize ?? $config['upload']['max_size'];

        // Make sure the base upload directory exists. mkdir is suppressed
        // because a race or pre-existing dir would otherwise emit a warning.
        if (!is_dir($this->uploadPath)) {
            @mkdir($this->uploadPath, 0755, true);
        }
    }

    /**
     * Upload a file and return its stored info.
     *
     * Any internal failure is normalised into a RuntimeException so callers
     * only need to catch one exception type and can return a clean JSON error.
     */
    public function upload(array $file, ?string $subdir = null): array
    {
        try {
            $this->validateFile($file);

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedFilename = $this->generateFilename($extension);

            $targetDir = $this->uploadPath;
            if ($subdir !== null && $subdir !== '') {
                $targetDir .= '/' . trim($subdir, '/');
            }
            // generateFilename can embed sub-paths (e.g. "2026/05/<hex>.pdf"),
            // so create the full directory tree the file actually lives in.
            $fullTargetDir = $targetDir . '/' . dirname($storedFilename);

            if (!is_dir($fullTargetDir) && !@mkdir($fullTargetDir, 0755, true) && !is_dir($fullTargetDir)) {
                throw new \RuntimeException("Could not prepare upload location. Please contact support.");
            }

            if (!is_writable($fullTargetDir)) {
                throw new \RuntimeException("Upload location is not writable. Please contact support.");
            }

            $targetPath = $targetDir . '/' . $storedFilename;

            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Fall back to copy() if move_uploaded_file fails (some sandboxed envs).
                if (!@copy($file['tmp_name'], $targetPath)) {
                    throw new \RuntimeException('Failed to save the uploaded file.');
                }
                @unlink($file['tmp_name']);
            }

            return [
                'original_filename' => basename($file['name']),
                'stored_filename' => ($subdir ? trim($subdir, '/') . '/' : '') . $storedFilename,
                'mime_type' => $file['type'] ?? 'application/octet-stream',
                'file_size' => (int) ($file['size'] ?? filesize($targetPath) ?: 0),
            ];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Convert RandomException / PDOException / etc. into a RuntimeException
            // so callers only deal with one exception type.
            error_log('FileUpload internal error: ' . $e->getMessage());
            throw new \RuntimeException('Could not process the upload. Please try again.');
        }
    }

    /**
     * Delete a stored file.
     */
    public function delete(string $storedFilename): bool
    {
        $filePath = $this->uploadPath . '/' . $storedFilename;
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Get the full path of a stored file.
     */
    public function getPath(string $storedFilename): string
    {
        return $this->uploadPath . '/' . $storedFilename;
    }

    /**
     * Validate an uploaded file.
     */
    private function validateFile(array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error: ' . $this->getUploadErrorMessage($file['error']));
        }

        if ($file['size'] > $this->maxSize) {
            throw new \RuntimeException('File size exceeds the maximum allowed size of ' . $this->formatBytes($this->maxSize) . '.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new \RuntimeException('File type not allowed. Allowed: ' . implode(', ', $this->allowedExtensions));
        }

        // Verify MIME type — only if fileinfo is available; otherwise rely on
        // the extension + content scan below. Some hosts disable the extension.
        if (class_exists('\\finfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                if ($mimeType !== false && !in_array($mimeType, $this->allowedTypes)) {
                    throw new \RuntimeException('File MIME type not allowed: ' . $mimeType);
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // If finfo itself errors, fall through to the content scan.
                error_log('FileUpload mime probe failed: ' . $e->getMessage());
            }
        }

        // Check for PHP/script content in the first KB of the file.
        $content = @file_get_contents($file['tmp_name'], false, null, 0, 1024);
        if ($content !== false && preg_match('/<\?php|<\?=|<script/i', $content)) {
            throw new \RuntimeException('File contains potentially malicious content.');
        }
    }

    /**
     * Generate a unique filename.
     */
    private function generateFilename(string $extension): string
    {
        try {
            $random = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            // Fallback when random_bytes is unavailable (rare). uniqid+mt_rand
            // is fine for filename uniqueness inside a per-patient subdir.
            $random = bin2hex(pack('N*', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
        }
        return date('Y/m/') . $random . '.' . $extension;
    }

    private function getUploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
