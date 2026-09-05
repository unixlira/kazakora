<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'profile_photo_url')) {
                $table->string('profile_photo_url')->nullable()->after('profile_name');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'contact_notes')) {
                $table->text('contact_notes')->nullable()->after('profile_photo_url');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'unread_count')) {
                $table->unsignedInteger('unread_count')->default(0)->after('needs_human');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'last_message_preview')) {
                $table->text('last_message_preview')->nullable()->after('unread_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('whatsapp_conversations', 'profile_photo_url') ? 'profile_photo_url' : null,
                Schema::hasColumn('whatsapp_conversations', 'contact_notes') ? 'contact_notes' : null,
                Schema::hasColumn('whatsapp_conversations', 'unread_count') ? 'unread_count' : null,
                Schema::hasColumn('whatsapp_conversations', 'last_message_preview') ? 'last_message_preview' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
