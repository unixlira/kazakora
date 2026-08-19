<?php

namespace App\Services\Correios;

use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\Exceptions\CorreiosException;
use App\Services\Correios\Exceptions\CorreiosNotConfiguredException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cria a pré-postagem de verdade na API dos Correios (POST /v1/prepostagens
 * — schema confirmado em apihom.correios.com.br/prepostagem/v3/api-docs,
 * não documentação secundária). O QR Code do "atendimento" mostrado pelo
 * app/site oficial dos Correios não tem endpoint público equivalente na
 * API — é gerado dentro da própria plataforma deles a partir do protocolo.
 * Por isso o QR que esta loja imprime é gerado aqui mesmo (no navegador,
 * ver resources/js/vendor/qrcode-generator.mjs), codificando o
 * `correios_id`/código de rastreio — o mesmo dado que o atendente da
 * agência usaria pra localizar a pré-postagem informando "o número do
 * lote", só que como QR em vez de texto (ver CorreiosController).
 *
 * @see https://www.correios.com.br/atendimento/developers/manuais/manual-para-integracao-correios-api
 */
class CorreiosPrePostagemService
{
    // Envelope(1)/Caixa ou Pacote(2)/Rolo ou Cilindro(3) — ver
    // codigoFormatoObjetoInformado no schema.
    public const FORMATO_ENVELOPE = '1';

    public const FORMATO_CAIXA = '2';

    public const FORMATO_ROLO = '3';

    public function __construct(private readonly CorreiosTokenService $tokenService)
    {
    }

    public function isConfigured(): bool
    {
        return $this->tokenService->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $input  Shape esperada, ver CorreiosController::store():
     *   customer: [name, document, phone, email]
     *   address: [zip, street, number, complement, neighborhood, city, state]
     *   service_code, service_label, weight_grams
     *   dimensions: [format, height, width, length, diameter] (cm)
     *   content_items: [[conteudo, quantidade, valor], ...]
     * @return array<string, mixed> resposta crua da API (PrePostagem)
     */
    public function create(array $input): array
    {
        if (! $this->isConfigured()) {
            throw new CorreiosNotConfiguredException(
                'Credenciais dos Correios não configuradas — defina CORREIOS_NUMERO_USUARIO e CORREIOS_CODIGO_ACESSO no .env.'
            );
        }

        $payload = [
            'remetente' => $this->buildRemetente(),
            'destinatario' => $this->buildDestinatario($input['customer'], $input['address']),
            'codigoServico' => $input['service_code'],
            'pesoInformado' => (string) $input['weight_grams'],
            // Obrigatório no schema real (confirmado em
            // apihom.correios.com.br/prepostagem/v3/api-docs, campo estava
            // faltando até 2026-08-11) — "1" = objeto permitido no fluxo
            // postal. Loja só vende produtos lícitos, então é sempre "1"
            // aqui, não um campo que o usuário preenche na tela.
            'cienteObjetoNaoProibido' => '1',
            'itensDeclaracaoConteudo' => collect($input['content_items'])->map(fn (array $item) => [
                'conteudo' => $item['conteudo'],
                'quantidade' => (string) $item['quantidade'],
                'valor' => number_format((float) $item['valor'], 2, '.', ''),
            ])->all(),
            ...$this->buildDimensions($input['dimensions'] ?? []),
        ];

        $token = $this->tokenService->tokenForPrePostagem();

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post(rtrim((string) config('services.correios.api_base_url'), '/').'/v1/prepostagens', $payload);

        Log::channel('correios')->info('correios.prepostagem_request', [
            'status' => $response->status(),
            'codigoServico' => $payload['codigoServico'],
        ]);

        if ($response->failed()) {
            Log::channel('correios')->error('correios.prepostagem_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new CorreiosException($this->extractErrorMessage($response), $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRemetente(): array
    {
        $company = Company::query()->first();

        if (! $company) {
            throw new CorreiosException('Cadastre os dados da empresa (Admin > Empresa) antes de gerar uma pré-postagem — o remetente vem de lá.');
        }

        [$ddd, $telefone] = $this->splitPhone($company->phone);

        return [
            'nome' => $company->nome_fantasia ?: $company->razao_social,
            'cpfCnpj' => $this->onlyDigits($company->cnpj),
            'dddTelefone' => $ddd,
            'telefone' => $telefone,
            'email' => $company->email,
            'endereco' => [
                'cep' => $this->onlyDigits($company->zip),
                'logradouro' => $company->street,
                'numero' => $company->number,
                'complemento' => $company->complement,
                'bairro' => $company->neighborhood,
                'cidade' => $company->city,
                'uf' => $company->state,
                'pais' => 'Brasil',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function buildDestinatario(array $customer, array $address): array
    {
        [$ddd, $telefone] = $this->splitPhone($customer['phone'] ?? null);

        return [
            'nome' => $customer['name'],
            'cpfCnpj' => $this->onlyDigits($customer['document'] ?? null),
            'dddTelefone' => $ddd,
            'telefone' => $telefone,
            'email' => $customer['email'] ?? null,
            'endereco' => [
                'cep' => $this->onlyDigits($address['zip']),
                'logradouro' => $address['street'],
                'numero' => $address['number'],
                'complemento' => $address['complement'] ?? null,
                'bairro' => $address['neighborhood'],
                'cidade' => $address['city'],
                'uf' => $address['state'],
                'pais' => 'Brasil',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    private function buildDimensions(array $dimensions): array
    {
        $format = $dimensions['format'] ?? self::FORMATO_CAIXA;

        $fields = ['codigoFormatoObjetoInformado' => $format];

        if ($format === self::FORMATO_ROLO) {
            $fields['diametroInformado'] = (string) ($dimensions['diameter'] ?? '');
            $fields['comprimentoInformado'] = (string) ($dimensions['length'] ?? '');
        } elseif ($format === self::FORMATO_CAIXA) {
            $fields['alturaInformada'] = (string) ($dimensions['height'] ?? '');
            $fields['larguraInformada'] = (string) ($dimensions['width'] ?? '');
            $fields['comprimentoInformado'] = (string) ($dimensions['length'] ?? '');
        }

        return $fields;
    }

    private function extractErrorMessage(Response $response): string
    {
        $body = $response->json();

        $message = $body['msgs'][0]
            ?? $body['mensagem']
            ?? $body['message']
            ?? (is_array($body['erros'] ?? null) ? ($body['erros'][0]['mensagem'] ?? null) : null)
            ?? null;

        return $message ?? "A API dos Correios recusou a pré-postagem (HTTP {$response->status()}).";
    }

    private function onlyDigits(?string $value): ?string
    {
        return $value ? preg_replace('/\D/', '', $value) : $value;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitPhone(?string $phone): array
    {
        $digits = $this->onlyDigits($phone);

        if (! $digits) {
            return [null, null];
        }

        return [substr($digits, 0, 2), substr($digits, 2)];
    }
}
