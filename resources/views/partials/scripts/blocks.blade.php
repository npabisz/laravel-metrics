@verbatim
<script>
// ─── Items (shared by tiles, inline stats, rows, hero) ──────
// { label, value: expr, text: template, format, sub: template, spark, status }
function normalizeItem(item) {
    return typeof item === 'string' ? { value: item } : (item || {});
}

function resolveItem(raw, ctx, row = null) {
    const item = normalizeItem(raw);
    const src = bindRowExpr(item.value, row);
    const value = src !== undefined ? evaluate(src, ctx.scope) : null;
    const hint = src !== undefined ? formatHint(src) : null;
    const display = item.text !== undefined
        ? renderTemplate(bindRowTemplate(item.text, row), ctx.scope, ctx.vars)
        : esc(formatValue(value, item.format, hint));
    const status = evalStatus(item.status, ctx.scope, value, row);
    const label = item.label !== undefined ? renderTemplate(bindRowTemplate(item.label, row), ctx.scope, ctx.vars)
        : esc(src !== undefined ? metricLabel(src) : '');
    const sub = item.sub !== undefined ? renderTemplate(bindRowTemplate(item.sub, row), ctx.scope, ctx.vars) : '';
    const sparkSrc = item.spark === true ? src : bindRowExpr(item.spark, row);
    return { value, display, status, label, sub, sparkSrc, item };
}

function statusClass(status) { return status ? ' ' + status : ''; }
function statusTitle(status) { return status === 'warn' ? ' title="Needs attention"' : status === 'error' ? ' title="Critical"' : ''; }

// ─── KPI strip ──────────────────────────────────────────────
function stripItems(section, ctx) {
    if (!section.keys) return section.items || [];
    // Dynamic tiles from custom-key patterns (legacy 'cards' and 'keys' strips).
    const keys = ctx.scope.keys()
        .filter(k => section.keys.some(p => matchCapture(k, p) !== null) && ctx.scope.get(k) !== null);
    return keys.map(key => {
        const conditions = (section.value_colors || [])
            .filter(r => !r.key || r.key === '*' || matchCapture(key, r.key) !== null)
            .flatMap(r => r.conditions || []);
        return {
            value: key,
            label: section.labels?.[key] ?? metricLabel(key),
            sub: /_avg_/.test(key) ? 'avg over {period}' : undefined,
            status: { legacy: conditions, legacyKey: key },
        };
    });
}

function renderStrip(section, ctx) {
    const items = stripItems(section, ctx);
    if (!items.length) return section.keys ? '' : `<div class="tile"><span class="empty">No metrics</span></div>`;
    return items.map(raw => {
        const it = resolveItem(raw, ctx);
        const spark = it.sparkSrc ? sparkSvg(seriesValues(it.sparkSrc, ctx), PALETTE.white, ctx.pin) : '';
        return `<div class="tile">
            <div class="muted small">${it.label}</div>
            <div class="tile-value${statusClass(it.status)}"${statusTitle(it.status)}>${it.display}</div>
            <div class="small muted">${it.sub}</div>${spark}
        </div>`;
    }).join('');
}

// ─── Inline blocks ──────────────────────────────────────────
function renderStats(block, ctx) {
    const items = Array.isArray(block) ? block : (block.items || []);
    const cols = parseInt(!Array.isArray(block) && block.columns, 10) || Math.min(items.length, 4) || 1;
    return `<div class="stats" style="grid-template-columns:repeat(${cols},minmax(0,1fr))">${items.map(raw => {
        const it = resolveItem(raw, ctx);
        return `<div><div class="muted small">${it.label}</div><div class="stat-value${statusClass(it.status)}"${statusTitle(it.status)}>${it.display}</div></div>`;
    }).join('')}</div>`;
}

function renderRows(block, ctx) {
    const items = Array.isArray(block) ? block : (block.items || []);
    return items.map(raw => {
        const it = resolveItem(raw, ctx);
        return `<div class="kv"><span class="muted">${it.label}</span><span class="${it.status || ''}"${statusTitle(it.status)}>${it.display}</span></div>`;
    }).join('');
}

function renderNote(block, ctx) {
    const text = typeof block === 'string' ? block : block.text;
    const divider = typeof block === 'object' && block.divider ? ' divider' : '';
    return `<div class="note${divider}">${renderTemplate(text, ctx.scope, ctx.vars)}</div>`;
}

function renderHero(block, ctx) {
    const it = resolveItem(block, ctx);
    const caption = block.caption ? ` <span class="muted">${renderTemplate(block.caption, ctx.scope, ctx.vars)}</span>` : '';
    return `<div><span class="hero-value${statusClass(it.status)}">${it.display}</span>${caption}</div>`;
}

