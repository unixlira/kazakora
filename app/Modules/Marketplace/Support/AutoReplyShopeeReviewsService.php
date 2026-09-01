<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Notifications\ShopeeReviewReplyFailedNotification;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Responde automaticamente avaliações da Shopee em cadência curta
 * (cron a cada 4min), pedido explícito do Lira em 2026-08-26/30.
 *
 * Política operacional: só Shopee, só uma vez por comment_id e só quando
 * ainda não existe tentativa marcada. Todas as notas podem ser respondidas
 * automaticamente: 4/5 agradecem e chamam para seguir a loja; 1/2/3 e sem
 * nota acolhem o problema, filtram palavras da avaliação para escolher uma
 * variação segura e direcionam para suporte sem discutir em público.
 */
class AutoReplyShopeeReviewsService
{
    /**
     * Corte de segurança só para positivas antigas já importadas antes da
     * automação. Avaliações 1/2/3 seguem a regra atual do Lira: responder
     * automaticamente com tom de suporte e filtro de palavras.
     */
    private const POSITIVE_AUTO_REPLY_ENABLED_AT = '2026-08-26 16:27:00';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var array<int, list<string>>
     */
    private array $templatesByRating = [
        0 => [
            'Obrigado pelo retorno. Se algo não saiu como esperado, chama a gente pelo chat da Shopee para entendermos melhor e te ajudar.',
            'Agradecemos por avisar. Fale com a gente por mensagem na Shopee para verificarmos seu pedido com cuidado.',
            'Sentimos se sua experiência não foi completa. Chama a gente no chat da Shopee com os detalhes para podermos ajudar.',
            'Obrigado por compartilhar sua experiência. Se tiver qualquer ponto a resolver, estamos disponíveis pelo chat da Shopee.',
            'Queremos entender melhor seu retorno. Por favor, fale com a gente por mensagem na Shopee para analisarmos o pedido.',
        ],
        1 => [
            'Sentimos muito que sua experiência não tenha sido boa. Chama a gente por mensagem com os detalhes do pedido para entendermos o que aconteceu e ajudar da melhor forma possível.',
            'Lamentamos pelo transtorno. Por favor, fale com a gente pelo chat da Shopee para verificarmos seu pedido com cuidado e buscarmos uma solução.',
            'Obrigado por avisar. Não era essa a experiência que queríamos entregar. Chama a gente por mensagem para analisarmos o caso e te orientar direitinho.',
            'Sentimos pelo ocorrido. Queremos entender melhor o que aconteceu, então pedimos que nos chame pelo chat da Shopee com os detalhes do pedido.',
            'Lamentamos que a compra não tenha atendido sua expectativa. Fale com a gente por mensagem para que possamos verificar e ajudar no que for possível.',
        ],
        2 => [
            'Sentimos que sua experiência não foi como deveria. Chama a gente por mensagem na Shopee para analisarmos o caso e te ajudar com mais precisão.',
            'Obrigado pelo retorno. Vamos verificar o que aconteceu; por favor, envie uma mensagem pelo chat da Shopee com os detalhes do pedido.',
            'Lamentamos pelo transtorno. Fale com a gente pelo chat para entendermos o problema e buscarmos uma solução adequada.',
            'Sentimos muito por isso. Chama a gente na mensagem da Shopee para avaliarmos seu pedido com cuidado e orientar os próximos passos.',
            'Agradecemos por avisar. Queremos corrigir o que for possível; por favor, fale com a gente pelo chat da Shopee.',
        ],
        3 => [
            'Obrigado pela avaliação. Se algo não saiu como esperado, chama a gente pelo chat da Shopee para entendermos melhor e te ajudar.',
            'Agradecemos seu retorno. Queremos melhorar sua experiência; se puder, mande mensagem para a gente com os detalhes do que aconteceu.',
            'Obrigado por compartilhar sua opinião. Caso precise de suporte ou tenha algum ponto a resolver, estamos disponíveis pelo chat da Shopee.',
            'Valeu pelo retorno. Se ficou alguma pendência ou dúvida sobre o produto, chama a gente por mensagem para ajudarmos.',
            'Obrigado pela compra e pela avaliação. Se sua experiência poderia ter sido melhor, fale com a gente pelo chat para verificarmos juntos.',
        ],
        4 => [
            'Obrigado pela avaliação! Aproveita e segue nossa loja para não perder as promoções e novidades que estão chegando. Se precisar, é só mandar mensagem pra gente. Valeu!',
            'Muito obrigado pela avaliação! Segue nossa loja por lá também, porque sempre aparecem novidades e promoções. Qualquer coisa, chama a gente.',
            'Valeu pela avaliação! Fica de olho na nossa loja e segue a gente para acompanhar as próximas promoções. Se precisar de ajuda, estamos por aqui.',
            'Obrigado pelo retorno! Aproveita para seguir nossa loja e não perder as novidades que vêm chegando. Precisando, pode mandar mensagem pra gente.',
            'Agradecemos muito sua avaliação! Segue nossa loja para acompanhar promoções, novidades e próximos produtos. Se precisar, chama a gente.',
            'Obrigado pela compra e pela avaliação! Já segue nossa loja para ficar por dentro das novidades e ofertas. Qualquer dúvida, é só mandar mensagem.',
            'Valeu demais pela avaliação! Segue a loja para não perder as próximas promoções. Se precisar de qualquer coisa, fala com a gente por mensagem.',
            'Obrigado pela avaliação! Estamos sempre trazendo novidades, então segue nossa loja para acompanhar tudo. Precisando, estamos à disposição.',
            'Muito obrigado pelo carinho na avaliação! Aproveita e segue nossa loja para receber novidades e promoções. Qualquer coisa, chama a gente.',
            'Valeu pela avaliação! Fica com a gente seguindo a loja, porque tem novidade e promoção chegando. Se precisar, é só mandar mensagem.',
        ],
        5 => [
            'Obrigado pela avaliação! Aproveita e segue nossa loja para não perder as promoções e novidades que estão chegando. Se precisar, é só mandar mensagem pra gente. Valeu!',
            'Muito obrigado pela avaliação! Segue nossa loja por lá também, porque sempre aparecem novidades e promoções. Qualquer coisa, chama a gente.',
            'Valeu pela avaliação! Fica de olho na nossa loja e segue a gente para acompanhar as próximas promoções. Se precisar de ajuda, estamos por aqui.',
            'Obrigado pelo retorno! Aproveita para seguir nossa loja e não perder as novidades que vêm chegando. Precisando, pode mandar mensagem pra gente.',
            'Agradecemos muito sua avaliação! Segue nossa loja para acompanhar promoções, novidades e próximos produtos. Se precisar, chama a gente.',
            'Obrigado pela compra e pela avaliação! Já segue nossa loja para ficar por dentro das novidades e ofertas. Qualquer dúvida, é só mandar mensagem.',
            'Valeu demais pela avaliação! Segue a loja para não perder as próximas promoções. Se precisar de qualquer coisa, fala com a gente por mensagem.',
            'Obrigado pela avaliação! Estamos sempre trazendo novidades, então segue nossa loja para acompanhar tudo. Precisando, estamos à disposição.',
            'Muito obrigado pelo carinho na avaliação! Aproveita e segue nossa loja para receber novidades e promoções. Qualquer coisa, chama a gente.',
            'Valeu pela avaliação! Fica com a gente seguindo a loja, porque tem novidade e promoção chegando. Se precisar, é só mandar mensagem.',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $supportTemplatesByKeywordGroup = [
        'delivery' => [
            'Sentimos pelo transtorno com a entrega. Chama a gente pelo chat da Shopee para conferirmos o pedido e te orientar da forma certa.',
            'Obrigado por avisar. Se o problema foi na entrega ou no recebimento, fale com a gente pelo chat da Shopee para verificarmos com cuidado.',
        ],
        'defect' => [
            'Sentimos que o produto não tenha funcionado como esperado. Chama a gente pelo chat da Shopee com os detalhes para analisarmos e ajudar.',
            'Obrigado pelo retorno. Se houve defeito ou mau funcionamento, fale com a gente pelo chat da Shopee para verificarmos seu pedido direitinho.',
        ],
        'wrong_or_missing' => [
            'Obrigado por avisar. Se chegou item errado, incompleto ou diferente do esperado, chama a gente pelo chat da Shopee para conferirmos e orientar você.',
            'Sentimos pelo ocorrido. Fale com a gente por mensagem na Shopee se faltou algo, veio errado ou diferente, para analisarmos seu pedido.',
        ],
        'performance' => [
            'Obrigado pelo retorno. Se o desempenho não ficou como esperado, chama a gente pelo chat da Shopee para verificarmos o uso e te orientar.',
            'Agradecemos por avisar. Quando o produto parece fraco, lento ou abaixo do esperado, fale com a gente por mensagem para analisarmos com você.',
        ],
    ];

    /**
     * @var list<string>
     */
    private array $chargerTemplates = [
        'Obrigado pelo retorno. Em carregadores turbo, a saúde da bateria do aparelho precisa estar acima de 80% para manter o carregamento rápido. Se ainda assim não funcionou bem, chama a gente pelo chat da Shopee para verificarmos.',
        'Obrigado pela avaliação! Dica rápida: quando a saúde da bateria está abaixo de 80%, o próprio aparelho pode limitar o carregamento turbo e ele deixa de carregar rápido. Qualquer dúvida, chama a gente.',
    ];

    public function __construct(private readonly MarketplaceDriverManager $drivers) {}

    /**
     * @return array{checked: int, sent: int, failed: int, skipped: int}
     */
    public function replyPendingPositiveShopeeReviews(int $limit = 25, bool $retryFailed = false): array
    {
        $summary = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $driver = $this->drivers->driver(MarketplaceAccount::CHANNEL_SHOPEE);

        Review::query()
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->whereNotNull('external_id')
            ->where(function ($status) use ($retryFailed): void {
                $status->whereNull('seller_reply_status');

                if ($retryFailed) {
                    $status->orWhere('seller_reply_status', self::STATUS_FAILED);
                }
            })
            ->where(function ($query): void {
                $query
                    ->where(function ($positive): void {
                        $positive
                            ->where('rating', '>=', 4)
                            ->where('rating', '<=', 5)
                            ->where('created_at', '>=', self::POSITIVE_AUTO_REPLY_ENABLED_AT);
                    })
                    ->orWhere(function ($support): void {
                        $support->where(function ($rating): void {
                            $rating
                                ->whereNull('rating')
                                ->orWhere('rating', '<=', 3);
                        });
                    });
            })
            ->with('product')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->each(function (Review $review) use ($driver, &$summary): void {
                $summary['checked']++;
                $rating = $this->normalizedRating($review->rating);
                $reply = $this->pickTemplate($review, $rating);

                $review->forceFill([
                    'seller_reply' => $reply,
                    'seller_reply_template' => $this->templateKey($reply, $rating),
                    'seller_reply_attempted_at' => now(),
                ])->save();

                try {
                    $response = $driver->replyReview((string) $review->external_id, $reply);

                    $review->forceFill([
                        'seller_reply_status' => self::STATUS_SENT,
                        'seller_replied_at' => now(),
                        'seller_reply_error' => null,
                        'seller_reply_payload' => $this->safePayload($response),
                    ])->save();

                    $summary['sent']++;
                } catch (Throwable $exception) {
                    $duplicate = $this->isDuplicateReplyException($exception);
                    $errorMessage = $this->friendlyErrorMessage($exception);

                    $review->forceFill([
                        'seller_reply_status' => $duplicate ? self::STATUS_SENT : self::STATUS_FAILED,
                        'seller_replied_at' => $duplicate ? now() : null,
                        'seller_reply_error' => $duplicate ? null : $errorMessage,
                        'seller_reply_payload' => null,
                    ])->save();

                    Log::channel('reviews')->warning('Falha ao responder avaliação da Shopee automaticamente', [
                        'review_id' => $review->id,
                        'external_id' => $review->external_id,
                        'duplicada' => $duplicate,
                        'erro' => $errorMessage,
                    ]);

                    if (! $duplicate) {
                        $this->notifyAdmins($review, $errorMessage);
                    }

                    $summary[$duplicate ? 'sent' : 'failed']++;
                }
            });

        return $summary;
    }

    private function notifyAdmins(Review $review, string $errorMessage): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ShopeeReviewReplyFailedNotification($review->id, $review->rating, $errorMessage));
        }
    }

    private function friendlyErrorMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);

        if ($this->isDuplicateReplyException($exception)) {
            return 'A Shopee informou que essa avaliação já foi respondida.';
        }

        if ($exception instanceof ShopeeException) {
            $result = $exception->context['body']['response']['result_list'][0] ?? null;

            if (is_array($result) && (! empty($result['fail_error']) || ! empty($result['fail_message']))) {
                $detail = trim(($result['fail_error'] ?? '').' '.($result['fail_message'] ?? ''));

                return 'A Shopee recusou a resposta: '.mb_substr($detail, 0, 180);
            }
        }

        if (str_contains($lower, 'check result_list')) {
            return 'A Shopee recusou a resposta. É necessário verificar o resultado detalhado da API para esta avaliação.';
        }

        if (str_contains($lower, 'invalid token') || str_contains($lower, 'access_token')) {
            return 'A Shopee recusou a autenticação da loja. Verifique a conexão da Shopee.';
        }

        if (str_contains($lower, 'not found')) {
            return 'A Shopee não encontrou essa avaliação para resposta.';
        }

        return 'Erro ao responder avaliação da Shopee: '.mb_substr($message, 0, 180);
    }

    private function normalizedRating(mixed $rating): int
    {
        if ($rating === null || (int) $rating < 1) {
            return 0;
        }

        return min((int) $rating, 5);
    }

    private function isDuplicateReplyException(Throwable $exception): bool
    {
        $lower = strtolower($exception->getMessage());

        if (str_contains($lower, 'duplicate') || str_contains($lower, 'replied')) {
            return true;
        }

        if (! $exception instanceof ShopeeException) {
            return false;
        }

        $resultList = $exception->context['body']['response']['result_list'] ?? [];

        foreach ((array) $resultList as $result) {
            if (! is_array($result)) {
                continue;
            }

            $detail = strtolower((string) ($result['fail_error'] ?? '').' '.(string) ($result['fail_message'] ?? ''));

            if (str_contains($detail, 'duplicate') || str_contains($detail, 'replied')) {
                return true;
            }
        }

        return false;
    }

    private function pickTemplate(Review $review, int $rating): string
    {
        $templates = $this->templatesByRating[$rating] ?? $this->templatesByRating[3];
        $wordGroup = $this->reviewWordGroup($review);
        $isCharger = $this->isChargerContext($review);

        if ($rating <= 3 && $wordGroup !== null) {
            $templates = $this->supportTemplatesByKeywordGroup[$wordGroup] ?? $templates;
        }

        if ($isCharger) {
            $templates = [...$templates, ...$this->chargerTemplates];
        }

        $seed = (string) $review->external_id.'|'.$rating.'|'.($wordGroup ?? 'generic').'|'.($isCharger ? 'charger' : 'default');
        $index = abs(crc32($seed)) % count($templates);

        return $templates[$index];
    }

    private function reviewWordGroup(Review $review): ?string
    {
        $text = $this->normalizedSearchText((string) $review->comment);

        if ($this->containsAny($text, ['atraso', 'atrasou', 'demora', 'demorou', 'entrega', 'transportadora', 'correio'])) {
            return 'delivery';
        }

        if ($this->containsAny($text, ['defeito', 'defeituoso', 'quebrado', 'quebrou', 'nao funciona', 'parou', 'danificado', 'avariado'])) {
            return 'defect';
        }

        if ($this->containsAny($text, ['errado', 'diferente', 'faltou', 'incompleto', 'nao veio', 'veio sem', 'nao recebi'])) {
            return 'wrong_or_missing';
        }

        if ($this->containsAny($text, ['fraco', 'lento', 'devagar', 'nao carrega', 'nao carregou', 'esquenta', 'aquecendo', 'aquecimento', 'ruim', 'pessimo'])) {
            return 'performance';
        }

        return null;
    }

    private function isChargerContext(Review $review): bool
    {
        $product = $review->product;
        $text = $this->normalizedSearchText(implode(' ', array_filter([
            $review->comment,
            $product?->name,
            $product?->brand,
            $product?->model,
            $product?->variation,
            $product?->description,
        ])));

        return str_contains($text, 'carregador')
            || str_contains($text, 'carregamento')
            || str_contains($text, 'carga rapida')
            || str_contains($text, 'fonte turbo')
            || str_contains($text, 'fonte usb')
            || str_contains($text, 'adaptador usb')
            || str_contains($text, 'usb-c')
            || str_contains($text, 'tipo c')
            || str_contains($text, 'gan charger');
    }

    private function normalizedSearchText(string $text): string
    {
        return Str::of($text)->ascii()->lower()->toString();
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function templateKey(string $reply, int $rating): string
    {
        $prefix = $rating >= 4 ? 'positive' : 'support';
        $ratingKey = $rating === 0 ? 'none' : (string) $rating;

        return $prefix.'_rating_'.$ratingKey.'_'.substr(sha1($reply), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        return [
            'request_id' => $payload['request_id'] ?? null,
            'error' => $payload['error'] ?? null,
            'message' => $payload['message'] ?? null,
            'response' => $payload['response'] ?? null,
        ];
    }
}
