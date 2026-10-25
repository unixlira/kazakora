<?php

namespace App\Modules\Admin\Support\Publicidade;

/**
 * Contrato de cada plataforma na Central de campanhas (pedido do Lira em
 * 2026-10-23): /admin/publicidade/campanhas junta Meta ADS e Google ADS numa
 * lista só. Cada plataforma devolve as campanhas já no formato comum
 * (linha()), então ligar o Google é criar a fonte dele e registrar em
 * CentralDeCampanhas::FONTES, sem mexer na tela.
 *
 * Só leitura: nenhuma fonte pausa, muda orçamento ou cria campanha.
 */
interface FonteDeCampanhas
{
    /** Canal de PlataformaDeAnuncio (meta_ads, google_ads). */
    public function canal(): string;

    /** Tem acesso configurado para ler a API da plataforma. */
    public function conectada(): bool;

    /**
     * Campanhas do período, cada uma no formato de CentralDeCampanhas::linha().
     *
     * @param  string  $periodo  chave de CentralDeCampanhas::PERIODOS
     * @return list<array<string, mixed>>
     *
     * @throws FonteIndisponivel
     */
    public function campanhas(string $periodo): array;

    /**
     * Alertas da própria plataforma (diagnóstico), já em linguagem de ação.
     *
     * @return list<array{prioridade: string, titulo: string, alvo: string, acao: string, prova: string, link: ?string}>
     *
     * @throws FonteIndisponivel
     */
    public function alertas(): array;
}