// ─── Rows built from a '*' pattern ──────────────────────────
// rows_from: 'container_*_memory_mb' → one row per match, '$' = matched part.
// rows: ['payment', 'search'] → fixed rows, in that order.
function expandRows(block, ctx) {
    if (Array.isArray(block.rows)) {
        return block.rows.map(capture => ({ capture: String(capture), name: displayName(String(capture)), value: null }));
    }
    const rows = matchedValues(ctx.scope, block.rows_from).map(x => ({ capture: x.capture, name: displayName(x.capture), value: x.value }));
    if (Array.isArray(block.order)) {
        const rank = c => { const i = block.order.indexOf(c); return i < 0 ? Infinity : i; };
        rows.sort((a, b) => rank(a.capture) - rank(b.capture) || a.capture.localeCompare(b.capture));
    } else if (block.sort === 'asc' || block.sort === 'desc') {
        rows.sort((a, b) => block.sort === 'asc' ? a.value - b.value : b.value - a.value);
    } else {
        rows.sort((a, b) => a.capture.localeCompare(b.capture));
    }
    return block.limit ? rows.slice(0, block.limit) : rows;
}

function percentOf(value, max) {
    if (!isNum(value) || !isNum(max) || max <= 0) return 0;
    return Math.max(0, Math.min(100, value / max * 100));
}

// ─── Meters ─────────────────────────────────────────────────
// { items: [{label, value, max}], layout: 'inline'|'stacked' } or rows_from.
function renderMeters(block, ctx) {
    const rows = block.rows_from ? expandRows(block, ctx) : null;
    const entries = rows
        ? rows.map(row => ({ row, raw: { label: '$', value: block.value, max: block.max, detail: block.detail, status: block.status } }))
        : (block.items || []).map(raw => ({ row: null, raw: normalizeItem(raw) }));
    if (!entries.length) return `<div class="empty">No data</div>`;
    return entries.map(({ row, raw }) => {
        const it = resolveItem(raw, ctx, row);
        const max = toNum(evaluate(bindRowExpr(raw.max ?? 100, row), ctx.scope));
        const pct = percentOf(toNum(it.value), max);
        const fill = `<div class="fill${statusClass(it.status)}" style="width:${pct.toFixed(1)}%"></div>`;
        if (block.layout === 'stacked') {
            const detail = raw.detail ? ` <span class="muted">· ${renderTemplate(bindRowTemplate(raw.detail, row), ctx.scope, ctx.vars)}</span>` : '';
            return `<div class="stack"><div class="stack-head"><span style="font-weight:600">${it.label}</span><span>${it.display}${detail}</span></div><div class="track">${fill}</div></div>`;
        }
        return `<div class="meter"><div class="meter-label">${it.label}</div><div class="track">${fill}</div><div class="meter-value">${it.display}</div></div>`;
    }).join('');
}

// ─── Table ──────────────────────────────────────────────────
// { rows_from, columns: [{label, value|text|bar, max, width, align, status}], spark, note|notes }
function renderTable(block, ctx) {
    const columns = block.columns || [];
    const template = `grid-template-columns:${columns.map(c => esc(c.width || '1fr')).join(' ')}`;
    const align = c => c.align === 'right' ? ' style="text-align:right"' : '';
    const head = `<div class="trow thead${block.divider ? ' note divider' : ''}" style="${template}">${columns.map(c => `<div${align(c)}>${esc(c.label ?? '')}</div>`).join('')}</div>`;
    const rows = expandRows(block, ctx);
    if (!rows.length) return head + `<div class="empty">No data</div>`;

    const body = rows.map(row => {
        const statuses = [];
        const cells = columns.map((col, i) => {
            if (col.bar !== undefined) {
                const value = toNum(evaluate(bindRowExpr(col.bar, row), ctx.scope));
                const max = toNum(evaluate(bindRowExpr(col.max ?? 100, row), ctx.scope));
                return `<div class="track thin"><div class="fill neutral" style="width:${percentOf(value, max).toFixed(1)}%"></div></div>`;
            }
            const it = resolveItem(col, ctx, row);
            if (it.status) statuses.push(it.status);
            const cls = ((i === 0 ? 'tcell-name' : '') + statusClass(it.status) + (it.status ? ' strong' : '')).trim();
            return `<div class="${cls}"${align(col)}${statusTitle(it.status)}>${it.display}</div>`;
        }).join('');
        const line = `<div class="trow" style="${template}">${cells}</div>`;
        const sparkSrc = bindRowExpr(block.spark, row);
        const color = STATUS_COLOR[worstStatus(statuses)] || PALETTE.white;
        const spark = sparkSrc ? sparkSvg(seriesValues(sparkSrc, ctx), color, ctx.pin) : '';
        const noteTpl = block.notes?.[row.capture] ?? block.note;
        const note = noteTpl ? `<div class="note">${renderTemplate(bindRowTemplate(noteTpl, row), ctx.scope, ctx.vars)}</div>` : '';
        return spark || note ? `<div class="tgroup">${line}${spark}${note}</div>` : line;
    }).join('');

    return `<div class="table">${head}${body}</div>`;
}

