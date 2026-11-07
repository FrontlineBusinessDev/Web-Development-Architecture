<?php

namespace App\Rules;

use App\Support\VirusScan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Validation rule for upload fields with no shared store method: `'receipt' => ['required', 'file', new Scanned('payment-proof')]`.
 * Put it last, after size and type, so nothing is sent to the scanner that the app would refuse anyway. A purpose whose
 * policy is `hold` needs the store method instead (the caller must know to mark the file pending).
 * Copied from Web-Development-Architecture templates/scan/ (integrations/fbs-suites-scanner.md).
 */
final class Scanned implements ValidationRule
{
    public function __construct(private string $purpose, private ?string $tenant = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        try {
            VirusScan::clean($value, $this->purpose, $this->tenant, $attribute);
        } catch (ValidationException $e) {
            $fail((string) collect($e->errors())->flatten()->first());
        }
    }
}
