<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class ChannelShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'status' => $this->status,
            'shipping_method' => $this->shipping_method,
            'tracking_code' => $this->tracking_code,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'label_ready_at' => $this->label_ready_at?->toIso8601String(),
            'error_message' => $this->error_message,
            // URL assinada temporária pro PDF da etiqueta — nunca expõe o
            // caminho real do disco nem exige o parceiro estar autenticado
            // de novo só pra baixar o arquivo (link de posse temporária,
            // expira sozinho). Só existe quando a etiqueta já foi baixada
            // do canal e salva localmente.
            'label_url' => $this->label_path
                ? URL::temporarySignedRoute('api.v1.pedidos.etiqueta', now()->addMinutes(30), ['order' => $this->order_id])
                : null,
        ];
    }
}
