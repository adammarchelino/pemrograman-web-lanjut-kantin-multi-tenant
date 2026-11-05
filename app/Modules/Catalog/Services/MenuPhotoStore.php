<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class MenuPhotoStore
{
    public function store(UploadedFile $photo, int $tenantId): string
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('Dukungan WebP pada ekstensi GD tidak tersedia.');
        }

        $image = match ($photo->getMimeType()) {
            'image/jpeg' => @imagecreatefromjpeg($photo->getRealPath()),
            'image/png' => @imagecreatefrompng($photo->getRealPath()),
            'image/webp' => @imagecreatefromwebp($photo->getRealPath()),
            default => false,
        };
        if (! $image) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 800 / max($width, $height));
        $target = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        ob_start();
        imagewebp($target, null, 82);
        $contents = ob_get_clean();
        imagedestroy($image);
        imagedestroy($target);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Gambar gagal dikonversi ke WebP.');
        }

        $path = sprintf('tenants/%d/menus/%s.webp', $tenantId, Str::uuid());
        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
