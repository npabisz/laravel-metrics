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
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .grid { display: grid; gap: 16px; }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); }
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
        @media (max-width: 768px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
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

    <div class="container">
        <!-- Summary Cards -->
        <div class="grid grid-4" id="summaryCards">
            <div class="card"><div class="card-title">HTTP Requests</div><div class="card-value" id="httpTotal">--</div><div class="card-sub" id="httpSub">--</div></div>
            <div class="card"><div class="card-title">Avg Response</div><div class="card-value" id="httpAvg">--</div><div class="card-sub" id="httpAvgSub">--</div></div>
            <div class="card"><div class="card-title">Error Rate</div><div class="card-value" id="errorRate">--</div><div class="card-sub" id="errorSub">--</div></div>
            <div class="card"><div class="card-title">Slow Requests</div><div class="card-value" id="slowReq">--</div><div class="card-sub">above threshold</div></div>
            <div class="card"><div class="card-title">Queue Depth</div><div class="card-value" id="queueDepth">--</div><div class="card-sub" id="queueSub">--</div></div>
            <div class="card"><div class="card-title">Jobs Processed</div><div class="card-value" id="jobsProcessed">--</div><div class="card-sub" id="jobsSub">--</div></div>
            <div class="card"><div class="card-title">DB Queries</div><div class="card-value" id="dbQueries">--</div><div class="card-sub" id="dbSub">--</div></div>
            <div class="card"><div class="card-title">Slow Queries</div><div class="card-value" id="dbSlow">--</div><div class="card-sub" id="dbSlowSub">--</div></div>
        </div>

        <!-- System Cards -->
        <div class="section-title">System & Redis</div>
        <div class="grid grid-4">
            <div class="card"><div class="card-title">CPU Load (1m)</div><div class="card-value" id="cpuLoad">--</div></div>
            <div class="card"><div class="card-title">Redis Memory</div><div class="card-value" id="redisMem">--</div><div class="card-sub" id="redisMemSub">--</div></div>
            <div class="card"><div class="card-title">Redis Ops/sec</div><div class="card-value" id="redisOps">--</div><div class="card-sub" id="redisHitRate">--</div></div>
            <div class="card"><div class="card-title">Disk Free</div><div class="card-value" id="diskFree">--</div></div>
        </div>

        <!-- Custom Metrics Cards -->
        <div class="section-title" id="customTitle" style="display:none">Custom Metrics</div>
        <div class="grid grid-4" id="customCards"></div>

        <!-- Built-in Charts -->
        <div class="section-title">Timeline</div>
        <div class="grid grid-2">
            <div class="card"><div class="card-title">Requests / minute</div><div class="chart-container"><canvas id="chartRequests"></canvas></div></div>
            <div class="card"><div class="card-title">Response time (ms)</div><div class="chart-container"><canvas id="chartDuration"></canvas></div></div>
            <div class="card"><div class="card-title">Queue depth</div><div class="chart-container"><canvas id="chartQueue"></canvas></div></div>
            <div class="card"><div class="card-title">DB queries / minute</div><div class="chart-container"><canvas id="chartDb"></canvas></div></div>
        </div>

        <!-- Custom Charts -->
        <div class="section-title" id="customChartsTitle" style="display:none">Custom Timeline</div>
        <div class="grid grid-2" id="customChartsGrid"></div>

        <!-- Slow Logs -->
        <div class="section-title">Slow Logs</div>
        <div class="card">
            <div class="slow-controls">
                <div class="tabs" style="margin-bottom:0; border-bottom:none;">
                    <div class="tab active" data-type="">All</div>
                    <div class="tab" data-type="request">Requests</div>
                    <div class="tab" data-type="query">Queries</div>
                </div>
                <div class="sort-controls">
                    <span style="font-size:11px;color:var(--text-muted);margin-right:4px;">Sort:</span>
                    <button class="sort-btn active" data-sort="duration">Slowest</button>
                    <button class="sort-btn" data-sort="time">Recent</button>
                </div>
            </div>
            <div style="border-bottom:1px solid var(--border); margin-bottom:16px;"></div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Type</th><th>Duration</th><th>Detail</th><th>Status</th><th>User</th><th>Time</th></tr></thead>
                    <tbody id="slowLogsBody"><tr><td colspan="6" class="no-data">Loading...</td></tr></tbody>
                </table>
            </div>
            <div class="pagination" id="slowLogsPagination"></div>
        </div>
    </div>

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

        // ─── State ──────────────────────────────────────────────
        const basePath = '{{ rtrim(config("monitoring.dashboard.path", "monitoring"), "/") }}';
        const customChartConfig = @json($customCharts ?? []);
        let refreshTimer = null;
        let currentSlowType = '';
        let currentSlowSort = 'duration';
        let currentSlowPage = 1;
        const charts = {};

        // ─── Events ─────────────────────────────────────────────
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentSlowType = tab.dataset.type;
                currentSlowPage = 1;
                fetchSlowLogs();
            });
        });
        document.querySelectorAll('.sort-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentSlowSort = btn.dataset.sort;
                currentSlowPage = 1;
                fetchSlowLogs();
            });
        });
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
                    updateSummary(data.summary);
                    updateCharts(data.timeline);
                    document.getElementById('lastUpdate').textContent = 'Updated: ' + new Date().toLocaleTimeString();
                })
                .catch(() => { document.getElementById('statusDot').className = 'status-dot error'; });
            fetchSlowLogs();
        }

        function fetchSlowLogs() {
            const minutes = document.getElementById('periodSelect').value;
            let url = `/${basePath}/api/slow-logs?minutes=${minutes}&per_page=25&sort=${currentSlowSort}&page=${currentSlowPage}`;
            if (currentSlowType) url += `&type=${currentSlowType}`;
            fetch(url).then(r => r.json()).then(data => {
                renderSlowLogs(data.data);
                renderPagination(data);
            }).catch(() => {});
        }

        // ─── Summary cards ──────────────────────────────────────
        function updateSummary(s) {
            const dot = document.getElementById('statusDot');
            if (s.error_rate > 5) dot.className = 'status-dot error';
            else if (s.error_rate > 1 || s.queue_depth_default > 100) dot.className = 'status-dot warn';
            else dot.className = 'status-dot ok';

            setVal('httpTotal', fmtNum(s.http_requests_total));
            setVal('httpSub', `2xx: ${fmtNum(s.http_requests_2xx)} | 4xx: ${fmtNum(s.http_requests_4xx)} | 5xx: ${fmtNum(s.http_requests_5xx)}`);

            const avg = s.http_avg_duration;
            setVal('httpAvg', fmtMs(avg), avg > 2000 ? 'red' : avg > 500 ? 'yellow' : 'green');
            setVal('httpAvgSub', `max: ${fmtMs(s.http_max_duration)}`);
            setVal('errorRate', s.error_rate + '%', s.error_rate > 5 ? 'red' : s.error_rate > 1 ? 'yellow' : 'green');
            setVal('errorSub', `${fmtNum(s.http_requests_5xx)} errors`);
            setVal('slowReq', fmtNum(s.http_slow_requests), s.http_slow_requests > 10 ? 'red' : s.http_slow_requests > 0 ? 'yellow' : 'green');

            const totalDepth = s.queue_depth_high + s.queue_depth_default + s.queue_depth_low;
            setVal('queueDepth', fmtNum(totalDepth), totalDepth > 100 ? 'red' : totalDepth > 20 ? 'yellow' : 'green');
            setVal('queueSub', `H: ${s.queue_depth_high} | D: ${s.queue_depth_default} | L: ${s.queue_depth_low}`);
            setVal('jobsProcessed', fmtNum(s.queue_jobs_processed), 'blue');
            setVal('jobsSub', `failed: ${fmtNum(s.queue_jobs_failed)}`);

            setVal('dbQueries', fmtNum(s.db_queries_total));
            setVal('dbSub', `avg: ${fmtMs(s.db_avg_query_ms)} | max: ${fmtMs(s.db_max_query_ms)}`);
            setVal('dbSlow', fmtNum(s.db_slow_queries), s.db_slow_queries > 10 ? 'red' : s.db_slow_queries > 0 ? 'yellow' : 'green');
            setVal('dbSlowSub', `above ${document.getElementById('periodSelect').selectedOptions[0].text}`);

            setVal('cpuLoad', s.cpu_load !== null ? s.cpu_load : 'N/A', s.cpu_load > 5 ? 'red' : s.cpu_load > 2 ? 'yellow' : 'green');
            setVal('redisMem', s.redis_memory_mb !== null ? s.redis_memory_mb + ' MB' : 'N/A', 'cyan');
            setVal('redisMemSub', s.redis_clients !== null ? s.redis_clients + ' clients' : '');
            setVal('redisOps', s.redis_ops_per_sec !== null ? fmtNum(s.redis_ops_per_sec) : 'N/A', 'cyan');
            setVal('redisHitRate', s.redis_hit_rate !== null ? 'hit rate: ' + s.redis_hit_rate + '%' : '');
            setVal('diskFree', s.disk_free_gb !== null ? s.disk_free_gb + ' GB' : 'N/A', s.disk_free_gb < 2 ? 'red' : s.disk_free_gb < 5 ? 'yellow' : 'green');

            renderCustomMetrics(s.custom);
        }

        function renderCustomMetrics(custom) {
            const container = document.getElementById('customCards');
            const title = document.getElementById('customTitle');
            if (!custom || Object.keys(custom).length === 0) { container.innerHTML = ''; title.style.display = 'none'; return; }
            title.style.display = '';
            container.innerHTML = Object.entries(custom).map(([key, value]) => {
                const label = formatMetricLabel(key);
                const formatted = formatMetricValue(key, value);
                const color = getMetricColor(key, value);
                return `<div class="card"><div class="card-title">${escHtml(label)}</div><div class="card-value ${color}">${formatted}</div></div>`;
            }).join('');
        }

        // ─── Built-in charts ────────────────────────────────────
        function updateCharts(timeline) {
            if (!timeline || timeline.length === 0) return;
            const labels = timeline.map(d => d.time);

            // Requests
            renderChart('chartRequests', labels, [
                { label: 'Requests', data: timeline.map(d => d.requests), ...COLORS.blue, type: 'bar' },
                { label: '5xx Errors', data: timeline.map(d => d.errors_5xx), ...COLORS.red, type: 'bar' },
            ], chartOptsMultiLegend());

            // Response time
            renderChart('chartDuration', labels, [
                { label: 'Avg', data: timeline.map(d => d.avg_duration), borderColor: COLORS.blue.border, backgroundColor: COLORS.blue.bg, type: 'line', fill: true, tension: 0.3, pointRadius: 0 },
                { label: 'Max', data: timeline.map(d => d.max_duration), borderColor: COLORS.red.border, backgroundColor: 'transparent', type: 'line', borderDash: [4, 4], tension: 0.3, pointRadius: 0 },
            ], chartOptsMultiLegend('ms'));

            // Queue
            renderChart('chartQueue', labels, [
                { label: 'High', data: timeline.map(d => d.queue_high), ...COLORS.red, type: 'bar', stack: 'q' },
                { label: 'Default', data: timeline.map(d => d.queue_default), ...COLORS.yellow, type: 'bar', stack: 'q' },
                { label: 'Low', data: timeline.map(d => d.queue_low), ...COLORS.blue, type: 'bar', stack: 'q' },
            ], (() => { const o = chartOptsMultiLegend(); o.scales.x.stacked = true; o.scales.y.stacked = true; return o; })());

            // DB queries
            renderChart('chartDb', labels, [
                { label: 'Queries', data: timeline.map(d => d.db_queries), ...COLORS.purple, type: 'bar' },
                { label: 'Slow', data: timeline.map(d => d.db_slow), ...COLORS.red, type: 'bar' },
            ], chartOptsMultiLegend());

            // Custom charts
            renderCustomCharts(timeline, labels);
        }

        function renderChart(canvasId, labels, datasets, options) {
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

            if (charts[canvasId]) {
                charts[canvasId].data.labels = labels;
                charts[canvasId].data.datasets.forEach((existing, i) => {
                    if (ds[i]) existing.data = ds[i].data;
                });
                charts[canvasId].update('none');
                return;
            }

            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            charts[canvasId] = new Chart(canvas, { type: 'bar', data: { labels, datasets: ds }, options });
        }

        // ─── Custom charts ──────────────────────────────────────
        const SAFE_COLORS = [COLORS.blue, COLORS.green, COLORS.yellow, COLORS.purple, COLORS.cyan, COLORS.orange, COLORS.pink];
        const ERROR_PATTERN = /error|5xx|fail|slow|max|high|timeout/i;

        function resolveColor(name) { return COLORS[name] || null; }

        function getCustomMetricColor(key, index, colorMap) {
            if (colorMap) {
                const clean = key.replace('c:', '');
                // Per-key color: { 'metric_name': 'red' }
                if (typeof colorMap === 'object' && !Array.isArray(colorMap)) {
                    // Check exact key match first, then wildcard patterns
                    if (colorMap[clean]) return resolveColor(colorMap[clean]) || COLORS.blue;
                    for (const [pattern, color] of Object.entries(colorMap)) {
                        if (pattern.endsWith('*') && clean.startsWith(pattern.slice(0, -1))) {
                            return resolveColor(color) || COLORS.blue;
                        }
                    }
                }
                // Indexed array: ['blue', 'red', 'green']
                if (Array.isArray(colorMap) && colorMap[index]) {
                    return resolveColor(colorMap[index]) || COLORS.blue;
                }
            }
            if (ERROR_PATTERN.test(key)) return COLORS.red;
            return SAFE_COLORS[index % SAFE_COLORS.length];
        }

        function matchesPattern(key, pattern) {
            if (pattern.endsWith('*')) {
                return key.startsWith(pattern.slice(0, -1));
            }
            return key === pattern;
        }

        function renderCustomCharts(timeline, labels) {
            const grid = document.getElementById('customChartsGrid');
            const title = document.getElementById('customChartsTitle');

            const allCustomKeys = {};
            timeline.forEach(row => Object.keys(row).forEach(k => { if (k.startsWith('c:')) allCustomKeys[k] = true; }));
            const keys = Object.keys(allCustomKeys);

            if (keys.length === 0) { grid.innerHTML = ''; title.style.display = 'none'; return; }
            title.style.display = '';

            let groups;
            if (customChartConfig.length > 0) {
                groups = buildConfigGroups(keys);
            } else {
                groups = autoGroupKeys(keys);
            }

            // Destroy old custom charts
            Object.keys(charts).forEach(k => {
                if (k.startsWith('customChart_')) { charts[k].destroy(); delete charts[k]; }
            });

            grid.innerHTML = groups.map(g =>
                `<div class="card"><div class="card-title">${escHtml(g.label)}</div><div class="chart-container"><canvas id="${g.id}"></canvas></div></div>`
            ).join('');

            groups.forEach(group => {
                let colorIndex = 0;
                const datasets = group.keys.map((key) => {
                    const label = formatMetricLabel(key.replace('c:', ''));
                    const isRate = key.includes('avg') || key.includes('_ms') || key.includes('_rate');
                    const c = getCustomMetricColor(key, colorIndex++, group.colors);
                    return {
                        label,
                        data: timeline.map(d => d[key] || 0),
                        backgroundColor: c.bg,
                        borderColor: c.border,
                        borderWidth: isRate ? 2 : 0,
                        type: isRate ? 'line' : 'bar',
                        fill: isRate,
                        tension: 0.3,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                    };
                });

                const opts = chartOptsMultiLegend();
                charts[group.id] = new Chart(document.getElementById(group.id), {
                    type: 'bar',
                    data: { labels, datasets },
                    options: opts,
                });
            });
        }

        // Build groups from user-defined config, leftover keys auto-grouped
        function buildConfigGroups(allKeys) {
            const claimed = new Set();
            const groups = [];

            customChartConfig.forEach((cfg, i) => {
                const matched = allKeys.filter(k => {
                    const clean = k.replace('c:', '');
                    return (cfg.keys || []).some(pattern => matchesPattern(clean, pattern));
                });
                matched.forEach(k => claimed.add(k));
                if (matched.length > 0) {
                    groups.push({
                        id: 'customChart_cfg_' + i,
                        label: cfg.label || 'Custom ' + (i + 1),
                        keys: matched,
                        colors: cfg.colors || null,
                    });
                }
            });

            // Auto-group unclaimed keys
            const unclaimed = allKeys.filter(k => !claimed.has(k));
            if (unclaimed.length > 0) {
                autoGroupKeys(unclaimed).forEach(g => groups.push(g));
            }

            return groups;
        }

        // Auto-group by shared prefix with merge logic
        function autoGroupKeys(keys) {
            const cleanKeys = keys.map(k => k.replace('c:', ''));

            // Find the longest prefix shared with at least one other key
            const keyToPrefix = {};
            cleanKeys.forEach(key => {
                const parts = key.split('_');
                let bestPrefix = parts[0];
                for (let len = parts.length - 1; len >= 1; len--) {
                    const prefix = parts.slice(0, len).join('_');
                    if (cleanKeys.some(other => other !== key && other.startsWith(prefix + '_'))) {
                        bestPrefix = prefix;
                        break;
                    }
                }
                keyToPrefix[key] = bestPrefix;
            });

            const prefixMap = {};
            cleanKeys.forEach(key => {
                const p = keyToPrefix[key];
                if (!prefixMap[p]) prefixMap[p] = [];
                prefixMap[p].push('c:' + key);
            });

            // Merge small groups (<=2 items) into the closest group sharing a common first segment
            let changed = true;
            while (changed) {
                changed = false;
                const entries = Object.entries(prefixMap);
                for (const [prefix, items] of entries) {
                    if (!prefixMap[prefix] || items.length > 2) continue;
                    const parts = prefix.split('_');
                    let bestTarget = null, bestCommon = 0;
                    for (const [other] of entries) {
                        if (other === prefix || !prefixMap[other]) continue;
                        const op = other.split('_');
                        let common = 0;
                        while (common < parts.length && common < op.length && parts[common] === op[common]) common++;
                        if (common > bestCommon) { bestCommon = common; bestTarget = other; }
                    }
                    if (bestTarget && bestCommon >= 1) {
                        prefixMap[bestTarget].push(...items);
                        delete prefixMap[prefix];
                        changed = true;
                        break;
                    }
                }
            }

            const groups = [];
            const maxPerChart = 5;
            Object.entries(prefixMap).forEach(([prefix, groupKeys]) => {
                for (let i = 0; i < groupKeys.length; i += maxPerChart) {
                    const chunk = groupKeys.slice(i, i + maxPerChart);
                    const suffix = groupKeys.length > maxPerChart ? ` (${Math.floor(i/maxPerChart)+1})` : '';
                    groups.push({ id: 'customChart_' + prefix + '_' + i, label: formatMetricLabel(prefix) + suffix, keys: chunk, colors: null });
                }
            });
            return groups;
        }

        // ─── Slow logs table ────────────────────────────────────
        function renderSlowLogs(logs) {
            const tbody = document.getElementById('slowLogsBody');
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

        function renderPagination(data) {
            const container = document.getElementById('slowLogsPagination');
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

        // ─── Helpers ────────────────────────────────────────────
        function setVal(id, value, colorClass) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = value;
            if (colorClass && el.classList.contains('card-value')) el.className = 'card-value ' + colorClass;
        }
        function fmtNum(n) { if (n === null || n === undefined) return 'N/A'; if (n >= 1e6) return (n/1e6).toFixed(1)+'M'; if (n >= 1e3) return (n/1e3).toFixed(1)+'K'; return Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(2)).toString(); }
        function fmtMs(ms) { if (ms === null || ms === undefined) return 'N/A'; if (ms >= 1000) return (ms/1000).toFixed(1)+'s'; return parseFloat(ms.toFixed(1))+'ms'; }
        function truncate(s, len) { return s && s.length > len ? s.substring(0, len) + '...' : (s || ''); }
        function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function statusBadge(code) { const cls = code >= 500 ? 'badge-slow' : code >= 400 ? 'badge-request' : 'badge-ok'; return `<span class="badge ${cls}">${code}</span>`; }
        function formatMetricLabel(key) { return key.replace(/_/g, ' ').replace(/\b(ms|mb|gb)\b/gi, m => m.toUpperCase()).replace(/\b(avg|max|p95|api)\b/gi, m => m.toUpperCase()).replace(/^\w/, c => c.toUpperCase()); }
        function formatMetricValue(key, value) { if (value === null || value === undefined) return 'N/A'; if (typeof value === 'string') return value; if (key.includes('_ms')) return fmtMs(value); if (key.includes('_mb')) return parseFloat(value.toFixed(1))+' MB'; if (key.includes('_gb')) return parseFloat(value.toFixed(1))+' GB'; if (key.includes('_rate')) return (value*100).toFixed(1)+'%'; return fmtNum(value); }
        function getMetricColor(key, value) { if (value === null || value === undefined) return ''; if (typeof value === 'string') { if (value === 'running') return 'green'; if (value === 'inactive' || value === 'unknown') return 'red'; return ''; } if (key.includes('error') && value > 0) return 'red'; if (key.includes('rate_limit') && value > 0) return 'yellow'; return ''; }

        // ─── Init ───────────────────────────────────────────────
        fetchData();
        const initialRefresh = parseInt(document.getElementById('refreshSelect').value);
        if (initialRefresh > 0) refreshTimer = setInterval(fetchData, initialRefresh * 1000);
    </script>
</body>
</html>
