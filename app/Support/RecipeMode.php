<?php

namespace App\Support;

class RecipeMode
{
    public const NORMAL = 'NORMAL';

    public const STRICT = 'STRICT_AVAILABLE_INGREDIENTS';

    public static function strict(array $input): bool
    {
        return ($input['mode'] ?? self::NORMAL) === self::STRICT;
    }
}
