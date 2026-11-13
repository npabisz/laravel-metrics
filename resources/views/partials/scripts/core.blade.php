@verbatim
<script>
// ─── Palette ────────────────────────────────────────────────
// Series colours follow the entity (fixed per series in config), never rank.
const PALETTE = {
    lime: '#C6F24E', white: '#F2F3F5', blue: '#7FB2FF', gray: '#7C8794', orange: '#FF9A4D',
    red: '#FF6B6B', purple: '#C9A6FF', cyan: '#6FD6E8', pink: '#FF8FC7', yellow: '#F2D24E',
    green: '#C6F24E', bar: '#4A5560',
};
const LINE_ORDER = ['lime', 'white', 'blue', 'gray', 'orange', 'purple', 'cyan', 'pink'];
const BAR_ORDER = ['bar', 'lime', 'white', 'blue', 'gray', 'orange', 'purple', 'cyan'];
const STATUS_COLOR = { ok: PALETTE.lime, warn: PALETTE.orange, error: PALETTE.red };
const STATUS_RANK = { ok: 1, warn: 2, error: 3 };
// Legacy 'value_colors': status colours map to states; the rest stay plain hues.
const LEGACY_STATUS = { green: 'ok', yellow: 'warn', orange: 'warn', red: 'error', blue: 'hue-blue', purple: 'hue-purple', cyan: 'hue-cyan', pink: 'hue-pink' };

