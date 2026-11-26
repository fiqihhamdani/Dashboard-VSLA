<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class GoogleDriveUrl implements ValidationRule
{
    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $url = parse_url($value);
        if (($url['scheme'] ?? '') !== 'https' || strtolower($url['host'] ?? '') !== 'drive.google.com' || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            return false;
        }
        if (preg_match('~^/file/d/[A-Za-z0-9_-]+(?:/(?:view|preview|edit))?/?$~D', $url['path'] ?? '')) {
            return true;
        }
        parse_str($url['query'] ?? '', $query);

        return in_array($url['path'] ?? '', ['/open', '/uc'], true) && is_string($query['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]+$/D', $query['id']) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid($value)) {
            $fail('Gunakan URL file Google Drive yang valid dengan https://drive.google.com/file/d/... atau /open?id=... .');
        }
    }
}
