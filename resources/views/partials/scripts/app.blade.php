@verbatim
<script>
// ─── Built-in sections ──────────────────────────────────────
const BUILT_IN_TILES = {
    http: [
        { label: 'Requests', value: 'http_requests_total', sub: '2xx {http_requests_2xx} · 4xx {http_requests_4xx} · 5xx {http_requests_5xx}', spark: true },
        { label: 'Avg response', value: 'http_avg_duration', sub: 'max {http_max_duration}', spark: true, status: { warn: '> 500', error: '> 2000', ok: false } },
        { label: 'Error rate', value: 'error_rate', sub: '5xx: {http_requests_5xx}', spark: true, status: { warn: '> 1', error: '> 5' } },
        { label: 'Slow requests', value: 'http_slow_requests', sub: 'above threshold', spark: true, status: { warn: '> 0', error: '> 10' } },
    ],
    queue: [
        { label: 'Queue depth', value: 'queue_depth', sub: 'H {queue_depth_high} · D {queue_depth_default} · L {queue_depth_low}', spark: true, status: { warn: '> 20', error: '> 100' } },
        { label: 'Jobs processed', value: 'queue_jobs_processed', sub: 'failed {queue_jobs_failed}' },
    ],
    database: [
        { label: 'DB queries', value: 'db_queries_total', sub: 'avg {db_avg_query_ms} · max {db_max_query_ms}', spark: true },
        { label: 'Slow queries', value: 'db_slow_queries', spark: true, status: { warn: '> 0', error: '> 10' } },
    ],
    system: [
        { label: 'CPU load (1m)', value: 'cpu_load', spark: true, status: { warn: '> 2', error: '> 5', ok: false } },
        { label: 'Redis memory', value: 'redis_memory_mb', sub: '{redis_clients} clients', spark: true },
        { label: 'Redis ops/sec', value: 'redis_ops_per_sec', sub: 'hit rate {redis_hit_rate}', spark: true },
        { label: 'Disk free', value: 'disk_free_gb', status: { warn: '< 5', error: '< 2' } },
    ],
};

const BUILT_IN_PANELS = [
    { type: 'panel', title: 'HTTP', caption: 'requests / min', blocks: [
        { chart: { series: ['http_requests_total'], highlight: { value: 'http_slow_requests', when: '> 0', label: 'has slow requests' } } },
        { stats: [
            { label: '2xx', value: 'http_requests_2xx' },
            { label: '4xx', value: 'http_requests_4xx' },
            { label: '5xx', value: 'http_requests_5xx', status: { warn: '> 0', ok: false } },
            { label: 'Slow', value: 'http_slow_requests', status: { warn: '> 0', ok: false } },
        ] },
    ] },
    { type: 'panel', title: 'Response time', caption: 'up to {max}', blocks: [
        { chart: { type: 'line', format: 'ms', series: [
            { value: 'http_max_duration', label: 'max', color: 'gray' },
            { value: 'http_avg_duration', label: 'avg', color: 'lime' },
        ] } },
    ] },
    { type: 'panel', title: 'Queue depth', caption: 'jobs waiting', blocks: [
        { chart: { type: 'stacked', series: [
            { value: 'queue_depth_high', label: 'high', color: 'orange' },
            { value: 'queue_depth_default', label: 'default', color: 'white' },
            { value: 'queue_depth_low', label: 'low', color: 'gray' },
        ] } },
    ] },
    { type: 'panel', title: 'Database', caption: 'queries / min', blocks: [
        { chart: { series: ['db_queries_total'], highlight: { value: 'db_slow_queries', when: '> 0', label: 'has a slow query' } } },
        { stats: [
            { label: 'Queries', value: 'db_queries_total' },
            { label: 'Avg / max', text: '{db_avg_query_ms|dec} / {db_max_query_ms|dec} ms' },
            { label: 'Slow', value: 'db_slow_queries', status: { warn: '> 0', ok: false } },
            { label: 'Redis', text: '{redis_ops_per_sec}/s' },
        ] },
    ] },
];

function builtInSections(id) {
    if (id) return [{ type: 'stats', items: BUILT_IN_TILES[id] || [] }];
    return [{ type: 'stats', items: Object.values(BUILT_IN_TILES).flat() }, ...BUILT_IN_PANELS];
}

// Original section types, rendered with the current components.
function expandLegacySections(sections) {
    return (sections || []).flatMap(s => {
        switch (s.type) {
            case 'built-in':
                return builtInSections(s.id);
            case 'cards':
                return [{ type: 'stats', label: s.label, keys: s.keys || [], value_colors: s.value_colors, labels: s.labels }];
            case 'all-custom-cards':
                return [{ type: 'stats', label: 'Custom metrics', keys: ['*'] }];
            case 'list':
                return [{ type: 'panel', title: s.label || 'Top', blocks: [{ ranked: { label_keys: s.label_keys, value_keys: s.value_keys, max: s.max } }] }];
            case 'chart':
                return [{ type: 'panel', title: s.label || 'Chart', blocks: [{ chart: {
                    type: s.chart_type === 'line' ? 'line' : 'bar', keys: s.keys || [], labels: s.labels,
                    labels_from: s.labels_from, colors: s.colors, chart_type: s.chart_type, format: s.format,
                } }] }];
            default:
                return [s];
        }
    });
}

