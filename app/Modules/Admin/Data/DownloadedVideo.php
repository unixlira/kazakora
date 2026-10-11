<?php

namespace App\Modules\Admin\Data;

final class DownloadedVideo
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly string $directory,
        public readonly ?string $mimeType = null,
    ) {}
}
