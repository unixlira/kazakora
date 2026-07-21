<?php

namespace App\Services;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Str;

/**
 * Generates a short, readable, unique SKU from whatever product/variation
 * data is available.
 *
 * Format: CATEGORIA-PRODUTO-MARCA-MODELO-COR-VARIACAO-CODIGO, omitting any
 * segment whose source field wasn't provided. Example: a "Camiseta Básica"
 * in category "Moda", color "Preta", size "P" becomes MOD-CAM-PRE-P-0001;
 * the same product in size "M" becomes MOD-CAM-PRE-M-0001.
 */
class SkuGeneratorService
{
    /**
     * @param  array{categoria?: ?string, produto?: ?string, marca?: ?string, modelo?: ?string, cor?: ?string, variacao?: ?string}  $productData
     */
    public function generate(array $productData): string
    {
        $identifierFields = ['categoria', 'produto', 'marca', 'modelo'];
        $attributeFields = ['cor', 'variacao'];

        $parts = [];

        foreach ($identifierFields as $field) {
            if (filled($productData[$field] ?? null)) {
                $parts[] = $this->abbreviateIdentifier($productData[$field]);
            }
        }

        foreach ($attributeFields as $field) {
            if (filled($productData[$field] ?? null)) {
                $parts[] = $this->abbreviateAttribute($productData[$field]);
            }
        }

        $parts = array_values(array_filter($parts, fn (string $part) => $part !== ''));

        $prefix = $parts === [] ? 'PROD' : implode('-', $parts);

        return $this->nextAvailableSku($prefix);
    }

    /**
     * Identifiers (category, product title, brand, model) are always
     * shortened to a 3-letter code — they're names, not data, so a fixed,
     * predictable length keeps the SKU compact and easy to scan.
     */
    private function abbreviateIdentifier(string $value): string
    {
        return mb_substr($this->normalize($this->firstWord($value)), 0, 3);
    }

    /**
     * Attributes (color, size/voltage/capacity) are often already compact
     * codes (P, M, 20W, 12L) or short words (Inox) — truncating those to 3
     * chars would destroy meaning, so anything already <= 4 chars, or that
     * contains a digit (a unit/measurement), is kept whole.
     */
    private function abbreviateAttribute(string $value): string
    {
        $word = $this->normalize($this->firstWord($value));

        if ($word === '') {
            return '';
        }

        if (mb_strlen($word) <= 4 || preg_match('/\d/', $word) === 1) {
            return $word;
        }

        return mb_substr($word, 0, 3);
    }

    private function firstWord(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        return preg_split('/\s+/', $trimmed)[0];
    }

    /**
     * Uppercase, accent-free, alphanumeric only — no spaces, slashes, dots
     * or other symbols can survive into a SKU segment.
     */
    private function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($value)));
    }

    /**
     * Finds the highest existing sequence number for this exact prefix and
     * returns prefix-NNNN for the next one, re-checking for existence in a
     * loop as a defence against races (two requests generating the same
     * "next" number at once) and against gaps left by soft-deleted rows.
     */
    private function nextAvailableSku(string $prefix): string
    {
        $maxSequence = Product::query()
            ->withTrashed()
            ->where('sku', 'like', $prefix.'-%')
            ->pluck('sku')
            ->map(function (string $sku) use ($prefix) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{4})$/', $sku, $matches) === 1) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max() ?? 0;

        $sequence = $maxSequence + 1;

        do {
            $candidate = sprintf('%s-%04d', $prefix, $sequence);
            $taken = Product::query()->withTrashed()->where('sku', $candidate)->exists();
            $sequence++;
        } while ($taken);

        return $candidate;
    }
}