// ─── Ranked pairs (label key + value key sharing the '*' part) ──
function pairKeys(scope, labelPatterns, valuePatterns) {
    const lp = [].concat(labelPatterns || []);
    const vp = [].concat(valuePatterns || []);
    const labels = [];
    const values = [];
    for (const key of scope.keys()) {
        const l = lp.map(p => matchCapture(key, p)).find(c => c !== null);
        if (l !== undefined) labels.push({ key, capture: l });
        const v = vp.map(p => matchCapture(key, p)).find(c => c !== null);
        if (v !== undefined) values.push({ key, capture: v });
    }
    const byCapture = new Map(values.map(v => [v.capture, v.key]));
    const paired = labels.every(l => byCapture.has(l.capture));
    labels.sort((a, b) => a.key.localeCompare(b.key, undefined, { numeric: true }));
    values.sort((a, b) => a.key.localeCompare(b.key, undefined, { numeric: true }));
    return labels.map((l, i) => ({
        label: scope.get(l.key),
        value: toNum(scope.get(paired ? byCapture.get(l.capture) : values[i]?.key)),
    })).filter(p => p.label !== null && p.label !== '' && p.value !== null);
}

function renderRanked(block, ctx) {
    const items = pairKeys(ctx.scope, block.label_keys, block.value_keys)
        .sort((a, b) => b.value - a.value).slice(0, block.max || 5);
    if (!items.length) return `<div class="empty">No data</div>`;
    const top = Math.max(...items.map(i => i.value), 0);
    const fmt = block.format;
    return items.map((it, i) => {
        const pct = percentOf(it.value, top).toFixed(1);
        const value = esc(formatValue(it.value, fmt ?? 'num'));
        if (block.layout === 'compact') {
            return `<div class="rank"><div class="rank-label">${esc(it.label)}</div><div class="track thin"><div class="fill" style="width:${pct}%"></div></div><div class="rank-value">${value}</div></div>`;
        }
        return `<div class="stack"><div class="stack-head"><span><span class="muted">#${i + 1}</span> ${esc(it.label)}</span><span style="font-weight:600">${value}</span></div><div class="track"><div class="fill" style="width:${pct}%"></div></div></div>`;
    }).join('');
}

// ─── Trends: one mini bar chart per label, same scale ───────
function renderTrends(block, ctx) {
    const series = new Map();
    ctx.rows.forEach((scope, i) => {
        pairKeys(scope, block.label_keys, block.value_keys).forEach(p => {
            if (!series.has(p.label)) series.set(p.label, new Array(ctx.rows.length).fill(0));
            series.get(p.label)[i] += p.value;
        });
    });
    const ranked = [...series.entries()]
        .map(([label, values]) => ({ label, values, total: values.reduce((s, v) => s + v, 0), peak: Math.max(...values) }))
        .sort((a, b) => b.total - a.total)
        .slice(0, block.max || 5);
    if (!ranked.length) return `<div class="empty">No data</div>`;
    const peak = Math.max(...ranked.map(r => r.peak), 0);
    return ranked.map(r => `<div class="trend">
        <div class="stack-head"><span>${esc(r.label)}</span><span class="muted">peak ${esc(formatValue(r.peak, 'num'))}</span></div>
        <div class="minibars" role="img" aria-label="${esc(r.label)}: peak ${esc(r.peak)} per sample">${r.values.map((v, i) =>
            `<div${i === ctx.pin ? ' class="pinned"' : ''} style="height:${Math.max(percentOf(v, peak), 3).toFixed(1)}%"></div>`).join('')}</div>
    </div>`).join('');
}

const BLOCK_RENDERERS = {
    stats: renderStats,
    rows: renderRows,
    note: renderNote,
    hero: renderHero,
    meters: renderMeters,
    table: renderTable,
    ranked: renderRanked,
    trends: renderTrends,
};
</script>
@endverbatim
