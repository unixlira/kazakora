<?php

namespace Tests\Feature\Amazon;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AmazonClient: todas as chamadas da SP-API (get/post/patch/put/delete,
 * RDT, feeds/documentos) e o tratamento de resposta — status de sucesso,
 * erros 4xx/5xx, 429 (limite da Amazon) e erro no corpo com HTTP 200.
 */
class AmazonClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE.'/',
            'services.amazon.lwa_client_id' => 'cid',
            'services.amazon.lwa_client_secret' => 'csecret',
        ]);
    }

    private function conectar(array $extra = []): MarketplaceAccount
    {
        return MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token',
            'refresh_token' => 'lwa-refresh',
            'token_expires_at' => now()->addHour(),
            ...$extra,
        ]);
    }

    private function client(): AmazonClient
    {
        return app(AmazonClient::class);
    }

    public function test_get_sends_the_lwa_token_and_query_and_returns_the_json(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/orders/v0/orders*' => Http::response(['payload' => ['Orders' => [['AmazonOrderId' => 'A1']]]], 200)]);

        $json = $this->client()->get('/orders/v0/orders', ['MarketplaceIds' => 'A2Q3Y263D00KWC']);

        $this->assertSame('A1', $json['payload']['Orders'][0]['AmazonOrderId']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->hasHeader('x-amz-access-token', 'lwa-token')
            && str_contains($r->url(), 'MarketplaceIds=A2Q3Y263D00KWC')
            && ! str_contains($r->url(), '//orders'));
    }

    public function test_post_patch_put_and_delete_use_the_right_verb_and_token(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['payload' => ['ok' => true]], 200)]);

        $this->assertTrue($this->client()->post('/x', ['a' => 1])['payload']['ok']);
        $this->assertTrue($this->client()->patch('/x', ['a' => 1])['payload']['ok']);
        $this->assertTrue($this->client()->put('/x', ['a' => 1])['payload']['ok']);
        $this->assertTrue($this->client()->delete('/x')['payload']['ok']);

        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $verbo) {
            Http::assertSent(fn (Request $r) => $r->method() === $verbo && $r->hasHeader('x-amz-access-token', 'lwa-token'));
        }
    }

    public function test_204_without_body_is_success_with_empty_array(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(null, 204)]);

        $this->assertSame([], $this->client()->post('/orders/v0/orders/A1/shipmentConfirmation', []));
    }

    /** @return array<string, array{int}> */
    public static function statusDeErro(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('statusDeErro')]
    public function test_http_errors_become_amazon_exception_with_the_status(int $status): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['errors' => [['code' => 'X', 'message' => "falhou {$status}"]]], $status)]);

        try {
            $this->client()->get('/orders/v0/orders');
            $this->fail('Deveria lançar AmazonException');
        } catch (AmazonException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame("falhou {$status}", $e->getMessage());
            $this->assertSame('X', $e->context['body']['errors'][0]['code']);
        }
    }

    public function test_non_json_error_body_still_gives_a_readable_message(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response('<html>Service Unavailable</html>', 503)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionMessage('Erro na API da Amazon (HTTP 503).');

        $this->client()->get('/orders/v0/orders');
    }

    public function test_errors_in_a_200_body_are_not_treated_as_success(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['errors' => [['message' => 'erro no corpo']]], 200)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionMessage('erro no corpo');

        $this->client()->get('/orders/v0/orders');
    }

    public function test_without_account_it_refuses_before_calling_amazon(): void
    {
        Http::fake();

        try {
            $this->client()->get('/orders/v0/orders');
            $this->fail('Deveria lançar AmazonException');
        } catch (AmazonException $e) {
            $this->assertSame('Nenhuma conta da Amazon está conectada.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_account_in_error_state_is_refused(): void
    {
        $this->conectar(['status' => MarketplaceAccount::STATUS_ERROR]);
        Http::fake();

        $this->expectException(AmazonException::class);

        $this->client()->get('/orders/v0/orders');
    }

    public function test_expiring_token_is_renewed_before_the_call(): void
    {
        $this->conectar(['token_expires_at' => now()->addMinutes(2)]);
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'novo-token', 'expires_in' => 3600], 200),
            self::BASE.'/*' => Http::response(['payload' => []], 200),
        ]);

        $this->client()->get('/sellers/v1/marketplaceParticipations');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE) && $r->hasHeader('x-amz-access-token', 'novo-token'));
        $this->assertSame('novo-token', MarketplaceAccount::first()->access_token);
    }

    public function test_restricted_get_asks_for_an_rdt_and_uses_it_instead_of_the_lwa_token(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/tokens/2021-03-01/restrictedDataToken' => Http::response(['restrictedDataToken' => 'rdt-123', 'expiresIn' => 3600], 200),
            self::BASE.'/orders/v0/orders/A1/address' => Http::response(['payload' => ['ShippingAddress' => ['City' => 'Campinas']]], 200),
        ]);

        $json = $this->client()->restrictedGet('/orders/v0/orders/A1/address', ['shippingAddress']);

        $this->assertSame('Campinas', $json['payload']['ShippingAddress']['City']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'restrictedDataToken')
            && $r['restrictedResources'][0] === ['method' => 'GET', 'path' => '/orders/v0/orders/A1/address', 'dataElements' => ['shippingAddress']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/address') && $r->hasHeader('x-amz-access-token', 'rdt-123'));
    }

    public function test_restricted_get_without_rdt_role_raises_the_amazon_message(): void
    {
        // Resposta REAL da produção em 2026-10-16: o app não tem o papel de dado restrito.
        $this->conectar();
        Http::fake([self::BASE.'/tokens/*' => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'Application does not have access to one or more requested data elements: [shippingAddress]']]], 400)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionMessage('does not have access');

        $this->client()->restrictedGet('/orders/v0/orders/A1/address', ['shippingAddress']);
    }

    public function test_restricted_get_with_empty_rdt_is_refused(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/tokens/*' => Http::response(['restrictedDataToken' => null], 200)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionMessage('Restricted Data Token');

        $this->client()->restrictedGet('/orders/v0/orders/A1/address', ['shippingAddress']);
    }

    public function test_feed_document_is_created_and_uploaded_without_amazon_token(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/feeds/2021-06-30/documents' => Http::response(['feedDocumentId' => 'DOC1', 'url' => 'https://s3.test/upload'], 201),
            'https://s3.test/upload' => Http::response('', 200),
        ]);

        $doc = $this->client()->createFeedDocument('text/xml');
        $this->client()->uploadFeedDocument($doc['url'], '<xml/>', 'text/xml');

        $this->assertSame(['feedDocumentId' => 'DOC1', 'url' => 'https://s3.test/upload'], $doc);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://s3.test/upload' && $r->method() === 'PUT' && $r->body() === '<xml/>' && ! $r->hasHeader('x-amz-access-token'));
    }

    public function test_upload_failure_raises(): void
    {
        Http::fake(['https://s3.test/*' => Http::response('denied', 403)]);

        $this->expectException(AmazonException::class);

        $this->client()->uploadFeedDocument('https://s3.test/upload', 'x', 'text/plain');
    }

    public function test_download_document_resolves_the_url_and_downloads(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/reports/2021-06-30/documents/R1' => Http::response(['url' => 'https://s3.test/r1'], 200),
            'https://s3.test/r1' => Http::response('conteudo', 200),
        ]);

        $this->assertSame('conteudo', $this->client()->downloadDocument('/reports/2021-06-30/documents/R1'));
    }

    public function test_download_document_without_url_or_with_failed_download_raises(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/reports/2021-06-30/documents/SEMURL' => Http::response(['x' => 1], 200),
            self::BASE.'/reports/2021-06-30/documents/QUEBRADO' => Http::response(['url' => 'https://s3.test/quebrado'], 200),
            'https://s3.test/quebrado' => Http::response('', 500),
        ]);

        foreach (['SEMURL', 'QUEBRADO'] as $id) {
            try {
                $this->client()->downloadDocument("/reports/2021-06-30/documents/{$id}");
                $this->fail("{$id} deveria falhar");
            } catch (AmazonException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