function colorOf(name, fallback) {
    if (!name) return fallback;
    return PALETTE[name] || (/^#[0-9a-f]{3,8}$/i.test(name) ? name : fallback);
}

// ─── HTML helpers ───────────────────────────────────────────
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function isNum(v) { return typeof v === 'number' && Number.isFinite(v); }

function toNum(v) {
    if (isNum(v)) return v;
    if (typeof v === 'string' && v.trim() !== '' && !isNaN(v)) return parseFloat(v);
    return null;
}

// ─── Number formatting ──────────────────────────────────────
const EMPTY = '–';

function trimNum(v, decimals) { return String(parseFloat(v.toFixed(decimals))); }

function compact(v) {
    const abs = Math.abs(v);
    if (abs >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (abs >= 1e3) return (v / 1e3).toFixed(1) + 'K';
    return trimNum(v, 2);
}

const FORMATS = {
    num: compact,
    int: v => Math.round(v).toLocaleString('en-US'),
    dec: v => trimNum(v, 2),
    ms: v => Math.abs(v) >= 1000 ? (v / 1000).toFixed(1) + ' s' : trimNum(v, 1) + ' ms',
    pct: v => trimNum(v, 1) + '%',
    ratio: v => trimNum(v * 100, 1) + '%',
    mb: v => trimNum(v, Math.abs(v) < 100 ? 2 : 1) + ' MB',
    gb: v => trimNum(v, 2) + ' GB',
    mb_as_gb: v => (v / 1024).toFixed(1) + ' GB',
    mbps: v => trimNum(v, 2) + ' Mbps',
};

const BUILTIN_FORMATS = {
    http_avg_duration: 'ms', http_max_duration: 'ms', db_avg_query_ms: 'ms', db_max_query_ms: 'ms',
    error_rate: 'pct', redis_hit_rate: 'pct', redis_memory_mb: 'mb', memory_mb: 'mb',
    disk_free_gb: 'gb', cpu_load: 'dec',
};

// Formats declared on legacy sections ('keys' + 'format') also apply to cards.
let legacyFormats = [];

function autoFormat(key) {
    if (!key) return 'num';
    if (BUILTIN_FORMATS[key]) return BUILTIN_FORMATS[key];
    const legacy = legacyFormats.find(f => f.keys.some(p => matchCapture(key, p) !== null));
    if (legacy) return legacy.format;
    if (/_ms$|_ms_|avg_ms|max_ms|_p95_|_p99_/.test(key)) return 'ms';
    if (/_percent$/.test(key)) return 'pct';
    if (/_rate$/.test(key)) return 'ratio';
    if (/mbps/.test(key)) return 'mbps';
    if (/_mb$/.test(key)) return 'mb';
    if (/_gb$/.test(key)) return 'gb';
    return 'num';
}

function formatValue(value, format, keyHint) {
    if (value === null || value === undefined || (typeof value === 'number' && !Number.isFinite(value))) return EMPTY;
    if (typeof value === 'string') {
        if (format === 'short') return value.split('.')[0];
        const n = toNum(value);
        if (n === null) return value;
        value = n;
    }
    const fmt = format ?? autoFormat(keyHint);
    if (fmt && typeof fmt === 'object') {
        const v = value * (fmt.multiply ?? 1);
        const body = fmt.decimals !== undefined ? v.toFixed(fmt.decimals) : trimNum(v, 2);
        return (fmt.prefix ?? '') + body + (fmt.suffix ?? '');
    }
    return (FORMATS[fmt] || FORMATS.num)(value);
}

function metricLabel(key) {
    const abbr = { cpu: 'CPU', api: 'API', avg: 'Avg', max: 'Max', min: 'Min', p95: 'P95', p99: 'P99', db: 'DB', http: 'HTTP', tcp: 'TCP', ip: 'IP', id: 'ID', url: 'URL', rx: 'RX', tx: 'TX', ops: 'Ops' };
    const label = String(key)
        .replace(/_(percent|ms|mb|gb|mbps)$/, '')
        .replace(/_/g, ' ').trim()
        .replace(/\b\w+/g, w => abbr[w.toLowerCase()] || w);
    return label.charAt(0).toUpperCase() + label.slice(1);
}

// ─── Wildcard patterns ──────────────────────────────────────
const patternCache = new Map();

// Returns the text matched by the first '*' ('' when there is none), or null.
function matchCapture(key, pattern) {
    let re = patternCache.get(pattern);
    if (!re) {
        const source = String(pattern).split('*').map(p => p.replace(/[.+?^${}()|[\]\\-]/g, '\\$&')).join('(.*?)');
        re = new RegExp('^' + source + '$');
        patternCache.set(pattern, re);
    }
    const m = re.exec(key);
    return m ? (m[1] ?? '') : null;
}

function displayName(capture) {
    const names = Settings.names;
    const name = names && !Array.isArray(names) && Object.prototype.hasOwnProperty.call(names, capture) ? names[capture] : null;
    return typeof name === 'string' && name !== '' ? name : capture;
}

// ─── Expressions ────────────────────────────────────────────
// Grammar: + - * / ( ), numbers, metric keys (may contain inner '-', so put
// spaces around a minus), and functions over '*' patterns:
// sum() avg() min() max() count() argmax() breakdown().
// A value that is a single word (no spaces, operators or parentheses) is
// looked up as a key directly, so any key name works there.
const exprCache = new Map();
const TOKEN = /\s*(?:(sum|avg|min|max|count|argmax|breakdown)\(\s*([^()\s]+)\s*\)|(\d+(?:\.\d+)?)|([A-Za-z_]\w*(?:-\w+)*)|(\S))/y;
const PLAIN_KEY = /^[^\s()+*\/]+$/;
const NUMBER = /^-?\d+(?:\.\d+)?$/;

function tokenize(src) {
    const tokens = [];
    TOKEN.lastIndex = 0;
    let m;
    while (TOKEN.lastIndex < src.length && (m = TOKEN.exec(src))) {
        if (m[1] !== undefined) tokens.push({ t: 'fn', v: m[1], pattern: m[2] });
        else if (m[3] !== undefined) tokens.push({ t: 'num', v: parseFloat(m[3]) });
        else if (m[4] !== undefined) tokens.push({ t: 'id', v: m[4] });
        else if (m[5] !== undefined) tokens.push({ t: 'op', v: m[5] });
        if (src.slice(TOKEN.lastIndex).trim() === '') break;
    }
    return tokens;
}

function parseExpr(src) {
    const tokens = tokenize(src);
    let i = 0;
    const peek = () => tokens[i];
    const take = (v) => {
        if (!tokens[i] || (v && tokens[i].v !== v)) throw new Error(`Expected ${v || 'token'} in "${src}"`);
        return tokens[i++];
    };
    function expr() {
        let node = term();
        while (peek() && (peek().v === '+' || peek().v === '-')) node = { t: 'bin', op: take().v, a: node, b: term() };
        return node;
    }
    function term() {
        let node = factor();
        while (peek() && (peek().v === '*' || peek().v === '/')) node = { t: 'bin', op: take().v, a: node, b: factor() };
        return node;
    }
    function factor() {
        const tok = take();
        if (tok.t === 'num') return { t: 'num', v: tok.v };
        if (tok.v === '-') return { t: 'neg', a: factor() };
        if (tok.v === '(') { const node = expr(); take(')'); return node; }
        if (tok.t === 'fn') return { t: 'fn', name: tok.v, pattern: tok.pattern };
        if (tok.t === 'id') return { t: 'id', name: tok.v };
        throw new Error(`Unexpected "${tok.v}" in "${src}"`);
    }
    const node = expr();
    if (i < tokens.length) throw new Error(`Unexpected "${tokens[i].v}" in "${src}"`);
    return node;
}

function compileExpr(src) {
    const key = String(src);
    if (!exprCache.has(key)) {
        try {
            exprCache.set(key, parseExpr(key));
        } catch (e) {
            console.warn('[metrics] ' + e.message);
            exprCache.set(key, { t: 'num', v: NaN });
        }
    }
    return exprCache.get(key);
}

// First metric key or pattern in an expression, used to pick a default format.
function formatHint(src) {
    if (typeof src === 'string' && PLAIN_KEY.test(src)) return src;
    const node = compileExpr(src);
    if (node.t === 'id') return node.name;
    if (node.t === 'fn' && ['sum', 'avg', 'min', 'max', 'breakdown'].includes(node.name)) return node.pattern;
    return null;
}

function matchedValues(scope, pattern) {
    const out = [];
    for (const key of scope.keys()) {
        const capture = matchCapture(key, pattern);
        if (capture === null) continue;
        const value = toNum(scope.get(key));
        if (value !== null) out.push({ key, capture, value });
    }
    return out;
}

function evalNode(node, scope) {
    switch (node.t) {
        case 'num': return node.v;
        case 'id': return scope.get(node.name);
        case 'neg': { const a = toNum(evalNode(node.a, scope)); return a === null ? null : -a; }
        case 'bin': {
            const a = toNum(evalNode(node.a, scope));
            const b = toNum(evalNode(node.b, scope));
            if (a === null || b === null) return null;
            if (node.op === '+') return a + b;
            if (node.op === '-') return a - b;
            if (node.op === '*') return a * b;
            return b === 0 ? (a === 0 ? 0 : null) : a / b;
        }
        case 'fn': return evalFunction(node, scope);
    }
    return null;
}

function evalFunction(node, scope) {
    const items = matchedValues(scope, node.pattern);
    const values = items.map(x => x.value);
    switch (node.name) {
        case 'sum': return values.reduce((s, v) => s + v, 0);
        case 'count': return values.length;
        case 'avg': return values.length ? values.reduce((s, v) => s + v, 0) / values.length : null;
        case 'min': return values.length ? Math.min(...values) : null;
        case 'max': return values.length ? Math.max(...values) : null;
        case 'argmax': {
            if (!items.length) return null;
            const top = items.reduce((best, x) => x.value > best.value ? x : best);
            return displayName(top.capture);
        }
        case 'breakdown': {
            const fmt = autoFormat(node.pattern);
            const parts = items.filter(x => x.value !== 0).sort((a, b) => b.value - a.value)
                .map(x => `${displayName(x.capture)} ${formatValue(x.value, fmt)}`);
            return parts.length ? parts.join(' · ') : 'none';
        }
    }
    return null;
}

function evaluate(src, scope) {
    if (src === null || src === undefined || src === '') return null;
    if (typeof src === 'number') return src;
    const text = String(src).trim();
    if (NUMBER.test(text)) return parseFloat(text);
    if (PLAIN_KEY.test(text)) return scope.get(text);
    return evalNode(compileExpr(text), scope);
}

// ─── Templates ──────────────────────────────────────────────
// "{expr}" or "{expr|format}"; {period} and {max} are page variables.
// Returns escaped HTML.
function renderTemplate(template, scope, vars = {}) {
    if (template === null || template === undefined) return '';
    return String(template).split(/(\{[^{}]+\})/).map(part => {
        if (!/^\{[^{}]+\}$/.test(part)) return esc(part);
        const body = part.slice(1, -1);
        const pipe = body.lastIndexOf('|');
        const src = (pipe >= 0 ? body.slice(0, pipe) : body).trim();
        const fmt = pipe >= 0 ? body.slice(pipe + 1).trim() : undefined;
        if (Object.prototype.hasOwnProperty.call(vars, src)) return esc(vars[src]);
        return esc(formatValue(evaluate(src, scope), fmt, formatHint(src)));
    }).join('');
}

// In rows built from a '*' pattern, '$' stands for the matched part: the raw
// key fragment inside {expressions}, the display name in plain text.
function bindRowTemplate(template, row) {
    if (typeof template !== 'string' || !row) return template;
    return template.split(/(\{[^{}]+\})/).map(part =>
        /^\{[^{}]+\}$/.test(part) ? part.split('$').join(row.capture) : part.split('$').join(row.name)
    ).join('');
}

function bindRowExpr(src, row) {
    return typeof src === 'string' && row ? src.split('$').join(row.capture) : src;
}

// ─── Status rules ───────────────────────────────────────────
// { warn: '> 0', error: '>= 50', value: 'other_metric', ok: false }
function testCondition(condition, value) {
    const m = /^\s*(<=|>=|==|!=|<|>)\s*(-?\d+(?:\.\d+)?)\s*$/.exec(String(condition));
    if (!m) return false;
    const t = parseFloat(m[2]);
    switch (m[1]) {
        case '<': return value < t;
        case '<=': return value <= t;
        case '>': return value > t;
        case '>=': return value >= t;
        case '==': return value === t;
        case '!=': return value !== t;
    }
    return false;
}

// Original 'value_colors' semantics: the first hit across all matching rules,
// else red for *error* keys above 0, amber for *rate_limit*, and string states.
function legacyStatus(rule, raw) {
    if (typeof raw === 'string' && toNum(raw) === null) {
        if (raw === 'running') return 'ok';
        return raw === 'inactive' || raw === 'unknown' ? 'error' : null;
    }
    const value = toNum(raw);
    if (value === null) return null;
    const hit = rule.legacy.find(c => testCondition(`${c.op || '>'} ${c.value}`, value));
    if (hit) return LEGACY_STATUS[hit.color] || null;
    const key = rule.legacyKey || '';
    if (key.includes('error') && value > 0) return 'error';
    if (key.includes('rate_limit') && value > 0) return 'warn';
    return null;
}

function evalStatus(rule, scope, ownValue, row) {
    if (!rule) return null;
    if (Array.isArray(rule.legacy)) return legacyStatus(rule, ownValue);
    const value = toNum(rule.value !== undefined ? evaluate(bindRowExpr(rule.value, row), scope) : ownValue);
    if (value === null) return null;
    if (rule.error !== undefined && testCondition(rule.error, value)) return 'error';
    if (rule.warn !== undefined && testCondition(rule.warn, value)) return 'warn';
    return rule.ok === false ? null : 'ok';
}

function worstStatus(statuses) {
    return statuses.reduce((worst, s) => (STATUS_RANK[s] || 0) > (STATUS_RANK[worst] || 0) ? s : worst, null);
}

// ─── Data scopes ────────────────────────────────────────────
// A scope resolves metric keys: built-in summary fields first, then custom.
const SUMMARY_COMPUTED = {
    queue_depth: s => (s.queue_depth_high ?? 0) + (s.queue_depth_default ?? 0) + (s.queue_depth_low ?? 0),
};
const ROW_FIELDS = {
    http_requests_total: 'requests', http_avg_duration: 'avg_duration', http_max_duration: 'max_duration',
    http_requests_5xx: 'errors_5xx', http_slow_requests: 'http_slow', queue_depth_high: 'queue_high',
    queue_depth_default: 'queue_default', queue_depth_low: 'queue_low', db_queries_total: 'db_queries',
    db_slow_queries: 'db_slow', cpu_load: 'cpu', redis_memory_mb: 'redis_memory', redis_ops_per_sec: 'redis_ops',
};
const ROW_COMPUTED = {
    queue_depth: r => (r.queue_high ?? 0) + (r.queue_default ?? 0) + (r.queue_low ?? 0),
    error_rate: r => r.requests > 0 ? Math.round(r.errors_5xx / r.requests * 10000) / 100 : 0,
};

function summaryScope(summary) {
    const custom = summary.custom || {};
    const keys = Object.keys(custom);
    return {
        get(name) {
            if (SUMMARY_COMPUTED[name]) return SUMMARY_COMPUTED[name](summary);
            if (name !== 'custom' && summary[name] !== undefined) return summary[name];
            return custom[name] ?? null;
        },
        keys: () => keys,
    };
}

function rowScope(row) {
    let keys = null;
    return {
        get(name) {
            if (row['c:' + name] !== undefined) return row['c:' + name];
            if (ROW_FIELDS[name]) return row[ROW_FIELDS[name]] ?? null;
            if (ROW_COMPUTED[name]) return ROW_COMPUTED[name](row);
            return null;
        },
        keys() {
            if (!keys) keys = Object.keys(row).filter(k => k.startsWith('c:')).map(k => k.slice(2));
            return keys;
        },
    };
}

function seriesValues(src, ctx) {
    return ctx.rows.map(scope => toNum(evaluate(src, scope)));
}
</script>
@endverbatim
