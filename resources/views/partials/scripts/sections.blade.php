@verbatim
<script>
// Sections are mounted once per view; each returns an updater that is called
// with a fresh context on every refresh. Charts keep their canvas between updates.
let uidCounter = 0;
const uid = () => 'mx' + (++uidCounter);
const byId = id => document.getElementById(id);

// ─── KPI strip ──────────────────────────────────────────────
function buildStrip(section) {
    const id = uid();
    const label = section.label ? `<h2 class="strip-label">${esc(section.label)}</h2>` : '';
    return {
        html: `<div class="strip-wrap" id="${id}-wrap">${label}<section class="strip" id="${id}"></section></div>`,
        attach() {
            const el = byId(id);
            const wrap = byId(id + '-wrap');
            return ctx => {
                const html = renderStrip(section, ctx);
                el.innerHTML = html;
                wrap.hidden = html === '';
            };
        },
    };
}

// ─── Panel ──────────────────────────────────────────────────
function markerHtml(status) {
    if (!status) return '';
    const label = { ok: 'OK', warn: 'Needs attention', error: 'Critical' }[status];
    return `<span class="marker lg ${status}" role="img" aria-label="${label}" title="${label}"></span>`;
}

function panelHead(panel, ctx) {
    const marker = markerHtml(evalStatus(panel.status, ctx.scope, null));
    const caption = panel.caption ? `<span class="muted">${renderTemplate(panel.caption, ctx.scope, ctx.vars)}</span>` : '';
    if (panel.figure !== undefined) {
        return `<div><div class="panel-title">${marker}<h2>${esc(panel.title || '')}</h2></div>${caption ? `<div>${caption}</div>` : ''}</div>`;
    }
    return `<div class="panel-head"><div class="panel-title">${marker}<h2>${esc(panel.title || '')}</h2></div>${caption}</div>`;
}

function buildPanel(panel) {
    const id = uid();
    const slots = [];
    const chartSlots = [];

    const slot = render => {
        const sid = uid();
        slots.push({ id: sid, render });
        return `<div class="slot" id="${sid}"></div>`;
    };

    const chartHtml = block => {
        const entry = { id: uid(), block, titleId: uid(), xId: uid(), legendId: uid() };
        chartSlots.push(entry);
        return `<div class="slot" id="${entry.titleId}"></div>
            <div class="chart-box" style="height:${parseInt(block.height, 10) || 120}px"><canvas id="${entry.id}" role="img" aria-label="${esc(panel.title || 'Chart')} over time"></canvas></div>
            <div class="x-labels" id="${entry.xId}"></div>
            <div class="legend" id="${entry.legendId}" hidden></div>`;
    };

    const blockHtml = block => {
        if (!block || typeof block !== 'object') return '';
        if (block.chart) return chartHtml(block.chart);
        const kind = Object.keys(BLOCK_RENDERERS).find(k => block[k] !== undefined);
        if (!kind) {
            console.warn('[metrics] Unknown panel block', block);
            return '';
        }
        return slot(ctx => BLOCK_RENDERERS[kind](block[kind], ctx));
    };

    const head = slot(ctx => panelHead(panel, ctx));
    const wide = panel.wide || panel.columns ? ' wide' : '';
    let html;

    if (panel.figure !== undefined) {
        const value = slot(ctx => {
            const it = resolveItem(panel.figure, ctx);
            return `<div class="figure-value${statusClass(it.status)}">${it.display}</div>`;
        });
        html = `<section class="panel figure${wide}" id="${id}">${head}${value}</section>`;
    } else if (Array.isArray(panel.columns)) {
        const cols = panel.columns.map((blocks, i) => `<div class="col">${i === 0 ? head : ''}${(blocks || []).map(blockHtml).join('')}</div>`);
        html = `<section class="panel split${wide}" id="${id}">${cols.join('')}</section>`;
    } else {
        html = `<section class="panel${wide}" id="${id}">${head}${(panel.blocks || []).map(blockHtml).join('')}</section>`;
    }

    return {
        html,
        attach() {
            const htmlSlots = slots.map(s => ({ el: byId(s.id), render: s.render }));
            const chartEls = chartSlots.map(c => ({ ...c, canvas: byId(c.id), title: byId(c.titleId), x: byId(c.xId), legend: byId(c.legendId) }));
            return ctx => {
                const models = chartEls.map(c => buildChartModel(c.block, ctx));
                const local = { ...ctx, vars: { ...ctx.vars, max: models[0]?.maxLabel ?? '' } };
                chartEls.forEach((c, i) => updateChartBlock(c, models[i], local, panel));
                htmlSlots.forEach(s => { s.el.innerHTML = s.render(local); });
            };
        },
    };
}

