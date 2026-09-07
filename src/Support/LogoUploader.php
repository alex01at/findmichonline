<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use finfo;
use RuntimeException;

final class LogoUploader
{
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /** mime type => file extension */
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private string $publicRoot)
    {
    }

    /**
     * @param array{error:int,size:int,tmp_name:string}|null $file
     * @throws RuntimeException with a translation-key message on validation failure
     */
    public function upload(int $userId, ?array $file): ?string
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new RuntimeException('card.edit.errors.logo_too_large');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('card.edit.errors.logo_upload_failed');
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('card.edit.errors.logo_too_large');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension = self::ALLOWED_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('card.edit.errors.logo_invalid_type');
        }

        $dir = $this->publicRoot . '/uploads/logos';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('card.edit.errors.logo_upload_failed');
        }

        $this->removeExisting($userId);

        $relativePath = "uploads/logos/{$userId}.{$extension}";
        if (!move_uploaded_file($file['tmp_name'], $this->publicRoot . '/' . $relativePath)) {
            throw new RuntimeException('card.edit.errors.logo_upload_failed');
        }

        return $relativePath;
    }

    public function remove(int $userId): void
    {
        $this->removeExisting($userId);
    }

    private function removeExisting(int $userId): void
    {
        foreach (glob($this->publicRoot . "/uploads/logos/{$userId}.*") ?: [] as $existingFile) {
            @unlink($existingFile);
        }
    }
}
