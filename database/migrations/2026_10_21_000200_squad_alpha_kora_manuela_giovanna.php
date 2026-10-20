<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correção do Lira (2026-10-20): o nome certo da squad é "Alpha Kora" (com K),
 * não "Alfacora", e faltavam a Manuela (atendente de WhatsApp da loja) e a
 * Giovanna (criação: banners, imagens e logos; foco em Shopee e TikTok).
 * Renomeia a squad criada pela migration anterior (slug vira alpha-kora) e
 * inclui as duas se ainda não existirem. Não mexe nos outros membros.
 */
return new class extends Migration
{
    private const PRECISA_OK = ['preco', 'campanha', 'cliente', 'publicar', 'apagar'];

    public function up(): void
    {
        $agora = now();
        $squad = DB::table('squads')->whereIn('slug', ['alfacora', 'alpha-kora'])->orderByRaw("slug = 'alpha-kora' desc")->first();
        if (! $squad) {
            return;
        }

        DB::table('squads')->where('id', $squad->id)->update([
            'nome' => 'Alpha Kora', 'slug' => 'alpha-kora', 'updated_at' => $agora,
        ]);

        $julianaId = DB::table('squad_membros')->where('slug', 'juliana-ops')->value('id');
        $ordem = (int) DB::table('squad_membros')->where('squad_id', $squad->id)->max('ordem');

        $membros = [
            [
                'nome' => 'Manuela', 'slug' => 'manuela-atendimento', 'area' => 'atendimento',
                'funcao' => 'Atendente de WhatsApp da loja',
                'o_que_faz' => 'Atende os clientes da KazaKora no WhatsApp (enxerga a foto do produto), pede desculpas e retoma quando houver demora, chama alguém do time quando precisa e manda os avisos da operação para o Lira.',
                'skills' => [
                    ['nome' => 'atendimento-whatsapp', 'descricao' => 'Atendimento da loja no WhatsApp com foto do produto e passagem para humano.'],
                    ['nome' => 'avisos-ao-lira', 'descricao' => 'Avisa o Lira no WhatsApp quando uma rotina da operação falha.'],
                ],
                'responde_para_id' => $julianaId,
            ],
            [
                'nome' => 'Giovanna', 'slug' => 'giovanna-criacao', 'area' => 'criacao',
                'funcao' => 'Criação: banners, imagens e logos',
                'o_que_faz' => 'Cria banners, imagens de anúncio, logos e vídeos curtos das lojas, com foco em Shopee e TikTok, e ajuda a estruturar Amazon e Mercado Livre.',
                'skills' => [
                    ['nome' => 'taste-skill', 'descricao' => 'Direção visual sem cara de template para tela nova ou redesign.'],
                    ['nome' => 'awesome-design-md', 'descricao' => '74 sistemas de design prontos para pedidos "no estilo de".'],
                    ['nome' => 'alpha-kora-business-operations', 'descricao' => 'Regras para ações reais no KazaKora.'],
                ],
                'responde_para_id' => $julianaId,
            ],
        ];

        foreach ($membros as $m) {
            if (DB::table('squad_membros')->where('slug', $m['slug'])->exists()) {
                continue;
            }
            DB::table('squad_membros')->insert([
                'squad_id' => $squad->id,
                'nome' => $m['nome'],
                'slug' => $m['slug'],
                'funcao' => $m['funcao'],
                'area' => $m['area'],
                'o_que_faz' => $m['o_que_faz'],
                'skills' => json_encode($m['skills'], JSON_UNESCAPED_UNICODE),
                'responde_para_id' => $m['responde_para_id'],
                'precisa_ok' => json_encode(self::PRECISA_OK),
                'status' => 'ativo',
                'ordem' => ++$ordem,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('squad_membros')->whereIn('slug', ['manuela-atendimento', 'giovanna-criacao'])->delete();
        DB::table('squads')->where('slug', 'alpha-kora')->update(['nome' => 'Alfacora', 'slug' => 'alfacora']);
    }
};
