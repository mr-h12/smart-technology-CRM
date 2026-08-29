<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * The boundary for `POST /customers/import`.
 *
 * ── The size ceiling is §17's, not a new one ──────────────────────────────
 *
 * `config('files.max_size_bytes')` already carries it — 30 MB, `D-71`
 * superseding `D-39`'s 10 MB — and it is the number every other upload in this
 * system is measured against. **Not `limits.max_file_size_mb`:** that
 * `SystemLimit` case is unseeded and there is no `config/limits.php`, so
 * `SettingReader::integer()` would fall through to a configured **0** and
 * refuse every file. Measured before it was used, not after.
 *
 * ── No MIME rule, and that is deliberate ──────────────────────────────────
 *
 * §17's true-MIME check exists for files this system **stores and serves back**
 * (`D-38`, `SEC-15`), and `AllowedFileType` is `D-40`'s six — PDF · JPG · PNG ·
 * WEBP · DOCX · XLSX — which does not include CSV at all. This file is parsed
 * and dropped: `import_batches` has no path column by Point 1.2's decision, so
 * nothing is stored, nothing is served, and there is nothing for a spoofed
 * extension to be spoofed *into*. A file that is not a CSV fails on its header
 * instead, with a message that says so.
 */
final class ImportCustomersRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // `Config::integer` and not `(int) config(...)`: PHPStan level 10
        // refuses the cast on `mixed`, and `Coding Standards` refuses it too —
        // narrow, never cast.
        $bytes = Config::integer('files.max_size_bytes');

        return [
            // `max:` counts kilobytes. intdiv, so a ceiling below 1 KB refuses
            // everything rather than rounding up into permission nobody gave.
            'file' => ['required', 'file', 'max:'.intdiv($bytes, 1024)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => (string) __('customers.attributes.file')];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        // Narrowed rather than cast: `rules()` guarantees one uploaded file,
        // and `Coding Standards` forbids the untyped escape hatch.
        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('The import request validated something that is not an uploaded file.');
        }

        return $file;
    }
}
