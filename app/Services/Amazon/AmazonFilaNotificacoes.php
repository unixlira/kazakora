<?php

namespace App\Services\Amazon;

use Aws\Sqs\SqsClient;
use RuntimeException;

/**
 * Fila SQS onde a Amazon publica os avisos (ORDER_CHANGE). Só lê e apaga:
 * a chave IAM da loja não tem permissão pra mais nada. Classe própria pra
 * os testes trocarem por uma fila falsa.
 */
class AmazonFilaNotificacoes
{
    private ?SqsClient $client = null;

    public function configurada(): bool
    {
        return filled(config('services.amazon.notificacoes.sqs_url'))
            && filled(config('services.amazon.notificacoes.sqs_key'))
            && filled(config('services.amazon.notificacoes.sqs_secret'));
    }

    /**
     * Long polling: espera até 20 s por mensagem, devolve até 10.
     *
     * @return array<int, array{receipt: string, body: string}>
     */
    public function receber(int $espera = 20): array
    {
        $resultado = $this->client()->receiveMessage([
            'QueueUrl' => $this->url(),
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => $espera,
        ]);

        return array_map(fn (array $m) => [
            'receipt' => (string) $m['ReceiptHandle'],
            'body' => (string) $m['Body'],
        ], $resultado->get('Messages') ?? []);
    }

    public function apagar(string $receipt): void
    {
        $this->client()->deleteMessage(['QueueUrl' => $this->url(), 'ReceiptHandle' => $receipt]);
    }

    /**
     * Mensagens esperando + em processamento (pra conferir a fila de fora).
     *
     * @return array<string, string>
     */
    public function atributos(): array
    {
        return $this->client()->getQueueAttributes([
            'QueueUrl' => $this->url(),
            'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'QueueArn'],
        ])->get('Attributes') ?? [];
    }

    private function url(): string
    {
        return (string) config('services.amazon.notificacoes.sqs_url');
    }

    private function client(): SqsClient
    {
        if (! $this->configurada()) {
            throw new RuntimeException('Fila SQS da Amazon não configurada (AMAZON_SQS_URL/KEY/SECRET).');
        }

        return $this->client ??= new SqsClient([
            'version' => '2012-11-05',
            'region' => config('services.amazon.notificacoes.sqs_region'),
            'credentials' => [
                'key' => config('services.amazon.notificacoes.sqs_key'),
                'secret' => config('services.amazon.notificacoes.sqs_secret'),
            ],
        ]);
    }
}
