<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\VideoParaGoogle;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml para o Google Search Console (pedido 2026-10-12): home, páginas
 * institucionais e todos os produtos ativos (sem as variações, que são a mesma
 * página do produto pai). Cache curto para não pesar no banco a cada visita do robô.
 * 10 minutos (era 1 hora): produto desativado seguia no mapa e o Google via 404.
 * Produto com vídeo leva a extensão de vídeo com miniatura (pedido 2026-10-15:
 * Search Console, "Nenhum URL de miniatura enviado").
 */
class SitemapController
{
    private const PAGINAS = [
        '/politica-de-entrega',
        '/trocas-e-devolucoes',
        '/politica-de-privacidade',
        '/politica-de-cookies',
        '/termos-de-uso',
        '/fale-conosco',
    ];

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addMinutes(10), function () {
            $base = rtrim(config('app.url'), '/');
            $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $linhas = ['<?xml version="1.0" encoding="UTF-8"?>',
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">',
                '  <url><loc>'.$esc($base.'/').'</loc><changefreq>daily</changefreq><priority>1.0</priority></url>'];

            foreach (self::PAGINAS as $caminho) {
                $linhas[] = '  <url><loc>'.$esc($base.$caminho).'</loc><changefreq>monthly</changefreq><priority>0.3</priority></url>';
            }

            Product::query()
                ->where('is_active', true)
                ->whereNull('parent_product_id')
                ->whereNotNull('slug')
                ->orderBy('id')
                ->with(['images' => fn ($images) => $images->select(['id', 'product_id', 'path', 'medium_path', 'thumb_path', 'position', 'is_primary'])
                    ->reorder()->orderByDesc('is_primary')->orderBy('position')])
                ->get(['id', 'slug', 'name', 'description', 'video_path', 'video_duration_seconds', 'created_at', 'updated_at'])
                ->each(function ($p) use (&$linhas, $base, $esc) {
                    $video = VideoParaGoogle::dados($p->video_path, $p->video_duration_seconds, (string) $p->name, $p->description, $p->images->first()?->media_url, $p->created_at);

                    $linhas[] = '  <url><loc>'.$esc($base.'/produtos/'.$p->slug).'</loc>'
                        .($p->updated_at ? '<lastmod>'.$p->updated_at->toAtomString().'</lastmod>' : '')
                        .'<changefreq>weekly</changefreq><priority>0.8</priority>'
                        .($video ? '<video:video>'
                            .'<video:thumbnail_loc>'.$esc($video['miniatura']).'</video:thumbnail_loc>'
                            .'<video:title>'.$esc(mb_substr($video['nome'], 0, 100)).'</video:title>'
                            .'<video:description>'.$esc($video['descricao']).'</video:description>'
                            .'<video:content_loc>'.$esc($video['conteudo']).'</video:content_loc>'
                            .($video['duracao'] ? '<video:duration>'.$video['duracao'].'</video:duration>' : '')
                            .'<video:publication_date>'.$video['enviado_em'].'</video:publication_date>'
                            .'</video:video>' : '')
                        .'</url>';
                });

            $linhas[] = '</urlset>';

            return implode("\n", $linhas)."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
