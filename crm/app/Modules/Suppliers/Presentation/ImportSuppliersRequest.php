<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * `D-85` (F-09 · 1.4) — the upload, on `ImportCustomersRequest`'s terms: one
 * file under `config('files.max_size_bytes')` (`D-71`), and no MIME rule,
 * because the reader's own refusals are what decide whether it is a supplier
 * file. Authorisation is the route's `permission:catalog.import`.
 */
final class ImportSuppliersRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $bytes = Config::integer('files.max_size_bytes');

        return [
            // `max:` counts kilobytes.
            'file' => ['required', 'file', 'max:'.intdiv($bytes, 1024)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => (string) __('suppliers.attributes.file')];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('The import request validated something that is not an uploaded file.');
        }

        return $file;
    }
}