function updateChartBlock(c, model, ctx, panel) {
    const block = c.block;
    const legend = showLegend(block, model) ? legendHtml(block, model, ctx) : '';
    const top = block.legend === 'top';
    // A chart's own title reads its own scale; the panel caption reads the first chart's.
    const title = block.title ? renderTemplate(block.title, ctx.scope, { ...ctx.vars, max: model.maxLabel }) : '';
    c.title.innerHTML = title || (top && legend)
        ? `<div class="chart-title"><span>${title}</span>${top ? `<span class="legend">${legend}</span>` : ''}</div>`
        : '';
    c.legend.innerHTML = top ? '' : legend;
    c.legend.hidden = top || legend === '';
    c.x.innerHTML = xLabelsHtml(ctx.times, block.ticks || (panel.wide ? 5 : 3));
    applyChart(c.id, c.canvas, block, model, ctx.times, ctx.pin);
}

// ─── Slow log ───────────────────────────────────────────────
// { type: 'slow-logs', title, caption, log_type: 'request'|'query', route: 'api/x/*', controls, limit }
function urlPath(url) {
    try {
        const u = new URL(url, window.location.origin);
        return u.pathname + u.search;
    } catch (e) {
        return url || '';
    }
}

function slowLogRow(log, showType, template) {
    const isRequest = log.type === 'request';
    const detail = isRequest
        ? `${esc(log.method || '')} ${esc(urlPath(log.url))}${log.status_code ? ' → ' + esc(log.status_code) : ''}`
        : esc(log.sql || '');
    const right = isRequest ? (log.user_id ?? '') : (log.connection ?? '');
    return `<div class="log-row" style="${template}">
        <div class="muted">${esc(log.time)}</div>
        ${showType ? `<div class="muted">${esc(log.type)}</div>` : ''}
        <div class="duration warn">${esc(formatValue(log.duration_ms, 'ms').replace(' ', ''))}</div>
        <div class="detail" title="${isRequest ? esc(log.url || '') : esc(log.sql || '')}">${detail}</div>
        <div class="muted" style="text-align:right">${esc(right)}</div>
    </div>`;
}

function segmentedHtml(name, options, current, group) {
    return `<div class="segmented compact" role="group" aria-label="${esc(name)}" data-group="${esc(group)}">${options.map(([value, label]) =>
        `<button type="button" data-value="${esc(value)}" aria-pressed="${value === current}">${esc(label)}</button>`).join('')}</div>`;
}

// Window around a pinned point: its bucket ±1 bucket. A metric row is stamped
// when collected and covers the bucket before it, so this always includes the
// requests behind a spike, plus a little context.
function pinWindow(ctx) {
    if (ctx.pin === null || ctx.pin === undefined) return null;
    const row = ctx.timeline[ctx.pin];
    if (!row || !isNum(row.ts)) return null;
    const start = bucketStart(row.ts, ctx.step);
    const span = ctx.step >= 3600 ? `±${trimNum(ctx.step / 3600, 1)} h` : `±${trimNum(ctx.step / 60, 1)} min`;
    return { from: start - ctx.step, to: start + ctx.step, label: `${ctx.pinLabel || row.time} ${span}`, key: `${start}:${ctx.step}` };
}