// ─── State ──────────────────────────────────────────────────
const DEFAULT_VIEWS = [{ label: 'Dashboard', sections: [{ type: 'built-in' }, { type: 'all-custom-cards' }, { type: 'slow-logs' }] }];
const views = uniqueSlugs((Settings.views && Settings.views.length ? Settings.views : DEFAULT_VIEWS)
    .map((v, i) => ({ ...v, label: v.label || `View ${i + 1}` })));
// pin: the clicked moment { ts, label }, kept across views, refreshes and
// periods; pinIndex: where it falls in the current timeline (null = outside).
const state = { view: 0, period: Settings.defaultPeriod, ctx: null, ctxId: 0, seq: 0, inFlight: false, mounted: [], pin: null, pinIndex: null };

function slugify(s) { return String(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, ''); }

// Hash links need a non-empty, distinct slug per view (labels may be non-Latin or collide).
function uniqueSlugs(list) {
    const used = new Set();
    return list.map((v, i) => {
        let slug = slugify(v.label) || `view-${i + 1}`;
        if (used.has(slug)) slug = `${slug}-${i + 1}`;
        used.add(slug);
        return { ...v, slug };
    });
}

function store(key, value) {
    try {
        if (value === undefined) return window.localStorage.getItem(key);
        window.localStorage.setItem(key, String(value));
    } catch (e) {
        return null;
    }
    return null;
}

function periodLabel(minutes) {
    return Settings.periods.find(p => p.minutes === minutes)?.label ?? `${minutes}m`;
}

function buildContext(data) {
    const summary = data.summary || { custom: {} };
    const timeline = data.timeline || [];
    return {
        id: ++state.ctxId,
        scope: summaryScope(summary),
        rows: timeline.map(rowScope),
        timeline,
        times: timeline.map(r => r.time),
        step: isNum(data.bucket) && data.bucket > 0 ? data.bucket : fallbackBucket(state.period),
        period: state.period,
        vars: { period: periodLabel(state.period) },
        empty: data.status !== 'ok',
    };
}

// Seconds per timeline row when the API doesn't say (mirrors the controller).
function fallbackBucket(minutes) {
    if (minutes > 2880) return 3600;
    return minutes > 120 ? 300 : 60;
}

// Start of the bucket a row belongs to. Raw rows are stamped a second or two
// after the minute, grouped rows with their first sample; hourly rows already
// sit on the hour. Flooring to the minute (raw) or 5 minutes (grouped, hourly)
// works in any real timezone, as all offsets are multiples of 15 minutes.
function bucketStart(ts, step) {
    const unit = Math.min(step, 300);
    return Math.floor(ts / unit) * unit;
}

// ─── Pinned time ────────────────────────────────────────────
// Resolved by timestamp, so the pin stays on the same moment while new
// minutes arrive, and lands in the matching bucket of longer periods.
function pinnedIndex(ctx) {
    const pin = state.pin;
    const rows = ctx ? ctx.timeline : [];
    if (!pin || rows.length === 0) return null;
    if (!isNum(pin.ts) || !rows.every(r => isNum(r.ts))) {
        const i = ctx.times.indexOf(pin.label);
        return i >= 0 ? i : null;
    }
    const starts = rows.map(r => bucketStart(r.ts, ctx.step));
    if (pin.ts < starts[0] || pin.ts >= starts[starts.length - 1] + ctx.step) return null;
    let index = 0;
    starts.forEach((start, i) => { if (start <= pin.ts) index = i; });
    return index;
}

function togglePin(index) {
    const row = state.ctx?.timeline[index];
    if (!row) return;
    state.pin = index === state.pinIndex ? null : { ts: row.ts, label: row.time };
    renderActive();
}

function clearPin() {
    if (!state.pin) return;
    state.pin = null;
    renderActive();
}

function updatePinChip(index) {
    const chip = byId('pinChip');
    chip.hidden = !state.pin;
    if (!state.pin) return;
    byId('pinLabel').textContent = `Pinned ${state.pin.label}${index === null ? ' · outside this range' : ''}`;
}

// ─── Header ─────────────────────────────────────────────────
function setPill(status, text) {
    const pill = byId('statusPill');
    pill.className = 'pill' + (status ? ' ' + status : '');
    pill.innerHTML = `<span class="marker${status && status !== 'ok' ? ' ' + status : ''}"></span>${esc(text)}`;
}

