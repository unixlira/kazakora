<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Console\Command;

/** Marca o pacote como falho quando o ChatGPT da Cora Box não conseguiu gerar (worker do Homer). */
class FotosAnuncioFalha extends Command
{
    protected $signature = 'fotos-anuncio:falha {uuid} {mensagem}';

    protected $description = 'Marca o pacote de fotos de anúncio como falho, com o motivo';

    public function handle(): int
    {
        $brief = MarketplaceAdPhotoBrief::query()->where('uuid', $this->argument('uuid'))->first();

        if (! $brief) {
            $this->error('Pacote não encontrado.');

            return self::FAILURE;
        }

        $brief->marcarFalha((string) $this->argument('mensagem'));
        $this->info('Pacote marcado como falho.');

        return self::SUCCESS;
    }
}
