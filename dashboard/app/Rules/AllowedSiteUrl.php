<?php

namespace App\Rules;

use App\Support\SiteUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedSiteUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        if ($problem = SiteUrlGuard::problem($value)) {
            $fail($problem);
        }
    }
}
