<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecipeFailureResponse
{
    public static function make(Request $request, string $message, int $status, array $headers = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status, $headers);
        }
        $request->session()->flashInput($request->only('ingredients', 'servings', 'preference', 'allergies', 'mode', 'idempotency_key'));

        return response()->view('recommendations.error', compact('message'), $status, $headers);
    }
}
