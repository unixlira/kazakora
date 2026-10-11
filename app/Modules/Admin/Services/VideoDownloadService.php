<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Data\DownloadedVideo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class VideoDownloadService
{
    public function download(string $url, ?User $user = null): DownloadedVideo
    {
        $directory = $this->createTemporaryDirectory();
        $command = $this->downloadCommand($url, $directory);

        try {
            $process = new Process($command);
            $process->setTimeout((int) config('video-downloads.timeout_seconds', 180));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->friendlyProcessError($process->getErrorOutput() ?: $process->getOutput()));
            }

            $path = $this->resolveDownloadedPath($directory, $process->getOutput());
            $this->ensureAllowedFileSize($path);

            Log::info('Video downloaded from admin tool.', [
                'user_id' => $user?->id,
                'host' => parse_url($url, PHP_URL_HOST),
                'filename' => basename($path),
                'size' => File::size($path),
            ]);

            return new DownloadedVideo(
                path: $path,
                filename: $this->safeFilename($path),
                directory: $directory,
                mimeType: File::mimeType($path) ?: 'video/mp4',
            );
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    private function downloadCommand(string $url, string $directory): array
    {
        $command = [
            ...$this->binaryCommand(),
            '--ignore-config',
            '--no-playlist',
            '--no-cache-dir',
            '--restrict-filenames',
            '--windows-filenames',
            '--merge-output-format',
            'mp4',
        ];

        if ($ffmpeg = $this->configuredExecutable('video-downloads.ffmpeg_binary')) {
            $command = [...$command, '--ffmpeg-location', $ffmpeg];
        }

        foreach ($this->configuredJsRuntimes() as $jsRuntime) {
            $command = [...$command, '--js-runtimes', $jsRuntime];
        }

        // YouTube barra download anônimo de servidor ("confirme que não é
        // um robô"): cookies de uma conta logada + o provedor de PO token
        // (bgutil) instalados na hospedagem. Os dois são opcionais — sem
        // eles, TikTok/Instagram/Facebook continuam funcionando.
        $cookies = config('video-downloads.cookies_path');

        if (is_string($cookies) && $cookies !== '' && is_readable($cookies)) {
            $command = [...$command, '--cookies', $cookies];
        }

        $potHome = config('video-downloads.youtube_pot_provider_home');

        if (is_string($potHome) && $potHome !== '' && is_dir($potHome)) {
            $command = [...$command, '--extractor-args', 'youtubepot-bgutilscript:server_home='.$potHome];
        }

        return [
            ...$command,
            '-f',
            'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/best',
            '--max-filesize',
            (string) config('video-downloads.max_filesize', '300M'),
            '--force-ipv4',
            '--socket-timeout',
            '20',
            '--retries',
            '2',
            '--extractor-retries',
            '2',
            '--fragment-retries',
            '2',
            '--print',
            'after_move:filepath',
            '-o',
            $directory.'/%(title).100s-%(id)s.%(ext)s',
            $url,
        ];
    }

    private function binaryCommand(): array
    {
        $configured = config('video-downloads.binary');

        if (is_string($configured) && $configured !== '') {
            if (is_executable($configured)) {
                return [$configured];
            }

            Log::warning('Configured yt-dlp binary is not executable; falling back to PATH lookup.', [
                'binary' => $configured,
            ]);
        }

        $finder = new ExecutableFinder();

        if ($ytDlp = $finder->find('yt-dlp')) {
            return [$ytDlp];
        }

        if ($youtubeDl = $finder->find('youtube-dl')) {
            return [$youtubeDl];
        }

        if ($python = $finder->find('python3')) {
            $probe = new Process([$python, '-m', 'yt_dlp', '--version']);
            $probe->setTimeout(10);
            $probe->run();

            if ($probe->isSuccessful()) {
                return [$python, '-m', 'yt_dlp'];
            }
        }

        throw new RuntimeException('O recurso de download ainda não está configurado no servidor. Instale yt-dlp e ffmpeg.');
    }

    private function configuredExecutable(string $configKey): ?string
    {
        $path = config($configKey);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (is_executable($path)) {
            return $path;
        }

        Log::warning('Configured video download executable is not available.', [
            'config_key' => $configKey,
            'path' => $path,
        ]);

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function configuredJsRuntimes(): array
    {
        $configured = config('video-downloads.js_runtimes');

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        $runtimes = [];

        foreach (array_filter(array_map('trim', explode(',', $configured))) as $runtime) {
            [$runtimeName, $runtimePath] = array_pad(explode(':', $runtime, 2), 2, null);

            if ($runtimeName && $runtimePath && is_executable($runtimePath)) {
                $runtimes[] = $runtime;
                continue;
            }

            Log::warning('Configured yt-dlp JavaScript runtime is not executable; running without this runtime.', [
                'runtime' => $runtime,
            ]);
        }

        return $runtimes;
    }

    private function createTemporaryDirectory(): string
    {
        $directory = rtrim((string) config('video-downloads.base_path'), '/').'/'.(string) Str::uuid();

        File::ensureDirectoryExists($directory, 0750, true);

        return $directory;
    }

    private function resolveDownloadedPath(string $directory, string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
        $candidates = [...$lines, ...File::files($directory)];
        $directoryRealPath = realpath($directory);

        foreach ($candidates as $candidate) {
            $path = is_object($candidate) && method_exists($candidate, 'getPathname') ? $candidate->getPathname() : (string) $candidate;

            if (! is_file($path)) {
                continue;
            }

            $realPath = realpath($path);

            if ($realPath && $directoryRealPath && str_starts_with($realPath, $directoryRealPath.DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
        }

        throw new RuntimeException('O vídeo foi processado, mas o arquivo final não foi encontrado.');
    }

    private function safeFilename(string $path): string
    {
        $filename = basename($path);
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $filename) ?: 'kazakora-video.mp4';

        return trim($filename, '.- ') ?: 'kazakora-video.mp4';
    }

    private function ensureAllowedFileSize(string $path): void
    {
        $limit = $this->sizeToBytes((string) config('video-downloads.max_filesize', '300M'));

        if ($limit > 0 && File::size($path) > $limit) {
            throw new RuntimeException('O vídeo excede o tamanho máximo permitido.');
        }
    }

    private function sizeToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(\d+)([KMG])?B?$/i', $value, $matches) !== 1) {
            return 0;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    private function friendlyProcessError(string $output): string
    {
        $normalized = Str::lower($output);

        return match (true) {
            str_contains($normalized, 'http error 429') || str_contains($normalized, 'too many requests') => 'A plataforma recusou temporariamente o servidor por excesso de requisições. Tente novamente mais tarde ou com outro link.',
            str_contains($normalized, 'confirm you') || str_contains($normalized, 'not a bot') => 'A plataforma exigiu verificação antirobô e recusou o download no servidor. Tente novamente mais tarde ou use outro link público.',
            str_contains($normalized, 'file is larger than max-filesize') => 'O vídeo excede o tamanho máximo permitido.',
            str_contains($normalized, 'private') || str_contains($normalized, 'login') || str_contains($normalized, 'sign in') => 'Não foi possível baixar este vídeo. Ele pode estar privado ou exigir login.',
            str_contains($normalized, 'unsupported url') => 'Este link não foi reconhecido pela ferramenta de download.',
            str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout') => 'O vídeo demorou demais para ser preparado. Tente novamente com um vídeo menor.',
            default => 'Não foi possível baixar este vídeo. Ele pode estar privado, removido ou bloqueado pela plataforma.',
        };
    }
}
