<?php

namespace App\Modules\Admin\Support\Publicidade;

/**
 * Google ADS na Central de campanhas. Ainda NÃO existe integração (sem
 * credencial nem serviço): a fonte diz que não está conectada e a tela
 * mostra o que falta (PlataformaDeAnuncio::dados('google_ads')['precisa']).
 *
 * Quando o Lira liberar o acesso (ID da conta, developer token, OAuth só
 * leitura): ler a API do Google Ads (GAQL em campaign + metrics no período)
 * e devolver cada campanha com CentralDeCampanhas::linha(). Status do Google:
 * ENABLED => ativa, PAUSED => pausada, REMOVED => encerrada; serving_status
 * ENDED => encerrada; policy/approval com problema => com_problema.
 */
class CampanhasGoogle implements FonteDeCampanhas
{
    public function canal(): string
    {
        return PlataformaDeAnuncio::GOOGLE;
    }

    public function conectada(): bool
    {
        return false;
    }

    public function campanhas(string $periodo): array
    {
        return [];
    }

    public function alertas(): array
    {
        return [];
    }
}
