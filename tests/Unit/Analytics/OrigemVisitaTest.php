<?php

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Support\OrigemVisita;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** De onde o visitante veio (pedido 2026-10-12, tela Visitas ao site). */
class OrigemVisitaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://kazakora.com']);
    }

    /** @return array<string, array{0: ?string, 1: array<string, ?string>, 2: ?string, 3: string}> */
    public static function casos(): array
    {
        return [
            'sem nada é direto' => [null, [], null, 'direto'],
            'referer vazio é direto' => ['', [], null, 'direto'],
            'link do instagram' => ['https://l.instagram.com/', [], null, 'instagram'],
            'instagram.com' => ['https://www.instagram.com/kazakora_achados/', [], null, 'instagram'],
            'lm.facebook.com' => ['https://lm.facebook.com/l.php', [], null, 'facebook'],
            'm.facebook.com' => ['https://m.facebook.com/', [], null, 'facebook'],
            'google.com.br' => ['https://www.google.com.br/', [], null, 'google'],
            'google.com' => ['https://www.google.com/search', [], null, 'google'],
            'anúncio do google pelo referer' => ['https://www.googleadservices.com/pagead/aclk', [], null, 'google_ads'],
            'gmail é e-mail, não google' => ['https://mail.google.com/', [], null, 'email'],
            't.co' => ['https://t.co/abc', [], null, 'x'],
            'wa.me' => ['https://wa.me/5511999999999', [], null, 'whatsapp'],
            'api.whatsapp.com' => ['https://api.whatsapp.com/send', [], null, 'whatsapp'],
            'web.whatsapp.com' => ['https://web.whatsapp.com/', [], null, 'whatsapp'],
            'shopee.com.br' => ['https://shopee.com.br/kazakora', [], null, 'shopee'],
            'mercado livre' => ['https://produto.mercadolivre.com.br/MLB-123', [], null, 'mercado_livre'],
            'volta do mercado pago' => ['https://www.mercadopago.com.br/checkout', [], null, 'mercado_pago'],
            'youtube' => ['https://m.youtube.com/watch', [], null, 'youtube'],
            'youtu.be' => ['https://youtu.be/x', [], null, 'youtube'],
            'bing' => ['https://www.bing.com/', [], null, 'bing'],
            'tiktok' => ['https://www.tiktok.com/@kazakora', [], null, 'tiktok'],
            'app do instagram no android' => ['android-app://com.instagram.android/', [], null, 'instagram'],
            'app do google no android' => ['android-app://com.google.android.googlequicksearchbox/', [], null, 'google'],
            'próprio site é interno' => ['https://kazakora.com/produtos/caneca', [], null, 'interno'],
            'www do próprio site é interno' => ['https://www.kazakora.com/', [], null, 'interno'],
            'homolog é interno' => ['https://kazakora.devlira.com.br/', [], null, 'interno'],
            'domínio que só termina parecido não é interno' => ['https://naokazakora.com/', [], null, 'outro'],
            'referer antigo sem https do próprio site' => ['kazakora.devlira.com.br/index.php?action=login', [], null, 'interno'],
            'referer lixo de robô não é direto' => ['${jndi:ldap://127.0.0.1#.${hostName}}', [], null, 'outro'],
            'telegram' => ['https://web.telegram.org/', [], null, 'telegram'],
            'outro site' => ['https://blog.exemplo.com.br/post', [], null, 'outro'],
            'gclid é google ads mesmo com utm' => ['https://www.google.com/', ['utm_source' => 'instagram'], 'gclid', 'google_ads'],
            'gbraid é google ads' => [null, [], 'gbraid', 'google_ads'],
            'utm vale mais que o referer' => ['https://www.google.com/', ['utm_source' => 'instagram'], null, 'instagram'],
            'utm em maiúsculas' => [null, ['utm_source' => 'Instagram'], null, 'instagram'],
            'utm google cpc é google ads' => [null, ['utm_source' => 'google', 'utm_medium' => 'cpc'], null, 'google_ads'],
            'utm facebook pago é meta ads' => [null, ['utm_source' => 'facebook', 'utm_medium' => 'paid_social'], null, 'meta_ads'],
            'utm whatsapp' => [null, ['utm_source' => 'whatsapp'], null, 'whatsapp'],
            'utm com domínio' => [null, ['utm_source' => 'l.instagram.com'], null, 'instagram'],
            'utm desconhecida é campanha' => [null, ['utm_source' => 'parceiro_joao'], null, 'campanha'],
            'fbclid com referer do instagram' => ['https://l.instagram.com/', [], 'fbclid', 'instagram'],
            'fbclid com referer do facebook' => ['https://lm.facebook.com/', [], 'fbclid', 'facebook'],
            'fbclid sem referer' => [null, [], 'fbclid', 'meta'],
            'fbclid com utm instagram' => [null, ['utm_source' => 'ig'], 'fbclid', 'instagram'],
            'ttclid' => [null, [], 'ttclid', 'tiktok_ads'],
            'msclkid' => [null, [], 'msclkid', 'bing_ads'],
            'igshid' => [null, [], 'igshid', 'instagram'],
        ];
    }

    #[DataProvider('casos')]
    public function test_classifica(?string $referer, array $utm, ?string $clique, string $esperado): void
    {
        $this->assertSame($esperado, OrigemVisita::classificar($referer, $utm, $clique));
    }

    public function test_rotulos(): void
    {
        $this->assertSame('Instagram', OrigemVisita::rotulo('instagram'));
        $this->assertSame('Google Ads', OrigemVisita::rotulo('google_ads'));
        $this->assertSame('Direto', OrigemVisita::rotulo('direto'));
        $this->assertSame('Sem dado', OrigemVisita::rotulo(null));
        $this->assertSame('Outro site (blog.exemplo.com.br)', OrigemVisita::rotulo('outro', 'https://www.blog.exemplo.com.br/post'));
        $this->assertSame('Campanha (parceiro_joao)', OrigemVisita::rotulo('campanha', null, 'parceiro_joao'));
    }

    public function test_click_id_pega_o_primeiro_da_lista(): void
    {
        $this->assertSame(['gclid', 'abc'], OrigemVisita::clickId(['fbclid' => 'x', 'gclid' => 'abc']));
        $this->assertSame(['fbclid', 'IwAR1'], OrigemVisita::clickId(['FBCLID' => 'IwAR1']));
        $this->assertSame([null, null], OrigemVisita::clickId(['utm_source' => 'x', 'fbclid' => '']));
        $this->assertSame([null, null], OrigemVisita::clickId(['fbclid' => ['array']]));
    }
}
