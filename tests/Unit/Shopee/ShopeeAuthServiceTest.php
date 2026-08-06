<?php

namespace Tests\Unit\Shopee;

use App\Services\Shopee\ShopeeAuthService;
use Tests\TestCase;

class ShopeeAuthServiceTest extends TestCase
{
    /**
     * Bug real encontrado 2026-08-06: o link de autorização usava o mesmo
     * host das chamadas de API (api_base_url) + path assinado com HMAC
     * (/api/v2/shop/auth_partner?...&timestamp=...&sign=...) — a Shopee
     * rejeitava isso ("endpoint errado", confirmado pelo suporte deles).
     * O link real usa um host REGIONAL separado (auth_base_url) + path
     * simples /auth, sem timestamp/sign — documentado em
     * open.shopee.com/developer-guide/20 ("Generating the Authorization
     * Link"). Trava a forma nova pra não regredir pra assinada de novo.
     */
    public function test_authorization_url_uses_the_regional_auth_host_not_the_api_host(): void
    {
        config([
            'services.shopee.partner_id' => '1239829',
            'services.shopee.redirect_url' => 'https://kazakora.devlira.com.br/api/shopee/callback',
            'services.shopee.auth_base_url' => 'https://open.sandbox.test-stable.shopee.com.br',
            'services.shopee.api_base_url' => 'https://partner.test-stable.shopeemobile.com',
        ]);

        $url = (new ShopeeAuthService)->getAuthorizationUrl();

        $this->assertStringStartsWith('https://open.sandbox.test-stable.shopee.com.br/auth?', $url);
        $this->assertStringNotContainsString('partner.test-stable.shopeemobile.com', $url);
        $this->assertStringNotContainsString('auth_partner', $url);
        $this->assertStringNotContainsString('timestamp=', $url);
        $this->assertStringNotContainsString('sign=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('1239829', $query['partner_id']);
        $this->assertSame('seller', $query['auth_type']);
        $this->assertSame('https://kazakora.devlira.com.br/api/shopee/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
    }

    public function test_authorization_url_strips_a_trailing_slash_from_the_configured_host(): void
    {
        config([
            'services.shopee.partner_id' => '1',
            'services.shopee.redirect_url' => 'https://example.com/callback',
            'services.shopee.auth_base_url' => 'https://open.shopee.com.br/',
        ]);

        $url = (new ShopeeAuthService)->getAuthorizationUrl();

        $this->assertStringStartsWith('https://open.shopee.com.br/auth?', $url);
        $this->assertStringNotContainsString('.com.br//auth', $url);
    }
}
