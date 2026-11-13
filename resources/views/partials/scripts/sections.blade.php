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

function slowLogSummary(log) {
    return log.type === 'request'
        ? `${log.method || ''} ${urlPath(log.url)}${log.status_code ? ' → ' + log.status_code : ''}`
        : (log.sql || '');
}

// A row opens to show the entry in full (slowLogDetailHtml), loaded on demand.
function slowLogRow(log, showType, template, panelId) {
    const isRequest = log.type === 'request';
    const right = isRequest ? (log.user_id ?? '') : (log.connection ?? '');
    return `<button type="button" class="log-row" style="${template}" data-log="${esc(log.id)}" aria-expanded="false" aria-controls="${panelId}-log-${esc(log.id)}">
        <span class="muted">${esc(log.time)}</span>
        ${showType ? `<span class="muted">${esc(log.type)}</span>` : ''}
        <span class="duration warn">${esc(formatValue(log.duration_ms, 'ms').replace(' ', ''))}</span>
        <span class="detail" title="${esc(isRequest ? log.url || '' : log.sql || '')}">${esc(slowLogSummary(log))}</span>
        <span class="muted" style="text-align:right">${esc(right)}</span>
    </button>
    <div class="log-detail" id="${panelId}-log-${esc(log.id)}" hidden></div>`;
}

function msText(value) {
    return isNum(value) ? formatValue(value, 'ms') : EMPTY;
}

