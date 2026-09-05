<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The boundary for `POST /supplier-quotations/{id}/documents` — §7.2's
 * `pdf_file`, "Scan or PDF of the offer".
 *
 * `AttachDealDocumentRequest`'s rules exactly, and for its reasons rather than
 * by imitation:
 *
 * ⚠️ **No `mimes:` rule.** Laravel's `mimes:` reads the client-supplied
 * extension — the one part of an upload §17 says a browser fully controls and a
 * spoofed `.pdf` exploits. `UploadValidatorInterface::validate()` decides the
 * type from the bytes, after this class hands the use case a path. Two checks
 * against two different signals could disagree; there is one, and it is the one
 * that reads the file.
 *
 * ⚠️ **No `max:` rule.** `D-71`'s ceiling is configuration (`AP-08`) and
 * `FinfoUploadValidator` already reads it from there. A `max:` here would be a
 * second ceiling, hard-coded, one deployment away from disagreeing.
 *
 * **No `attributes()`, unlike the deals request.** This module carries no
 * `attributes` block — `SaveSupplierQuotationRequest` has none and its 422s
 * already name `supplier_id` and `code` as they appear on the wire. Translating
 * one field here would leave the other seven untranslated. **Ceiling:** the
 * Arabic 422 for a missing file reads `document` in Latin script; the fix is
 * the module's whole attribute set at once, recorded as debt.
 */
final class UploadSupplierQuotationDocumentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file'],
        ];
    }

    public function document(): UploadedFile
    {
        $file = $this->file('document');

        if (! $file instanceof UploadedFile) {
            // Unreachable once validation has passed: `required|file` already
            // refused anything else. Guarding it is cheaper than a controller
            // that trusts a validated() array to be typed.
            throw new RuntimeException('The document request validated a field that is not an uploaded file.');
        }

        return $file;
    }
}
