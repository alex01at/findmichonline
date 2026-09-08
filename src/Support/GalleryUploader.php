<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use finfo;
use RuntimeException;

final class GalleryUploader
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
    public function upload(int $cardId, ?array $file): ?string
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new RuntimeException('card.gallery.errors.too_large');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('card.gallery.errors.upload_failed');
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('card.gallery.errors.too_large');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension = self::ALLOWED_TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('card.gallery.errors.invalid_type');
        }

        $dir = $this->publicRoot . '/uploads/gallery';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('card.gallery.errors.upload_failed');
        }

        $filename = $cardId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $relativePath = "uploads/gallery/{$filename}";
        if (!move_uploaded_file($file['tmp_name'], $this->publicRoot . '/' . $relativePath)) {
            throw new RuntimeException('card.gallery.errors.upload_failed');
        }

        return $relativePath;
    }

    public function remove(string $relativePath): void
    {
        $full = $this->publicRoot . '/' . ltrim($relativePath, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
