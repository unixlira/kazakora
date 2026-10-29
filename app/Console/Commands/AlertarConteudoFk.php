<?php

namespace App\Console\Commands;

use App\Modules\Admin\Support\AlertasAdmin;
use Illuminate\Console\Command;

/**
 * A rotina de conteúdo da FerraKora (roda no PC do Homer, fora do Laravel)
 * avisa no painel quando dá erro (pedido do Lira em 2026-10-29). Abre o
 * alerta conteudo_fk da chave; com --resolver fecha o aberto da mesma chave.
 */
class AlertarConteudoFk extends Command
{
    protected $signature = 'alertas:conteudo-fk
        {chave : identifica a falha, ex.: instagram, tiktok, facebook, geracao}
        {titulo? : título do alerta (obrigatório sem --resolver)}
        {mensagem? : o que deu errado (obrigatório sem --resolver)}
        {--resolver : fecha o alerta aberto desta chave}';

    protected $description = 'Abre ou resolve o alerta de falha do conteúdo automático da FerraKora';

    public function handle(AlertasAdmin $alertas): int
    {
        $chave = trim((string) $this->argument('chave'));

        if ($chave === '') {
            $this->error('Informe a chave.');

            return self::FAILURE;
        }

        if ($this->option('resolver')) {
            $fechados = $alertas->resolver(AlertasAdmin::CONTEUDO_FK, $chave, 'conteudo-fk');
            $this->info("{$fechados} alerta(s) resolvido(s) para a chave {$chave}.");

            return self::SUCCESS;
        }

        $titulo = trim((string) $this->argument('titulo'));
        $mensagem = trim((string) $this->argument('mensagem'));

        if ($titulo === '' || $mensagem === '') {
            $this->error('Informe título e mensagem (ou use --resolver).');

            return self::FAILURE;
        }

        $alerta = $alertas->disparar(AlertasAdmin::CONTEUDO_FK, $chave, $titulo, $mensagem);

        if (! $alerta) {
            $this->warn('Alerta conteudo_fk desligado em Sistema › Alertas: nada aberto.');

            return self::SUCCESS;
        }

        $this->info("Alerta #{$alerta->id} aberto.");

        return self::SUCCESS;
    }
}
