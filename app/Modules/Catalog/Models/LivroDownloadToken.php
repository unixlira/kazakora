<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Token do link de download de um livro de brinde (Lira, 2026-10-22). Um por
 * livro do pedido, o mesmo no e-mail e na página do pedido. Vale para UM
 * download e para MAX_TENTATIVAS aberturas da página dos termos; um link novo
 * só sai pelo `livros:novo-link`, que revoga o anterior.
 *
 * @property int $id
 * @property int $order_id
 * @property int $livro_digital_id
 * @property string $token
 * @property int $tentativas
 * @property ?Carbon $usado_em
 * @property ?string $usado_ip
 * @property ?Carbon $revogado_em
 */
class LivroDownloadToken extends Model
{
    /** Aberturas da página dos termos por link (o leitor de e-mail também abre). */
    public const MAX_TENTATIVAS = 5;

    protected $table = 'livro_download_tokens';

    protected $fillable = ['order_id', 'livro_digital_id', 'token'];

    protected function casts(): array
    {
        return [
            'tentativas' => 'integer',
            'usado_em' => 'datetime',
            'revogado_em' => 'datetime',
        ];
    }

    public function livro(): BelongsTo
    {
        return $this->belongsTo(LivroDigital::class, 'livro_digital_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Token do livro no pedido: o que já existe (mesmo usado, para o link
     * gasto não virar um novo ao recarregar a página do pedido) ou o primeiro.
     */
    public static function doPedido(Order $order, LivroDigital $livro): self
    {
        return self::query()
            ->where('order_id', $order->id)
            ->where('livro_digital_id', $livro->id)
            ->whereNull('revogado_em')
            ->latest('id')
            ->first()
            ?? self::novo($order, $livro);
    }

    /** Revoga os links do livro no pedido e cria outro (atendimento). */
    public static function renovar(Order $order, LivroDigital $livro): self
    {
        self::query()
            ->where('order_id', $order->id)
            ->where('livro_digital_id', $livro->id)
            ->whereNull('revogado_em')
            ->update(['revogado_em' => now()]);

        return self::novo($order, $livro);
    }

    private static function novo(Order $order, LivroDigital $livro): self
    {
        return self::query()->create([
            'order_id' => $order->id,
            'livro_digital_id' => $livro->id,
            'token' => Str::random(64),
        ]);
    }

    public function url(): string
    {
        return route('livros.download', $this->token);
    }

    /** Já baixou, foi revogado ou abriu a página vezes demais. */
    public function esgotado(): bool
    {
        return $this->usado_em !== null
            || $this->revogado_em !== null
            || $this->tentativas >= self::MAX_TENTATIVAS;
    }

    /** Conta uma abertura da página; false se o link já não vale. Atômico. */
    public function registrarTentativa(): bool
    {
        $ok = self::query()
            ->whereKey($this->id)
            ->whereNull('usado_em')
            ->whereNull('revogado_em')
            ->where('tentativas', '<', self::MAX_TENTATIVAS)
            ->increment('tentativas') === 1;

        $this->refresh();

        return $ok;
    }

    /**
     * Gasta o token no download. Atômico: dois cliques ao mesmo tempo baixam
     * uma vez só. Não exige ter aberto a página antes (o POST já é o aceite).
     */
    public function consumir(string $ip): bool
    {
        $ok = self::query()
            ->whereKey($this->id)
            ->whereNull('usado_em')
            ->whereNull('revogado_em')
            ->update(['usado_em' => now(), 'usado_ip' => $ip, 'updated_at' => now()]) === 1;

        $this->refresh();

        return $ok;
    }
}
