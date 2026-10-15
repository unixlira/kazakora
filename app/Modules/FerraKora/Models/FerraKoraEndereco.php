<?php

namespace App\Modules\FerraKora\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Endereço do Meu Perfil da conta FerraKora (dados separados por projeto,
 * 2026-10-14), no mesmo modelo do BeautyKoraEndereco. O pedido guarda a
 * cópia do endereço (orders.shipping_*); "tem pedido" é pedido do FerraKora
 * desta conta com o mesmo CEP e número.
 */
class FerraKoraEndereco extends Model
{
    public const CAMPOS = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado'];

    protected $table = 'ferrakora_enderecos';

    protected $fillable = ['cliente_id', ...self::CAMPOS, 'apelido', 'destinatario', 'telefone', 'principal'];

    protected function casts(): array
    {
        return ['principal' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(FerraKoraCliente::class, 'cliente_id');
    }

    /** Pedidos com este endereço: com algum, ele não pode ser excluído. */
    public function pedidos(): Builder
    {
        $cep = preg_replace('/\D/', '', (string) $this->cep);

        return $this->cliente->pedidos()
            ->where('shipping_number', $this->numero)
            ->whereIn('shipping_zip', [$cep, substr($cep, 0, 5).'-'.substr($cep, 5)]);
    }

    /** Formato do Meu Perfil, com os mesmos nomes do Meu Perfil da KazaKora. */
    public function paraPerfil(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->apelido,
            'recipient_name' => $this->destinatario,
            'phone' => $this->telefone,
            'zip' => $this->cep,
            'street' => $this->logradouro,
            'number' => $this->numero,
            'complement' => $this->complemento,
            'neighborhood' => $this->bairro,
            'city' => $this->cidade,
            'state' => $this->estado,
            'is_default' => (bool) $this->principal,
            'tem_pedido' => $this->pedidos()->exists(),
        ];
    }

    /** Campos do Meu Perfil (nomes da KazaKora) para as colunas daqui. */
    public static function doPerfil(array $dados): array
    {
        return [
            'apelido' => $dados['label'] ?? null,
            'destinatario' => $dados['recipient_name'],
            'telefone' => $dados['phone'],
            'cep' => $dados['zip'],
            'logradouro' => $dados['street'],
            'numero' => $dados['number'],
            'complemento' => $dados['complement'] ?? null,
            'bairro' => $dados['neighborhood'],
            'cidade' => $dados['city'],
            'estado' => mb_strtoupper($dados['state']),
        ];
    }
}
