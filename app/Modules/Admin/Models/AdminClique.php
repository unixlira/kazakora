<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contagem de cliques do painel por dia, elemento, página e usuário
 * (pedido do Lira 2026-10-25). Ver CliquesAdminController.
 */
class AdminClique extends Model
{
    protected $table = 'admin_cliques';

    public const TIPOS = ['menu', 'topo', 'aba', 'botao'];

    protected $fillable = ['dia', 'elemento', 'tipo', 'pagina', 'destino', 'user_id', 'contagem'];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'contagem' => 'integer',
        ];
    }

    /**
     * Caminho sem domínio nem query, com número e código longo com dígito
     * (hash, uuid) trocados por {id}: /admin/pedidos/123 e /admin/pedidos/456 contam
     * como a mesma página.
     */
    public static function normalizarCaminho(?string $caminho): ?string
    {
        if ($caminho === null || $caminho === '') {
            return null;
        }

        $caminho = (string) parse_url($caminho, PHP_URL_PATH);
        $partes = array_map(
            fn (string $parte) => preg_match('/^(\d+|(?=.*\d)[A-Za-z0-9_-]{16,})$/', $parte) === 1 ? '{id}' : $parte,
            explode('/', $caminho),
        );
        $caminho = rtrim(implode('/', $partes), '/');

        return mb_substr($caminho === '' ? '/' : $caminho, 0, 150);
    }

    /** Soma $quantos na linha do dia (cria a linha se não existir). */
    public static function somar(int $userId, string $elemento, string $tipo, string $pagina, ?string $destino, int $quantos, ?Carbon $quando = null): void
    {
        $agora = now();
        $dia = ($quando ?? $agora)->copy()->setTimezone('America/Sao_Paulo')->toDateString();

        DB::table('admin_cliques')->upsert(
            [[
                'dia' => $dia,
                'elemento' => $elemento,
                'tipo' => $tipo,
                'pagina' => $pagina,
                'destino' => $destino,
                'user_id' => $userId,
                'contagem' => $quantos,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]],
            ['dia', 'elemento', 'pagina', 'user_id'],
            [
                'contagem' => DB::raw('contagem + '.(int) $quantos),
                'tipo' => $tipo,
                'destino' => $destino,
                'updated_at' => $agora,
            ],
        );
    }
}
