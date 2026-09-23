<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The boundary for `POST /purchase-orders/{id}/documents` (Module 10 · 2.3),
 * `AttachDealDocumentRequest`'s shape: presence here, type and size in
 * Storage's validator (§17: the true MIME type, not the extension).
 */
final class UploadPurchaseOrderDocumentRequest extends FormRequest
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
            'document' => (string) __('quotations.attributes.document'),
        ];
    }

    public function document(): UploadedFile
    {
        $file = $this->file('document');

        if (! $file instanceof UploadedFile) {
            // Unreachable once `required|file` has passed.
            throw new RuntimeException('The document request validated a field that is not an uploaded file.');
        }

        return $file;
    }
}
