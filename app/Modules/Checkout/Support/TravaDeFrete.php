<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\FreteTrava;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Trava do frete (pedido do Lira em 2026-10-24): quando o visitante calcula o
 * frete de um produto (CEP na página do produto ou no checkout), o frete que
 * ele viu fica guardado com o IP e a hash do aparelho. Se ele voltar ao site
 * com o mesmo CEP, o frete daquele produto não sobe (Correios ou regra da
 * cidade mudaram no meio): vale o menor entre o guardado e o de agora.
 *
 * O visitante é reconhecido pelo par IP + hash do aparelho (navegador,
 * idioma e sistema, que o navegador manda em toda chamada) ou, se o IP mudou
 * (4G, outra rede), pelo cookie do aparelho de 1 ano. Qualquer falha aqui
 * (tabela ausente, banco fora) = sem trava: a loja nunca para por isso.
 */
class TravaDeFrete
{
    /** @var array<string, array<int, FreteTrava>> travas válidas por visitante e site:cep, nesta requisição */
    private array $carregadas = [];

    public static function ligada(): bool
    {
        return (int) config('frete_embutido.trava_dias', 30) > 0;
    }

    /** Hash do aparelho: navegador, idioma e sistema (cabeçalhos do próprio navegador). */
    public function hashDoAparelho(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->request()->userAgent(),
            (string) $this->request()->header('Accept-Language'),
            (string) $this->request()->header('Sec-CH-UA-Platform'),
            (string) $this->request()->header('Sec-CH-UA-Mobile'),
        ]));
    }

    /** Trava válida do produto (ou do produto pai) para o CEP, se houver. */
    public function doProduto(string $site, string $cep, int $produtoId, ?int $paiId = null): ?FreteTrava
    {
        if (! self::ligada()) {
            return null;
        }

        $travas = $this->carregar($site, $cep);

        return $travas[$produtoId] ?? ($paiId ? ($travas[$paiId] ?? null) : null);
    }

    /**
     * Guarda o frete que o visitante acabou de ver. Trava que ainda vale não
     * muda (o primeiro frete visto é o que conta); vencida, renova.
     *
     * @param  array{embutido: float, prazo: ?int, correios?: float, regra?: string}  $frete
     */
    public function registrar(string $site, string $cep, int $produtoId, array $frete): void
    {
        if (! self::ligada() || $this->doProduto($site, $cep, $produtoId)) {
            return;
        }

        rescue(function () use ($site, $cep, $produtoId, $frete) {
            FreteTrava::query()->updateOrCreate(
                ['site' => $site, 'dispositivo_hash' => $this->hashDoAparelho(), 'ip' => (string) $this->request()->ip(), 'cep' => $cep, 'product_id' => $produtoId],
                [
                    'dispositivo_id' => $this->idDoAparelho(),
                    'embutido' => round((float) $frete['embutido'], 2),
                    'prazo_dias' => $frete['prazo'] ?? null,
                    'correios' => $frete['correios'] ?? null,
                    'regra' => isset($frete['regra']) ? Str::limit((string) $frete['regra'], 117) : null,
                    'expira_em' => now()->addDays((int) config('frete_embutido.trava_dias', 30)),
                ],
            );
            // O cookie do aparelho pode ter nascido agora: a próxima leitura busca de novo.
            $this->carregadas = [];
        }, null, false);
    }

    /** @return array<int, FreteTrava> por produto */
    private function carregar(string $site, string $cep): array
    {
        $chave = $this->chave($site, $cep);
        if (isset($this->carregadas[$chave])) {
            return $this->carregadas[$chave];
        }

        $hash = $this->hashDoAparelho();
        $ip = (string) $this->request()->ip();
        $cookie = $this->cookieDoAparelho();

        return $this->carregadas[$chave] = rescue(fn () => FreteTrava::query()
            ->where('site', $site)
            ->where('cep', $cep)
            ->where('expira_em', '>', now())
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('dispositivo_hash', $hash)->where('ip', $ip))
                ->when($cookie, fn ($q) => $q->orWhere('dispositivo_id', $cookie)))
            // A mais antiga ganha: é o primeiro frete que o visitante viu.
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('product_id')
            ->all(), [], false);
    }

    /** Requisição atual (a do container muda a cada chamada nos testes). */
    private function request(): Request
    {
        return request();
    }

    private function chave(string $site, string $cep): string
    {
        return implode('|', [spl_object_id($this->request()), $site, $cep, $this->hashDoAparelho(), $this->request()->ip(), $this->cookieDoAparelho()]);
    }

    private function cookieDoAparelho(): ?string
    {
        $valor = (string) $this->request()->cookie((string) config('frete_embutido.trava_cookie', 'kz_aparelho'));

        return Str::isUuid($valor) ? $valor : null;
    }

    /** Cookie do aparelho (1 ano, só HTTP): cria na primeira trava. */
    private function idDoAparelho(): string
    {
        $id = $this->cookieDoAparelho();
        if (! $id) {
            $id = (string) Str::uuid();
            $this->request()->cookies->set((string) config('frete_embutido.trava_cookie', 'kz_aparelho'), $id);
            cookie()->queue(cookie((string) config('frete_embutido.trava_cookie', 'kz_aparelho'), $id, 60 * 24 * 365, null, null, null, true, false, 'lax'));
        }

        return $id;
    }
}
