<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'serie' => $this->serie,
            'numero' => $this->numero,
            'valor_total' => $this->valor_total !== null ? (float) $this->valor_total : null,
            'chave_acesso' => $this->chave_acesso,
            'protocolo_autorizacao' => $this->protocolo_autorizacao,
            'autorizada_em' => $this->autorizada_em?->toIso8601String(),
            'motivo_rejeicao' => $this->motivo_rejeicao,
            'cancelada_em' => $this->cancelada_em?->toIso8601String(),
        ];
    }
}