function updatePill(ctx) {
    if (ctx.empty) return setPill(null, 'No data for this period');
    const results = (Settings.status || []).map(rule => ({ rule, status: evalStatus({ ...rule, ok: true }, ctx.scope, null) }));
    const worst = worstStatus(results.map(r => r.status));
    if (!worst) return setPill(null, 'No status rules');
    if (worst === 'ok') return setPill('ok', 'All systems OK');
    const labels = results.filter(r => r.status === worst).map(r => r.rule.label || r.rule.value).join(', ');
    setPill(worst, `${worst === 'error' ? 'Critical' : 'Attention'} · ${labels}`);
}

function setUpdated(date) {
    const every = Settings.refresh > 0 ? `every ${Settings.refresh} s` : 'auto-refresh off';
    byId('lastUpdate').textContent = `Updated ${date.toLocaleTimeString([], { hour12: false })} · ${every}`;
}

function renderPeriods() {
    const el = byId('periods');
    el.innerHTML = Settings.periods.map(p =>
        `<button type="button" data-minutes="${p.minutes}" aria-pressed="${p.minutes === state.period}">${esc(p.label)}</button>`).join('');
    el.addEventListener('click', e => {
        const button = e.target.closest('button[data-minutes]');
        if (!button) return;
        state.period = parseInt(button.dataset.minutes, 10);
        store('metrics_period', state.period);
        el.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
        refresh(true);
    });
}

// ─── Views & navigation ─────────────────────────────────────
function mountViews() {
    const root = byId('views');
    root.innerHTML = views.map((v, i) => `<div class="view" id="view-${i}" hidden></div>`).join('');
    state.mounted = views.map((view, i) => {
        const parts = buildSections(view.sections);
        byId(`view-${i}`).innerHTML = parts.map(p => p.html).join('');
        return parts.map(p => p.attach());
    });

    if (views.length > 1) {
        const tabs = byId('tabs');
        tabs.hidden = false;
        tabs.innerHTML = views.map((v, i) => `<a href="#${v.slug}" data-view="${i}">${esc(v.label)}</a>`).join('');
    }
}

function viewFromHash() {
    let slug = window.location.hash.slice(1);
    try { slug = decodeURIComponent(slug); } catch (e) { /* keep raw */ }
    return slug ? views.findIndex(v => v.slug === slug) : -1;
}

function showView(index) {
    state.view = index;
    views.forEach((v, i) => { byId(`view-${i}`).hidden = i !== index; });
    document.querySelectorAll('#tabs a').forEach(a => {
        if (Number(a.dataset.view) === index) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
    });
    document.title = views.length > 1 ? `Monitoring — ${views[index].label}` : 'Monitoring';
    store('metrics_view', views[index].slug);
    renderActive();
}

function renderActive() {
    if (!state.ctx) return;
    state.pinIndex = pinnedIndex(state.ctx);
    updatePinChip(state.pinIndex);
    const ctx = { ...state.ctx, pin: state.pinIndex, pinLabel: state.pin?.label ?? null };
    (state.mounted[state.view] || []).forEach(update => safely(update, ctx));
}

// ─── Data ───────────────────────────────────────────────────
async function refresh(force = false) {
    if (state.inFlight && !force) return;
    const seq = ++state.seq;
    state.inFlight = true;
    try {
        const res = await fetch(`${BASE_URL}/api/data?minutes=${state.period}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (seq !== state.seq) return;
        state.ctx = buildContext(data.status === 'ok' ? data : { status: 'no_data' });
        renderActive();
        updatePill(state.ctx);
        setUpdated(new Date());
    } catch (e) {
        if (seq !== state.seq) return;
        console.error('[metrics] refresh failed', e);
        setPill('error', "Can't load metrics");
    } finally {
        if (seq === state.seq) state.inFlight = false;
    }
}

function boot() {
    legacyFormats = views.flatMap(v => v.sections || [])
        .filter(s => (s.type === 'cards' || s.type === 'chart') && Array.isArray(s.keys) && s.format)
        .map(s => ({ keys: s.keys, format: s.format }));

    const savedPeriod = parseInt(store('metrics_period'), 10);
    if (Settings.periods.some(p => p.minutes === savedPeriod)) state.period = savedPeriod;

    renderPeriods();
    mountViews();

    const fromHash = viewFromHash();
    const saved = views.findIndex(v => v.slug === store('metrics_view'));
    showView(fromHash >= 0 ? fromHash : Math.max(saved, 0));
    window.addEventListener('hashchange', () => {
        const index = viewFromHash();
        if (index >= 0 && index !== state.view) showView(index);
    });
    byId('pinClear').addEventListener('click', clearPin);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') clearPin(); });

    refresh(true);
    if (Settings.refresh > 0) setInterval(refresh, Settings.refresh * 1000);
}

boot();
</script>
@endverbatim
