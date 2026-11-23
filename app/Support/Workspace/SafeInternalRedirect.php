<?php

namespace App\Support\Workspace;

use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Normalize and validate same-origin internal redirect targets.
 * Rejects external origins, protocol-relative URLs, and open redirects.
 */
final class SafeInternalRedirect
{
    /**
     * Accept a relative path or same-origin absolute URL; return a path + query (+ fragment).
     *
     * @throws ValidationException
     */
    public static function normalize(?string $redirect, string $fallback): string
    {
        if ($redirect === null || $redirect === '') {
            return self::toRelative($fallback);
        }

        $redirect = trim($redirect);

        if (str_starts_with($redirect, '//')) {
            throw ValidationException::withMessages([
                'redirect' => 'Invalid redirect target.',
            ]);
        }

        if (str_starts_with($redirect, '/')) {
            return $redirect;
        }

        if (! preg_match('#^https?://#i', $redirect)) {
            throw ValidationException::withMessages([
                'redirect' => 'Invalid redirect target.',
            ]);
        }

        $parts = parse_url($redirect);
        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            throw ValidationException::withMessages([
                'redirect' => 'Invalid redirect target.',
            ]);
        }

        $app = parse_url(URL::to('/'));
        $appHost = strtolower((string) ($app['host'] ?? ''));
        $appPort = isset($app['port']) ? (int) $app['port'] : self::defaultPort((string) ($app['scheme'] ?? 'http'));
        $redirectHost = strtolower($parts['host']);
        $redirectPort = isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($parts['scheme']);

        if ($redirectHost !== $appHost || $redirectPort !== $appPort) {
            throw ValidationException::withMessages([
                'redirect' => 'Invalid redirect target.',
            ]);
        }

        $path = $parts['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#'.$parts['fragment'];
        }

        return $path;
    }

    /**
     * Generate a relative path for an internal named route (Inertia/form payloads).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return route($name, $parameters, absolute: false);
    }

    public static function toRelative(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        try {
            return self::normalize($url, '/');
        } catch (ValidationException) {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : '/';
        }
    }

    private static function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'https' ? 443 : 80;
    }
}
