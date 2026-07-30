<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Banner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BannerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Banners/Index', [
            'banners' => Banner::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['image_path'] = $request->file('image')->store('banners', 'public');

        if ($request->hasFile('image_mobile')) {
            $validated['image_path_mobile'] = $request->file('image_mobile')->store('banners', 'public');
        }

        $validated['sort_order'] = (int) Banner::query()->max('sort_order') + 1;

        Banner::create($validated);

        return redirect()->route('admin.banners.listar')->with('success', 'Banner criado com sucesso.');
    }

    public function edit(Banner $banner): Response
    {
        return Inertia::render('Admin/Banners/Edit', [
            'banner' => $banner,
        ]);
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $validated = $this->validated($request, requireImage: false);

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($banner->image_path);
            $validated['image_path'] = $request->file('image')->store('banners', 'public');
        }

        if ($request->hasFile('image_mobile')) {
            if ($banner->image_path_mobile) {
                Storage::disk('public')->delete($banner->image_path_mobile);
            }
            $validated['image_path_mobile'] = $request->file('image_mobile')->store('banners', 'public');
        }

        $banner->update($validated);

        return redirect()->route('admin.banners.listar')->with('success', 'Banner atualizado com sucesso.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        Storage::disk('public')->delete($banner->image_path);
        if ($banner->image_path_mobile) {
            Storage::disk('public')->delete($banner->image_path_mobile);
        }
        $banner->delete();

        return back()->with('success', 'Banner removido com sucesso.');
    }

    public function moveUp(Banner $banner): RedirectResponse
    {
        $previous = Banner::query()
            ->where('sort_order', '<', $banner->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        return $this->swapOrder($banner, $previous);
    }

    public function moveDown(Banner $banner): RedirectResponse
    {
        $next = Banner::query()
            ->where('sort_order', '>', $banner->sort_order)
            ->orderBy('sort_order')
            ->first();

        return $this->swapOrder($banner, $next);
    }

    private function swapOrder(Banner $banner, ?Banner $sibling): RedirectResponse
    {
        if ($sibling) {
            [$banner->sort_order, $sibling->sort_order] = [$sibling->sort_order, $banner->sort_order];
            $banner->save();
            $sibling->save();
        }

        return back();
    }

    private function validated(Request $request, bool $requireImage = true): array
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'max:255'],
            'image' => [$requireImage ? 'required' : 'nullable', 'image', 'max:15360'],
            'image_mobile' => ['nullable', 'image', 'max:15360'],
        ]);

        // The quick-upload flow on Index.vue doesn't send `is_active` at all
        // (new banners should show right away); Edit.vue always sends it
        // explicitly via Inertia's useForm, so only default to true there.
        $validated['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $validated;
    }
}
