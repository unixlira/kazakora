<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductVideoController extends Controller
{
    private const MAX_DURATION_SECONDS = 60;

    private const MAX_KILOBYTES = 102400; // 100 MB

    public function store(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.self::MAX_KILOBYTES],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DURATION_SECONDS],
        ]);

        if ($product->video_path) {
            Storage::disk('public')->delete($product->video_path);
        }

        $path = $request->file('video')->store("products/{$product->id}/video", 'public');

        $product->update([
            'video_path' => $path,
            'video_duration_seconds' => $validated['duration_seconds'],
        ]);

        return back()->with('success', 'Vídeo do produto enviado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->video_path) {
            Storage::disk('public')->delete($product->video_path);
        }

        $product->update(['video_path' => null, 'video_duration_seconds' => null]);

        return back()->with('success', 'Vídeo removido.');
    }
}
