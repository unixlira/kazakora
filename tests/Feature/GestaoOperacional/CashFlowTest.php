<?php

namespace Tests\Feature\GestaoOperacional;

use App\Models\User;
use App\Modules\Financeiro\Models\CashFlowEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_record_entries_but_only_admin_can_delete_them(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)->post('/admin/fluxo-de-caixa', [
            'type' => CashFlowEntry::TYPE_INCOME,
            'description' => 'Venda avulsa',
            'amount' => 150,
            'entry_date' => now()->toDateString(),
        ])->assertRedirect();

        $entry = CashFlowEntry::query()->firstOrFail();
        $this->assertSame($manager->id, $entry->created_by);

        $this->actingAs($manager)->delete("/admin/fluxo-de-caixa/{$entry->id}")->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->delete("/admin/fluxo-de-caixa/{$entry->id}")->assertRedirect();
        $this->assertDatabaseMissing('cash_flow_entries', ['id' => $entry->id]);
    }

    public function test_subscriber_cannot_record_entries(): void
    {
        $subscriber = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);

        $this->actingAs($subscriber)->get('/admin/fluxo-de-caixa')->assertOk();
        $this->actingAs($subscriber)->post('/admin/fluxo-de-caixa', [
            'type' => CashFlowEntry::TYPE_EXPENSE,
            'description' => 'x',
            'amount' => 10,
            'entry_date' => now()->toDateString(),
        ])->assertForbidden();
    }
}
