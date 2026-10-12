<?php

namespace App\Support;

use Illuminate\Http\Client\Response;

class GeminiErrorDiagnostics
{
    public static function context(Response $response): array
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        // Provider errors can echo input. Only approved diagnostic phrases reach logs.
        $safe = [];
        foreach (['Request contains an invalid argument.', 'This model is currently experiencing high demand.', 'Invalid JSON payload received.', 'Unknown name', 'Quota exceeded', 'API key not valid'] as $phrase) {
            if (str_contains($message, $phrase)) {
                $safe[] = $phrase;
            }
        }
        $fields = [];
        $encoded = json_encode($error) ?: '';
        foreach (['responseMimeType', 'responseJsonSchema', 'responseSchema', 'responseFormat', 'thinkingConfig', 'thinkingLevel', 'maxOutputTokens', 'additionalProperties', 'maxItems', 'minimum', 'maximum'] as $field) {
            if (str_contains($encoded, $field)) {
                $fields[] = $field;
            }
        }
        $details = [];
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            $type = is_array($detail) ? ($detail['@type'] ?? '') : '';
            if (in_array($type, ['type.googleapis.com/google.rpc.BadRequest', 'type.googleapis.com/google.rpc.ErrorInfo', 'type.googleapis.com/google.rpc.QuotaFailure', 'type.googleapis.com/google.rpc.RetryInfo'], true)) {
                $details[] = ['type' => $type];
            }
        }

        return ['http_status' => $response->status(), 'error' => [
            'code' => is_int($error['code'] ?? null) ? $error['code'] : null,
            'status' => in_array($error['status'] ?? '', ['INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'RESOURCE_EXHAUSTED', 'NOT_FOUND', 'INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED'], true) ? $error['status'] : 'UNKNOWN',
            'message' => $safe ? implode(' ', $safe) : '[Pesan provider disaring]',
            'details' => $details,
        ], 'referenced_fields' => $fields];
    }

    public static function isQuota(Response $response): bool
    {
        return $response->status() === 429
            || $response->json('error.status') === 'RESOURCE_EXHAUSTED'
            || str_contains(json_encode($response->json('error.details')) ?: '', 'google.rpc.QuotaFailure');
    }
}
