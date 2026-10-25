<?php

namespace App\Services\TikTokShop;

use App\Modules\Marketplace\Models\TikTokShopConexao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API do TikTok Shop pelo app próprio da loja (Partner Center, categoria
 * "TikTok Shop Seller"). Hoje usada só pelo chat (Customer Service API,
 * escopo seller.customer_service); os pedidos continuam vindo pelo Bling.
 *
 * Assinatura (docs "Sign your API request"): HMAC-SHA256 com o app_secret de
 * secret + caminho + parâmetros ordenados (sem sign e access_token) + corpo + secret.
 */
class TikTokShopClient
{
    /** @return list<string> o que falta no .env para ligar a aba */
    public static function faltando(): array
    {
        $c = config('services.tiktok_shop');
        $falta = [];
        if (blank($c['app_key'] ?? null) || blank($c['app_secret'] ?? null)) {
            $falta[] = 'TIKTOK_SHOP_APP_KEY e TIKTOK_SHOP_APP_SECRET (app próprio aprovado no Partner Center)';
        }
        if (blank($c['service_id'] ?? null)) {
            $falta[] = 'TIKTOK_SHOP_SERVICE_ID (link de autorização da loja)';
        }

        return $falta;
    }

    public static function appConfigurado(): bool
    {
        return self::faltando() === [];
    }

    public static function conectado(): bool
    {
        return self::appConfigurado() && rescue(fn () => TikTokShopConexao::query()->exists(), false, false);
    }

    public function urlDeAutorizacao(string $estado): string
    {
        return rtrim(config('services.tiktok_shop.authorize_url'), '/').'?'.http_build_query([
            'service_id' => config('services.tiktok_shop.service_id'),
            'state' => $estado,
        ]);
    }

    /** Troca o auth_code do retorno da autorização pelo token e guarda a loja. */
    public function conectar(string $codigo, ?int $userId): TikTokShopConexao
    {
        $dados = $this->token(['auth_code' => $codigo, 'grant_type' => 'authorized_code']);

        $conexao = TikTokShopConexao::query()->create($this->camposDoToken($dados) + [
            'vendedor_nome' => $dados['seller_name'] ?? null,
            'conectado_por_user_id' => $userId,
        ]);

        $lojas = $this->chamar('GET', '/authorization/202309/shops', [], null, $conexao, false)['shops'] ?? [];
        $loja = collect($lojas)->firstWhere('region', 'BR') ?? ($lojas[0] ?? null);
        $conexao->update(['shop_cipher' => $loja['cipher'] ?? null, 'shop_nome' => $loja['name'] ?? null]);

        // Uma loja só: conexões antigas saem.
        TikTokShopConexao::query()->whereKeyNot($conexao->id)->delete();

        return $conexao;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $corpo
     * @return array<string, mixed> o campo data da resposta
     */
    public function chamar(string $metodo, string $caminho, array $query = [], ?array $corpo = null, ?TikTokShopConexao $conexao = null, bool $comLoja = true): array
    {
        $conexao ??= TikTokShopConexao::atual() ?? throw new RuntimeException('Loja do TikTok Shop não conectada.');
        $conexao = $this->renovarSePreciso($conexao);

        $query = array_filter($query, fn ($v) => $v !== null && $v !== '') + [
            'app_key' => config('services.tiktok_shop.app_key'),
            'timestamp' => time(),
        ];
        if ($comLoja && $conexao->shop_cipher) {
            $query['shop_cipher'] = $conexao->shop_cipher;
        }
        $json = $corpo === null ? '' : json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query['sign'] = self::assinar($caminho, $query, $json, (string) config('services.tiktok_shop.app_secret'));

        $resposta = Http::timeout(20)
            ->withHeaders(['x-tts-access-token' => $conexao->access_token, 'Content-Type' => 'application/json'])
            ->when($json !== '', fn ($r) => $r->withBody($json, 'application/json'))
            ->send($metodo, rtrim(config('services.tiktok_shop.api_url'), '/').$caminho.'?'.http_build_query($query));

        $dados = $resposta->json() ?? [];
        if (! $resposta->successful() || (int) ($dados['code'] ?? -1) !== 0) {
            throw new RuntimeException('TikTok Shop: '.($dados['message'] ?? 'HTTP '.$resposta->status()).' (código '.($dados['code'] ?? $resposta->status()).')');
        }

        return $dados['data'] ?? [];
    }

    /** @param array<string, scalar> $query */
    public static function assinar(string $caminho, array $query, string $corpo, string $segredo): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query);
        $base = $caminho;
        foreach ($query as $k => $v) {
            $base .= $k.$v;
        }

        return hash_hmac('sha256', $segredo.$base.$corpo.$segredo, $segredo);
    }

    private function renovarSePreciso(TikTokShopConexao $conexao): TikTokShopConexao
    {
        if ($conexao->access_expira_em && $conexao->access_expira_em->gt(now()->addMinutes(10))) {
            return $conexao;
        }
        if ($conexao->refresh_expira_em && $conexao->refresh_expira_em->isPast()) {
            throw new RuntimeException('A autorização do TikTok Shop venceu. Conecte a loja de novo.');
        }

        $conexao->update($this->camposDoToken($this->token(['refresh_token' => $conexao->refresh_token, 'grant_type' => 'refresh_token'])));

        return $conexao;
    }

    /** @return array<string, mixed> */
    private function token(array $parametros): array
    {
        $rota = $parametros['grant_type'] === 'refresh_token' ? '/api/v2/token/refresh' : '/api/v2/token/get';
        $resposta = Http::timeout(20)->get(rtrim(config('services.tiktok_shop.auth_url'), '/').$rota, $parametros + [
            'app_key' => config('services.tiktok_shop.app_key'),
            'app_secret' => config('services.tiktok_shop.app_secret'),
        ]);
        $dados = $resposta->json() ?? [];
        if (! $resposta->successful() || (int) ($dados['code'] ?? -1) !== 0 || empty($dados['data']['access_token'])) {
            throw new RuntimeException('TikTok Shop recusou o token: '.($dados['message'] ?? 'HTTP '.$resposta->status()));
        }

        return $dados['data'];
    }

    /** @param array<string, mixed> $d */
    private function camposDoToken(array $d): array
    {
        $quando = fn ($ts) => $ts ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;

        return [
            'access_token' => $d['access_token'],
            'refresh_token' => $d['refresh_token'] ?? '',
            'access_expira_em' => $quando($d['access_token_expire_in'] ?? null),
            'refresh_expira_em' => $quando($d['refresh_token_expire_in'] ?? null),
        ];
    }
}
