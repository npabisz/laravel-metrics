@verbatim
<script>
// ─── Chart.js defaults ──────────────────────────────────────
Chart.defaults.color = '#9BA3AE';
Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.animation = false;

const charts = new Map();
const HIGHLIGHT_COLOR = PALETTE.orange;

// Crosshair synced across every visible chart, for time correlation.
let syncingCrosshair = false;
Chart.register({
    id: 'crosshairSync',
    afterEvent(chart, args) {
        const evt = args.event;
        let idx = null;
        if (evt.type === 'mousemove') {
            const hits = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, false);
            idx = hits.length ? hits[0].index : null;
        } else if (evt.type !== 'mouseout') {
            return;
        }
        if (idx === chart._crosshairIndex) return;
        chart._crosshairIndex = idx;
        chart.draw();
        if (syncingCrosshair) return;
        syncingCrosshair = true;
        charts.forEach(c => {
            if (c !== chart && c.canvas.offsetParent !== null) { c._crosshairIndex = idx; c.draw(); }
        });
        syncingCrosshair = false;
    },
    afterDatasetsDraw(chart) {
        drawPin(chart);
        const idx = chart._crosshairIndex;
        const meta = chart.getDatasetMeta(0);
        if (idx == null || !meta || !meta.data[idx]) return;
        const { top, bottom } = chart.chartArea;
        const ctx = chart.ctx;
        ctx.save();
        ctx.beginPath();
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = 'rgba(155,163,174,0.35)';
        ctx.lineWidth = 1;
        ctx.moveTo(meta.data[idx].x, top);
        ctx.lineTo(meta.data[idx].x, bottom);
        ctx.stroke();
        ctx.restore();
    },
});

// Pinned time (click a chart): a solid line plus a marker on every line series,
// kept across views and refreshes. chart.$pin is the index in this chart's data.
const PIN_COLOR = 'rgba(242,243,245,0.85)';

function drawPin(chart) {
    const idx = chart.$pin;
    const meta = chart.getDatasetMeta(0);
    if (idx == null || !meta || !meta.data[idx]) return;
    const { top, bottom } = chart.chartArea;
    const x = meta.data[idx].x;
    const ctx = chart.ctx;
    ctx.save();
    ctx.beginPath();
    ctx.strokeStyle = PIN_COLOR;
    ctx.lineWidth = 1;
    ctx.moveTo(x, top);
    ctx.lineTo(x, bottom);
    ctx.stroke();
    chart.data.datasets.forEach((ds, i) => {
        const point = chart.getDatasetMeta(i).data[idx];
        if (ds.type !== 'line' || !point || !isNum(ds.data[idx])) return;
        ctx.beginPath();
        ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = ds.borderColor;
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#1A1D21';
        ctx.stroke();
    });
    ctx.restore();
}

function pinHint(items) {
    const idx = items[0]?.dataIndex;
    if (state.pinIndex === null) return 'Click to pin this time';
    return idx === state.pinIndex ? 'Click to unpin' : 'Click to move the pin';
}

// ─── Scale helpers ──────────────────────────────────────────
function niceMax(value) {
    if (!isNum(value) || value <= 0) return 1;
    const exp = Math.pow(10, Math.floor(Math.log10(value)));
    const step = [1, 2, 2.5, 3, 4, 5, 6, 8, 10].find(s => s * exp >= value);
    return step * exp;
}

function tickIndices(count, ticks) {
    if (count <= 0) return [];
    if (count === 1 || ticks <= 1) return [0];
    const n = Math.min(ticks, count);
    return Array.from({ length: n }, (_, k) => Math.round(k * (count - 1) / (n - 1)));
}

function xLabelsHtml(times, ticks) {
    return tickIndices(times.length, ticks).map(i => `<span>${esc(times[i])}</span>`).join('');
}

