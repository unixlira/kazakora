<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Catalog\Models\Banner;
use App\Support\Marca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BannerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Banners/Index', [
            'banners' => Banner::query()->orderBy('sort_order')->get(),
            'marca' => [
                ...Marca::urls(),
                'personalizado' => collect(array_keys(Marca::ITENS))->mapWithKeys(fn ($item) => [$item => (bool) Marca::caminho($item)]),
            ],
        ]);
    }

    /**
     * Logos e favicon (pedido 2026-10-10): logo do topo, logo do rodapé e
     * favicon. Cada um é opcional; o que vier substitui o atual. "restaurar"
     * volta ao padrão da loja.
     */
    public function salvarMarca(Request $request): RedirectResponse
    {
        $request->validate([
            'logo_nav' => ['nullable', 'file', 'mimes:png,webp,jpg,jpeg', 'max:2048', 'dimensions:min_width=200,min_height=30,max_width=4000,max_height=1500'],
            'logo_rodape' => ['nullable', 'file', 'mimes:png,webp,jpg,jpeg', 'max:2048', 'dimensions:min_width=200,min_height=30,max_width=4000,max_height=1500'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'restaurar' => ['nullable', 'array'],
            'restaurar.*' => [Rule::in(array_keys(Marca::ITENS))],
        ], [
            'logo_nav.mimes' => 'A logo do topo precisa ser PNG, WebP ou JPG.',
            'logo_nav.max' => 'A logo do topo pode ter no máximo 2 MB.',
            'logo_nav.dimensions' => 'A logo do topo precisa ter entre 200 e 4000 px de largura (recomendado 520 × 92 px).',
            'logo_rodape.mimes' => 'A logo do rodapé precisa ser PNG, WebP ou JPG.',
            'logo_rodape.max' => 'A logo do rodapé pode ter no máximo 2 MB.',
            'logo_rodape.dimensions' => 'A logo do rodapé precisa ter entre 200 e 4000 px de largura (recomendado 850 × 150 px).',
            'favicon.mimes' => 'O favicon precisa ser PNG ou ICO.',
            'favicon.max' => 'O favicon pode ter no máximo 512 KB.',
        ]);

        // Favicon PNG tem que ser quadrado (ICO o navegador já trata).
        if ($request->hasFile('favicon') && strtolower($request->file('favicon')->getClientOriginalExtension()) === 'png') {
            [$largura, $altura] = getimagesize($request->file('favicon')->getRealPath()) ?: [0, 0];
            if ($largura !== $altura || $largura < 32 || $largura > 1024) {
                return back()->withErrors(['favicon' => 'O favicon PNG precisa ser quadrado, entre 32 e 1024 px (recomendado 512 × 512 px).']);
            }
        }

        foreach ((array) $request->input('restaurar', []) as $item) {
            $this->trocarArquivoDaMarca($item, null);
        }
        foreach (array_keys(Marca::ITENS) as $item) {
            if ($request->hasFile($item)) {
                $this->trocarArquivoDaMarca($item, $request->file($item)->store('marca', 'public'));
            }
        }

        return back()->with('success', 'Logos e favicon atualizados.');
    }

    private function trocarArquivoDaMarca(string $item, ?string $novo): void
    {
        if ($antigo = Marca::caminho($item)) {
            Storage::disk('public')->delete($antigo);
        }
        Setting::set(Marca::chave($item), $novo);
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
