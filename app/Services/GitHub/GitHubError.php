<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * What GitHub said when it refused a request, in full.
 *
 * RequestException's own message is the status line plus the response body
 * cut short with "(truncated...)", which is the part an admin needed. GitHub
 * answers errors as JSON — a `message`, and for validation failures an
 * `errors` list — so those are read out whole instead. A body that is not
 * JSON is left out rather than shown raw: it is an HTML error page at best.
 */
class GitHubError
{
    public static function describe(Throwable $exception): string
    {
        if (! $exception instanceof RequestException) {
            return $exception->getMessage();
        }

        $response = $exception->response;
        $body = $response->json();

        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : $response->reason();

        $errors = collect(is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [])
            ->map(fn (mixed $error): ?string => match (true) {
                is_string($error) => $error,
                is_array($error) && is_string($error['message'] ?? null) => $error['message'],
                is_array($error) => collect($error)->only(['resource', 'field', 'code'])->filter(fn (mixed $part): bool => is_string($part))->implode(' '),
                default => null,
            })
            ->filter()
            ->implode(' ');

        return trim("GitHub answered {$response->status()}: {$message} {$errors}");
    }
}
