<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Operacional\Mail\NotaEEtiquetaPendentes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Pedido pago que ainda não tem NF-e autorizada E não foi impresso nem
 * separado no KoraSync (pedido 2026-10-19): é o pedido que atrasa o
 * despacho. Roda de hora em hora; manda e-mail só quando aparece pedido
 * novo nessa situação, listando todos os que continuam travados.
 *
 * Nota "external" conta como emitida (o canal emite, ver Invoice). Pedidos
 * criados há menos de 30 minutos ficam de fora: a nota automática ainda
 * pode estar saindo. Olha só os últimos 7 dias.
 */
class AlertarNotaEEtiquetaPendentes extends Command
{
    protected $signature = 'loja:nota-etiqueta-pendentes
        {--para= : Outro destinatário (teste, não marca como avisado)}
        {--listar : Só mostra na tela, sem e-mail}';

    protected $description = 'Avisa por e-mail pedidos pagos sem NF-e autorizada e sem etiqueta impressa';

    private const CONTROLE = 'automation/notified_nota_etiqueta_pendentes.json';

    public function handle(): int
    {
        $pedidos = Order::query()
            ->with(['items:id,order_id,product_name,quantity', 'invoice:id,order_id,status,motivo_rejeicao'])
            ->vendaEfetiva()
            ->where('status', Order::STATUS_PAID)
            ->whereBetween('created_at', [now()->subDays(7)->startOfDay(), now()->subMinutes(30)])
            ->whereNull('packed_at')
            ->whereDoesntHave('invoice', fn ($q) => $q->whereIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL]))
            ->whereNotExists(fn ($q) => $q->from('print_jobs')
                ->whereColumn('print_jobs.order_id', 'orders.id')
                ->where('print_jobs.status', PrintJob::STATUS_PRINTED))
            ->orderBy('created_at')
            ->get();

        if ($pedidos->isEmpty()) {
            $this->info('Nenhum pedido pago sem nota e sem etiqueta.');

            return self::SUCCESS;
        }

        $controle = Storage::disk('local')->exists(self::CONTROLE)
            ? (json_decode((string) Storage::disk('local')->get(self::CONTROLE), true) ?: [])
            : [];
        $teste = filled($this->option('para'));

        $lista = $pedidos->map(fn (Order $o) => [
            'id' => $o->id,
            'origin' => $o->origin,
            'external_order_id' => $o->external_order_id,
            'created_at' => $o->created_at,
            'nota' => $o->invoice?->status,
            'motivo' => $o->invoice?->motivo_rejeicao,
            'novo' => ! isset($controle[(string) $o->id]),
            'items' => $o->items->map(fn ($i) => ['product_name' => $i->product_name, 'quantity' => (int) $i->quantity])->all(),
        ])->all();

        if ($this->option('listar')) {
            $this->table(['Pedido', 'Canal', 'No canal', 'Criado', 'Nota', 'Novo'], array_map(fn ($p) => [
                $p['id'], $p['origin'], $p['external_order_id'], $p['created_at']->format('d/m H:i'), $p['nota'] ?? 'sem nota', $p['novo'] ? 'sim' : 'não',
            ], $lista));

            return self::SUCCESS;
        }

        $novos = array_filter($lista, fn ($p) => $p['novo']);
        if (! $novos && ! $teste) {
            $this->info(count($lista).' pedido(s) travado(s), todos já avisados.');

            return self::SUCCESS;
        }

        $para = $teste ? [$this->option('para')] : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));
        Mail::to($para)->send(new NotaEEtiquetaPendentes($lista));

        if (! $teste) {
            // Só guarda quem ainda está travado: se sair e voltar, avisa de novo.
            $controle = array_intersect_key($controle, array_flip(array_map('strval', array_column($lista, 'id'))));
            foreach ($novos as $p) {
                $controle[(string) $p['id']] = now()->toIso8601String();
            }
            Storage::disk('local')->put(self::CONTROLE, json_encode($controle, JSON_PRETTY_PRINT));
        }

        $this->info(count($lista).' pedido(s) no e-mail ('.count($novos).' novo(s)) para '.implode(', ', $para).'.');

        return self::SUCCESS;
    }
}
