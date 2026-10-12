<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecipeImageService
{
    private const VERSION = '8';

    private const QUERIES = [
        'nasi goreng telur' => 'Indonesian egg fried rice',
        'nasi goreng' => 'Indonesian fried rice',
        'mie goreng' => 'Indonesian fried noodles',
        'telur dadar' => 'Indonesian omelette',
        'ayam kecap' => 'Indonesian soy sauce chicken',
        'tumis kangkung' => 'Stir fried water spinach',
        'telur rebus' => 'boiled egg',
    ];

    // Controlled dish families keep queries useful when AI adds descriptive title words.
    private const FAMILIES = [
        'nasi goreng' => ['nasi goreng', 'fried rice'],
        'mie goreng' => ['mie goreng', 'mi goreng', 'fried noodles', 'fried noodle'],
        'telur dadar' => ['telur dadar', 'omelette', 'omelet'],
        'ayam kecap' => ['ayam kecap', 'soy sauce chicken', 'chicken in soy sauce', 'chicken with soy sauce'],
        'tumis kangkung' => ['tumis kangkung', 'stir fried water spinach', 'stir fried kangkung'],
        'telur rebus' => ['telur rebus', 'boiled egg', 'boiled eggs'],
        'sayur bening' => ['sayur bening', 'spinach soup', 'clear vegetable soup'],
        'tumis tahu' => ['tumis tahu', 'stir fried tofu', 'tofu stir fry'],
        'sup tahu' => ['sup tahu', 'sop tahu', 'tofu soup'],
        'sup seafood' => ['sup seafood', 'sop seafood', 'seafood soup', 'shrimp soup', 'prawn soup', 'squid soup'],
        'ayam goreng' => ['ayam goreng', 'fried chicken'],
        'ayam bakar' => ['ayam bakar', 'grilled chicken'],
        'ikan goreng' => ['ikan goreng', 'fried fish'],
        'telur kukus' => ['telur kukus', 'steamed egg', 'steamed eggs'],
        'bakwan' => ['bakwan', 'vegetable fritters'],
        'perkedel' => ['perkedel', 'potato fritters'],
        'capcay' => ['capcay', 'cap cai', 'stir fried vegetables'],
        'soto' => ['soto', 'indonesian chicken soup'],
        'sate' => ['sate', 'satay'],
        'rendang' => ['rendang'],
        'sup sayur' => ['sup sayur', 'sop sayur', 'vegetable soup'],
        'tumis sayur' => ['tumis sayur', 'vegetable stir fry'],
    ];

    private function dishFamily(string $name): ?string
    {
        $title = implode(' ', $this->keywords($name));
        foreach (self::FAMILIES as $family => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->containsPhrase($title, $alias)) {
                    return $family;
                }
            }
        }
        $words = $this->keywords($name);
        if (in_array('tumis', $words, true) && in_array('tahu', $words, true)) {
            return 'tumis tahu';
        }
        if (array_intersect($words, ['sup', 'sop', 'soup'])) {
            if (in_array('tahu', $words, true)) {
                return 'sup tahu';
            }
            if (array_intersect($words, ['seafood', 'udang', 'cumi'])) {
                return 'sup seafood';
            }

            return 'sup sayur';
        }
        if (in_array('tumis', $words, true)) {
            return 'tumis sayur';
        }

        return null;
    }

    public function normalizeRecipeName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($name)));
    }

    public function buildSearchQuery(string $name): string
    {
        $name = $this->normalizeRecipeName($name);

        if (isset(self::QUERIES[$name])) {
            return self::QUERIES[$name];
        }
        $family = $this->dishFamily($name);
        $query = self::QUERIES[$family ?? ''] ?? match ($family) {
            'sayur bening' => 'clear vegetable soup',
            'tumis tahu' => 'stir fried tofu',
            'sup tahu' => 'tofu soup',
            'sup seafood' => 'seafood soup',
            default => $name,
        };
        // Translate only known meaningful modifiers, never invented adjectives from a title.
        if ($family !== null) {
            $words = $this->keywords($name);
            foreach (['seafood' => 'seafood', 'udang' => 'shrimp', 'cumi' => 'squid',
                'jagung' => 'corn', 'wortel' => 'carrot', 'tahu' => 'tofu', 'bayam' => 'spinach', 'sayur' => 'vegetable', 'telur' => 'egg'] as $word => $english) {
                if (in_array($word, $words, true) && ! in_array($english, $this->keywords($query), true)
                    && ! ($word === 'telur' && $family === 'telur dadar')) {
                    $query .= ' '.$english;
                }
            }
        }

        return $query;
    }

    public function buildSearchQueries(string $name): array
    {
        $name = $this->normalizeRecipeName($name);
        if ($name === '') {
            return [];
        }
        $words = explode(' ', $name);
        $family = $this->dishFamily($name);
        // Unknown dishes retain at least two words: never reduce 'ayam ...' to raw 'ayam'.
        $base = $family ?? implode(' ', array_slice($words, 0, 2));
        $queries = [$name];
        if (count($words) > 2) {
            array_pop($words);
            $shorter = implode(' ', $words);
            // Keep required identity even for non-prefix names, e.g. sup ... tahu.
            if ($family === null || $this->dishFamily($shorter) === $family) {
                $queries[] = $shorter;
            }
        }
        $queries[] = $base;
        if ($family === 'telur dadar') {
            $queries = [...$queries, 'vegetable omelette', 'omelette'];
        } elseif ($family !== null) {
            // The final alias is a controlled equivalent, not an AI translation call.
            $queries[] = match ($family) {
                'nasi goreng' => 'fried rice',
                'mie goreng' => 'fried noodles',
                'sup seafood' => 'seafood soup',
                'ayam kecap' => 'soy sauce chicken',
                'tumis kangkung' => 'stir fried water spinach',
                default => self::FAMILIES[$family][array_key_last(self::FAMILIES[$family])],
            };
        }

        return array_slice(array_values(array_unique($queries)), 0, 5);
    }

    private function similarCacheKey(string $name): ?string
    {
        $family = $this->dishFamily($name);

        return $family === null ? null : 'recipe-image-similar:2:'.hash('sha256', $family);
    }

    public function cacheKey(string $name): string
    {
        return 'recipe-image:'.self::VERSION.':'.hash('sha256', $this->normalizeRecipeName($name));
    }

    public function getPlaceholder(): array
    {
        return ['image_url' => '/images/recipe-placeholder.svg', 'image_small_url' => '/images/recipe-placeholder.svg',
            'image_alt' => 'Ilustrasi peralatan masak; foto masakan belum tersedia', 'image_source' => 'placeholder',
            'photographer_name' => null, 'photographer_url' => null, 'photo_url' => null, 'unsplash_photo_id' => null];
    }

    // Pure display validation: never performs HTTP, including for legacy favorites.
    public function forDisplay(mixed $image): array
    {
        if (! is_array($image)) {
            return $this->getPlaceholder();
        }
        if (($image['image_source'] ?? '') === 'local' && $this->localPath($image['image_url'] ?? null)) {
            return array_replace($this->getPlaceholder(), ['image_source' => 'local', 'image_url' => $image['image_url'],
                'image_small_url' => $image['image_url'], 'image_alt' => 'Foto masakan terkurasi']);
        }
        if (($image['image_source'] ?? '') === 'unsplash'
            && $this->url($image['image_url'] ?? null, 'images.unsplash.com')
            && $this->url($image['image_small_url'] ?? null, 'images.unsplash.com')
            && $this->url($image['photographer_url'] ?? null, 'unsplash.com')
            && $this->url($image['photo_url'] ?? null, 'unsplash.com')
            && $this->text($image['photographer_name'] ?? null)
            && $this->text($image['image_alt'] ?? null)
            && is_string($image['unsplash_photo_id'] ?? null)
            && preg_match('/\A[\w-]{1,100}\z/', $image['unsplash_photo_id'])) {
            return array_intersect_key($image, $this->getPlaceholder());
        }

        return $this->getPlaceholder();
    }

    public function getRecipeImage(string $title): array
    {
        return $this->getImagesForRecipes([['title' => $title]])[0];
    }

    public function getImagesForRecipes(array $recipes): array
    {
        if (count($recipes) > 3) {
            throw new \InvalidArgumentException('Maksimal tiga resep per batch gambar.');
        }
        $output = array_fill(0, count($recipes), $this->getPlaceholder());
        $pending = [];
        $lock = null;
        $acquired = false;
        try {
            foreach (array_values($recipes) as $index => $recipe) {
                $name = $this->normalizeRecipeName($recipe['title']);
                $local = config('services.unsplash.local_images', [])[$name] ?? null;
                if ($this->localPath($local)) {
                    $output[$index] = $this->forDisplay(['image_source' => 'local', 'image_url' => $local]);

                    continue;
                }
                $key = $this->cacheKey($name);
                $cached = $this->cachedImage($key) ?? $this->cachedSimilarImage($name);
                if ($cached !== null) {
                    $output[$index] = $cached;
                } elseif ($name !== '') {
                    $pending[$key]['name'] = $name;
                    $pending[$key]['queries'] = $this->buildSearchQueries($name);
                    $pending[$key]['indices'][] = $index;
                }
            }
            $accessKey = config('services.unsplash.access_key');
            if ($pending === [] || ! is_string($accessKey) || trim($accessKey) === ''
                || config('services.unsplash.base_url') !== 'https://api.unsplash.com'
                || ! (Cache::getStore() instanceof LockProvider)) {
                return $output;
            }
            $scope = 'unsplash:'.hash('sha256', $accessKey);
            // One batch at a time per credential; up to three searches run concurrently inside it.
            $lock = Cache::lock($scope.':lock', 65);
            $acquired = $lock->get();
            if (! $acquired || Cache::has($scope.':cooldown')) {
                return $output;
            }
            foreach ($pending as $key => $entry) {
                if (($cached = $this->cachedImage($key) ?? $this->cachedSimilarImage($entry['name'])) !== null) {
                    foreach ($entry['indices'] as $index) {
                        $output[$index] = $cached;
                    }
                    unset($pending[$key]);
                }
            }
            // Up to five rounds; unresolved recipes run concurrently within each round.
            for ($level = 0; $level < 5 && $pending !== []; $level++) {
                if (Cache::has($scope.':cooldown')) {
                    break;
                }
                Cache::add($scope.':budget', 0, 3600);
                $slots = max(0, 45 - (int) Cache::get($scope.':budget'));
                $remaining = Cache::get($scope.':remaining');
                if (is_int($remaining)) {
                    $slots = min($slots, max(0, $remaining - 2));
                }
                $pending = array_slice($pending, 0, $slots, true);
                if ($pending === []) {
                    return $output;
                }
                Cache::increment($scope.':budget', count($pending));
                if (is_int($remaining)) {
                    Cache::decrement($scope.':remaining', count($pending));
                }
                $responses = Http::pool(function (Pool $pool) use ($pending, $accessKey, $level) {
                    foreach ($pending as $key => $entry) {
                        $pool->as($key)->acceptJson()->withHeaders(['Authorization' => 'Client-ID '.$accessKey, 'Accept-Version' => 'v1'])
                            ->withoutRedirecting()->connectTimeout(3)->timeout(min(10, max(1, (int) config('services.unsplash.timeout'))))
                            ->get(config('services.unsplash.base_url').'/search/photos', [
                                'query' => $entry['queries'][$level],
                                'per_page' => min(30, max(1, (int) config('services.unsplash.per_page'))),
                                'orientation' => 'landscape', 'order_by' => 'relevant', 'content_filter' => 'high',
                            ]);
                    }
                }, concurrency: 3);
                $next = [];
                foreach ($pending as $key => $entry) {
                    $response = $responses[$key] ?? null;
                    // A less specific query is only useful after a valid search response.
                    // Transport/auth/quota errors must never trigger extra requests.
                    $canBroaden = isset($entry['queries'][$level + 1])
                        && $response instanceof Response && $response->successful()
                        && is_array($response->json('results')) && array_is_list($response->json('results'));
                    $image = $this->processResponse($response, $entry['name'], $key, $scope, ! $canBroaden);
                    foreach ($entry['indices'] as $index) {
                        $output[$index] = $image;
                    }
                    if ($canBroaden && $image['image_source'] === 'placeholder') {
                        $next[$key] = $entry;
                    }
                }
                $pending = $next;
            }
        } catch (Throwable) {
            // No raw exception logging; preserve any already resolved local/cache results.
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The lease expires if the cache backend becomes unavailable.
                }
            }
        }

        return $output;
    }

    private function cachedSimilarImage(string $name): ?array
    {
        $key = $this->similarCacheKey($name);
        if ($key === null) {
            return null;
        }
        $image = $this->cachedImage($key);
        if (($image['image_source'] ?? null) !== 'unsplash') {
            return null;
        }
        $image['image_alt'] = 'Foto ilustrasi '.$name.'; tampilan masakan dapat berbeda';

        return $image;
    }

    private function cachedImage(string $key): ?array
    {
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            return null;
        }
        $image = $this->forDisplay($cached);
        if ($image['image_source'] === 'placeholder' && ($cached['image_source'] ?? null) !== 'placeholder') {
            Cache::forget($key);

            return null;
        }

        return $image;
    }

    private function processResponse(mixed $response, string $name, string $key, string $scope, bool $cachePlaceholder = true): array
    {
        $image = $this->getPlaceholder();
        $ttl = min(60, max(1, (int) config('services.unsplash.negative_cache_ttl')));
        try {
            if (! $response instanceof Response) {
                Log::warning('unsplash.search', ['status' => 'connection_or_timeout']);
            } else {
                $remaining = $response->header('X-Ratelimit-Remaining');
                $limit = $response->header('X-Ratelimit-Limit');
                Log::info('unsplash.search', ['status' => $response->status(),
                    'remaining' => ctype_digit($remaining) ? (int) $remaining : null,
                    'limit' => ctype_digit($limit) ? (int) $limit : null]);
                if (ctype_digit($remaining)) {
                    $previous = Cache::get($scope.':remaining');
                    Cache::put($scope.':remaining', is_int($previous) ? min($previous, (int) $remaining) : (int) $remaining, 3600);
                }
                if ($response->status() === 429 || (ctype_digit($remaining) && (int) $remaining <= 2)) {
                    $retry = $response->header('Retry-After');
                    $delay = ctype_digit($retry) ? (int) $retry : max(0, (strtotime($retry) ?: 0) - time());
                    $until = max((int) Cache::get($scope.':cooldown_until', 0), time() + max(3600, $delay));
                    Cache::put($scope.':cooldown_until', $until, $until - time());
                    Cache::put($scope.':cooldown', true, $until - time());
                    $ttl = max(3600, $delay);
                } elseif (in_array($response->status(), [401, 403], true)) {
                    if (! Cache::has($scope.':cooldown')) {
                        Cache::put($scope.':cooldown', true, 3600);
                    }
                    $ttl = 3600;
                }
                // All responses in the current batch are processed even when one activates cooldown.
                if ($response->successful()) {
                    $results = $response->json('results');
                    if (is_array($results) && array_is_list($results)) {
                        $image = $this->selectRelevantImage($results, $name) ?? $image;
                        Log::info('unsplash.selection', ['status' => $image['image_source'] === 'placeholder' ? 'no_relevant_photo' : 'selected',
                            'candidate_count' => count($results)]);
                        $ttl = max(1, (int) config($image['image_source'] === 'placeholder'
                            ? 'services.unsplash.negative_cache_ttl' : 'services.unsplash.cache_ttl'));
                    }
                }
            }
        } catch (Throwable) {
            // Malformed metadata only affects this recipe.
        }
        try {
            if ($cachePlaceholder || $image['image_source'] !== 'placeholder') {
                Cache::put($key, $image, $ttl);
                if ($image['image_source'] === 'unsplash' && ($similarKey = $this->similarCacheKey($name)) !== null) {
                    Cache::put($similarKey, $image, $ttl);
                }
            }
        } catch (Throwable) {
            // A photo that was validated remains usable even if writing its cache fails.
        }

        return $image;
    }

    private function selectRelevantImage(array $photos, string $name): ?array
    {
        $best = null;
        $bestScore = 0;
        foreach ($photos as $index => $photo) {
            if (! is_array($photo)) {
                $this->logCandidate($index, 'invalid_metadata');

                continue;
            }
            $description = $photo['description'] ?? null;
            $alt = $photo['alt_description'] ?? null;
            if (($description !== null && ! $this->text($description)) || ($alt !== null && ! $this->text($alt))) {
                $this->logCandidate($index, 'invalid_metadata');

                continue;
            }
            $score = $this->photoRelevanceScore($name, $description ?? '', $alt ?? '');
            if ($score <= $bestScore) {
                $this->logCandidate($index, $score === 0 ? ($this->conflictingPhoto($name, ($description ?? '').' '.($alt ?? '')) ? 'conflicting_dish' : 'dish_not_identified') : 'lower_rank');

                continue;
            }
            $image = $this->forDisplay([
                'image_url' => $photo['urls']['regular'] ?? null, 'image_small_url' => $photo['urls']['small'] ?? null,
                'image_alt' => 'Foto ilustrasi '.$name.'; tampilan masakan dapat berbeda', 'image_source' => 'unsplash',
                'photographer_name' => $photo['user']['name'] ?? null,
                'photographer_url' => $photo['user']['links']['html'] ?? null,
                'photo_url' => $photo['links']['html'] ?? null, 'unsplash_photo_id' => $photo['id'] ?? null,
            ]);
            if ($image['image_source'] === 'unsplash') {
                $image['photographer_url'] = $this->attributionUrl($image['photographer_url']);
                $image['photo_url'] = $this->attributionUrl($image['photo_url']);

                $best = $image;
                $bestScore = $score;
                $this->logCandidate($index, 'eligible');
            } else {
                $this->logCandidate($index, 'invalid_url_or_attribution');
            }
        }

        return $best;
    }

    private function logCandidate(int|string $index, string $reason): void
    {
        Log::info('unsplash.candidate', ['index' => is_int($index) ? $index : null, 'reason' => $reason]);
    }

    private function conflictingPhoto(string $name, string $metadata): bool
    {
        $family = $this->dishFamily($name);
        $words = $this->keywords($metadata);
        if ($family === 'telur dadar' && array_intersect($words, ['soup', 'sup', 'quiche'])) {
            return true;
        }

        // Tofu in noodle soup does not make it a vegetable/tofu soup illustration.
        return in_array($family, ['sup tahu', 'sayur bening', 'sup sayur'], true)
            && (bool) array_intersect($words, ['noodle', 'noodles', 'pasta', 'mie', 'bihun', 'ramen']);
    }

    private function photoRelevanceScore(string $name, string $description, string $alt): int
    {
        if ($this->conflictingPhoto($name, $description.' '.$alt)) {
            return 0;
        }
        $score = $this->relevanceScore($name, $description.' '.$alt);

        // Prefer candidates independently supported by the visual alt description.
        return $score > 0 ? $score + $this->relevanceScore($name, $alt) : 0;
    }

    private function keywords(string $text): array
    {
        return array_values(array_unique(preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY)));
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        return str_contains(' '.$text.' ', ' '.$phrase.' ');
    }

    private function relevanceScore(string $name, string $metadata): int
    {
        $title = implode(' ', preg_split('/[^\pL\pN]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY));
        $text = implode(' ', preg_split('/[^\pL\pN]+/u', mb_strtolower($metadata), -1, PREG_SPLIT_NO_EMPTY));
        $words = $this->keywords($metadata);
        // Dish identity is mandatory. Modifiers such as egg/sayur improve ranking,
        // but generic rice/food/plate alone never identifies fried rice.
        $family = $this->dishFamily($name);
        $known = $family !== null;
        $score = 0;
        foreach (self::FAMILIES[$family ?? ''] ?? [] as $alias) {
            if ($this->containsPhrase($text, $alias)) {
                $score = 10;
                break;
            }
        }
        // Core dish words may be separated: 'soup with tofu', 'stir fry with tofu'.
        $core = match ($family) {
            'sup tahu' => [['soup', 'sup', 'sop'], ['tofu', 'tahu']],
            'sayur bening' => [['soup', 'sup', 'sop'], ['spinach', 'bayam', 'vegetable', 'vegetables']],
            'tumis tahu' => [['stir', 'tumis'], ['fried', 'fry', 'tumis'], ['tofu', 'tahu']],
            default => [],
        };
        if ($score === 0 && $core !== [] && count(array_filter($core, fn ($group) => (bool) array_intersect($group, $words))) === count($core)) {
            $score = 10;
        }
        if ($known && $score === 0) {
            return 0;
        }
        if (! $known) {
            $tokens = array_values(array_diff($this->keywords($name), ['dan', 'dengan', 'ala', 'praktis', 'sederhana', 'hemat', 'mudah', 'lezat', 'rumahan']));
            $matches = count(array_intersect($tokens, $words));
            $anchor = array_slice($tokens, 0, 2);
            $anchorMatches = count($anchor) > 0 && count(array_intersect($anchor, $words)) === count($anchor);
            if (! $anchorMatches && (count($tokens) < 2 || $matches < max(2, (int) ceil(count($tokens) * 0.75)))) {
                return 0;
            }
            $score = 5 + $matches;
        }
        foreach (['telur' => ['egg', 'eggs', 'telur'], 'sayur' => ['vegetable', 'vegetables', 'sayur'],
            'tahu' => ['tofu', 'tahu'], 'bayam' => ['spinach', 'bayam'],
            'seafood' => ['seafood', 'shrimp', 'prawn', 'squid'], 'udang' => ['shrimp', 'prawn', 'udang'],
            'cumi' => ['squid', 'cumi'], 'ayam' => ['chicken', 'ayam'], 'pedas' => ['spicy', 'chili', 'chilli', 'pedas']] as $modifier => $aliases) {
            if (in_array($modifier, $this->keywords($name), true) && array_intersect($aliases, $words)) {
                $score += 3;
            }
        }

        return $score + ($this->containsPhrase($text, $title) ? 5 : 0);
    }

    private function attributionUrl(string $url): string
    {
        $parts = explode('#', $url, 2);

        return $parts[0].(str_contains($parts[0], '?') ? '&' : '?').'utm_source=dapur_rantau&utm_medium=referral'
            .(isset($parts[1]) ? '#'.$parts[1] : '');
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 3000;
    }

    private function url(mixed $value, string $host): bool
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return false;
        }
        $parts = parse_url($value);

        return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    private function localPath(mixed $path): bool
    {
        return is_string($path) && preg_match('#\A/images/recipes/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)\z#', $path)
            && is_file(public_path(ltrim($path, '/')));
    }
}
