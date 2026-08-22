<!doctype html>
{{-- The SPA shell. Laravel serves this for every non-API route and Vue Router
     takes over from there (D-67). It carries no user-facing text: all strings
     live in lang files, and this document exists only to mount the app. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar']) ? 'rtl' : 'ltr' }}">
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
