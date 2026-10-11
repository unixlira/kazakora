<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('reviews', 'seller_reply')) {
                $table->text('seller_reply')->nullable()->after('comment');
            }

            if (! Schema::hasColumn('reviews', 'seller_replied_at')) {
                $table->timestamp('seller_replied_at')->nullable()->after('seller_reply');
            }

            if (! Schema::hasColumn('reviews', 'seller_reply_attempted_at')) {
                $table->timestamp('seller_reply_attempted_at')->nullable()->after('seller_replied_at');
            }

            if (! Schema::hasColumn('reviews', 'seller_reply_status')) {
                $table->string('seller_reply_status')->nullable()->after('seller_reply_attempted_at');
            }

            if (! Schema::hasColumn('reviews', 'seller_reply_template')) {
                $table->string('seller_reply_template')->nullable()->after('seller_reply_status');
            }

            if (! Schema::hasColumn('reviews', 'seller_reply_error')) {
                $table->text('seller_reply_error')->nullable()->after('seller_reply_template');
            }

            if (! Schema::hasColumn('reviews', 'seller_reply_payload')) {
                $table->json('seller_reply_payload')->nullable()->after('seller_reply_error');
            }
        });

        if (! $this->indexExists('reviews', 'reviews_auto_reply_lookup_idx')) {
            try {
                Schema::table('reviews', function (Blueprint $table) {
                    $table->index(['channel', 'rating', 'seller_reply_status'], 'reviews_auto_reply_lookup_idx');
                });
            } catch (Throwable $exception) {
                if (! str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                    throw $exception;
                }
            }
        }

        // Trava de segurança: ao ativar a automação, avaliações Shopee que já
        // estavam importadas não podem sair recebendo resposta em massa no
        // primeiro cron. Só avaliações novas daqui pra frente ficam com status
        // nulo e entram no fluxo automático.
        if (Schema::hasColumn('reviews', 'seller_reply_status')) {
            DB::table('reviews')
                ->where('channel', 'shopee')
                ->whereNotNull('external_id')
                ->where('rating', '>=', 4)
                ->whereNull('seller_reply_status')
                ->update([
                    'seller_reply_status' => 'skipped',
                    'seller_reply_error' => 'Automação de respostas habilitada após esta avaliação já existir no KazaKora.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if ($this->indexExists('reviews', 'reviews_auto_reply_lookup_idx')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dropIndex('reviews_auto_reply_lookup_idx');
            });
        }

        Schema::table('reviews', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('reviews', 'seller_reply') ? 'seller_reply' : null,
                Schema::hasColumn('reviews', 'seller_replied_at') ? 'seller_replied_at' : null,
                Schema::hasColumn('reviews', 'seller_reply_attempted_at') ? 'seller_reply_attempted_at' : null,
                Schema::hasColumn('reviews', 'seller_reply_status') ? 'seller_reply_status' : null,
                Schema::hasColumn('reviews', 'seller_reply_template') ? 'seller_reply_template' : null,
                Schema::hasColumn('reviews', 'seller_reply_error') ? 'seller_reply_error' : null,
                Schema::hasColumn('reviews', 'seller_reply_payload') ? 'seller_reply_payload' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $existing) {
                if (($existing['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
