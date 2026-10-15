<?php

namespace App\Modules\BeautyKora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Endereço de entrega de um cliente do Beauty Kora (pedido 2026-10-12). */
class BeautyKoraEndereco extends Model
{
    public const CAMPOS = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado'];

    protected $table = 'beautykora_enderecos';

    protected $fillable = ['cliente_id', ...self::CAMPOS, 'apelido', 'destinatario', 'telefone', 'principal'];

    protected function casts(): array
    {
        return ['principal' => 'boolean'];
    }

    /** Pedidos do site com este endereço: com algum, ele não pode ser excluído. */
    public function pedidos(): HasMany
    {
        return $this->hasMany(BeautyKoraPedido::class, 'endereco_id');
    }

    public function paraSite(): array
    {
        return ['id' => (string) $this->id, 'user_id' => (string) $this->cliente_id, ...$this->only(self::CAMPOS)];
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
            'tem_pedido' => (bool) ($this->tem_pedido ?? $this->pedidos()->exists()),
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
