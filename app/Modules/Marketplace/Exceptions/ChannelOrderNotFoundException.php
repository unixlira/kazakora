<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * O canal (ou a ponte pra ele) não conhece este pedido — tentar de novo
 * não muda nada.
 *
 * INCIDENTE 2026-09-10: a fila tinha **7.794 falhas em 24h**, quase todas
 * o mesmo punhado de 110 pedidos do TikTok Shop de AGOSTO, ainda marcados
 * "pago" aqui, que o Bling não conhece. Cada varredura horária reimportava
 * o pedido, redisparava o ConfirmChannelShippingJob, e ele queimava as 6
 * tentativas (~3h de backoff) pra morrer no mesmo erro. Um mês disso.
 *
 * O custo não é o registro de erro: é a fila. Esses jobs disputavam worker
 * com a nota fiscal e a etiqueta de venda de verdade — e foi provavelmente
 * por isso que a NF-e travada do #1913 passou 24h sem ser retentada.
 *
 * Erro que não muda com o tempo merece UMA tentativa e uma marca no
 * envio (`channel_shipments.unrecoverable_at`), não retentativa eterna.
 */
class ChannelOrderNotFoundException extends RuntimeException
{
}
