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
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
