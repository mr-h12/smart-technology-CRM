<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Module 9, Point 3.5 — the one thing a render request says: the document's
 * language (Q15), which the template has words for in Arabic and English only.
 */
final class GenerateQuotationPdfRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'locale' => (string) __('pdf.attributes.locale'),
        ];
    }

    /**
     * Q15: the language named, or else the request's own (`OpenAPI §2`), which
     * `SetLocaleFromRequest` has already settled.
     *
     * @return 'ar'|'en'
     */
    public function documentLocale(): string
    {
        $locale = $this->validated('locale') ?? app()->getLocale();

        return match ($locale) {
            'ar', 'en' => $locale,
            default => throw new RuntimeException('The request settled on a language the PDF template has no words for.'),
        };
    }
}
