<?php

namespace App\Modules\Admin\Http\Requests;

use App\Support\Rbac\Permissions;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class VideoDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Permissions::has($this->user(), Permissions::OPERACIONAL_CREATE);
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Cole a URL do vídeo antes de baixar.',
            'url.url' => 'Cole uma URL válida de YouTube, TikTok, Facebook ou Instagram.',
            'url.max' => 'A URL informada é longa demais.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $url = (string) $this->input('url');
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $host = $this->normalizedHost($url);

                if (! in_array($scheme, ['http', 'https'], true) || $host === null) {
                    $validator->errors()->add('url', 'Cole uma URL pública começando com http ou https.');
                    return;
                }

                if (! $this->isAllowedHost($host)) {
                    $validator->errors()->add('url', 'Este site não é suportado. Use YouTube, TikTok, Facebook ou Instagram.');
                }
            },
        ];
    }

    /**
     * A prévia é chamada por fetch e lê errors.url do 422. Fora de /api o app
     * responde validação com redirect (bootstrap/app.php), então o JSON sai
     * daqui.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function normalizedHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(trim($host, '.'));
    }

    private function isAllowedHost(string $host): bool
    {
        foreach ((array) config('video-downloads.allowed_hosts', []) as $allowedHost) {
            $allowedHost = strtolower((string) $allowedHost);

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }
}
