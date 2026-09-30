<?php

declare(strict_types=1);

namespace App\Support;

final class ContentImageUrl
{
    /**
     * Public Hotelbeds photo CDN for a stored relative image path.
     * This is not an HBX API request. Unsafe or absolute paths are not rendered.
     */
    public static function thumbnail(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '//')) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $path) !== 1 || str_starts_with($path, '/')) {
            return null;
        }

        $base = rtrim((string) config('hbx.content.photo_thumbnail_base'), '/');

        if (preg_match('#^https://photos\.hotelbeds\.com/giata(?:/[A-Za-z0-9_-]+)?$#', $base) !== 1) {
            return null;
        }

        return $base.'/'.$path;
    }
}
