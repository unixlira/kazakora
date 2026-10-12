<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Produção recebe primeiro só a coluna site nula (025000); depois a 030000
 * marca o que ficou nulo como kazakora e põe o padrão.
 */
class SiteVisitsColunaSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_030000_preenche_nulos_com_kazakora_quando_a_coluna_ja_existe(): void
    {
        $mig030 = require database_path('migrations/2026_10_13_030000_add_site_to_site_visits.php');
        $mig025 = require database_path('migrations/2026_10_13_025000_add_site_nullable_to_site_visits.php');

        // Volta ao estado da produção: sem a coluna, depois só a 025000.
        $mig030->down();
        $this->assertFalse(Schema::hasColumn('site_visits', 'site'));
        $mig025->up();

        DB::table('site_visits')->insert(['visitor_id' => 'a', 'path' => '/', 'ip' => '1.1.1.1', 'created_at' => now()]);
        $this->assertNull(DB::table('site_visits')->value('site'));

        $mig030->up();
        $this->assertSame('kazakora', DB::table('site_visits')->value('site'));

        DB::table('site_visits')->insert(['visitor_id' => 'b', 'path' => '/', 'ip' => '2.2.2.2', 'created_at' => now()]);
        $this->assertSame(0, DB::table('site_visits')->whereNull('site')->count());

        // Rodar a 025000 de novo (homolog) não mexe em nada.
        $mig025->up();
        $this->assertSame(2, DB::table('site_visits')->where('site', 'kazakora')->count());
    }
}
