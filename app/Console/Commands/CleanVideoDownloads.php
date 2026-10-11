<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class CleanVideoDownloads extends Command
{
    protected $signature = 'video-downloads:clean';

    protected $description = 'Remove arquivos temporários do módulo Admin > Download vídeos.';

    public function handle(): int
    {
        $basePath = (string) config('video-downloads.base_path');
        $ttlMinutes = (int) config('video-downloads.cleanup_ttl_minutes', 120);
        $cutoff = Carbon::now()->subMinutes($ttlMinutes)->getTimestamp();
        $removed = 0;

        if (! is_dir($basePath)) {
            $this->info('Nenhum diretório temporário encontrado.');
            return self::SUCCESS;
        }

        foreach (File::directories($basePath) as $directory) {
            if (File::lastModified($directory) <= $cutoff) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        $this->info("Diretórios removidos: {$removed}");

        return self::SUCCESS;
    }
}
