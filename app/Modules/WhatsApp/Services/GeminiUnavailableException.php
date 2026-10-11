<?php

namespace App\Modules\WhatsApp\Services;

use RuntimeException;

/** Gemini sobrecarregado ou fora do ar (429/5xx): vale tentar de novo. */
class GeminiUnavailableException extends RuntimeException
{
}
