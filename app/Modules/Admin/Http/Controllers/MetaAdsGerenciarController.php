<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Meta ADS (pedido do Lira 2026-10-20): editar e excluir campanha, conjunto
 * e anúncio que já estão na Meta, direto no admin (espelho "Na Meta agora"
 * e tela da campanha), inclusive os criados direto no Gerenciador.
 *
 * Tudo pelo id da Meta, conferindo antes que o item é da conta de anúncios
 * da loja. Editar = nome e, quando o orçamento é diário naquele nível, o
 * orçamento por dia (mudar o orçamento exige confirmar o gasto). Excluir =
 * DELETED na Meta, definitivo, com confirmação; conjunto ou anúncio que é o
 * único ativo pede uma confirmação a mais. O KazaKora só muda depois que a
 * Meta aceita; o que existe aqui fica marcado como excluído (soft delete)
 * para não perder o vínculo com o gasto.
 *
 * Anúncio que nasceu no KazaKora usa o editar e excluir próprio
 * (MetaAdsController::videoUpdate/videoDestroy), que troca texto e arquivo.
 */
class MetaAdsGerenciarController extends Controller
{
    private const ROTULOS = ['campanha' => 'a campanha', 'conjunto' => 'o conjunto', 'anuncio' => 'o anúncio'];

