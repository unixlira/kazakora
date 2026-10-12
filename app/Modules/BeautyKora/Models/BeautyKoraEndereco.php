<?php

namespace App\Modules\BeautyKora\Models;

use Illuminate\Database\Eloquent\Model;

/** Endereço de entrega de um cliente do Beauty Kora (pedido 2026-10-12). */
class BeautyKoraEndereco extends Model
{
    public const CAMPOS = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado'];

    protected $table = 'beautykora_enderecos';

    protected $fillable = ['cliente_id', ...self::CAMPOS];

    public function paraSite(): array
    {
        return ['id' => (string) $this->id, 'user_id' => (string) $this->cliente_id, ...$this->only(self::CAMPOS)];
    }
}
