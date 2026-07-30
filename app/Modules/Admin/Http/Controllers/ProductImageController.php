<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    /** Longest side, in pixels, after resizing — plenty for zoomed product photos. */
    private const MAX_DIMENSION = 1600;

    private const JPEG_QUALITY = 82;

    public function store(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:4096'],
        ]);

        $path = $request->file('image')->store("products/{$product->id}", 'public');
        $path = $this->optimize($path) ?? $path;

        $product->images()->create([
            'path' => $path,
            'position' => $product->images()->count(),
            'is_primary' => $product->images()->count() === 0,
        ]);

        return back()->with('success', 'Foto adicionada.');
    }

    public function destroy(Product $product, ProductImage $image): RedirectResponse
    {
        abort_if($image->product_id !== $product->id, 404);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        if (! $product->images()->where('is_primary', true)->exists()) {
            $product->images()->oldest('position')->first()?->update(['is_primary' => true]);
        }

        return back()->with('success', 'Foto removida.');
    }

    /**
     * Photos uploaded straight from a phone/camera routinely land at
     * 1.5-2MB+ and several thousand pixels wide — way more than a product
     * gallery needs, and slow enough to feel like the images "don't load"
     * when several show at once. Resize to a sane max dimension and
     * re-encode as JPEG (much smaller than PNG for photographic content).
     * Returns the new relative storage path, or null if optimization wasn't
     * possible (missing GD extension, unrecognized format) — the original
     * upload is kept as-is in that case.
     */
    private function optimize(string $path): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $disk = Storage::disk('public');
        $fullPath = $disk->path($path);
        $info = @getimagesize($fullPath);

        if (! $info) {
            return null;
        }

        [$width, $height, $type] = $info;

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($fullPath),
            IMAGETYPE_PNG => @imagecreatefrompng($fullPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : null,
            default => null,
        };

        if (! $source) {
            return null;
        }

        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $newPath = preg_replace('/\.\w+$/', '.jpg', $path);
        $newFullPath = $disk->path($newPath);

        imagejpeg($resized, $newFullPath, self::JPEG_QUALITY);

        imagedestroy($source);
        imagedestroy($resized);

        if ($newFullPath !== $fullPath) {
            @unlink($fullPath);
        }

        return $newPath;
    }
}