    public function update(Request $request, string $nivel, string $metaId, MetaAdsService $meta): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'nome' => ['required', 'string', 'max:255'],
            'orcamento_diario' => ['nullable', 'numeric', 'min:'.MetaAdsCampanha::MINIMO_POR_CONJUNTO, 'max:10000'],
            'confirmo_gasto' => ['boolean'],
        ], [
            'nome.required' => 'Dê um nome.',
            'orcamento_diario.min' => 'Mínimo de R$ '.number_format(MetaAdsCampanha::MINIMO_POR_CONJUNTO, 2, ',', '.').' por dia.',
            'orcamento_diario.max' => 'Máximo de R$ 10.000,00 por dia por aqui.',
        ]);
        if ($validador->fails()) {
            return $this->erroDeCampo($validador->errors()->toArray());
        }

        if ($resposta = $this->semMeta($meta)) {
            return $resposta;
        }

        try {
            $objeto = $meta->objeto($nivel, $metaId);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'Não deu para ler '.self::ROTULOS[$nivel].' na Meta: '.$erro->getMessage()], 422);
        }

        [$campanha, $conjunto, $video] = $this->locais($nivel, $objeto);
        if ($bloqueio = $this->bloqueio($nivel, $campanha, $conjunto, $video)) {
            return response()->json(['message' => $bloqueio], 409);
        }

        $nome = trim((string) $request->input('nome'));
        $orcamento = null;

        if ($nivel !== 'anuncio' && $request->filled('orcamento_diario')) {
            if ($objeto['orcamento_diario'] === null) {
                return $this->erroDeCampo(['orcamento_diario' => ['Aqui o orçamento não é diário (fica no outro nível ou é total). Mude pelo Gerenciador.']]);
            }

            $novo = round((float) $request->input('orcamento_diario'), 2);
            if ($novo !== round($objeto['orcamento_diario'], 2)) {
                if (! $request->boolean('confirmo_gasto')) {
                    return $this->erroDeCampo(['confirmo_gasto' => ['Confirme o novo gasto por dia.']]);
                }
                $orcamento = $novo;
            }
        }

        if ($nome === $objeto['nome'] && $orcamento === null) {
            return response()->json(['message' => 'Nada mudou.', 'ok' => true]);
        }

        try {
            $meta->editarObjeto($objeto, $nome, $orcamento);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'A Meta recusou: '.rtrim($erro->getMessage(), '.').'. Nada foi alterado.'], 422);
        }

        // A Meta aceitou: agora o que existe no KazaKora acompanha.
        DB::transaction(function () use ($nivel, $campanha, $conjunto, $nome, $orcamento) {
            if ($nivel === 'campanha' && $campanha) {
                $campanha->forceFill(['nome' => $nome] + ($orcamento !== null ? ['orcamento_diario' => $orcamento] : []))->save();
            }

            if ($nivel === 'conjunto' && $conjunto) {
                // Na Meta o conjunto chama "campanha | conjunto"; aqui fica só o nome dele.
                $prefixo = $campanha ? $campanha->nome.' | ' : '';
                $conjunto->forceFill([
                    'nome' => Str::limit($prefixo !== '' && str_starts_with($nome, $prefixo) ? Str::after($nome, $prefixo) : $nome, 120, ''),
                ] + ($orcamento !== null ? ['orcamento_diario' => $orcamento] : []))->save();
                $this->somarOrcamentoDosConjuntos($campanha);
            }
        });

        return response()->json(['message' => ucfirst(Str::after(self::ROTULOS[$nivel], ' ')).' foi atualizad'.($nivel === 'campanha' ? 'a' : 'o').' na Meta.', 'ok' => true]);
    }

    public function destroy(Request $request, string $nivel, string $metaId, MetaAdsService $meta): JsonResponse
    {
        if (! $request->boolean('confirmo_exclusao')) {
            return $this->erroDeCampo(['confirmo_exclusao' => ['Confirme que a exclusão na Meta é definitiva.']]);
        }

        if ($resposta = $this->semMeta($meta)) {
            return $resposta;
        }

        try {
            $objeto = $meta->objeto($nivel, $metaId);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'Não deu para ler '.self::ROTULOS[$nivel].' na Meta: '.$erro->getMessage()], 422);
        }

        [$campanha, $conjunto, $video] = $this->locais($nivel, $objeto);
        if ($bloqueio = $this->bloqueio($nivel, $campanha, $conjunto, $video)) {
            return response()->json(['message' => $bloqueio], 409);
        }

        try {
            if ($nivel !== 'campanha' && ! $request->boolean('sem_ativo') && $meta->unicoAtivo($nivel, $objeto)) {
                $mensagem = $nivel === 'conjunto'
                    ? 'É o único conjunto ativo da campanha. Sem ele a campanha para de entregar.'
                    : 'É o último anúncio ativo do conjunto. Sem ele o conjunto para de entregar.';

                return response()->json(['message' => $mensagem, 'errors' => ['sem_ativo' => [$mensagem]], 'ultimo_ativo' => true], 422);
            }

            $meta->excluirObjeto($objeto);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'A Meta recusou a exclusão: '.rtrim($erro->getMessage(), '.').'. Nada foi alterado.'], 422);
        }

        DB::transaction(function () use ($nivel, $campanha, $conjunto) {
            if ($nivel === 'campanha' && $campanha) {
                $this->excluirAnuncios($campanha->videos);
                $campanha->conjuntos->each->delete();
                $campanha->delete();
            }

            if ($nivel === 'conjunto' && $conjunto) {
                $this->excluirAnuncios($campanha?->videos->where('conjunto_id', $conjunto->id) ?? collect());
                $conjunto->delete();
                $this->somarOrcamentoDosConjuntos($campanha);
            }
        });

        return response()->json([
            'message' => ucfirst(Str::after(self::ROTULOS[$nivel], ' ')).' foi excluíd'.($nivel === 'campanha' ? 'a' : 'o').' na Meta.',
            'ok' => true,
        ]);
    }

    /**
     * "Pausar criativo" nas sugestões do O que fazer (pedido do Lira
     * 2026-10-21): pausa o anúncio na Meta, nascido aqui ou no Gerenciador.
     * Só anúncio ativo; o último ativo do conjunto pede confirmação a mais.
     */
    public function pausar(Request $request, string $metaId, MetaAdsService $meta): JsonResponse
    {
        if ($resposta = $this->semMeta($meta)) {
            return $resposta;
        }

        try {
            $objeto = $meta->objeto('anuncio', $metaId);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'Não deu para ler o anúncio na Meta: '.$erro->getMessage()], 422);
        }

        if ($objeto['status'] !== 'ACTIVE') {
            $meta->esquecerEspelho($objeto['campanha_id']);

            return response()->json(['message' => 'O anúncio não está ativo na Meta agora. Nada foi alterado.'], 409);
        }

        [$campanha] = $this->locais('anuncio', $objeto);
        $video = MetaAdsVideo::query()->where('meta_ad_id', $objeto['id'])->first();
        if ($campanha?->status === MetaAdsCampanha::STATUS_ENVIANDO || $video?->temEdicaoPendente()) {
            return response()->json(['message' => 'Este anúncio está sendo enviado ou editado agora. Espere terminar.'], 409);
        }

        try {
            if (! $request->boolean('sem_ativo') && $meta->unicoAtivo('anuncio', $objeto)) {
                $mensagem = 'É o último anúncio ativo do conjunto. Sem ele o conjunto para de entregar.';

                return response()->json(['message' => $mensagem, 'errors' => ['sem_ativo' => [$mensagem]], 'ultimo_ativo' => true], 422);
            }

            $meta->pausarObjeto($objeto);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'A Meta não pausou o anúncio: '.rtrim($erro->getMessage(), '.').'. O anúncio continua como estava.'], 422);
        }

        // Igual ao MetaAdsService::pausarAnuncio para o anúncio feito aqui.
        $video?->forceFill(['ativado_em' => null])->save();

        return response()->json(['message' => 'Criativo pausado na Meta.', 'ok' => true]);
    }

    /**
     * Troca o link de destino de um anúncio direto na tabela (pedido do Lira
     * 2026-10-21). Criativo novo com o link novo; o anúncio volta para a
     * análise da Meta, por isso pede confirmação. Anúncio do KazaKora passa
     * pela mesma edição do modal (edicao_pendente + avancarEdicao), para o
     * link ficar gravado aqui também; o do Gerenciador muda só na Meta.
     */
    public function link(Request $request, string $metaId, MetaAdsService $meta): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'link' => ['required', 'string', 'max:2048', 'url:https'],
            'confirmo_analise' => ['boolean'],
        ], [
            'link.required' => 'Cole o link de destino.',
            'link.url' => 'Use um link completo começando com https://',
        ]);
        $validador->after(function ($v) use ($request) {
            $host = (string) parse_url((string) $request->input('link'), PHP_URL_HOST);
            if (preg_match('/(^|\.)kazakora\.com\.br$|devlira\.com\.br$/i', $host)) {
                $v->errors()->add('link', 'Esse endereço não é o da loja no ar. Use https://kazakora.com/...');
            }
        });
        if ($validador->fails()) {
            return $this->erroDeCampo($validador->errors()->toArray());
        }
        if (! $request->boolean('confirmo_analise')) {
            return $this->erroDeCampo(['confirmo_analise' => ['Confirme que o anúncio volta para a análise da Meta.']]);
        }

        if ($resposta = $this->semMeta($meta)) {
            return $resposta;
        }

        try {
            $objeto = $meta->objeto('anuncio', $metaId);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'Não deu para ler o anúncio na Meta: '.$erro->getMessage()], 422);
        }

        $link = trim((string) $request->input('link'));
        [$campanha] = $this->locais('anuncio', $objeto);
        $video = MetaAdsVideo::query()->where('meta_ad_id', $objeto['id'])->first();

        if ($campanha?->status === MetaAdsCampanha::STATUS_ENVIANDO || $video?->temEdicaoPendente()) {
            return response()->json(['message' => 'Este anúncio está sendo enviado ou editado agora. Espere terminar.'], 409);
        }

        try {
            if ($video && $campanha) {
                if ($video->criativo($campanha)['link'] === $link) {
                    return response()->json(['message' => 'O link já é esse.', 'ok' => true]);
                }
                $video->forceFill(['edicao_pendente' => [
                    'campos' => ['link_destino' => $link, 'produto_id' => $this->produtoDoLink($link) ?? $video->produto_id],
                    'nome' => null,
                    'muda_criativo' => true,
                    'midia' => null,
                ]])->save();
                $meta->avancarEdicao($campanha, $video);
                $meta->esquecerStatusAoVivo();
            } else {
                $meta->trocarLinkDoAnuncio($objeto, $link);
            }
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'A Meta recusou: '.rtrim($erro->getMessage(), '.').'. Nada foi alterado.'], 422);
        }

        return response()->json(['message' => 'Link trocado na Meta. O anúncio volta para a análise antes de rodar de novo.', 'ok' => true, 'link' => $link]);
    }

    /**
     * Status real na Meta, para a tela atualizar sozinha (pedido do Lira
     * 2026-10-21). Devolve o effective_status de tudo da conta e o status
     * das campanhas montadas no KazaKora; campanha já enviada acompanha a
     * Meta (ativada ou pausada no Gerenciador muda aqui também).
     */
    public function status(MetaAdsService $meta): JsonResponse
    {
        $naMeta = null;
        $erro = null;
        if ($meta->configurado()) {
            try {
                $naMeta = $meta->statusAoVivo();
            } catch (MetaAdsException $e) {
                $erro = $e->getMessage();
            }
        }

        $locais = MetaAdsCampanha::query()->get()->map(function (MetaAdsCampanha $c) use ($naMeta) {
            $daMeta = filled($c->meta_campaign_id) ? ($naMeta['campanhas'][(string) $c->meta_campaign_id] ?? null) : null;

            if ($daMeta !== null && in_array($c->status, [MetaAdsCampanha::STATUS_ATIVA, MetaAdsCampanha::STATUS_ENVIADA_PAUSADA], true)) {
                $acompanha = match ($daMeta) {
                    'ACTIVE' => MetaAdsCampanha::STATUS_ATIVA,
                    'PAUSED' => MetaAdsCampanha::STATUS_ENVIADA_PAUSADA,
                    default => $c->status,
                };
                if ($acompanha !== $c->status) {
                    $c->forceFill(['status' => $acompanha])->save();
                }
            }

            return [
                'id' => $c->id,
                'status' => $c->status,
                'status_rotulo' => $c->status_rotulo,
                'status_meta' => $daMeta,
                'ultimo_erro' => $c->ultimo_erro,
            ];
        });

        return response()->json([
            'meta' => $naMeta,
            'erro' => $erro,
            'locais' => $locais,
        ]);
    }

    // ------------------------------------------------------------------------

    /**
     * Registros do KazaKora ligados ao item da Meta (podem não existir: item
     * criado direto no Gerenciador).
     *
     * @param  array{id: string, campanha_id: ?string}  $objeto
     * @return array{0: ?MetaAdsCampanha, 1: ?MetaAdsConjunto, 2: ?MetaAdsVideo}
     */
    private function locais(string $nivel, array $objeto): array
    {
        $campanha = filled($objeto['campanha_id'])
            ? MetaAdsCampanha::query()->where('meta_campaign_id', $objeto['campanha_id'])->with(['conjuntos', 'videos'])->first()
            : null;

        return [
            $campanha,
            $nivel === 'conjunto' ? $campanha?->conjuntos->firstWhere('meta_adset_id', $objeto['id']) : null,
            $nivel === 'anuncio' ? MetaAdsVideo::query()->where('meta_ad_id', $objeto['id'])->first() : null,
        ];
    }

    private function bloqueio(string $nivel, ?MetaAdsCampanha $campanha, ?MetaAdsConjunto $conjunto, ?MetaAdsVideo $video): ?string
    {
        if ($nivel === 'anuncio' && $video) {
            return 'Este anúncio nasceu no KazaKora: use o Editar e o Excluir do próprio anúncio.';
        }

        if ($campanha?->status === MetaAdsCampanha::STATUS_ENVIANDO) {
            return 'A campanha está sendo enviada para a Meta. Espere terminar.';
        }

        $conjuntos = $nivel === 'campanha' ? ($campanha?->conjuntos ?? collect()) : collect([$conjunto])->filter();
        if ($conjuntos->contains(fn (MetaAdsConjunto $c) => $c->enviando)) {
            return 'Tem conjunto sendo enviado para a Meta. Espere terminar.';
        }

        $videos = ($campanha?->videos ?? collect())->when($nivel === 'conjunto', fn ($v) => $v->where('conjunto_id', $conjunto?->id));
        if ($nivel !== 'anuncio' && $videos->contains(fn (MetaAdsVideo $v) => $v->temEdicaoPendente())) {
            return 'A Meta está processando o arquivo novo de um anúncio. Espere terminar.';
        }

        return null;
    }

    /**
     * Anúncios que saem junto com a campanha ou o conjunto: arquivo apagado;
     * o que foi para a Meta fica marcado como excluído, o resto sai de vez.
     *
     * @param  iterable<MetaAdsVideo>  $videos
     */
    private function excluirAnuncios(iterable $videos): void
    {
        foreach ($videos as $video) {
            Storage::disk('local')->delete(array_values(array_filter([$video->path, $video->path_vertical])));

            if ($video->temObjetoNoMeta()) {
                $video->forceFill(['ativado_em' => null])->save();
                $video->delete();
            } else {
                $video->forceDelete();
            }
        }
    }

    /** Orçamento por conjunto: o diário da campanha é a soma (igual ao MetaAdsController). */
    private function somarOrcamentoDosConjuntos(?MetaAdsCampanha $campanha): void
    {
        if (! $campanha || $campanha->orcamentoDaCampanha()) {
            return;
        }

        $campanha->forceFill(['orcamento_diario' => round((float) $campanha->conjuntos()->sum('orcamento_diario'), 2)])->save();
    }

    /** Produto da loja pelo link (https://kazakora.com/produtos/slug), como na sincronização. */
    private function produtoDoLink(string $link): ?int
    {
        return preg_match('#^https?://(www\.)?kazakora\.com/produtos/([^/?\#]+)#', $link, $m)
            ? Product::query()->where('slug', $m[2])->value('id')
            : null;
    }

    private function semMeta(MetaAdsService $meta): ?JsonResponse
    {
        return $meta->configurado() ? null : response()->json(['message' => 'Meta não configurada no servidor.'], 409);
    }

    /** @param  array<string, list<string>>  $erros */
    private function erroDeCampo(array $erros): JsonResponse
    {
        return response()->json(['message' => collect($erros)->flatten()->first(), 'errors' => $erros], 422);
    }
}
