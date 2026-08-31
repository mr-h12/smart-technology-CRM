<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The boundary for `POST /deals/{deal}/documents`.
 *
 * ⚠️ **No `mimes:` rule, and that is the point of §17, not an omission.**
 * Laravel's `mimes:` reads the client-supplied extension — the one part of an
 * upload §17 says a browser fully controls and a spoofed `.pdf` exploits.
 * `UploadValidatorInterface::validate()` is what actually decides the type,
 * from the bytes, and it runs after this class hands the use case a path.
 * Two checks against two different signals would let them disagree; there is
 * one check, and it is the one that reads the file.
 *
 * `max:` is left out for the same reason: `D-71`'s ceiling is configuration
 * (`AP-08`), and `FinfoUploadValidator` already reads it from there. A `max:`
 * rule here would be a second ceiling, hard-coded, one deployment away from
 * disagreeing with the first.
 */
final class AttachDealDocumentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'document' => (string) __('deals.attributes.document'),
        ];
    }

    public function document(): UploadedFile
    {
        $file = $this->file('document');

        if (! $file instanceof UploadedFile) {
            // Unreachable once validation has passed: `required|file` already
            // refused anything else. Guarding it anyway is cheaper than a
            // controller that trusts a validated() array to be typed.
            throw new RuntimeException('The document request validated a field that is not an uploaded file.');
        }

        return $file;
    }
}
