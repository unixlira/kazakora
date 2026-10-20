<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dados iniciais da squad Alfacora (pedido do Lira 2026-10-20): os 6
 * especialistas que rodam no Claude Code do PC do Lira, com slug igual ao
 * arquivo do agente (~/projects/homer/workspace/.claude/agents/<slug>.md).
 * Função, o que faz e skills tirados da descrição de cada agente.
 * Juliana responde para o Homer; Jonathan responde para a Juliana; os
 * outros, para o Homer. Não sobrescreve nada se a squad já existir.
 */
return new class extends Migration
{
    private const PRECISA_OK = ['preco', 'campanha', 'cliente', 'publicar', 'apagar'];

    public function up(): void
    {
        if (DB::table('squads')->where('slug', 'alfacora')->exists()) {
            return;
        }

        $agora = now();
        $squadId = DB::table('squads')->insertGetId([
            'nome' => 'Alfacora', 'slug' => 'alfacora', 'ativa' => true,
            'created_at' => $agora, 'updated_at' => $agora,
        ]);

        $membros = [
            [
                'nome' => 'Juliana', 'slug' => 'juliana-ops', 'area' => 'operacao',
                'funcao' => 'Sub-gerente operacional da operação KazaKora',
                'o_que_faz' => 'Organiza e executa as rotinas da operação (pedidos, estoque, etiquetas, cadastros), distribui tarefas entre os especialistas e consolida os resultados para o Homer.',
                'skills' => [
                    ['nome' => 'alpha-kora-business-operations', 'descricao' => 'Operação do KazaKora: site, marketplaces, KoraSync, fiscal e admin com mudança verificada.'],
                    ['nome' => 'document-format', 'descricao' => 'Tarefas, projetos e reuniões no formato da base de conhecimento.'],
                ],
            ],
            [
                'nome' => 'Cauê', 'slug' => 'caue-shopee', 'area' => 'shopee',
                'funcao' => 'Estrategista de Shopee: ADS, ROAS, preço e anúncios',
                'o_que_faz' => 'Lê as métricas da Shopee e entrega o plano de ação do dia: ADS e meta de ROAS, preço, qualidade de anúncio (imagens e título), promoções, avaliações, estoque e conversão, sempre pelo lucro.',
                'skills' => [
                    ['nome' => 'caue-shopee-manager', 'descricao' => 'Gestão da Shopee: ADS, preço, anúncios, promoções e diagnóstico estilo Seller Bump.'],
                    ['nome' => 'shopee-promotions-operations', 'descricao' => 'Ofertas Relâmpago e promoções com auditoria e simulação antes de mexer ao vivo.'],
                ],
            ],
            [
                'nome' => 'Diego', 'slug' => 'diego-mercado-livre', 'area' => 'mercado_livre',
                'funcao' => 'Especialista em Mercado Livre: promoções, Ads e preço',
                'o_que_faz' => 'Cuida da Central de Promoções, Mercado Ads, ROAS/ACOS, cupons, Oferta Relâmpago, preço competitivo e paridade entre canais, protegendo a margem.',
                'skills' => [
                    ['nome' => 'diego-mercado-livre-manager', 'descricao' => 'Promoções, Ads, cupons e preço competitivo no Mercado Livre com margem protegida.'],
                    ['nome' => 'ml-estrategista-promocoes-ads-precos', 'descricao' => 'Estratégia de campanhas, cupons, Ads e precificação no ML.'],
                    ['nome' => 'alpha-kora-business-operations', 'descricao' => 'Regras para ações reais no KazaKora.'],
                ],
            ],
            [
                'nome' => 'Rafael', 'slug' => 'rafael-market-radar', 'area' => 'mercado',
                'funcao' => 'Radar de mercado: concorrentes e faixa de preço',
                'o_que_faz' => 'Pesquisa preço de concorrentes, compara marketplaces, acha oportunidades de produto e monta a faixa de preço saudável pela margem de contribuição.',
                'skills' => [
                    ['nome' => 'rafael-market-radar', 'descricao' => 'Varredura de concorrentes, benchmarking e preço piso, saudável e teto.'],
                    ['nome' => 'alpha-kora-business-operations', 'descricao' => 'Regras para ações reais no KazaKora.'],
                ],
            ],
            [
                'nome' => 'Jonathan', 'slug' => 'jonathan-copy', 'area' => 'copy',
                'funcao' => 'Copywriter e UX: textos, títulos e páginas',
                'o_que_faz' => 'Escreve textos de anúncio, títulos, descrições, landing pages e mensagens no tom do Lira (direto, casual, levemente provocativo) e revisa a experiência das telas.',
                'skills' => [
                    ['nome' => 'taste-skill', 'descricao' => 'Direção visual sem cara de template para tela nova ou redesign.'],
                    ['nome' => 'awesome-design-md', 'descricao' => '74 sistemas de design prontos para pedidos "no estilo de".'],
                    ['nome' => 'image-to-code', 'descricao' => 'Transforma imagem, print ou mockup em página.'],
                    ['nome' => 'web-design-guidelines', 'descricao' => 'Revisão de UI, acessibilidade e UX.'],
                    ['nome' => 'playwright-cli', 'descricao' => 'Abre a página de verdade e confere com snapshot e print.'],
                ],
                'responde' => 'juliana-ops',
            ],
            [
                'nome' => 'Paulo', 'slug' => 'paulo-dev', 'area' => 'dev',
                'funcao' => 'Desenvolvedor: KazaKora, KoraSync, scripts e integrações',
                'o_que_faz' => 'Mexe no código do KazaKora (Laravel/Vue), KoraSync, scripts e integrações, seguindo o fluxo ramo, homolog, PR e main, com testes e evidência.',
                'skills' => [
                    ['nome' => 'taste-skill', 'descricao' => 'Direção visual sem cara de template para tela nova ou redesign.'],
                    ['nome' => 'awesome-design-md', 'descricao' => '74 sistemas de design prontos para pedidos "no estilo de".'],
                    ['nome' => 'image-to-code', 'descricao' => 'Transforma imagem, print ou mockup em página.'],
                    ['nome' => 'web-design-guidelines', 'descricao' => 'Revisão de UI, acessibilidade e UX.'],
                    ['nome' => 'playwright-cli', 'descricao' => 'Abre a página de verdade e confere com snapshot e print.'],
                ],
            ],
        ];

        $ids = [];
        foreach ($membros as $ordem => $m) {
            $ids[$m['slug']] = DB::table('squad_membros')->insertGetId([
                'squad_id' => $squadId,
                'nome' => $m['nome'],
                'slug' => $m['slug'],
                'funcao' => $m['funcao'],
                'area' => $m['area'],
                'o_que_faz' => $m['o_que_faz'],
                'skills' => json_encode($m['skills'], JSON_UNESCAPED_UNICODE),
                'responde_para_id' => isset($m['responde']) ? ($ids[$m['responde']] ?? null) : null,
                'precisa_ok' => json_encode(self::PRECISA_OK),
                'status' => 'ativo',
                'ordem' => $ordem + 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        $squad = DB::table('squads')->where('slug', 'alfacora')->first();
        if ($squad) {
            DB::table('squad_membros')->where('squad_id', $squad->id)->update(['responde_para_id' => null]);
            DB::table('squad_membros')->where('squad_id', $squad->id)->delete();
            DB::table('squads')->where('id', $squad->id)->delete();
        }
    }
};
