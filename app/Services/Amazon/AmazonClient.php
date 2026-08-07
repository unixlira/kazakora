<?php

namespace App\Services\Amazon;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chamadas autenticadas da Selling Partner API. Desde a remoção do
 * requisito de assinatura AWS SigV4 pras operações padrão (out/2023), só o
 * header `x-amz-access-token` (token LWA) é necessário — ver
 * AmazonAuthService. A única exceção real é dado pessoal do comprador
 * (nome/endereço/CPF), que exige um Restricted Data Token (RDT) de uso
 * único no lugar do access_token normal — ver restrictedGet()/restrictedPost().
 */
class AmazonClient
{
    public function __construct(private readonly AmazonAuthService $auth) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->get($path, $query);

        return $this->handleResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->post($path, $data);

        return $this->handleResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function patch(string $path, array $data = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->patch($path, $data);

        return $this->handleResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function put(string $path, array $data = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->put($path, $data);

        return $this->handleResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->delete($path, $query);

        return $this->handleResponse($path, $response);
    }

    /**
     * Dado pessoal do comprador (nome/endereço completo/CPF-CNPJ) só é
     * liberado com um Restricted Data Token (RDT, Tokens API), token de uso
     * único/curta duração emitido ESPECIFICAMENTE pro path+dataElements
     * pedidos — não dá pra usar o access_token normal pra isso, a API
     * devolve o valor mascarado ("Buyer info requested").
     *
     * @param  array<int, string>  $dataElements  ex: ['buyerInfo', 'shippingAddress']
     * @return array<string, mixed>
     */
    public function restrictedGet(string $path, array $dataElements, array $query = []): array
    {
        $account = $this->authenticatedAccount();

        $tokenResponse = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $account->access_token])
            ->post('/tokens/2021-03-01/restrictedDataToken', [
                'restrictedResources' => [
                    ['method' => 'GET', 'path' => $path, 'dataElements' => $dataElements],
                ],
            ]);

        $rdt = $this->handleResponse('/tokens/2021-03-01/restrictedDataToken', $tokenResponse)['restrictedDataToken'] ?? null;

        if (! $rdt) {
            throw new AmazonException('Amazon não devolveu um Restricted Data Token válido.', $tokenResponse->status(), ['body' => $tokenResponse->json()]);
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders(['x-amz-access-token' => $rdt])
            ->get($path, $query);

        return $this->handleResponse($path, $response);
    }

    /**
     * Feeds API (usado pra POST_INVOICE_CONFIRMATION — upload da NF-e) e
     * Reports API seguem o mesmo padrão de 2 passos: cria um "documento"
     * (devolve um id + uma URL pré-assinada da Amazon pra upload) e depois
     * faz o PUT do conteúdo direto nessa URL (fora da SP-API em si, sem
     * header de auth da Amazon — a URL já carrega a autorização).
     *
     * @return array{feedDocumentId: string, url: string}
     */
    public function createFeedDocument(string $contentType): array
    {
        $document = $this->post('/feeds/2021-06-30/documents', ['contentType' => $contentType]);

        return [
            'feedDocumentId' => $document['feedDocumentId'],
            'url' => $document['url'],
        ];
    }

    public function uploadFeedDocument(string $uploadUrl, string $contents, string $contentType): void
    {
        $response = Http::withBody($contents, $contentType)->put($uploadUrl);

        if ($response->failed()) {
            throw new AmazonException('Falha ao enviar o arquivo do feed pra Amazon.', $response->status(), ['body' => $response->body()]);
        }
    }

    /**
     * Baixa e devolve o conteúdo bruto de um feedDocumentId/reportDocumentId
     * já existente (a URL de download muda a cada chamada a getFeedDocument,
     * então sempre resolve de novo em vez de cachear a URL).
     */
    public function downloadDocument(string $documentPath): string
    {
        $document = $this->get($documentPath);
        $url = $document['url'] ?? null;

        if (! $url) {
            throw new AmazonException('Amazon não devolveu uma URL de download pro documento.', 0, ['body' => $document]);
        }

        $response = Http::get($url);

        if ($response->failed()) {
            throw new AmazonException('Falha ao baixar o documento da Amazon.', $response->status());
        }

        return $response->body();
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.amazon.sp_api_base_url'), '/');
    }

    private function authenticatedAccount(): MarketplaceAccount
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->first();

        if (! $account) {
            throw new AmazonException('Nenhuma conta da Amazon está conectada.');
        }

        return $this->auth->ensureValidToken($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(string $path, Response $response): array
    {
        Log::channel('amazon')->info('amazon.request', ['path' => $path, 'status' => $response->status()]);

        $json = $response->json() ?? [];

        if ($response->failed() || ! empty($json['errors'])) {
            $message = $json['errors'][0]['message'] ?? "Erro na API da Amazon (HTTP {$response->status()}).";

            throw new AmazonException($message, $response->status(), ['body' => $json]);
        }

        return $json;
    }
}
