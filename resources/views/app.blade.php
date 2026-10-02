<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#faf8ff">
        <title inertia>{{ config('app.name', 'Railway Assistant') }}</title>
        @viteReactRefresh
        @vite(["resources/js/app.tsx"])
        @inertiaHead
    </head>
    <body class="bg-background text-on-surface antialiased">
        @inertia
    </body>
</html>
