<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopeeController extends Controller
{
    public function __construct(private readonly ShopeeAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'shop_id' => ['required', 'integer'],
        ]);

        try {
            $this->auth->handleCallback($validated['code'], (int) $validated['shop_id']);
        } catch (ShopeeException $exception) {
            return redirect('/admin/empresa')->with('error', $exception->getMessage());
        }

        return redirect('/admin/empresa')->with('success', 'Loja da Shopee conectada com sucesso.');
    }
}
