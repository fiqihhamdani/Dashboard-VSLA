const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const namespace = 'http://www.w3.org/2000/svg';
const makeSvg = (tag, attributes = {}, text = '') => {
    const element = document.createElementNS(namespace, tag);
    for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, value);
    element.textContent = text;
    return element;
};
const monthly = JSON.parse(document.getElementById('monthly-data')?.textContent || '[]');
for (const container of document.querySelectorAll('.trend-chart')) {
    const series = Object.entries(JSON.parse(container.dataset.series));
    const colors = ['#047857', '#e89042', '#5b65c8'];
    const legend = document.createElement('div');
    legend.className = 'mb-3 flex flex-wrap gap-4 text-xs text-slate-500';
    series.forEach(([, label], index) => {
        const item = document.createElement('span'); item.textContent = `● ${label}`; item.style.color = colors[index]; legend.append(item);
    });
    container.append(legend);
    const width = Math.max(400, container.clientWidth);
    const svg = makeSvg('svg', { viewBox: `0 0 ${width} 245`, class: 'w-full overflow-visible', role: 'group', 'aria-label': container.getAttribute('aria-label') });
    const values = monthly.flatMap(row => series.map(([key]) => row[key]));
    const min = Math.min(0, ...values); const max = Math.max(1000, ...values); const range = max - min;
    const x = i => 70 + (monthly.length === 1 ? (width - 100) / 2 : i * (width - 100) / Math.max(1, monthly.length - 1));
    const y = v => 200 - (v - min) / range * 170;
    for (let tick = 0; tick <= 4; tick++) {
        const value = min + range * tick / 4;
        svg.append(makeSvg('line', { x1: 65, x2: width - 20, y1: y(value), y2: y(value), stroke: '#e2e8f0', 'stroke-dasharray': '3 5' }));
        const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(Math.round(value));
        svg.append(makeSvg('text', { x: 55, y: y(value) + 4, 'text-anchor': 'end', fill: '#94a3b8', 'font-size': 11 }, compact));
    }
    const output = document.createElement('output'); output.className = 'block min-h-10 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600'; output.setAttribute('aria-live', 'polite'); output.textContent = 'Nilai dalam rupiah · pilih sebuah titik';
    monthly.forEach((row, i) => {
        if (i % Math.ceil(monthly.length / 6) === 0 || i === monthly.length - 1) svg.append(makeSvg('text', { x: x(i), y: 228, 'text-anchor': 'middle', fill: '#94a3b8', 'font-size': 11 }, row.label));
    });
    series.forEach(([key, label], color) => {
        const path = monthly.map((row, i) => `${i ? 'L' : 'M'}${x(i)},${y(row[key])}`).join(' ');
        svg.append(makeSvg('path', { d: path, fill: 'none', stroke: colors[color], 'stroke-width': 2.5, 'stroke-linejoin': 'round' }));
        monthly.forEach((row, i) => {
            const description = `${row.label} · ${label}: ${rupiah(row[key])}`;
            const point = makeSvg('circle', { cx: x(i), cy: y(row[key]), r: 6, fill: colors[color], stroke: 'white', 'stroke-width': 2, tabindex: 0, role: 'button', 'aria-label': description, style: 'cursor:pointer' });
            point.append(makeSvg('title', {}, description));
            const show = () => { output.textContent = description; };
            point.addEventListener('click', show); point.addEventListener('focus', show); point.addEventListener('mouseenter', show);
            point.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); show(); } });
            svg.append(point);
        });
    });
    container.append(svg, output);
}
const context = document.querySelector('[data-context-form]');
context?.querySelector('[name=group_id]')?.addEventListener('change', () => {
    const member = context.querySelector('[name=member_id]'); if (member) member.value = ''; context.requestSubmit();
});
context?.querySelector('[name=member_id]')?.addEventListener('change', () => context.requestSubmit());
const transaction = document.querySelector('[data-transaction-form]');
const rows = document.getElementById('saving-rows');
if (rows) {
    let next = Math.max(...[...rows.querySelectorAll('select')].map(el => Number(el.name.match(/\[(\d+)\]/)[1]))) + 1;
    const updateTotal = () => { document.getElementById('saving-total').textContent = rupiah([...rows.querySelectorAll('input')].reduce((sum, input) => sum + Number(input.value || 0), 0)); };
    rows.addEventListener('input', updateTotal);
    rows.addEventListener('click', event => {
        if (event.target.closest('[data-remove-row]') && rows.children.length > 1) { event.target.closest('[data-saving-row]').remove(); updateTotal(); }
    });
    document.getElementById('add-saving-row').addEventListener('click', () => {
        if (rows.children.length >= 100) return;
        const copy = rows.firstElementChild.cloneNode(true);
        for (const field of copy.querySelectorAll('input,select')) { field.name = field.name.replace(/\[\d+\]/, `[${next}]`); field.value = ''; }
        next++; rows.append(copy); copy.querySelector('select').focus(); updateTotal();
    });
    updateTotal();
}
if (transaction?.dataset.kind === 'loan') {
    const preview = document.getElementById('loan-preview');
    const update = () => {
        const principal = transaction.elements.principal.value; const rate = transaction.elements.rate.value; const months = Number(transaction.elements.months.value); const date = transaction.elements.date.value;
        if (!/^\d+$/.test(principal) || !/^\d{1,3}(\.\d{1,2})?$/.test(rate) || !Number.isInteger(months) || months < 1 || months > 120 || !date) { preview.textContent = 'Isi pokok, bunga, tenor, dan tanggal yang valid.'; return; }
        const [whole, fraction = ''] = rate.split('.'); const bps = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
        if (BigInt(principal) < 1n || BigInt(principal) > 1000000000000n || bps > 10000n) { preview.textContent = 'Periksa batas pokok pinjaman dan bunga (maksimal 100% per bulan).'; return; }
        const interest = (BigInt(principal) * bps * BigInt(months) + 5000n) / 10000n; const total = BigInt(principal) + interest; const part = total / BigInt(months);
        preview.replaceChildren();
        const heading = document.createElement('p'); heading.className = 'mb-3 font-semibold text-emerald-900'; heading.textContent = `Bunga ${rupiah(interest)} · Total kewajiban ${rupiah(total)}`; preview.append(heading);
        const list = document.createElement('ol'); list.className = 'max-h-64 space-y-2 overflow-y-auto';
        const [year, month, day] = date.split('-').map(Number);
        for (let i = 1; i <= months; i++) {
            const target = new Date(Date.UTC(year, month - 1 + i, 1)); const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate(); target.setUTCDate(Math.min(day, last));
            const due = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'UTC' }).format(target);
            const amount = i === months ? total - part * BigInt(i - 1) : part;
            const row = document.createElement('li'); row.textContent = `Cicilan ${i} · ${due} · ${rupiah(amount)}`; list.append(row);
        }
        preview.append(list);
    };
    transaction.addEventListener('input', update); update();
}
transaction?.addEventListener('submit', () => {
    const button = transaction.querySelector('[data-submit-transaction]'); button.disabled = true; button.textContent = 'Menyimpan…';
});
