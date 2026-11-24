<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * A Amazon não deixa agendar este pedido do DBA pela API (listHandoverSlots
 * "operation is not supported" e o agendamento sem horário recusado). O
 * Lira já foi avisado no sino e por e-mail para agendar no Seller Central,
 * e AguardarAgendamentoDbaJob confere a cada 30 min: o
 * ConfirmChannelShippingJob não repete a tentativa.
 */
class AgendamentoDbaManualException extends RuntimeException
{
}
