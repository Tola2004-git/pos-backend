<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// A "data:image/png;base64,..." prefix match only checks the label - it
// doesn't prove the bytes after it are actually a valid image of that type.
// Decoding and reading the real image header catches a relabeled text file,
// truncated upload, or disguised payload before it's stored as a product
// photo or profile picture.
class ValidBase64Image implements ValidationRule
{
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,(.+)$/s', $value, $matches)) {
            $fail('The :attribute must be a base64-encoded PNG, JPEG, GIF, or WEBP image.');
            return;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false) {
            $fail('The :attribute contains invalid base64 data.');
            return;
        }

        $info = @getimagesizefromstring($decoded);
        if ($info === false || ! in_array($info['mime'], self::ALLOWED_MIME_TYPES, true)) {
            $fail('The :attribute is not a valid image file.');
        }
    }
}
