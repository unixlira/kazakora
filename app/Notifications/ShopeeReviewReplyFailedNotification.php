<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ShopeeReviewReplyFailedNotification extends Notification
{
    public function __construct(
        private readonly int $reviewId,
        private readonly ?int $rating,
        private readonly string $reason,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $rating = $this->rating === null ? 'sem nota' : "{$this->rating} estrela(s)";

        return [
            'review_id' => $this->reviewId,
            'rating' => $this->rating,
            'reason' => $this->reason,
            'message' => "Falha ao responder avaliação da Shopee #{$this->reviewId} ({$rating}): {$this->reason}. Verifique em Admin > Avaliações.",
        ];
    }
}
