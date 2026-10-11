<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Mail\WelcomeEmail;
use App\Modules\Cart\Mail\CarrinhoAbandonado;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\CupomPromocional;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Contato\Mail\MensagemDoSite;
use App\Modules\Contato\Models\MensagemContato;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Manda um exemplo de cada e-mail da loja para conferir o visual (pedido
 * 2026-10-10). Nada é gravado no banco: cliente, cupom, carrinho e mensagem
 * de exemplo só existem na memória. O pedido é o último com nota emitida.
 */
class EnviarEmailsDeTeste extends Command
{
    protected $signature = 'loja:emails-teste {email : Para onde mandar os exemplos}';

    protected $description = 'Envia um exemplo de cada e-mail da loja';

    public function handle(): int
    {
        $para = (string) $this->argument('email');

        $cliente = (new User)->forceFill(['name' => 'Cliente Exemplo', 'email' => $para]);
        $cliente->id = 0;

        $produtos = Product::query()->where('is_active', true)->whereNull('parent_product_id')
            ->whereHas('images')->inRandomOrder()->limit(2)->pluck('id');
        $carrinho = (new CartSnapshot)->forceFill([
            'email' => $para,
            'itens' => $produtos->mapWithKeys(fn ($id, $i) => [$id => $i + 1])->all(),
        ]);
        $carrinho->id = 0;

        $cupom = (new Coupon)->forceFill([
            'code' => 'EXEMPLO10',
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'min_order_value' => 99,
            'expires_at' => now()->addDays(7),
        ]);

        $mensagem = (new MensagemContato)->forceFill([
            'nome' => 'Maria Exemplo',
            'email' => 'maria.exemplo@example.com',
            'telefone' => '(11) 90000-0000',
            'assunto' => 'Dúvida sobre o prazo de entrega',
            'mensagem' => "Olá! Comprando hoje, chega até sexta?\n\nObrigada!",
            'created_at' => now(),
        ]);

        $pedido = Order::query()
            ->whereHas('invoice', fn ($query) => $query->where('status', Invoice::STATUS_AUTHORIZED))
            ->latest('id')->first() ?? Order::query()->latest('id')->first();

        $emails = [
            'Boas-vindas (cadastro)' => new WelcomeEmail($cliente),
            'Boas-vindas com senha temporária (compra sem cadastro)' => new WelcomeEmail($cliente, 'Ex3mpl0aB9'),
            'Recuperação de senha' => new PasswordResetMail($cliente, url('/redefinir-senha/exemplo?email='.urlencode($para))),
            'Carrinho abandonado (1º lembrete)' => new CarrinhoAbandonado($carrinho, 1),
            'Carrinho abandonado (último lembrete)' => new CarrinhoAbandonado($carrinho, 8),
            'Promocional (cupom)' => new CupomPromocional($cliente, $cupom, 'Um presente para você: 10% OFF 🎁', "Separamos um cupom especial para a sua próxima compra.\n\nAproveite: vale para toda a loja, com frete grátis."),
            'Mensagem do site (Fale conosco)' => new MensagemDoSite($mensagem),
        ];

        if ($pedido) {
            $emails["Pedido concluído (#{$pedido->id}, com a nota em anexo se houver)"] = new OrderConfirmation($pedido);
        }

        foreach ($emails as $nome => $mailable) {
            /** @var Mailable $mailable */
            try {
                Mail::to($para)->send($mailable);
                $this->info("✔ {$nome}");
            } catch (\Throwable $erro) {
                $this->error("✘ {$nome}: {$erro->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
