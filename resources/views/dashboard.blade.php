<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600&display=swap">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    @include('metrics::partials.styles')
</head>
<body>
    <div class="page">
        <header class="top">
            <div class="top-left">
                <span class="brand">Monitoring</span>
                <span class="pill" id="statusPill" role="status"><span class="marker"></span>Loading…</span>
                <span class="muted" id="lastUpdate"></span>
                <span class="pin-chip" id="pinChip" hidden>
                    <span class="pin-swatch" aria-hidden="true"></span><span id="pinLabel" role="status"></span>
                    <button type="button" id="pinClear" aria-label="Clear pinned time" title="Clear (Esc)">×</button>
                </span>
            </div>
            <div class="segmented" id="periods" role="group" aria-label="Time range"></div>
        </header>

        <nav class="tabs" id="tabs" aria-label="Sections" hidden></nav>

        <main id="views"></main>
    </div>

    <script>
        const Settings = @json($settings);
        const BASE_URL = @json($basePath === '' ? '' : '/' . $basePath);
    </script>
    @include('metrics::partials.scripts.core')
    @include('metrics::partials.scripts.charts')
    @include('metrics::partials.scripts.blocks')
    @include('metrics::partials.scripts.sections')
    @include('metrics::partials.scripts.app')
</body>
</html>
