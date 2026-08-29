<!doctype html>
{{-- The SPA shell. Laravel serves this for every non-API route and Vue Router
     takes over from there (D-67). It carries no user-facing text: all strings
     live in lang files, and this document exists only to mount the app. --}}
{{-- §1 makes this an Arabic-first product, so the shell opens in the configured
     default language (APP_LOCALE) rather than in whichever language the visitor's
     browser happens to prefer. This used to render app()->getLocale(), which
     SetLocaleFromRequest resolves from Accept-Language — so an English browser
     was handed an English product by default and nobody had chosen that.

     The *API* still negotiates (OpenAPI §2, LocaleTest). Nothing was taken away
     there: api.ts sends this document's language as Accept-Language, so the two
     halves continue to agree by construction.

     `app.default_locale` and not `app.locale`: Application::setLocale() writes
     the negotiated locale back into `app.locale`, so by the time this renders
     that key reports the visitor's own Accept-Language. Measured, not read.

     The value is matched against the two supported codes rather than trusted,
     for the same reason SetLocaleFromRequest does it: a locale is a path
     fragment to the translation loader. It is spread over two directives
     because NoHardCodedTextTest reads a @php block's body as prose and only
     understands the single-expression form. --}}
@php($locale = config('app.default_locale'))
@php($locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'ar')
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>

    {{-- Design System §3.1: "The selected theme must apply before the main
         application shell paints, avoiding a visible flash of another theme."

         This is the only place that can honour that sentence. Everything else
         in the application is inside the Vue bundle, which @vite loads as a
         module script — and module scripts are deferred, so they run after the
         document has already painted. A theme applied there is applied one
         frame too late, which is precisely the flash §3.1 forbids.

         So it is inline, synchronous, and above @vite. Not stylistic: a `defer`
         or `async` attribute, or moving it below the bundle, reintroduces the
         flash while leaving code that still looks correct. ThemeFlashTest
         asserts the ordering and the absence of both attributes.

         Only the two non-default themes set an attribute. §3.1 as D-73
         rewrote it makes Warm Editorial the product default and tokens.css puts
         it on :root, so "no attribute" is already the right answer and writing
         one would be work with no effect.

         The stored value is compared against a fixed list rather than trusted.
         localStorage is writable by anything running on this origin, and this
         script's whole job is to take a value from there and put it into the
         document. --}}
    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('crm.theme');

                if (stored === 'clean-monochrome' || stored === 'midnight-obsidian') {
                    document.documentElement.setAttribute('data-theme', stored);
                }

                // The language is the same problem as the theme, and a worse
                // flash: the document above opens in the product default, so
                // without this a reader who chose the other language watches
                // the whole layout arrive in the wrong direction before the
                // bundle corrects it. Both attributes move together — a
                // document that says lang="en" and still lays out RTL is wrong
                // in a way neither the reader nor the stylesheet can correct.
                var language = window.localStorage.getItem('crm.locale');

                if (language === 'ar' || language === 'en') {
                    document.documentElement.lang = language;
                    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr';
                }
            } catch (error) {
                // Storage throws rather than returning null when a browser has
                // it disabled or is in a restricted mode. The default theme is
                // the correct outcome there, and a shell that fails to paint
                // because of a preference lookup is not.
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
