<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Registering the GitHub App…</title>
</head>
<body>
    {{-- GitHub takes a manifest only as a form POST from the browser. --}}
    <form id="manifest" action="{{ $action }}" method="post">
        <input type="hidden" name="manifest" value="{{ $manifest }}">
        <p>Taking you to GitHub to create the app…</p>
        <noscript><button type="submit">Continue to GitHub</button></noscript>
    </form>
    <script>document.getElementById('manifest').submit();</script>
</body>
</html>