// ─── Sparkline (inline SVG, no axes) ────────────────────────
function sparkSvg(values, color, pin = null) {
    const nums = values.filter(isNum);
    if (values.length < 2 || nums.length === 0) return '';
    const max = Math.max(...nums, 0);
    const min = Math.min(...nums, 0);
    const range = max - min;
    let d = '';
    let pen = 'M';
    values.forEach((v, i) => {
        if (!isNum(v)) { pen = 'M'; return; }
        const x = (i / (values.length - 1) * 100).toFixed(1);
        const y = (range > 0 ? 96 - (v - min) / range * 82 : 96).toFixed(1);
        d += `${pen}${x} ${y} `;
        pen = 'L';
    });
    const pinX = isNum(pin) && pin < values.length ? (pin / (values.length - 1) * 100).toFixed(1) : null;
    const pinLine = pinX === null ? '' : `<line class="spark-pin" x1="${pinX}" x2="${pinX}" y1="0" y2="100"></line>`;
    return `<svg class="spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${pinLine}<path d="${d.trim()}" style="stroke:${color}"></path></svg>`;
}

// ─── Chart models ───────────────────────────────────────────
function normalizeSeries(series) {
    const list = Array.isArray(series) ? series : (series ? [series] : []);
    return list.map(s => typeof s === 'string' ? { value: s } : s);
}

// Legacy 'chart' sections pick their series from key patterns at runtime.
function legacySeries(block, ctx) {
    const found = new Set();
    ctx.timeline.forEach(row => Object.keys(row).forEach(k => {
        if (k.startsWith('c:') && isNum(toNum(row[k]))) found.add(k.slice(2));
    }));
    const keys = [...found].filter(k => (block.keys || []).some(p => matchCapture(k, p) !== null));
    return keys.map((key, i) => {
        let label = block.labels?.[key] ?? (Array.isArray(block.labels) ? block.labels[i] : undefined);
        const fromKey = block.labels_from?.[key];
        if (fromKey && ctx.scope.get(fromKey)) label = ctx.scope.get(fromKey);
        let color = Array.isArray(block.colors) ? block.colors[i] : undefined;
        if (block.colors && !Array.isArray(block.colors)) {
            color = block.colors[key] ?? Object.entries(block.colors).find(([p]) => p.includes('*') && matchCapture(key, p) !== null)?.[1];
        }
        if (!color && /error|5xx|fail|timeout/i.test(key)) color = 'red';
        const rateLike = block.chart_type === 'line' || /avg|_ms|_rate|_percent/.test(key);
        return { value: key, label: label || metricLabel(key), color, type: rateLike ? 'line' : 'bar' };
    });
}

function buildChartModel(block, ctx) {
    const kind = block.type || 'bar';
    const series = block.keys ? legacySeries(block, ctx) : normalizeSeries(block.series);
    const barCount = series.filter(s => (s.type || (kind === 'line' ? 'line' : 'bar')) === 'bar').length;
    const order = barCount > 1 || kind === 'line' ? null : 'bar';
    let lineIdx = 0;
    let barIdx = 0;

    const items = series.map(s => {
        const type = s.type || (kind === 'line' ? 'line' : 'bar');
        const fallback = type === 'line' ? LINE_ORDER[lineIdx++ % LINE_ORDER.length] : (order || BAR_ORDER[barIdx++ % BAR_ORDER.length]);
        const values = seriesValues(s.value, ctx);
        const format = s.format ?? block.format ?? autoFormat(formatHint(s.value));
        return { ...s, type, values, format, color: colorOf(s.color, PALETTE[fallback]), label: s.label ?? metricLabel(s.value) };
    });

    const highlight = block.highlight ? seriesValues(block.highlight.value, ctx) : null;
    const isHot = i => highlight && isNum(highlight[i]) && testCondition(block.highlight.when ?? '> 0', highlight[i]);
    const stacked = kind === 'stacked';
    const dataMax = stacked
        ? Math.max(0, ...ctx.timeline.map((_, i) => items.reduce((sum, it) => sum + (it.values[i] || 0), 0)))
        : Math.max(0, ...items.flatMap(it => it.values.filter(isNum)));
    const max = niceMax(dataMax);
    const format = block.format ?? items[0]?.format ?? 'num';

    const datasets = items.map(it => it.type === 'line'
        ? {
            type: 'line', label: it.label, data: it.values, borderColor: it.color, backgroundColor: it.color,
            borderWidth: 1.75, pointRadius: 0, pointHoverRadius: 3, tension: 0, fill: false, spanGaps: true, order: 0, _format: it.format,
        }
        : {
            type: 'bar', label: it.label, data: it.values, order: 1, _format: it.format,
            backgroundColor: highlight ? it.values.map((_, i) => isHot(i) ? HIGHLIGHT_COLOR : it.color) : it.color,
            borderRadius: { topLeft: 2, topRight: 2 }, borderSkipped: 'bottom',
            barPercentage: barCount > 1 && !stacked ? 0.9 : 0.85, categoryPercentage: barCount > 1 && !stacked ? 0.8 : 1,
            stack: stacked ? 'stack' : undefined,
        });

    return { items, datasets, max, maxLabel: formatValue(max, format), format, stacked, highlight: !!highlight };
}

