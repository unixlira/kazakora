<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Logs\LogFileReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Menu "Log" (pedido explícito 2026-09-14): todos os logs do sistema numa
 * lista só, com filtro de data/hora, de nível (erro, warning, ...) e busca
 * por texto — pra achar o que aconteceu sem depender de SSH e grep.
 *
 * Só leitura, e só admin: log de produção tem dado de cliente, payload de
 * marketplace e trecho de token dentro. A rota fica no grupo `admin` junto
 * com Auditoria pelo mesmo motivo.
 */
class SystemLogController extends Controller
{
    private const NIVEL_LABELS = [
        'debug' => 'Debug',
        'info' => 'Info',
        'notice' => 'Aviso',
        'warning' => 'Warning',
        'error' => 'Erro',
        'critical' => 'Crítico',
        'alert' => 'Alerta',
        'emergency' => 'Emergência',
    ];

    public function index(Request $request, LogFileReader $reader): Response
    {
        $validated = $request->validate([
            'arquivo' => ['nullable', 'string', 'max:120'],
            'niveis' => ['nullable', 'array', 'max:8'],
            'niveis.*' => ['string', Rule::in(LogFileReader::LEVELS)],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:200'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:500'],
            'porPagina' => ['nullable', 'integer', Rule::in([50, 100, 200])],
        ]);

        $arquivos = $reader->files();

        // O nome vem da query string: só vale se for exatamente um dos
        // arquivos listados (nada de ../../.env).
        $arquivo = $validated['arquivo'] ?? null;
        if ($arquivo !== null && ! in_array($arquivo, array_column($arquivos, 'name'), true)) {
            $arquivo = null;
        }

        $de = $this->parseInstant($validated['de'] ?? null);
        // "até" só com o dia (sem hora) significa o dia inteiro, senão
        // filtrar "até 14/09" devolveria nada — pararia à meia-noite.
        $ate = $this->parseInstant($validated['ate'] ?? null, fimDoDia: true);
        $niveis = array_values(array_unique($validated['niveis'] ?? []));
        $pagina = (int) ($validated['pagina'] ?? 1);
        $porPagina = (int) ($validated['porPagina'] ?? 50);

        $resultado = $reader->search([
            'arquivo' => $arquivo,
            'niveis' => $niveis,
            'de' => $de,
            'ate' => $ate,
            'q' => $validated['q'] ?? null,
            'pagina' => $pagina,
            'porPagina' => $porPagina,
        ]);

        return Inertia::render('Admin/Logs/Index', [
            'logs' => collect($resultado['entries'])
                ->values()
                ->map(fn (array $entry, int $indice) => $entry + [
                    'id' => ($pagina - 1) * $porPagina + $indice + 1,
                    'nivelLabel' => self::NIVEL_LABELS[$entry['nivel']] ?? '—',
                ])
                ->all(),
            'arquivos' => collect($arquivos)->map(fn (array $file) => [
                'name' => $file['name'],
                'canal' => $file['channel'],
                'data' => $file['date'],
                'tamanho' => $this->formatarTamanho($file['size']),
                'atualizado' => Carbon::createFromTimestamp($file['modified'], config('app.timezone'))->format('d/m/Y H:i'),
            ])->all(),
            'niveis' => collect(self::NIVEL_LABELS)->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])->values()->all(),
            'filtros' => [
                'arquivo' => $arquivo,
                'niveis' => $niveis,
                'de' => $validated['de'] ?? null,
                'ate' => $validated['ate'] ?? null,
                'q' => $validated['q'] ?? null,
                'porPagina' => $porPagina,
            ],
            'paginacao' => [
                'pagina' => $pagina,
                'porPagina' => $porPagina,
                'temProxima' => $resultado['hasMore'],
            ],
            'varredura' => [
                'arquivos' => $resultado['filesScanned'],
                'lidos' => $this->formatarTamanho($resultado['scanned']),
                'parcial' => $resultado['truncated'],
            ],
        ]);
    }

    private function parseInstant(?string $value, bool $fimDoDia = false): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $instante = Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $fimDoDia && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $instante->endOfDay()
            : $instante;
    }

    private function formatarTamanho(int $bytes): string
    {
        $unidades = ['B', 'KB', 'MB', 'GB'];
        $valor = (float) $bytes;
        $indice = 0;

        while ($valor >= 1024 && $indice < count($unidades) - 1) {
            $valor /= 1024;
            $indice++;
        }

        return $indice === 0
            ? $bytes.' B'
            : number_format($valor, 1, ',', '.').' '.$unidades[$indice];
    }
}
