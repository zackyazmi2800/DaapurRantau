<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

class VisitorIdentity
{
    public function id(Session $session): string
    {
        if (! is_string($session->get('visitor_id')) || ! Str::isUuid($session->get('visitor_id'))) {
            $session->put('visitor_id', (string) Str::uuid());
        }

        return $session->get('visitor_id');
    }
}
