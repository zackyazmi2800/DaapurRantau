<?php

namespace App\Exceptions;

use RuntimeException;

class RecipeGenerationException extends RuntimeException
{
    public const UNAVAILABLE = 'Maaf, rekomendasi masakan belum tersedia. Silakan coba lagi.';

    public const INCOMPLETE = 'Belum tersedia tiga resep unik yang aman. Coba tambahkan atau ubah bahan makananmu.';

    public const CATEGORIES = 'Daftar bahan resep belum dapat diproses dengan benar. Silakan coba lagi.';

    public const OUTAGE = 'Maaf, layanan rekomendasi sedang mengalami gangguan. Silakan coba kembali nanti.';

    public const QUOTA = 'Rekomendasi AI sedang mencapai batas penggunaan. Silakan coba kembali nanti.';

    public function __construct(public readonly string $reason, public readonly int $status = 503, public readonly array $diagnostics = [])
    {
        if ($reason === 'incomplete_categories') {
            parent::__construct('Rekomendasi belum lengkap untuk tiga kategori. Coba tambahkan atau ubah bahan makananmu.');

            return;
        }
        if ($reason === 'no_best_match') {
            parent::__construct('Belum ditemukan resep paling cocok dengan maksimal dua bahan tambahan. Coba tambahkan atau ubah bahan makananmu.');

            return;
        }
        if ($reason === 'invalid_ingredient_names') {
            parent::__construct(self::CATEGORIES);

            return;
        }
        if (in_array($reason, ['invalid_recipe_count', 'duplicate_recipes', 'allergen_conflict'], true)) {
            parent::__construct(self::INCOMPLETE);

            return;
        }
        parent::__construct($status === 429 ? self::QUOTA : (in_array($reason, ['provider_unavailable', 'connection_or_timeout'], true) ? self::OUTAGE : self::UNAVAILABLE));
    }
}
