<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Console\Command;

/**
 * Liga (ou desfaz) o aviso de pedido da Amazon: destino SQS + assinatura do
 * evento ORDER_CHANGE na Notifications API.
 *
 * - Destino: grantless (não é do vendedor, é do app). Reaproveita o que já
 *   aponta pra mesma fila (AMAZON_SQS_ARN).
 * - Assinatura: token do vendedor. Uma por evento; se já existe pro mesmo
 *   destino, não faz nada.
 *
 * --listar só lê. --remover apaga assinatura e destino (pede confirmação).
 * A Amazon não aceita duas assinaturas do mesmo evento pro mesmo vendedor:
 * rodar isto no homolog e na produção aponta os dois pra MESMA fila.
 */
class ConfigurarNotificacoesAmazon extends Command
{
    private const EVENTO = 'ORDER_CHANGE';

    protected $signature = 'amazon:notificacoes-configurar
        {--listar : Só mostra destinos e assinatura}
        {--remover : Apaga a assinatura ORDER_CHANGE e o destino da fila}';

    protected $description = 'Cria o destino SQS e a assinatura ORDER_CHANGE na SP-API da Amazon';

    public function handle(AmazonClient $client): int
    {
        $arn = (string) config('services.amazon.notificacoes.sqs_arn');

        if (! $this->option('listar') && $arn === '') {
            $this->error('AMAZON_SQS_ARN não configurado no .env.');

            return self::FAILURE;
        }

        $destinos = $client->grantless('GET', '/notifications/v1/destinations')['payload'] ?? [];
        $assinatura = $this->assinaturaAtual($client);

        if ($this->option('listar')) {
            $this->table(['destinationId', 'nome', 'ARN'], array_map(fn ($d) => [
                $d['destinationId'] ?? '',
                $d['name'] ?? '',
                $d['resource']['sqs']['arn'] ?? '(não SQS)',
            ], $destinos));

            $this->line($assinatura
                ? 'Assinatura '.self::EVENTO.": {$assinatura['subscriptionId']} → destino {$assinatura['destinationId']}"
                : 'Sem assinatura '.self::EVENTO.'.');

            return self::SUCCESS;
        }

        $destino = collect($destinos)->first(fn ($d) => ($d['resource']['sqs']['arn'] ?? null) === $arn);

        if ($this->option('remover')) {
            return $this->remover($client, $assinatura, $destino);
        }

        if (! $destino) {
            $destino = $client->grantless('POST', '/notifications/v1/destinations', [
                'name' => 'kazakora-sqs',
                'resourceSpecification' => ['sqs' => ['arn' => $arn]],
            ])['payload'] ?? null;

            $this->info("Destino criado: {$destino['destinationId']}");
        } else {
            $this->line("Destino já existe: {$destino['destinationId']}");
        }

        if ($assinatura && ($assinatura['destinationId'] ?? null) === $destino['destinationId']) {
            $this->line("Assinatura já existe: {$assinatura['subscriptionId']}");

            return self::SUCCESS;
        }

        if ($assinatura) {
            $this->error("Já existe assinatura {$assinatura['subscriptionId']} apontando para outro destino ({$assinatura['destinationId']}). Confira com --listar e apague essa assinatura antes de criar outra.");

            return self::FAILURE;
        }

        // Sem processingDirective a Amazon recusa o ORDER_CHANGE com 400
        // ("missing or invalid parameters"), conferido na produção em 16/10.
        $nova = $client->post('/notifications/v1/subscriptions/'.self::EVENTO, [
            'payloadVersion' => '1.0',
            'destinationId' => $destino['destinationId'],
            'processingDirective' => [
                'eventFilter' => [
                    'eventFilterType' => self::EVENTO,
                    'orderChangeTypes' => ['OrderStatusChange', 'BuyerRequestedChange'],
                ],
            ],
        ])['payload'] ?? [];

        $this->info('Assinatura '.self::EVENTO." criada: {$nova['subscriptionId']}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assinaturaAtual(AmazonClient $client): ?array
    {
        try {
            return $client->get('/notifications/v1/subscriptions/'.self::EVENTO)['payload'] ?? null;
        } catch (AmazonException $e) {
            // 404 = ainda não existe assinatura pra esse evento.
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $assinatura
     * @param  array<string, mixed>|null  $destino
     */
    private function remover(AmazonClient $client, ?array $assinatura, ?array $destino): int
    {
        if (! $assinatura && ! $destino) {
            $this->line('Nada para remover.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apagar a assinatura '.self::EVENTO.' e o destino da fila na Amazon?')) {
            return self::FAILURE;
        }

        if ($assinatura && (! $destino || $assinatura['destinationId'] === $destino['destinationId'])) {
            $client->grantless('DELETE', '/notifications/v1/subscriptions/'.self::EVENTO.'/'.$assinatura['subscriptionId']);
            $this->info("Assinatura {$assinatura['subscriptionId']} apagada.");
        }

        if ($destino) {
            $client->grantless('DELETE', '/notifications/v1/destinations/'.$destino['destinationId']);
            $this->info("Destino {$destino['destinationId']} apagado.");
        }

        return self::SUCCESS;
    }
}