function buildSlowLogs(section) {
    const id = uid();
    // scope: 'pin' = only around the pinned time (when one is set), 'all' = whole range.
    const state = { type: section.log_type || '', sort: section.sort || 'duration', page: 1, seq: 0, ctx: null, key: null, window: null, scope: 'pin' };
    const showTypeFilter = section.controls !== false && !section.log_type;
    const controls = section.controls === false ? '' : `
        ${showTypeFilter ? segmentedHtml('Log type', [['', 'All'], ['request', 'Requests'], ['query', 'Queries']], state.type, 'type') : ''}
        ${segmentedHtml('Sort', [['duration', 'Slowest'], ['time', 'Recent']], state.sort, 'sort')}`;
    const caption = section.caption ?? (section.controls === false ? (state.sort === 'time' ? 'most recent first' : 'slowest first') : '');
    const showType = !section.log_type;
    const template = `grid-template-columns:76px ${showType ? '70px ' : ''}80px minmax(0,1fr) 60px`;

    const html = `<section class="panel" id="${id}" style="gap:4px;padding-bottom:10px">
        <div class="panel-head" style="padding-bottom:6px">
            <h2>${esc(section.title || section.label || 'Slow log')}</h2>
            <div class="log-controls">
                <div class="slot" id="${id}-pin"></div>
                ${caption ? `<span class="muted" id="${id}-caption">${esc(caption)}</span>` : ''}${controls}
            </div>
        </div>
        <div class="log"><div class="log-rows" id="${id}-rows"></div></div>
        <div class="pager" id="${id}-pager" hidden></div>
    </section>`;

    const pinned = () => state.window !== null && state.scope === 'pin';

    function renderPinControls() {
        const caption = byId(id + '-caption');
        byId(id + '-pin').innerHTML = state.window
            ? segmentedHtml('Time window', [['pin', state.window.label], ['all', 'Whole range']], state.scope, 'scope')
            : '';
        if (caption) caption.hidden = pinned();
    }

    async function load() {
        if (!state.ctx) return;
        const seq = ++state.seq;
        const params = new URLSearchParams({ minutes: state.ctx.period, per_page: section.limit || 25, sort: state.sort, page: state.page });
        if (state.type) params.set('type', state.type);
        if (section.route) params.set('route', section.route);
        if (pinned()) {
            params.set('from', state.window.from);
            params.set('to', state.window.to);
        }
        const rows = byId(id + '-rows');
        const pager = byId(id + '-pager');
        const empty = pinned() ? `at ${state.window.label}` : 'in this period';
        try {
            const res = await fetch(`${BASE_URL}/api/slow-logs?${params}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (seq !== state.seq) return;
            rows.innerHTML = data.data?.length
                ? data.data.map(log => slowLogRow(log, showType, template)).join('')
                : `<div class="empty" style="border-top:1px solid var(--line)">Nothing above the slow threshold ${esc(empty)}.</div>`;
            state.page = data.page || 1;
            pager.hidden = section.controls === false || !(data.last_page > 1);
            pager.innerHTML = `<button type="button" data-page="${state.page - 1}" aria-label="Previous page" ${state.page <= 1 ? 'disabled' : ''}>‹</button>
                <span>Page ${state.page} of ${data.last_page} · ${esc(data.total)} total</span>
                <button type="button" data-page="${state.page + 1}" aria-label="Next page" ${state.page >= data.last_page ? 'disabled' : ''}>›</button>`;
        } catch (e) {
            if (seq !== state.seq) return;
            console.error('[metrics] slow log', e);
            rows.innerHTML = `<div class="empty" style="border-top:1px solid var(--line)">Couldn't load the slow log. It retries on the next refresh.</div>`;
            pager.hidden = true;
        }
    }

    return {
        html,
        attach() {
            const root = byId(id);
            root.addEventListener('click', e => {
                const button = e.target.closest('button');
                if (!button || button.disabled) return;
                if (button.dataset.page) {
                    state.page = parseInt(button.dataset.page, 10);
                } else if (button.dataset.value !== undefined) {
                    const group = button.parentElement;
                    group.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
                    const field = { sort: 'sort', type: 'type', scope: 'scope' }[group.dataset.group];
                    if (!field) return;
                    state[field] = button.dataset.value;
                    state.page = 1;
                    renderPinControls();
                } else {
                    return;
                }
                load();
            });
            return ctx => {
                // Same data and same pin (re-render, switching back to this
                // view) keeps the rows already loaded.
                const win = pinWindow(ctx);
                const key = `${ctx.id}|${win ? win.key : ''}`;
                if (key === state.key) return;
                if (state.ctx && state.ctx.period !== ctx.period) state.page = 1;
                if ((win?.key ?? null) !== (state.window?.key ?? null)) {
                    // A new pin jumps to it; unpinning returns to the whole range.
                    state.page = 1;
                    state.scope = 'pin';
                }
                state.key = key;
                state.ctx = ctx;
                state.window = win;
                renderPinControls();
                load();
            };
        },
    };
}

// ─── View assembly ──────────────────────────────────────────
// Consecutive panels share one responsive grid; 'wide' panels span it.
function buildSections(sections) {
    const parts = [];
    let grid = null;
    const flush = () => {
        if (!grid) return;
        const members = grid;
        grid = null;
        parts.push({
            html: `<div class="grid">${members.map(m => m.html).join('')}</div>`,
            attach() {
                const updaters = members.map(m => m.attach());
                return ctx => updaters.forEach(u => safely(u, ctx));
            },
        });
    };

    expandLegacySections(sections).forEach(section => {
        if (section.type === 'panel') {
            (grid ||= []).push(buildPanel(section));
            return;
        }
        flush();
        if (section.type === 'stats') parts.push(buildStrip(section));
        else if (section.type === 'slow-logs') parts.push(buildSlowLogs(section));
        else console.warn('[metrics] Unknown section type', section);
    });
    flush();
    return parts;
}

function safely(update, ctx) {
    try {
        update(ctx);
    } catch (e) {
        console.error('[metrics] section update failed', e);
    }
}
</script>
@endverbatim
