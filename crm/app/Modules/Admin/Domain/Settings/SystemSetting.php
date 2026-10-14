<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Settings;

/**
 * §13 screen 4's fields — the ones this endpoint owns.
 *
 * **Why an enum over a key/value table.** The table will accept any key; §13
 * names ten fields and no more, and an endpoint that stores `haxx.enabled`
 * because somebody sent it is not a settings screen. The membership of *this*
 * list is fixed by the document, exactly as `ManagedList`'s four lists are — it
 * is the **values** that are configuration, and none is seeded here.
 *
 * **Two of §13's ten are deliberately absent.**
 *
 * - **Logo.** §13 screen 4 names it; storing it means a `files` row, an upload
 *   endpoint and `SEC-15`'s scan — Module 5 owns all three, and a settings key
 *   holding a path would bypass `D-38`'s permission-checked download.
 * - **PDF and email templates.** §16 makes the PDF template editable from the
 *   Super Admin screen, and §9's Module 9 owns what a template *is*. A string
 *   column here would be a template engine nobody designed.
 *
 * Both are owed, and named in `CHECKLIST.md` rather than quietly dropped.
 *
 * **The types are read, not invented.** Everything §13 names here is text on
 * a form. `default tax`, the one percentage, left with `D-118` (F-48 · 2): a
 * quotation's and a supplier offer's rates are typed on them.
 */
enum SystemSetting: string
{
    /** §13/4 "company name". Printed on every quotation §16 generates. */
    case CompanyName = 'company.name';

    /** §13/4 "address". */
    case CompanyAddress = 'company.address';

    /** §13/4 "phone numbers" — plural, and free text: §4.2 stores a customer's the same way. */
    case CompanyPhones = 'company.phones';

    /** `D-107`: the e-mail the customer PDF's footer prints. */
    case CompanyEmail = 'company.email';

    /** §13/4 "default currency". §5.3's three are the values; the row holds the code. */
    case DefaultCurrency = 'defaults.currency';

    /** §13/4 "language". `§14.2`'s two, and what `SetLocaleFromRequest` falls back to. */
    case Language = 'locale.language';

    /**
     * §13/4 "time zone". `DB-08` stores UTC regardless; this is the company's
     * calendar day — for display, and for `J-01`, which expires a quotation once
     * its `valid_until` is before today here (Module 10 · 2.1).
     */
    case Timezone = 'locale.timezone';

    /** §13/4 "date format". */
    case DateFormat = 'locale.date_format';

    /**
     * How the stored string is to be read — one of the five `settings.value_type`
     * accepts (Point 1.1).
     */
    public function valueType(): string
    {
        return 'string';
    }

    /**
     * The validation rule the boundary applies, beyond "is a string".
     */
    public function rule(): string
    {
        return match ($this) {
            self::CompanyEmail => 'email',
            default => 'string',
        };
    }
}
