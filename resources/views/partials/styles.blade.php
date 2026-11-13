@verbatim
<style>
    :root {
        --bg: #111315;
        --surface: #1A1D21;
        --line: #2B3036;
        --text: #F2F3F5;
        --muted: #9BA3AE;
        --bar: #4A5560;
        --ok: #C6F24E;
        --ok-bg: #1F2A0E;
        --warn: #FF9A4D;
        --warn-bg: #2E2112;
        --error: #FF6B6B;
        --error-bg: #321818;
        --font: Inter, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        --font-display: 'Space Grotesk', Inter, system-ui, sans-serif;
        --font-mono: ui-monospace, Menlo, Consolas, monospace;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font); font-size: 13px; line-height: 1.45; font-variant-numeric: tabular-nums; }
    a { color: var(--ok); }
    a:hover { color: #E0FA9A; }
    button { cursor: pointer; font: inherit; }
    a:focus-visible, button:focus-visible { outline: 2px solid var(--ok); outline-offset: 2px; }
    h2 { margin: 0; font-size: 15px; font-weight: 600; }
    [hidden] { display: none !important; }

    .page { min-height: 100vh; padding: 20px 24px 40px; display: flex; flex-direction: column; gap: 14px; }
    .muted { color: var(--muted); }
    .small { font-size: 12px; }
    .display { font-family: var(--font-display); font-weight: 600; }
    .ok { color: var(--ok); }
    .warn { color: var(--warn); }
    .error { color: var(--error); }
    .hue-blue { color: #7FB2FF; }
    .hue-purple { color: #C9A6FF; }
    .hue-cyan { color: #6FD6E8; }
    .hue-pink { color: #FF8FC7; }

    /* Header */
    .top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .top-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .brand { font-family: var(--font-display); font-weight: 600; font-size: 20px; }
    .pill { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; font-weight: 600; background: var(--line); color: var(--muted); }
    .pill.ok { background: var(--ok-bg); color: var(--ok); }
    .pill.warn { background: var(--warn-bg); color: var(--warn); }
    .pill.error { background: var(--error-bg); color: var(--error); }
    .segmented { display: flex; gap: 4px; padding: 4px; background: var(--surface); border-radius: 12px; }
    .segmented button { min-width: 52px; height: 44px; padding: 0 12px; background: transparent; color: var(--muted); border: 0; border-radius: 8px; }
    .segmented button:hover { color: var(--text); }
    .segmented button[aria-pressed="true"] { background: var(--text); color: var(--bg); font-weight: 600; }
    .segmented.compact { padding: 3px; border-radius: 10px; background: var(--bg); }
    .segmented.compact button { min-width: 0; height: 32px; padding: 0 10px; font-size: 12px; }

    /* Section nav */
    .tabs { display: flex; flex-wrap: wrap; gap: 4px; }
    .tabs a { display: flex; align-items: center; height: 44px; padding: 0 16px; border-radius: 10px; color: var(--muted); text-decoration: none; font-weight: 500; }
    .tabs a:hover { color: var(--text); }
    .tabs a[aria-current="page"] { background: var(--surface); color: var(--text); font-weight: 600; }
    .view { display: flex; flex-direction: column; gap: 14px; }

    /* Status markers: shape + colour, never colour alone */
    .marker { flex: none; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
    .marker.warn { border-radius: 0; background: transparent; border: 2px solid currentColor; transform: rotate(45deg); }
    .marker.error { border-radius: 1px; }
    .marker.lg { width: 10px; height: 10px; }

    /* KPI strip */
    .strip-wrap { display: flex; flex-direction: column; gap: 8px; }
    .strip-label { font-size: 13px; font-weight: 500; color: var(--muted); }
    /* Dividers drawn by each tile (right + bottom, clipped at the edges), so a
       partly filled last row stays plain surface. */
    .strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); background: var(--surface); border-radius: 16px; overflow: hidden; }
    .tile { padding: 16px 18px; display: flex; flex-direction: column; gap: 2px; min-width: 0; box-shadow: 1px 0 0 var(--line), 0 1px 0 var(--line); }
    .tile-value { font-family: var(--font-display); font-size: 26px; font-weight: 600; line-height: 1.15; overflow-wrap: anywhere; }
    .spark { width: 100%; height: 26px; margin-top: 6px; display: block; overflow: visible; }
    .spark path { fill: none; stroke-width: 1.5px; vector-effect: non-scaling-stroke; stroke-linejoin: round; }

    /* Panels */
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr)); gap: 14px; }
    .panel { background: var(--surface); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
    .panel.wide { grid-column: 1 / -1; }
    .panel-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 4px 12px; }
    .panel-title { display: flex; align-items: center; gap: 10px; }
    .panel.split { flex-flow: row wrap; gap: 20px 28px; }
    .panel.split .col { flex: 2 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 10px; }
    .panel.split .col:first-child { flex: 1 1 220px; }
    .panel.split .panel-title h2 { font-family: var(--font-display); font-size: 20px; }
    .panel.figure { flex-direction: row; justify-content: space-between; align-items: baseline; }
    .figure-value { font-family: var(--font-display); font-size: 34px; font-weight: 600; line-height: 1.1; }
    .hero-value { font-family: var(--font-display); font-size: 34px; font-weight: 600; line-height: 1.1; }

    /* Charts */
    .chart-title { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 14px; color: var(--muted); font-size: 12px; }
    .chart-box { position: relative; flex: none; width: 100%; min-width: 0; border-bottom: 1px solid var(--line); }
    .chart-box canvas { position: absolute; inset: 0; cursor: pointer; }

    /* Pinned time: white, like the pin line drawn on every chart */
    .pin-chip { display: flex; align-items: center; gap: 8px; height: 32px; padding: 0 4px 0 12px; border-radius: 999px; background: var(--text); color: var(--bg); font-weight: 600; }
    .pin-swatch { width: 2px; height: 14px; background: var(--bg); }
    .pin-chip button { width: 26px; height: 26px; border: 0; border-radius: 50%; background: transparent; color: var(--bg); font-size: 16px; line-height: 1; }
    .pin-chip button:hover { background: rgba(17, 19, 21, 0.12); }
    .spark-pin { stroke: rgba(242, 243, 245, 0.6); stroke-width: 1px; vector-effect: non-scaling-stroke; }
    .minibars > div.pinned { background: var(--text); }
    .x-labels { display: flex; justify-content: space-between; color: var(--muted); font-size: 12px; }
    .legend { display: flex; flex-wrap: wrap; gap: 6px 16px; color: var(--muted); font-size: 12px; }
    .legend > span { display: flex; align-items: center; gap: 6px; }
    .legend .value { color: var(--text); font-weight: 600; font-size: 13px; }
    .swatch { flex: none; width: 12px; height: 2px; }
    .swatch.box { width: 10px; height: 10px; border-radius: 2px; }

    /* Inline stats, rows, notes */
    .stats { display: grid; gap: 8px 10px; }
    .stat-value { font-family: var(--font-display); font-size: 18px; font-weight: 600; overflow-wrap: anywhere; }
    .kv { display: flex; justify-content: space-between; gap: 12px; border-top: 1px solid var(--line); padding-top: 10px; }
    .kv > :last-child { font-weight: 600; text-align: right; }
    .note { color: var(--muted); font-size: 12px; }
    .note.divider { border-top: 1px solid var(--line); padding-top: 10px; }
    .empty { color: var(--muted); font-size: 12px; padding: 8px 0; }

    /* Meters, ranked bars, mini bars */
    .track { flex: 1; height: 8px; background: var(--line); border-radius: 4px; overflow: hidden; }
    .track.thin { height: 6px; border-radius: 3px; }
    .fill { height: 100%; background: var(--ok); border-radius: inherit; }
    .fill.neutral { background: var(--bar); }
    .fill.warn { background: var(--warn); }
    .fill.error { background: var(--error); }
    .meter { display: flex; align-items: center; gap: 10px; }
    .meter-label { flex: none; width: 60px; color: var(--muted); }
    .meter-value { flex: none; min-width: 52px; text-align: right; font-weight: 600; }
    .stack { display: flex; flex-direction: column; gap: 6px; }
    .stack .track { flex: none; }
    .stack-head { display: flex; justify-content: space-between; gap: 8px; }
    .stack-head > :first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rank { display: flex; align-items: center; gap: 10px; }
    .rank-label { flex: 1 1 0; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rank .track { flex: none; width: 90px; }
    .rank-value { flex: none; min-width: 16px; text-align: right; font-weight: 600; }
    .trend { display: flex; flex-direction: column; gap: 4px; border-top: 1px solid var(--line); padding-top: 8px; }
    .minibars { display: flex; align-items: flex-end; gap: 2px; height: 30px; }
    .minibars > div { flex: 1; min-width: 0; background: #7C8794; border-radius: 1px 1px 0 0; }

    /* Tables */
    .table { display: flex; flex-direction: column; gap: 0; }
    .trow { display: grid; gap: 8px; align-items: center; }
    .thead { color: var(--muted); font-size: 12px; padding-bottom: 4px; }
    .tgroup { border-top: 1px solid var(--line); padding: 10px 0 4px; display: flex; flex-direction: column; gap: 4px; }
    .table.dense .tgroup { padding: 6px 0; }
    .tgroup .spark { height: 28px; margin-top: 0; }
    .tcell-name { font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .strong { font-weight: 600; }
    .slot { display: contents; }

    /* Slow log */
    .log { overflow-x: auto; }
    .log-rows { min-width: 700px; display: flex; flex-direction: column; }
    .log-row { display: grid; gap: 12px; align-items: baseline; width: 100%; padding: 8px 4px; margin: 0; background: none; color: inherit; text-align: left; border: 0; border-top: 1px solid var(--line); font-family: var(--font-mono); font-size: 12px; }
    .log-row:hover { background: rgba(255, 255, 255, 0.03); }
    .log-row[aria-expanded="true"] { background: rgba(255, 255, 255, 0.05); }
    .log-row .detail, .related-row .detail { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .log-row .duration, .related-row .duration { font-weight: 700; }
    .log-detail { display: flex; flex-direction: column; gap: 14px; padding: 12px 4px 18px; border-top: 1px dashed var(--line); font-size: 12px; }
    .detail-block { display: flex; flex-direction: column; gap: 6px; }
    .mini-head { color: var(--muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 3px 16px; margin: 0; }
    .facts dt { color: var(--muted); }
    .facts dd { margin: 0; font-family: var(--font-mono); overflow-wrap: anywhere; }
    .code { margin: 0; padding: 10px 12px; max-height: 260px; overflow: auto; background: var(--bg); border-radius: 8px; font-family: var(--font-mono); font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
    .trace-summary { display: flex; flex-wrap: wrap; gap: 6px 20px; }
    .trace-summary b { font-weight: 600; }
    .wf { display: flex; flex-direction: column; gap: 3px; }
    .wf-row { display: grid; grid-template-columns: minmax(160px, 30%) minmax(0, 1fr) 72px; gap: 12px; align-items: center; }
    .wf-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: var(--font-mono); }
    .wf-track { position: relative; height: 10px; background: var(--bg); border-radius: 3px; }
    .wf-bar { position: absolute; top: 0; bottom: 0; min-width: 2px; background: var(--ok); border-radius: 3px; }
    .wf-bar.parent { background: var(--bar); }
    .wf-bar.error { background: var(--error); }
    .wf-ms { text-align: right; font-family: var(--font-mono); }
    .wf-axis { font-size: 11px; }
    .wf-scale { display: flex; justify-content: space-between; }
    .stmt-list, .mark-list, .related { display: flex; flex-direction: column; gap: 4px; font-family: var(--font-mono); }
    .stmt { display: grid; grid-template-columns: minmax(0, 1fr) 48px 72px 96px; gap: 12px; }
    .stmt > span:not(.stmt-sql) { text-align: right; }
    .stmt-sql { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .related-row { display: grid; grid-template-columns: 76px 70px 80px minmax(0, 1fr); gap: 12px; }
    .log-controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .pager { display: flex; align-items: center; justify-content: center; gap: 12px; padding-top: 8px; color: var(--muted); font-size: 12px; }
    .pager button { height: 32px; min-width: 32px; background: var(--bg); color: var(--text); border: 0; border-radius: 8px; }
    .pager button:disabled { opacity: 0.35; cursor: default; }

    @media (max-width: 640px) {
        .page { padding: 16px 16px 32px; }
        .panel { padding: 16px; }
        .tabs a { padding: 0 12px; }
    }
</style>
@endverbatim
