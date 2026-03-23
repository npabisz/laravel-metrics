<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --card-hover: #253349;
            --border: #334155;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --green: #22c55e;
            --green-bg: rgba(34,197,94,0.1);
            --red: #ef4444;
            --red-bg: rgba(239,68,68,0.1);
            --yellow: #eab308;
            --yellow-bg: rgba(234,179,8,0.1);
            --blue: #3b82f6;
            --blue-bg: rgba(59,130,246,0.1);
            --purple: #a855f7;
            --purple-bg: rgba(168,85,247,0.1);
            --cyan: #06b6d4;
            --cyan-bg: rgba(6,182,212,0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        .header { background: var(--card); border-bottom: 1px solid var(--border); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; }
        .header h1 { font-size: 18px; font-weight: 600; }
        .header-controls { display: flex; gap: 12px; align-items: center; }
        .header-controls select, .header-controls button { background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
        .header-controls button:hover { background: var(--card-hover); }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .status-dot.ok { background: var(--green); box-shadow: 0 0 6px var(--green); }
        .status-dot.warn { background: var(--yellow); box-shadow: 0 0 6px var(--yellow); }
        .status-dot.error { background: var(--red); box-shadow: 0 0 6px var(--red); }
        .status-dot.off { background: var(--text-muted); }

        /* View tabs */
        .view-tabs { background: var(--card); border-bottom: 1px solid var(--border); padding: 0 24px; display: flex; gap: 0; overflow-x: auto; }
        .view-tab { padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; white-space: nowrap; user-select: none; }
        .view-tab:hover { color: var(--text); background: rgba(255,255,255,0.02); }
        .view-tab.active { color: var(--blue); border-bottom-color: var(--blue); }

        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .view-page { display: none; }
        .view-page.active { display: block; }
        .grid { display: grid; gap: 16px; }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; }
        .card-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 8px; }
        .card-value { font-size: 28px; font-weight: 700; line-height: 1.2; }
        .card-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .card-value.green { color: var(--green); }
        .card-value.red { color: var(--red); }
        .card-value.yellow { color: var(--yellow); }
        .card-value.blue { color: var(--blue); }
        .card-value.purple { color: var(--purple); }
        .card-value.cyan { color: var(--cyan); }
        .section-title { font-size: 14px; font-weight: 600; color: var(--text-muted); margin: 24px 0 12px; text-transform: uppercase; letter-spacing: 1px; }
        .section-title:first-child { margin-top: 0; }
        .chart-container { position: relative; height: 220px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 500; border-bottom: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
        tr:hover td { background: var(--card-hover); }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-request { background: var(--blue-bg); color: var(--blue); }
        .badge-query { background: var(--purple-bg); color: var(--purple); }
        .badge-slow { background: var(--red-bg); color: var(--red); }
        .badge-ok { background: var(--green-bg); color: var(--green); }
        .tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
        .tab { padding: 8px 16px; font-size: 13px; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .tab:hover { color: var(--text); }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }
        .no-data { text-align: center; padding: 40px; color: var(--text-muted); }
        .refresh-indicator { font-size: 11px; color: var(--text-muted); }
        .sql-text { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 12px; word-break: break-all; max-width: 500px; }
        .slow-controls { display: flex; justify-content: space-between; align-items: center; }
        .sort-controls { display: flex; gap: 4px; align-items: center; }
        .sort-btn { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); border-radius: 4px; padding: 4px 10px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .sort-btn:hover { color: var(--text); }
        .sort-btn.active { color: var(--blue); border-color: var(--blue); background: rgba(59,130,246,0.1); }
        .pagination { display: flex; gap: 6px; align-items: center; justify-content: center; padding: 12px 0 4px; }
        .page-btn { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); border-radius: 4px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
        .page-btn:hover:not(:disabled) { color: var(--text); border-color: var(--text-muted); }
        .page-btn:disabled { opacity: 0.3; cursor: default; }
        .page-btn.active { color: var(--blue); border-color: var(--blue); }
        .page-info { font-size: 11px; color: var(--text-muted); }
        /* Ranked list section */
        .ranked-list { list-style: none; padding: 0; margin: 0; }
        .ranked-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .ranked-list li:last-child { border-bottom: none; }
        .ranked-list .rank { font-weight: 700; color: var(--text-muted); min-width: 28px; }
        .ranked-list .rank-label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin: 0 12px; }
        .ranked-list .rank-value { font-weight: 600; font-variant-numeric: tabular-nums; }
        .ranked-list li:nth-child(1) .rank { color: var(--red); }
        .ranked-list li:nth-child(2) .rank { color: var(--yellow); }
        .ranked-list li:nth-child(3) .rank { color: var(--green); }
        .ranked-list .no-data-row { color: var(--text-muted); justify-content: center; }
        .ranked-list-card { padding: 0; overflow: hidden; margin-bottom: 16px; }

        @media (max-width: 1024px) {
            .grid-3 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .view-tabs { padding: 0 12px; }
            .view-tab { padding: 8px 14px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <span class="status-dot off" id="statusDot"></span>
            Monitoring Dashboard
        </h1>
        <div class="header-controls">
            <span class="refresh-indicator" id="lastUpdate">--</span>
            <select id="periodSelect">
                <option value="5">Last 5 min</option>
                <option value="15">Last 15 min</option>
                <option value="30">Last 30 min</option>
                <option value="60" selected>Last 1 hour</option>
                <option value="360">Last 6 hours</option>
                <option value="1440">Last 24 hours</option>
            </select>
            <select id="refreshSelect">
                <option value="0">Auto-refresh: Off</option>
                <option value="10">Every 10s</option>
                <option value="30" selected>Every 30s</option>
                <option value="60">Every 60s</option>
            </select>
            <button onclick="fetchData()">Refresh</button>
        </div>
    </div>

    <div id="viewTabs" class="view-tabs" style="display:none"></div>

    <div class="container" id="dashboardContainer"></div>

    <script>
        // ─── Chart.js defaults ──────────────────────────────────
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.borderColor = '#334155';
        Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.animation.duration = 400;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.padding = 12;

        const COLORS = {
            blue:   { bg: 'rgba(59,130,246,0.15)',  border: '#3b82f6' },
            red:    { bg: 'rgba(239,68,68,0.15)',   border: '#ef4444' },
            green:  { bg: 'rgba(34,197,94,0.15)',   border: '#22c55e' },
            yellow: { bg: 'rgba(234,179,8,0.15)',   border: '#eab308' },
            purple: { bg: 'rgba(168,85,247,0.15)',  border: '#a855f7' },
            cyan:   { bg: 'rgba(6,182,212,0.15)',   border: '#06b6d4' },
            orange: { bg: 'rgba(249,115,22,0.15)',  border: '#f97316' },
            pink:   { bg: 'rgba(236,72,153,0.15)',  border: '#ec4899' },
        };
        const COLOR_LIST = Object.values(COLORS);
        const SAFE_COLORS = [COLORS.blue, COLORS.green, COLORS.yellow, COLORS.purple, COLORS.cyan, COLORS.orange, COLORS.pink];
        const ERROR_PATTERN = /error|5xx|fail|slow|max|high|timeout/i;

        const chartOpts = (yLabel = '') => ({
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    borderColor: '#334155',
                    borderWidth: 1,
                    titleColor: '#e2e8f0',
                    bodyColor: '#94a3b8',
                    padding: 10,
                    cornerRadius: 6,
                    callbacks: {
                        label: function(ctx) {
                            let v = ctx.parsed.y;
                            if (v === null || v === undefined) return ctx.dataset.label + ': N/A';
                            v = Number.isInteger(v) ? v : parseFloat(v.toFixed(2));
                            return ctx.dataset.label + ': ' + v.toLocaleString();
                        }
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 20 } },
                y: { beginAtZero: true, grid: { color: 'rgba(51,65,85,0.5)' }, title: yLabel ? { display: true, text: yLabel, color: '#94a3b8' } : { display: false }, ticks: { callback: v => Number.isInteger(v) ? v : parseFloat(v.toFixed(2)) } },
            },
        });

        const chartOptsMultiLegend = (yLabel = '') => {
            const opts = chartOpts(yLabel);
            opts.plugins.legend = { display: true, position: 'top', align: 'start' };
            return opts;
        };

        // ─── Config & State ───────────────────────────────────
        const basePath = '{{ rtrim(config("monitoring.dashboard.path", "monitoring"), "/") }}';
        const viewsConfig = @json($views ?? []);
        const metricConfigs = @json($metricConfigs ?? []);
        let refreshTimer = null;
        let currentSlowType = '';
        let currentSlowSort = 'duration';
        let currentSlowPage = 1;
        let activeViewIndex = 0;
        const charts = {};
        let lastData = null;

        // ─── Default view (when no views configured) ──────────
        // Single page with all built-in panels, custom metric cards, and slow logs
        const defaultViews = [{
            label: 'Dashboard',
            sections: [
                { type: 'built-in' },
                { type: 'all-custom-cards' },
                { type: 'slow-logs' },
            ],
        }];

        const activeViews = viewsConfig.length > 0 ? viewsConfig : defaultViews;

        // ─── View system ──────────────────────────────────────
        function initDashboard() {
            // Show tab bar only when multiple views are configured
            if (activeViews.length > 1) {
                const tabBar = document.getElementById('viewTabs');
                tabBar.style.display = 'flex';
                tabBar.innerHTML = activeViews.map((v, i) =>
                    `<div class="view-tab${i === 0 ? ' active' : ''}" data-view="${i}">${escHtml(v.label || 'View ' + (i + 1))}</div>`
                ).join('');

                tabBar.querySelectorAll('.view-tab').forEach(tab => {
                    tab.addEventListener('click', () => switchView(parseInt(tab.dataset.view)));
                });
            }

            const container = document.getElementById('dashboardContainer');
            container.innerHTML = activeViews.map((view, vi) =>
                `<div class="view-page${vi === 0 ? ' active' : ''}" data-view-page="${vi}">${buildViewSections(view, vi)}</div>`
            ).join('');

            // Restore active tab from hash or localStorage
            if (activeViews.length > 1) {
                const hash = window.location.hash.slice(1);
                if (hash) {
                    const idx = activeViews.findIndex(v => slugify(v.label) === hash);
                    if (idx >= 0) switchView(idx);
                } else {
                    const saved = localStorage.getItem('monitoring_active_view');
                    if (saved !== null && parseInt(saved) < activeViews.length) switchView(parseInt(saved));
                }
            }

            bindSlowLogEvents();
        }

        function buildViewSections(view, viewIndex) {
            const sections = view.sections || [];
            let html = '';
            let chartBuffer = [];

            function flushCharts() {
                if (chartBuffer.length === 0) return;
                html += `<div class="grid grid-3">${chartBuffer.join('')}</div>`;
                chartBuffer = [];
            }

            sections.forEach((section, si) => {
                const sectionId = `v${viewIndex}_s${si}`;
                if (section.type === 'chart') {
                    chartBuffer.push(buildChartSection(section, sectionId));
                } else {
                    flushCharts();
                    switch (section.type) {
                        case 'built-in':         html += buildBuiltInSection(section, sectionId); break;
                        case 'cards':            html += buildCardsSection(section, sectionId); break;
                        case 'list':             html += buildListSection(section, sectionId); break;
                        case 'slow-logs':        html += buildSlowLogsSection(sectionId); break;
                        case 'all-custom-cards': html += buildAllCustomCardsSection(sectionId); break;
                    }
                }
            });

            flushCharts();
            return html;
        }

        function buildBuiltInSection(section, sectionId) {
            const ids = section.id ? [section.id] : ['http', 'queue', 'database', 'system'];
            let html = '';

            if (ids.includes('http')) {
                html += `<div class="grid grid-4" id="${sectionId}_http">
                    <div class="card"><div class="card-title">HTTP Requests</div><div class="card-value bi-val" data-key="httpTotal">--</div><div class="card-sub bi-sub" data-key="httpSub">--</div></div>
                    <div class="card"><div class="card-title">Avg Response</div><div class="card-value bi-val" data-key="httpAvg">--</div><div class="card-sub bi-sub" data-key="httpAvgSub">--</div></div>
                    <div class="card"><div class="card-title">Error Rate</div><div class="card-value bi-val" data-key="errorRate">--</div><div class="card-sub bi-sub" data-key="errorSub">--</div></div>
                    <div class="card"><div class="card-title">Slow Requests</div><div class="card-value bi-val" data-key="slowReq">--</div><div class="card-sub">above threshold</div></div>
                </div>`;
            }
            if (ids.includes('queue')) {
                html += `<div class="grid grid-4" id="${sectionId}_queue" style="margin-top:16px">
                    <div class="card"><div class="card-title">Queue Depth</div><div class="card-value bi-val" data-key="queueDepth">--</div><div class="card-sub bi-sub" data-key="queueSub">--</div></div>
                    <div class="card"><div class="card-title">Jobs Processed</div><div class="card-value bi-val" data-key="jobsProcessed">--</div><div class="card-sub bi-sub" data-key="jobsSub">--</div></div>
                </div>`;
            }
            if (ids.includes('database')) {
                html += `<div class="grid grid-4" id="${sectionId}_db" style="margin-top:16px">
                    <div class="card"><div class="card-title">DB Queries</div><div class="card-value bi-val" data-key="dbQueries">--</div><div class="card-sub bi-sub" data-key="dbSub">--</div></div>
                    <div class="card"><div class="card-title">Slow Queries</div><div class="card-value bi-val" data-key="dbSlow">--</div><div class="card-sub bi-sub" data-key="dbSlowSub">--</div></div>
                </div>`;
            }
            if (ids.includes('system')) {
                html += `${section.id ? '' : '<div class="section-title">System & Redis</div>'}
                <div class="grid grid-4" id="${sectionId}_sys">
                    <div class="card"><div class="card-title">CPU Load (1m)</div><div class="card-value bi-val" data-key="cpuLoad">--</div></div>
                    <div class="card"><div class="card-title">Redis Memory</div><div class="card-value bi-val" data-key="redisMem">--</div><div class="card-sub bi-sub" data-key="redisMemSub">--</div></div>
                    <div class="card"><div class="card-title">Redis Ops/sec</div><div class="card-value bi-val" data-key="redisOps">--</div><div class="card-sub bi-sub" data-key="redisHitRate">--</div></div>
                    <div class="card"><div class="card-title">Disk Free</div><div class="card-value bi-val" data-key="diskFree">--</div></div>
                </div>`;
            }

            // Built-in timeline charts
            if (!section.id || ids.length > 1) {
                html += `<div class="section-title">Timeline</div>
                <div class="grid grid-2">
                    <div class="card"><div class="card-title">Requests / minute</div><div class="chart-container"><canvas class="bi-chart" data-chart="requests_${sectionId}"></canvas></div></div>
                    <div class="card"><div class="card-title">Response time (ms)</div><div class="chart-container"><canvas class="bi-chart" data-chart="duration_${sectionId}"></canvas></div></div>
                    <div class="card"><div class="card-title">Queue depth</div><div class="chart-container"><canvas class="bi-chart" data-chart="queue_${sectionId}"></canvas></div></div>
                    <div class="card"><div class="card-title">DB queries / minute</div><div class="chart-container"><canvas class="bi-chart" data-chart="db_${sectionId}"></canvas></div></div>
                </div>`;
            }

            return html;
        }

        function buildCardsSection(section, sectionId) {
            const label = section.label ? `<div class="section-title">${escHtml(section.label)}</div>` : '';
            return `${label}<div class="grid grid-4 custom-cards" id="cards_${sectionId}" data-keys='${escAttr(JSON.stringify(section.keys || []))}'></div>`;
        }

        function buildListSection(section, sectionId) {
            const label = section.label ? `<div class="section-title">${escHtml(section.label)}</div>` : '';
            return `${label}<div class="card ranked-list-card" id="list_${sectionId}"
                data-label-keys='${escAttr(JSON.stringify(section.label_keys || []))}'
                data-value-keys='${escAttr(JSON.stringify(section.value_keys || []))}'
                data-max='${section.max || 5}'>
                <ul class="ranked-list"><li class="no-data-row">No data</li></ul>
            </div>`;
        }

        function buildAllCustomCardsSection(sectionId) {
            return `<div class="section-title all-custom-title" style="display:none">Custom Metrics</div>
                <div class="grid grid-4 all-custom-cards" id="cards_${sectionId}"></div>`;
        }

        function buildChartSection(section, sectionId) {
            const label = section.label || 'Chart';
            return `<div class="card custom-chart-card"><div class="card-title">${escHtml(label)}</div>
                    <div class="chart-container"><canvas class="custom-chart" id="chart_${sectionId}"
                        data-keys='${escAttr(JSON.stringify(section.keys || []))}'
                        data-colors='${escAttr(JSON.stringify(section.colors || null))}'
                        data-chart-type='${section.chart_type || "bar"}'
                        data-gauge='${section.gauge ? "1" : "0"}'
                    ></canvas></div></div>`;
        }

        function buildSlowLogsSection(sectionId) {
            return `<div class="section-title">Slow Logs</div>
                <div class="card slow-logs-panel">
                    <div class="slow-controls">
                        <div class="tabs" style="margin-bottom:0; border-bottom:none;">
                            <div class="tab slow-type-tab active" data-type="">All</div>
                            <div class="tab slow-type-tab" data-type="request">Requests</div>
                            <div class="tab slow-type-tab" data-type="query">Queries</div>
                        </div>
                        <div class="sort-controls">
                            <span style="font-size:11px;color:var(--text-muted);margin-right:4px;">Sort:</span>
                            <button class="sort-btn slow-sort-btn active" data-sort="duration">Slowest</button>
                            <button class="sort-btn slow-sort-btn" data-sort="time">Recent</button>
                        </div>
                    </div>
                    <div style="border-bottom:1px solid var(--border); margin-bottom:16px;"></div>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Type</th><th>Duration</th><th>Detail</th><th>Status</th><th>User</th><th>Time</th></tr></thead>
                            <tbody class="slow-logs-body"><tr><td colspan="6" class="no-data">Loading...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="pagination slow-logs-pagination"></div>
                </div>`;
        }

        function switchView(index) {
            activeViewIndex = index;
            document.querySelectorAll('.view-tab').forEach((t, i) => t.classList.toggle('active', i === index));
            document.querySelectorAll('.view-page').forEach((p, i) => p.classList.toggle('active', i === index));
            localStorage.setItem('monitoring_active_view', index);
            const label = activeViews[index]?.label;
            if (label) window.history.replaceState(null, '', '#' + slugify(label));
            if (lastData) updateViewData(lastData);
        }

        function slugify(str) {
            return (str || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }

        // ─── Slow log event binding ───────────────────────────
        function bindSlowLogEvents() {
            document.querySelectorAll('.slow-type-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    tab.closest('.slow-logs-panel').querySelectorAll('.slow-type-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    currentSlowType = tab.dataset.type;
                    currentSlowPage = 1;
                    fetchSlowLogs();
                });
            });
            document.querySelectorAll('.slow-sort-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    btn.closest('.slow-logs-panel').querySelectorAll('.slow-sort-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentSlowSort = btn.dataset.sort;
                    currentSlowPage = 1;
                    fetchSlowLogs();
                });
            });
        }

        // ─── Events ─────────────────────────────────────────────
        document.getElementById('periodSelect').addEventListener('change', () => { currentSlowPage = 1; fetchData(); });
        document.getElementById('refreshSelect').addEventListener('change', () => {
            clearInterval(refreshTimer);
            const sec = parseInt(document.getElementById('refreshSelect').value);
            if (sec > 0) refreshTimer = setInterval(fetchData, sec * 1000);
        });

        // ─── Data fetching ──────────────────────────────────────
        function fetchData() {
            const minutes = document.getElementById('periodSelect').value;
            fetch(`/${basePath}/api/data?minutes=${minutes}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'no_data') {
                        document.getElementById('statusDot').className = 'status-dot off';
                        return;
                    }
                    lastData = data;
                    updateViewData(data);
                    document.getElementById('lastUpdate').textContent = 'Updated: ' + new Date().toLocaleTimeString();
                })
                .catch(() => { document.getElementById('statusDot').className = 'status-dot error'; });
            fetchSlowLogs();
        }

        function updateViewData(data) {
            updateBuiltInCards(data.summary);
            updateBuiltInCharts(data.timeline);
            updateCustomCards(data.summary.custom);
            updateListSections(data.summary.custom);
            updateAllCustomCards(data.summary.custom);
            updateCustomCharts(data.timeline);
        }

        function fetchSlowLogs() {
            const minutes = document.getElementById('periodSelect').value;
            let url = `/${basePath}/api/slow-logs?minutes=${minutes}&per_page=25&sort=${currentSlowSort}&page=${currentSlowPage}`;
            if (currentSlowType) url += `&type=${currentSlowType}`;
            fetch(url).then(r => r.json()).then(data => {
                document.querySelectorAll('.slow-logs-body').forEach(tbody => renderSlowLogs(tbody, data.data));
                document.querySelectorAll('.slow-logs-pagination').forEach(el => renderPagination(el, data));
            }).catch(() => {});
        }

        // ─── Built-in card updates ────────────────────────────
        function updateBuiltInCards(s) {
            const dot = document.getElementById('statusDot');
            if (s.error_rate > 5) dot.className = 'status-dot error';
            else if (s.error_rate > 1 || s.queue_depth_default > 100) dot.className = 'status-dot warn';
            else dot.className = 'status-dot ok';

            const vals = {
                httpTotal:     { text: fmtNum(s.http_requests_total) },
                httpSub:       { text: `2xx: ${fmtNum(s.http_requests_2xx)} | 4xx: ${fmtNum(s.http_requests_4xx)} | 5xx: ${fmtNum(s.http_requests_5xx)}`, isSub: true },
                httpAvg:       { text: fmtMs(s.http_avg_duration), color: s.http_avg_duration > 2000 ? 'red' : s.http_avg_duration > 500 ? 'yellow' : 'green' },
                httpAvgSub:    { text: `max: ${fmtMs(s.http_max_duration)}`, isSub: true },
                errorRate:     { text: s.error_rate + '%', color: s.error_rate > 5 ? 'red' : s.error_rate > 1 ? 'yellow' : 'green' },
                errorSub:      { text: `${fmtNum(s.http_requests_5xx)} errors`, isSub: true },
                slowReq:       { text: fmtNum(s.http_slow_requests), color: s.http_slow_requests > 10 ? 'red' : s.http_slow_requests > 0 ? 'yellow' : 'green' },
                queueDepth:    { text: fmtNum(s.queue_depth_high + s.queue_depth_default + s.queue_depth_low), color: (s.queue_depth_high + s.queue_depth_default + s.queue_depth_low) > 100 ? 'red' : (s.queue_depth_high + s.queue_depth_default + s.queue_depth_low) > 20 ? 'yellow' : 'green' },
                queueSub:      { text: `H: ${s.queue_depth_high} | D: ${s.queue_depth_default} | L: ${s.queue_depth_low}`, isSub: true },
                jobsProcessed: { text: fmtNum(s.queue_jobs_processed), color: 'blue' },
                jobsSub:       { text: `failed: ${fmtNum(s.queue_jobs_failed)}`, isSub: true },
                dbQueries:     { text: fmtNum(s.db_queries_total) },
                dbSub:         { text: `avg: ${fmtMs(s.db_avg_query_ms)} | max: ${fmtMs(s.db_max_query_ms)}`, isSub: true },
                dbSlow:        { text: fmtNum(s.db_slow_queries), color: s.db_slow_queries > 10 ? 'red' : s.db_slow_queries > 0 ? 'yellow' : 'green' },
                dbSlowSub:     { text: `above ${document.getElementById('periodSelect').selectedOptions[0].text}`, isSub: true },
                cpuLoad:       { text: s.cpu_load !== null ? s.cpu_load : 'N/A', color: s.cpu_load > 5 ? 'red' : s.cpu_load > 2 ? 'yellow' : 'green' },
                redisMem:      { text: s.redis_memory_mb !== null ? s.redis_memory_mb + ' MB' : 'N/A', color: 'cyan' },
                redisMemSub:   { text: s.redis_clients !== null ? s.redis_clients + ' clients' : '', isSub: true },
                redisOps:      { text: s.redis_ops_per_sec !== null ? fmtNum(s.redis_ops_per_sec) : 'N/A', color: 'cyan' },
                redisHitRate:  { text: s.redis_hit_rate !== null ? 'hit rate: ' + s.redis_hit_rate + '%' : '', isSub: true },
                diskFree:      { text: s.disk_free_gb !== null ? s.disk_free_gb + ' GB' : 'N/A', color: s.disk_free_gb < 2 ? 'red' : s.disk_free_gb < 5 ? 'yellow' : 'green' },
            };

            document.querySelectorAll('.bi-val').forEach(el => {
                const v = vals[el.dataset.key];
                if (v) { el.textContent = v.text; if (v.color) el.className = 'card-value bi-val ' + v.color; }
            });
            document.querySelectorAll('.bi-sub').forEach(el => {
                const v = vals[el.dataset.key];
                if (v) el.textContent = v.text;
            });
        }

        // ─── Built-in charts ────────────────────────────────────
        function updateBuiltInCharts(timeline) {
            if (!timeline || timeline.length === 0) return;
            const labels = timeline.map(d => d.time);

            document.querySelectorAll('.bi-chart').forEach(canvas => {
                const chartKey = canvas.dataset.chart;
                const type = chartKey.split('_')[0];

                if (type === 'requests') {
                    renderChart(chartKey, canvas, labels, [
                        { label: 'Requests', data: timeline.map(d => d.requests), ...COLORS.blue, type: 'bar' },
                        { label: '5xx Errors', data: timeline.map(d => d.errors_5xx), ...COLORS.red, type: 'bar' },
                    ], chartOptsMultiLegend());
                } else if (type === 'duration') {
                    renderChart(chartKey, canvas, labels, [
                        { label: 'Avg', data: timeline.map(d => d.avg_duration), borderColor: COLORS.blue.border, backgroundColor: COLORS.blue.bg, type: 'line', fill: true, tension: 0.3, pointRadius: 0 },
                        { label: 'Max', data: timeline.map(d => d.max_duration), borderColor: COLORS.red.border, backgroundColor: 'transparent', type: 'line', borderDash: [4, 4], tension: 0.3, pointRadius: 0 },
                    ], chartOptsMultiLegend('ms'));
                } else if (type === 'queue') {
                    const o = chartOptsMultiLegend(); o.scales.x.stacked = true; o.scales.y.stacked = true;
                    renderChart(chartKey, canvas, labels, [
                        { label: 'High', data: timeline.map(d => d.queue_high), ...COLORS.red, type: 'bar', stack: 'q' },
                        { label: 'Default', data: timeline.map(d => d.queue_default), ...COLORS.yellow, type: 'bar', stack: 'q' },
                        { label: 'Low', data: timeline.map(d => d.queue_low), ...COLORS.blue, type: 'bar', stack: 'q' },
                    ], o);
                } else if (type === 'db') {
                    renderChart(chartKey, canvas, labels, [
                        { label: 'Queries', data: timeline.map(d => d.db_queries), ...COLORS.purple, type: 'bar' },
                        { label: 'Slow', data: timeline.map(d => d.db_slow), ...COLORS.red, type: 'bar' },
                    ], chartOptsMultiLegend());
                }
            });
        }

        function renderChart(chartKey, canvas, labels, datasets, options) {
            const ds = datasets.map(d => ({
                label: d.label,
                data: d.data,
                backgroundColor: d.bg || d.backgroundColor || COLORS.blue.bg,
                borderColor: d.border || d.borderColor || COLORS.blue.border,
                borderWidth: d.type === 'line' ? 2 : 0,
                type: d.type || 'bar',
                fill: d.fill ?? (d.type !== 'line'),
                tension: d.tension ?? 0,
                pointRadius: d.pointRadius ?? 0,
                pointHoverRadius: d.pointHoverRadius ?? 4,
                borderDash: d.borderDash || [],
                stack: d.stack,
                order: d.type === 'line' ? 0 : 1,
            }));

            if (charts[chartKey]) {
                charts[chartKey].data.labels = labels;
                charts[chartKey].data.datasets.forEach((existing, i) => {
                    if (ds[i]) existing.data = ds[i].data;
                });
                charts[chartKey].update('none');
                return;
            }

            if (!canvas) return;
            charts[chartKey] = new Chart(canvas, { type: 'bar', data: { labels, datasets: ds }, options });
        }

        // ─── Ranked list sections ─────────────────────────────
        function updateListSections(custom) {
            if (!custom) return;
            document.querySelectorAll('.ranked-list-card').forEach(container => {
                const labelKeys = JSON.parse(container.dataset.labelKeys || '[]');
                const valueKeys = JSON.parse(container.dataset.valueKeys || '[]');
                const max = parseInt(container.dataset.max) || 5;

                const matchedLabels = Object.keys(custom).filter(k => labelKeys.some(p => matchesPattern(k, p))).sort();
                const matchedValues = Object.keys(custom).filter(k => valueKeys.some(p => matchesPattern(k, p))).sort();

                const items = [];
                const count = Math.min(matchedLabels.length, matchedValues.length, max);

                for (let i = 0; i < count; i++) {
                    const label = custom[matchedLabels[i]];
                    const value = custom[matchedValues[i]];
                    if (label && value !== undefined && value !== null) {
                        items.push({ label, value });
                    }
                }

                const ul = container.querySelector('.ranked-list');
                if (items.length === 0) {
                    ul.innerHTML = '<li class="no-data-row">No data</li>';
                    return;
                }

                ul.innerHTML = items.map((item, i) => {
                    const formatted = typeof item.value === 'number' ? item.value.toLocaleString() : item.value;
                    return `<li><span class="rank">#${i + 1}</span><span class="rank-label">${escHtml(String(item.label))}</span><span class="rank-value">${formatted}</span></li>`;
                }).join('');
            });
        }

        // ─── Custom cards (pattern-matched) ──────────────────
        function updateCustomCards(custom) {
            if (!custom) return;
            document.querySelectorAll('.custom-cards').forEach(container => {
                const patterns = JSON.parse(container.dataset.keys || '[]');
                const matchedKeys = Object.keys(custom).filter(k => patterns.some(p => matchesPattern(k, p)));
                if (matchedKeys.length === 0) { container.innerHTML = ''; return; }
                container.innerHTML = matchedKeys.map(key => {
                    const label = formatMetricLabel(key);
                    const formatted = formatMetricValue(key, custom[key]);
                    const color = getMetricColor(key, custom[key]);
                    return `<div class="card"><div class="card-title">${escHtml(label)}</div><div class="card-value ${color}">${formatted}</div></div>`;
                }).join('');
            });
        }

        // ─── All custom cards (default view fallback) ─────────
        function updateAllCustomCards(custom) {
            document.querySelectorAll('.all-custom-cards').forEach(container => {
                const title = container.previousElementSibling;
                if (!custom || Object.keys(custom).length === 0) {
                    container.innerHTML = '';
                    if (title && title.classList.contains('all-custom-title')) title.style.display = 'none';
                    return;
                }
                if (title && title.classList.contains('all-custom-title')) title.style.display = '';
                container.innerHTML = Object.entries(custom).map(([key, value]) => {
                    const label = formatMetricLabel(key);
                    const formatted = formatMetricValue(key, value);
                    const color = getMetricColor(key, value);
                    return `<div class="card"><div class="card-title">${escHtml(label)}</div><div class="card-value ${color}">${formatted}</div></div>`;
                }).join('');
            });
        }

        // ─── Custom charts ───────────────────────────────────
        function updateCustomCharts(timeline) {
            if (!timeline || timeline.length === 0) return;
            const labels = timeline.map(d => d.time);

            document.querySelectorAll('.custom-chart').forEach(canvas => {
                const chartKey = canvas.id;
                const patterns = JSON.parse(canvas.dataset.keys || '[]');
                const colors = JSON.parse(canvas.dataset.colors || 'null');
                const chartType = canvas.dataset.chartType || 'bar';
                const isLineChart = chartType === 'line';

                const allTimelineKeys = {};
                timeline.forEach(row => Object.keys(row).forEach(k => { if (k.startsWith('c:')) allTimelineKeys[k] = true; }));
                const matchedKeys = Object.keys(allTimelineKeys).filter(k => {
                    const clean = k.replace('c:', '');
                    return patterns.some(p => matchesPattern(clean, p));
                });

                if (matchedKeys.length === 0) return;

                let colorIndex = 0;
                const datasets = matchedKeys.map(key => {
                    const label = formatMetricLabel(key.replace('c:', ''));
                    const isRate = isLineChart || key.includes('avg') || key.includes('_ms') || key.includes('_rate');
                    const c = getCustomMetricColor(key, colorIndex++, colors);
                    return {
                        label,
                        data: timeline.map(d => d[key] || 0),
                        bg: c.bg,
                        border: c.border,
                        borderWidth: isRate ? 2 : 0,
                        type: isRate ? 'line' : 'bar',
                        fill: isRate && !isLineChart,
                        tension: 0.3,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                    };
                });

                if (charts[chartKey] && charts[chartKey].data.datasets.length !== datasets.length) {
                    charts[chartKey].destroy();
                    delete charts[chartKey];
                }

                renderChart(chartKey, canvas, labels, datasets, chartOptsMultiLegend());
            });
        }

        // ─── Slow logs ──────────────────────────────────────────
        function renderSlowLogs(tbody, logs) {
            if (!logs || logs.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="no-data">No slow logs in this period</td></tr>'; return; }
            tbody.innerHTML = logs.map(log => `<tr>
                <td><span class="badge badge-${log.type}">${log.type}</span></td>
                <td><span class="badge badge-slow">${fmtMs(log.duration_ms)}</span></td>
                <td>${log.type === 'request' ? `<strong>${log.method}</strong> ${escHtml(truncate(log.url, 70))}` : `<span class="sql-text">${escHtml(truncate(log.sql, 80))}</span>`}</td>
                <td>${log.status_code ? statusBadge(log.status_code) : (log.connection || '-')}</td>
                <td>${log.user_id || '-'}</td>
                <td>${log.time}</td>
            </tr>`).join('');
        }

        function renderPagination(container, data) {
            if (!data || data.last_page <= 1) { container.innerHTML = ''; return; }
            const { page, last_page, total } = data;
            let html = `<button class="page-btn" onclick="goToPage(1)" ${page <= 1 ? 'disabled' : ''}>&laquo;</button>`;
            html += `<button class="page-btn" onclick="goToPage(${page - 1})" ${page <= 1 ? 'disabled' : ''}>&lsaquo;</button>`;
            const start = Math.max(1, page - 2);
            const end = Math.min(last_page, page + 2);
            for (let i = start; i <= end; i++) {
                html += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
            }
            html += `<button class="page-btn" onclick="goToPage(${page + 1})" ${page >= last_page ? 'disabled' : ''}>&rsaquo;</button>`;
            html += `<button class="page-btn" onclick="goToPage(${last_page})" ${page >= last_page ? 'disabled' : ''}>&raquo;</button>`;
            html += `<span class="page-info">${total} total</span>`;
            container.innerHTML = html;
        }
        function goToPage(p) { currentSlowPage = p; fetchSlowLogs(); }

        // ─── Shared helpers ─────────────────────────────────────
        function resolveColor(name) { return COLORS[name] || null; }

        function getCustomMetricColor(key, index, colorMap) {
            if (colorMap) {
                const clean = key.replace('c:', '');
                if (typeof colorMap === 'object' && !Array.isArray(colorMap)) {
                    if (colorMap[clean]) return resolveColor(colorMap[clean]) || COLORS.blue;
                    for (const [pattern, color] of Object.entries(colorMap)) {
                        if (pattern.endsWith('*') && clean.startsWith(pattern.slice(0, -1))) {
                            return resolveColor(color) || COLORS.blue;
                        }
                    }
                }
                if (Array.isArray(colorMap) && colorMap[index]) {
                    return resolveColor(colorMap[index]) || COLORS.blue;
                }
            }
            if (ERROR_PATTERN.test(key)) return COLORS.red;
            return SAFE_COLORS[index % SAFE_COLORS.length];
        }

        function matchesPattern(key, pattern) {
            if (pattern.endsWith('*')) return key.startsWith(pattern.slice(0, -1));
            return key === pattern;
        }

        function fmtNum(n) { if (n === null || n === undefined) return 'N/A'; if (n >= 1e6) return (n/1e6).toFixed(1)+'M'; if (n >= 1e3) return (n/1e3).toFixed(1)+'K'; return Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(2)).toString(); }
        function fmtMs(ms) { if (ms === null || ms === undefined) return 'N/A'; if (ms >= 1000) return (ms/1000).toFixed(1)+'s'; return parseFloat(ms.toFixed(1))+'ms'; }
        function truncate(s, len) { return s && s.length > len ? s.substring(0, len) + '...' : (s || ''); }
        function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function escAttr(s) { return s.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        function statusBadge(code) { const cls = code >= 500 ? 'badge-slow' : code >= 400 ? 'badge-request' : 'badge-ok'; return `<span class="badge ${cls}">${code}</span>`; }
        function formatMetricLabel(key) { return key.replace(/_/g, ' ').replace(/\b(ms|mb|gb)\b/gi, m => m.toUpperCase()).replace(/\b(avg|max|p95|api)\b/gi, m => m.toUpperCase()).replace(/^\w/, c => c.toUpperCase()); }
        function formatMetricValue(key, value) {
            if (value === null || value === undefined) return 'N/A';
            if (typeof value === 'string') return value;
            const fmt = findCustomFormat(key);
            if (fmt) {
                let v = value;
                if (fmt.multiply) v = v * fmt.multiply;
                const dec = fmt.decimals !== undefined ? fmt.decimals : 1;
                return parseFloat(v.toFixed(dec)) + (fmt.suffix || '');
            }
            if (key.includes('_ms')) return fmtMs(value);
            if (key.includes('_mb')) return parseFloat(value.toFixed(1))+' MB';
            if (key.includes('_gb')) return parseFloat(value.toFixed(1))+' GB';
            if (key.includes('_rate')) return (value*100).toFixed(1)+'%';
            return fmtNum(value);
        }
        function findCustomFormat(key) {
            for (const cfg of metricConfigs) {
                if (!cfg.format) continue;
                const keys = cfg.keys || [];
                if (keys.some(pattern => matchesPattern(key, pattern))) return cfg.format;
            }
            return null;
        }
        function getMetricColor(key, value) { if (value === null || value === undefined) return ''; if (typeof value === 'string') { if (value === 'running') return 'green'; if (value === 'inactive' || value === 'unknown') return 'red'; return ''; } if (key.includes('error') && value > 0) return 'red'; if (key.includes('rate_limit') && value > 0) return 'yellow'; return ''; }

        // ─── Init ───────────────────────────────────────────────
        initDashboard();
        fetchData();
        const initialRefresh = parseInt(document.getElementById('refreshSelect').value);
        if (initialRefresh > 0) refreshTimer = setInterval(fetchData, initialRefresh * 1000);
    </script>
</body>
</html>
