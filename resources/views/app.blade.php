<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        {{ config('app.name', 'Zeerak Real Estate & Builders') }}
    </title>

    <link rel="preload" as="image" href="{{ asset('images/zeerak-logo.jpeg') }}">
    <link rel="icon" type="image/jpeg" href="{{ asset('images/zeerak-logo.jpeg') }}">
    <link rel="shortcut icon" type="image/jpeg" href="{{ asset('images/zeerak-logo.jpeg') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/zeerak-logo.jpeg') }}">
    <meta name="theme-color" content="#165134">

    <link
        href="{{ mix('css/app.css') }}"
        rel="stylesheet"
    >
</head>

<body>
    <div id="app"></div>

    <script src="{{ mix('js/app.js') }}"></script>
</body>
</html>