function chartOptions(block, model) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4 } },
        onClick: (evt, _active, chart) => {
            const hits = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, false);
            if (hits.length) togglePin(hits[0].index);
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#111315', borderColor: '#2B3036', borderWidth: 1,
                titleColor: '#F2F3F5', bodyColor: '#F2F3F5', padding: 10, cornerRadius: 8,
                boxWidth: 8, boxHeight: 8, usePointStyle: true,
                footerColor: '#9BA3AE', footerFont: { weight: 'normal', size: 11 }, footerMarginTop: 8,
                callbacks: {
                    footer: pinHint,
                    label: c => `${c.dataset.label}: ${formatValue(c.parsed.y, c.dataset._format)}`,
                    labelColor: c => {
                        const bg = c.dataset.backgroundColor;
                        const color = Array.isArray(bg) ? bg[c.dataIndex] : (bg || c.dataset.borderColor);
                        return { borderColor: color, backgroundColor: color };
                    },
                },
            },
        },
        scales: {
            x: { display: false, stacked: model.stacked },
            y: {
                display: !!block.axis, stacked: model.stacked, min: 0, max: model.max,
                grid: { display: false }, border: { display: true, color: '#2B3036' },
                ticks: { maxTicksLimit: 6, callback: v => formatValue(v, model.format) },
            },
        },
    };
}

function applyChart(id, canvas, block, model, labels, pin) {
    let chart = charts.get(id);
    if (chart && chart.data.datasets.length !== model.datasets.length) {
        chart.destroy();
        charts.delete(id);
        chart = null;
    }
    if (!chart) {
        chart = new Chart(canvas, { type: 'bar', data: { labels, datasets: model.datasets }, options: chartOptions(block, model) });
        charts.set(id, chart);
        chart.$pin = pin;
        chart.draw();
        return;
    }
    chart.$pin = pin;
    chart.data.labels = labels;
    model.datasets.forEach((ds, i) => Object.assign(chart.data.datasets[i], ds));
    chart.options.scales.y.max = model.max;
    chart.update('none');
}

function legendHtml(block, model, ctx) {
    // A lone series is named by the panel title; it only gets a key when it shows a value.
    const named = model.items.length > 1 || block.values ? model.items : [];
    const parts = named.map(it => {
        const swatch = `<span class="swatch${it.type === 'bar' ? ' box' : ''}" style="background:${it.color}"></span>`;
        const value = block.values ? ` <span class="value">${esc(formatValue(evaluate(it.value, ctx.scope), it.format))}</span>` : '';
        return `<span>${swatch}${esc(it.label)}${value}</span>`;
    });
    if (block.highlight) {
        parts.push(`<span><span class="swatch box" style="background:${HIGHLIGHT_COLOR}"></span>${esc(block.highlight.label || 'highlighted')}</span>`);
    }
    if (block.legend_note) parts.push(`<span>${renderTemplate(block.legend_note, ctx.scope, ctx.vars)}</span>`);
    return parts.join('');
}

function showLegend(block, model) {
    if (block.legend === false) return false;
    return model.items.length > 1 || model.highlight || !!block.legend_note || !!block.values;
}
</script>
@endverbatim