function factsHtml(facts) {
    const rows = facts.filter(([, value]) => value !== null && value !== undefined && value !== '');
    return rows.length
        ? `<dl class="facts">${rows.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>`).join('')}</dl>`
        : '';
}

function detailBlock(title, body, note = '') {
    return body ? `<div class="detail-block"><div class="mini-head">${esc(title)}${note ? ` <span class="muted">· ${esc(note)}</span>` : ''}</div>${body}</div>` : '';
}

// Spans on a common time axis. A span with spans inside it is drawn grey:
// only what is inside it is explained, its own time is not.
function waterfallHtml(trace) {
    const spans = trace.spans || [];
    if (!spans.length) return '';
    const total = Math.max(trace.total_ms || 0, ...spans.map(s => s.at + s.ms)) || 1;
    const pct = v => Math.min(100, Math.max(0, v / total * 100));
    const hasInner = (span, index) => spans.some((other, j) => j !== index && other.depth === span.depth + 1
        && other.at >= span.at - 0.05 && other.at <= span.at + span.ms + 0.05);
    const metaText = meta => Object.entries(meta || {}).map(([k, v]) => `${k}: ${v === true ? 'yes' : v}`).join(' · ');
    return `<div class="wf">${spans.map((span, i) => {
        const failed = span.meta && span.meta.failed !== undefined && span.meta.failed !== false;
        const kind = failed ? ' error' : (hasInner(span, i) ? ' parent' : '');
        const meta = metaText(span.meta);
        return `<div class="wf-row">
            <span class="wf-name" style="padding-left:${Math.min(span.depth, 6) * 14}px" title="${esc(span.name + (meta ? ' · ' + meta : ''))}">${esc(span.name)}${meta ? ` <span class="muted">${esc(meta)}</span>` : ''}</span>
            <span class="wf-track"><span class="wf-bar${kind}" style="left:${pct(span.at)}%;width:${Math.max(pct(span.ms), 0.4)}%"></span></span>
            <span class="wf-ms">${esc(msText(span.ms))}</span>
        </div>`;
    }).join('')}
        <div class="wf-row wf-axis muted"><span></span><span class="wf-scale"><span>0</span><span>${esc(msText(total))}</span></span><span></span></div>
    </div>`;
}

function traceHtml(trace) {
    if (!trace) return '';
    const db = trace.db;
    const unaccountedShare = trace.total_ms ? trace.unaccounted_ms / trace.total_ms : 0;
    const summary = `<div class="trace-summary">
        <span>total <b>${esc(msText(trace.total_ms))}</b></span>
        ${isNum(trace.before_ms) ? `<span>before middleware <b>${esc(msText(trace.before_ms))}</b></span>` : ''}
        <span>unaccounted <b class="${unaccountedShare > 0.25 ? 'warn' : ''}">${esc(msText(trace.unaccounted_ms))}</b>${trace.approximate ? ' <span class="muted">(at most)</span>' : ''}</span>
        ${db ? `<span>db <b>${esc(db.count)}</b> ${db.count === 1 ? 'query' : 'queries'} · <b>${esc(msText(db.ms))}</b> · ${esc(db.distinct)} distinct</span>` : ''}
        ${trace.truncated ? '<span class="warn">cut down to fit</span>' : ''}
    </div>`;
    const dropped = Object.entries(trace.spans_dropped || {});
    const droppedNote = dropped.length
        ? `<div class="muted">Over the span limit: ${esc(dropped.map(([name, d]) => `${name} ×${d.count} (${msText(d.ms)})`).join(', '))}</div>`
        : '';
    const statements = db && db.top && db.top.length
        ? `<div class="stmt-list">${db.top.map(st => `<div class="stmt">
            <span class="stmt-sql" title="${esc(st.sql)}">${esc(st.sql)}</span>
            <span class="muted">×${esc(st.count)}</span>
            <span>${esc(msText(st.ms))}</span>
            <span class="muted">max ${esc(msText(st.max_ms))}</span>
        </div>`).join('')}</div>`
        : '';
    const marks = (trace.marks || []).length
        ? `<div class="mark-list">${trace.marks.map(m => `<div><span class="muted">${esc(msText(m.at))}</span> ${esc(m.msg)}</div>`).join('')}${trace.marks_dropped ? `<div class="muted">+${esc(trace.marks_dropped)} more</div>` : ''}</div>`
        : '';
    const tags = Object.keys(trace.tags || {}).length ? factsHtml(Object.entries(trace.tags)) : '';
    return summary
        + detailBlock('Timeline', waterfallHtml(trace) + droppedNote)
        + detailBlock('Costliest statements', statements)
        + detailBlock('Marks', marks)
        + detailBlock('Tags', tags);
}

const KNOWN_CONTEXT = ['trace', 'trace_id', 'threshold', 'ip', 'user_agent'];

function slowLogDetailHtml(payload) {
    const log = payload.data;
    const context = log.context || {};
    const isRequest = log.type === 'request';
    const facts = isRequest
        ? [['Date', log.date], ['Route', log.route], ['URL', log.url], ['Status', log.status_code], ['User', log.user_id],
            ['Threshold', isNum(context.threshold) ? msText(context.threshold) : null], ['IP', context.ip], ['User agent', context.user_agent], ['Trace', context.trace_id]]
        : [['Date', log.date], ['Connection', log.connection], ['Trace', context.trace_id]];
    const other = Object.fromEntries(Object.entries(context).filter(([key]) => !KNOWN_CONTEXT.includes(key)));
    const bindings = (log.bindings || []).length
        ? `<pre class="code">${esc(log.bindings.map(b => JSON.stringify(b)).join(', '))}</pre>`
        : '';
    const related = (payload.related || []).length
        ? `<div class="related">${payload.related.map(r => `<div class="related-row">
            <span class="muted">${esc(r.time)}</span><span class="muted">${esc(r.type)}</span>
            <span class="duration warn">${esc(msText(r.duration_ms))}</span>
            <span class="detail" title="${esc(slowLogSummary(r))}">${esc(slowLogSummary(r))}</span>
        </div>`).join('')}</div>`
        : '';
    return factsHtml(facts)
        + (isRequest ? '' : detailBlock('SQL', `<pre class="code">${esc(log.sql || '')}</pre>`))
        + detailBlock('Bindings', bindings, log.bindings_total > (log.bindings || []).length ? `first ${log.bindings.length} of ${log.bindings_total}` : '')
        + (isRequest && !context.trace ? `<div class="muted">No trace: logged before tracing, or tracing is off.</div>` : traceHtml(context.trace))
        + detailBlock(isRequest ? 'Slow queries in this request' : 'Same request', related)
        + detailBlock('Context', Object.keys(other).length ? `<pre class="code">${esc(JSON.stringify(other, null, 1))}</pre>` : '');
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
    const state = { type: section.log_type || '', sort: section.sort || 'duration', page: 1, seq: 0, ctx: null, key: null, window: null, scope: 'pin',
        // Opened entries stay open across refreshes; their details are fetched once.
        open: new Set(), details: new Map() };
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

    function renderDetail(logId) {
        const panel = byId(`${id}-log-${logId}`);
        if (!panel) return;
        const row = panel.previousElementSibling;
        const open = state.open.has(logId);
        row.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
        if (!open) return;
        const entry = state.details.get(logId);
        panel.innerHTML = entry === undefined
            ? '<div class="muted">Loading…</div>'
            : (entry === null ? '<div class="muted">Couldn\'t load this entry.</div>' : slowLogDetailHtml(entry));
    }

    async function toggleDetail(logId) {
        if (state.open.has(logId)) {
            state.open.delete(logId);
            renderDetail(logId);
            return;
        }
        state.open.add(logId);
        renderDetail(logId);
        if (state.details.get(logId)) return;
        try {
            const res = await fetch(`${BASE_URL}/api/slow-logs/${encodeURIComponent(logId)}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            state.details.set(logId, await res.json());
        } catch (e) {
            console.error('[metrics] slow log entry', e);
            state.details.set(logId, null);
        }
        renderDetail(logId);
        // A failed load is tried again the next time the entry is opened.
        if (state.details.get(logId) === null) state.details.delete(logId);
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
                ? data.data.map(log => slowLogRow(log, showType, template, id)).join('')
                : `<div class="empty" style="border-top:1px solid var(--line)">Nothing above the slow threshold ${esc(empty)}.</div>`;
            state.open.forEach(logId => {
                if (byId(`${id}-log-${logId}`)) renderDetail(logId);
            });
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
                if (button.dataset.log) {
                    toggleDetail(button.dataset.log);
                    return;
                }
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
