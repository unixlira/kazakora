<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Guarda a arte que o ChatGPT da Cora Box gerou para um item do pacote (worker do Homer). */
class FotosAnuncioAnexar extends Command
{
    protected $signature = 'fotos-anuncio:anexar {uuid} {chave} {arquivo : PNG/JPG/WebP gerado, no servidor}';

    protected $description = 'Anexa a imagem gerada ao item do pacote de fotos de anúncio';

    public function handle(): int
    {
        $brief = MarketplaceAdPhotoBrief::query()->where('uuid', $this->argument('uuid'))->first();
        $arquivo = (string) $this->argument('arquivo');

        if (! $brief) {
            $this->error('Pacote não encontrado.');

            return self::FAILURE;
        }

        $info = is_file($arquivo) ? @getimagesize($arquivo) : false;

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            $this->error('Arquivo ausente ou não é PNG/JPG/WebP.');

            return self::FAILURE;
        }

        $extensao = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $chave = (string) $this->argument('chave');
        $caminho = "marketplace-ad-photos/{$brief->uuid}/{$chave}.{$extensao}";

        Storage::disk('public')->put($caminho, file_get_contents($arquivo));

        try {
            $brief->anexarImagem($chave, $caminho, Storage::disk('public')->url($caminho));
        } catch (\InvalidArgumentException $exception) {
            Storage::disk('public')->delete($caminho);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $brief->refresh();
        $this->info("{$chave} anexada ({$brief->generation_progress_label}), pacote {$brief->status}.");

        return self::SUCCESS;
    }
}
