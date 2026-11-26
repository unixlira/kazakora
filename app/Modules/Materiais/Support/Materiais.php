<?php

namespace App\Modules\Materiais\Support;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Materiais\Models\LeadMaterial;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** Lista de materiais (config/materiais.php) e o PDF de cada um. */
final class Materiais
{
    /** @return array<string, mixed>|null */
    public static function dados(string $material): ?array
    {
        $dados = config("materiais.lista.{$material}");

        return is_array($dados) ? $dados : null;
    }

    /** Livro Digital com o PDF do material (pelo id do .env ou pelo título), se existir no disco. */
    public static function livro(string $material): ?LivroDigital
    {
        $dados = self::dados($material);
        if ($dados === null) {
            return null;
        }

        $livro = filled($dados['livro_id'] ?? null)
            ? LivroDigital::query()->find((int) $dados['livro_id'])
            : LivroDigital::query()
                ->where('titulo', 'like', '%'.($dados['livro_titulo'] ?? "\0").'%')
                // O ativo (que aparece na página do produto) primeiro; sem ele, o mais novo.
                ->orderByDesc('ativo')->orderByDesc('id')->first();

        return $livro && Storage::disk('local')->exists($livro->arquivo) ? $livro : null;
    }

    public static function linkDoPdf(LeadMaterial $lead): string
    {
        return URL::temporarySignedRoute(
            'api.ferrakora.materiais.pdf',
            now()->addDays(max(1, (int) config('materiais.dias_link', 30))),
            ['material' => $lead->material, 'lead' => $lead->id],
        );
    }
}
