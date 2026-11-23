<?php

use App\Support\Workspace\SafeInternalRedirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

test('relative paths are accepted unchanged', function () {
    expect(SafeInternalRedirect::normalize('/management/company/settings', '/management'))
        ->toBe('/management/company/settings');

    expect(SafeInternalRedirect::normalize('/branch/parties?q=acme', '/branch'))
        ->toBe('/branch/parties?q=acme');
});

test('same-origin absolute urls are normalized to relative paths', function () {
    URL::forceRootUrl('http://localhost');

    $absolute = 'http://localhost/management/company/settings';
    expect(SafeInternalRedirect::normalize($absolute, '/management'))
        ->toBe('/management/company/settings');
});

test('external and protocol-relative redirects are rejected', function () {
    URL::forceRootUrl('http://localhost');

    expect(fn () => SafeInternalRedirect::normalize('https://evil.example/x', '/'))
        ->toThrow(ValidationException::class);

    expect(fn () => SafeInternalRedirect::normalize('//evil.example/x', '/'))
        ->toThrow(ValidationException::class);
});

test('null redirect uses relative fallback', function () {
    expect(SafeInternalRedirect::normalize(null, '/management/companies'))
        ->toBe('/management/companies');
});

test('route helper returns relative paths', function () {
    expect(SafeInternalRedirect::route('owner.settings'))->toStartWith('/');
    expect(SafeInternalRedirect::route('owner.settings'))->not->toContain('://');
});
