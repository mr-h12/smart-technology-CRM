<?php

declare(strict_types=1);

namespace App\Support\Csv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * The boundary for every CSV import — `POST /customers/import` and
 * `POST /suppliers/import` (`D-85`), and F-10's catalog import (`D-86`). Moved
 * here from Customers in F-10 · 1.2; the suppliers' copy differed only in its
 * lang key, and the two keys said the same word. The field's name now comes
 * from `validation.attributes.file`, so no `attributes()` is needed.
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
 * ── Its true MIME type, not `AllowedFileType` (F-22 · 1.2) ────────────────
 *
 * `D-97`: a file that is not CSV is refused with a `422` that names CSV and
 * quotes nothing from the file. Before it, a fake `.xlsx` failed on its header
 * and the message echoed its bytes (E2-4). `mimetypes:` reads the content
 * through libmagic, not the extension. `AllowedFileType` stays `D-40`'s six for
 * stored attachments; CSV is not among them, and this file is never stored.
 * The three types were measured in this image (2026-09-28): a CSV is
 * `text/csv`, but a one-column or `;`-separated one is `text/plain`, and a
 * 0-byte file is `application/x-empty`, admitted so `CsvReader` still answers
 * "this file is empty" (the owner's ruling, 2026-09-28).
 */
final class ImportFileRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.intdiv($bytes, 1024), 'mimetypes:text/csv,text/plain,application/x-empty'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // The default lists the MIME types; the owner's wording names CSV and
        // says how to get one out of Excel.
        return ['file.mimetypes' => (string) __('uploads.import.not_csv')];
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
