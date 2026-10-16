<?php

namespace App\Console\Commands;

use App\Jobs\ProcessarNotificacaoAmazon;
use App\Modules\Marketplace\Models\AmazonNotificacao;
use App\Services\Amazon\AmazonFilaNotificacoes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lê os avisos ORDER_CHANGE que a Amazon publica na fila SQS da loja (long
 * polling, 20 s por chamada) e despacha ProcessarNotificacaoAmazon. Na
 * produção roda pelo supervisor (programa kazakora-amazon-sqs) e sai sozinho
 * depois de --max-tempo pra recomeçar limpo.
 *
 * Mensagem só sai da fila depois de gravada em amazon_notificacoes; se der
 * erro, volta pra fila sozinha e, depois de 5 tentativas, cai na fila de
 * mensagens com problema (DLQ) configurada na AWS.
 *
 * --so-registrar: grava os avisos sem mexer em pedido (validação inicial).
 * --uma-vez: uma leitura só e sai (homolog não tem supervisor).
 */
class OuvirNotificacoesAmazon extends Command
{
    protected $signature = 'amazon:notificacoes-ouvir
        {--so-registrar : Só grava os avisos, não mexe em pedido}
        {--uma-vez : Faz uma leitura e sai}
        {--max-tempo=3600 : Segundos até sair (o supervisor reinicia)}';

    protected $description = 'Lê os avisos de pedido da Amazon (fila SQS) e aplica nos pedidos';

    public function handle(AmazonFilaNotificacoes $fila): int
    {
        if (! $fila->configurada()) {
            $this->error('Fila SQS da Amazon não configurada (AMAZON_SQS_URL/KEY/SECRET no .env).');

            return self::FAILURE;
        }

        $fim = time() + max(60, (int) $this->option('max-tempo'));

        do {
            try {
                $mensagens = $fila->receber($this->option('uma-vez') ? 5 : 20);
            } catch (Throwable $e) {
                Log::channel('amazon')->error('amazon.sqs.receber_falhou', ['erro' => $e->getMessage()]);
                $this->error("Falha ao ler a fila: {$e->getMessage()}");

                if ($this->option('uma-vez')) {
                    return self::FAILURE;
                }

                sleep(30);

                continue;
            }

            foreach ($mensagens as $mensagem) {
                $this->tratar($fila, $mensagem);
            }
        } while (! $this->option('uma-vez') && time() < $fim);

        return self::SUCCESS;
    }

    /**
     * @param  array{receipt: string, body: string}  $mensagem
     */
    private function tratar(AmazonFilaNotificacoes $fila, array $mensagem): void
    {
        try {
            $dados = json_decode($mensagem['body'], true);
            $aviso = is_array($dados) ? AmazonNotificacao::registrar($dados) : null;

            if (! $aviso) {
                // Mensagem de teste da própria SQS/AWS ou formato estranho:
                // registra no log e tira da fila pra não voltar sempre.
                Log::channel('amazon')->warning('amazon.sqs.mensagem_sem_aviso', ['body' => mb_substr($mensagem['body'], 0, 2000)]);
                $fila->apagar($mensagem['receipt']);

                return;
            }

            if ($aviso->wasRecentlyCreated) {
                $this->line("Aviso {$aviso->notification_type} pedido {$aviso->amazon_order_id}: {$aviso->order_status}");

                if (! $this->option('so-registrar')) {
                    ProcessarNotificacaoAmazon::dispatch($aviso->id);
                }
            }

            $fila->apagar($mensagem['receipt']);
        } catch (Throwable $e) {
            Log::channel('amazon')->error('amazon.sqs.mensagem_falhou', ['erro' => $e->getMessage()]);
            $this->warn("Mensagem não processada (volta pra fila): {$e->getMessage()}");
        }
    }
}
