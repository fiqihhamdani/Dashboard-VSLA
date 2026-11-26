import './admin-transactions';
import './deposit-details';
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
    const colors = container.dataset.chartId === 'balance' ? ['#14b8a6'] : ['#6366f1', '#f59e0b', '#14b8a6'];
    const hidden = new Set();
    let selected = Math.max(0, monthly.length - 1);
    let crosshair;
    const legend = document.createElement('div'); legend.className = 'chart-legend';
    const svg = makeSvg('svg', { class: 'block w-full overflow-visible', role: 'group', 'aria-label': container.getAttribute('aria-label'), 'font-family': 'inherit' });
    const output = document.createElement('output'); output.className = 'chart-tooltip'; output.setAttribute('aria-live', 'polite');
    let width = 600;
    const x = i => 58 + (monthly.length === 1 ? (width - 80) / 2 : i * (width - 80) / Math.max(1, monthly.length - 1));
    const showMonth = index => {
        selected = index;
        output.replaceChildren();
        if (crosshair) { crosshair.setAttribute('x1', x(index)); crosshair.setAttribute('x2', x(index)); }
        const row = monthly[index];
        if (!row) { output.textContent = 'Belum ada data bulanan.'; return; }
        const month = document.createElement('strong'); month.textContent = row.label; output.append(month);
        series.forEach(([key, label], color) => {
            if (hidden.has(key)) return;
            const item = document.createElement('span'); item.className = 'inline-flex items-center gap-1.5';
            const dot = document.createElement('span'); dot.className = 'inline-block size-1.5 rounded-full'; dot.style.background = colors[color];
            const text = document.createElement('span'); text.textContent = `${label}: ${rupiah(row[key])}`;
            item.append(dot, text); output.append(item);
        });
        if (hidden.size === series.length) { output.append(document.createTextNode('Aktifkan garis melalui legenda di atas.')); }
    };
    const draw = () => {
        if (!container.clientWidth) return;
        width = Math.max(340, container.clientWidth);
        svg.setAttribute('viewBox', `0 0 ${width} 245`); svg.replaceChildren();
        const values = monthly.flatMap(row => series.filter(([key]) => !hidden.has(key)).map(([key]) => row[key]));
        const low = Math.min(0, ...values); const high = Math.max(1000, ...values);
        const rough = (high - low) / 4; const magnitude = 10 ** Math.floor(Math.log10(rough)); const step = Math.ceil(rough / magnitude) * magnitude;
        const min = Math.floor(low / step) * step; const max = Math.ceil(high / step) * step;
        const y = value => 198 - (value - min) / (max - min) * 172;
        for (let value = min; value <= max; value += step) {
            svg.append(makeSvg('line', { x1: 52, x2: width - 15, y1: y(value), y2: y(value), stroke: '#edf0f6', 'stroke-dasharray': '3 5' }));
            const label = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
            svg.append(makeSvg('text', { x: 43, y: y(value) + 3, 'text-anchor': 'end', fill: '#64748b', 'font-size': 10 }, label));
        }
        monthly.forEach((row, i) => {
            if ((i % Math.ceil(monthly.length / Math.max(2, Math.floor(width / 95))) === 0 && (i === 0 || x(monthly.length - 1) - x(i) > 65)) || i === monthly.length - 1) svg.append(makeSvg('text', { x: x(i), y: 223, 'text-anchor': 'middle', fill: '#64748b', 'font-size': 10 }, row.label));
        });
        crosshair = makeSvg('line', { x1: x(selected), x2: x(selected), y1: 22, y2: 201, stroke: '#c7d2fe', 'stroke-dasharray': '4 4', 'stroke-width': 1 }); svg.append(crosshair);
        series.forEach(([key, label], color) => {
            if (hidden.has(key)) return;
            const path = monthly.map((row, i) => `${i ? 'L' : 'M'}${x(i)},${y(row[key])}`).join(' ');
            if (series.length === 1 && monthly.length) {
                const id = `gradient-${container.dataset.chartId}`; const defs = makeSvg('defs'); const gradient = makeSvg('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1 });
                gradient.append(makeSvg('stop', { offset: '0%', 'stop-color': colors[color], 'stop-opacity': .16 }), makeSvg('stop', { offset: '100%', 'stop-color': colors[color], 'stop-opacity': 0 })); defs.append(gradient); svg.append(defs);
                svg.append(makeSvg('path', { d: `${path} L${x(monthly.length - 1)},${y(min)} L${x(0)},${y(min)} Z`, fill: `url(#${id})` }));
            }
            svg.append(makeSvg('path', { d: path, fill: 'none', stroke: colors[color], 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'data-series-path': key }));
            monthly.forEach((row, i) => {
                const description = `${row.label} · ${label}: ${rupiah(row[key])}`;
                const point = makeSvg('circle', { cx: x(i), cy: y(row[key]), r: 4, fill: 'white', stroke: colors[color], 'stroke-width': 2, tabindex: 0, role: 'button', 'aria-label': description, style: 'cursor:pointer' });
                point.append(makeSvg('title', {}, description));
                point.addEventListener('click', () => showMonth(i)); point.addEventListener('focus', () => showMonth(i)); point.addEventListener('mouseenter', () => showMonth(i));
                point.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); showMonth(i); } }); svg.append(point);
            });
        });
        showMonth(selected);
    };
    series.forEach(([key, label], index) => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'chart-legend-button'; button.setAttribute('aria-pressed', 'true'); button.setAttribute('aria-label', `${label}: tampilkan atau sembunyikan garis`);
        const dot = document.createElement('span'); dot.className = 'size-2 rounded-full'; dot.style.background = colors[index]; button.append(dot, document.createTextNode(label));
        button.addEventListener('click', () => { if (hidden.has(key)) hidden.delete(key); else hidden.add(key); button.setAttribute('aria-pressed', String(!hidden.has(key))); draw(); }); legend.append(button);
    });
    svg.addEventListener('mousemove', event => {
        if (monthly.length < 2) return;
        const bounds = svg.getBoundingClientRect(); const local = (event.clientX - bounds.left) / bounds.width * width;
        showMonth(Math.max(0, Math.min(monthly.length - 1, Math.round((local - 58) / (width - 80) * (monthly.length - 1)))));
    });
    container.append(legend, svg, output); draw();
    const observer = new ResizeObserver(() => draw()); observer.observe(container);
    const card = container.closest('[data-chart-card]');
    for (const button of card.querySelectorAll('[data-chart-view]')) {
        button.addEventListener('click', () => {
            const isTable = button.dataset.chartView === 'table'; container.hidden = isTable; card.querySelector('[data-chart-table]').hidden = !isTable;
            card.querySelectorAll('[data-chart-view]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
            card.querySelector('.chart-help').hidden = isTable; if (!isTable) draw();
        });
    }
}
for (const container of document.querySelectorAll('[data-sparkline]')) {
    const values = monthly.map(row => row[container.dataset.sparkline]); if (!values.length) continue;
    const min = Math.min(...values); const max = Math.max(...values); const svg = makeSvg('svg', { width: 82, height: 32, viewBox: '0 0 82 32' });
    const points = values.map((value, i) => `${i ? 'L' : 'M'}${2 + i * 78 / Math.max(1, values.length - 1)},${max === min ? 16 : 28 - (value - min) / (max - min) * 24}`).join(' ');
    svg.append(makeSvg('path', { d: points, fill: 'none', stroke: container.dataset.featured === 'true' ? '#c7d2fe' : '#a5b4fc', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })); container.append(svg);
}
const transaction = document.querySelector('[data-transaction-form]');
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

const bulkMembers = document.querySelector('[data-bulk-members]');
if (bulkMembers) {
    const memberRows = bulkMembers.querySelector('[data-member-rows]');
    const addButton = bulkMembers.querySelector('[data-add-member]');
    const saveButton = bulkMembers.querySelector('[data-save-members]');
    const feedback = bulkMembers.querySelector('[data-paste-feedback]');
    const template = memberRows.firstElementChild.cloneNode(true);
    const refresh = () => {
        [...memberRows.children].forEach((row, index) => {
            const field = row.querySelector('input');
            field.name = `members[${index}][name]`; field.id = `member_name_${index}`;
            const label = row.querySelector('[data-member-label]'); label.htmlFor = field.id; label.textContent = `Nama anggota ${index + 1}`;
            const remove = row.querySelector('[data-remove-member]'); remove.disabled = memberRows.children.length === 1; remove.setAttribute('aria-label', `Hapus anggota baris ${index + 1}`);
        });
        bulkMembers.querySelector('[data-member-count]').textContent = `${memberRows.children.length} / 100 baris`;
        addButton.disabled = memberRows.children.length >= 100;
    };
    const appendName = (name = '') => {
        const row = template.cloneNode(true); const field = row.querySelector('input'); field.value = name;
        field.removeAttribute('aria-invalid'); field.removeAttribute('aria-describedby'); row.querySelectorAll('[id^=member_error_]').forEach(error => error.remove());
        memberRows.append(row); return field;
    };
    addButton.addEventListener('click', () => {
        if (memberRows.children.length >= 100) return;
        const field = appendName(); refresh(); field.focus();
    });
    memberRows.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-member]');
        if (!button || memberRows.children.length <= 1) return;
        button.closest('[data-member-row]').remove();
        memberRows.querySelectorAll('[id^=member_error_]').forEach(error => error.remove());
        memberRows.querySelectorAll('input').forEach(field => { field.removeAttribute('aria-invalid'); field.removeAttribute('aria-describedby'); });
        refresh();
    });
    bulkMembers.querySelector('[data-import-names]').addEventListener('click', () => {
        const textarea = bulkMembers.querySelector('#pasted_names');
        const names = textarea.value.split(/\r\n|\n|\r/).map(name => name.trim()).filter(Boolean);
        if (!names.length) { feedback.textContent = 'Tempel setidaknya satu nama terlebih dahulu.'; return; }
        if (names.some(name => name.includes('\t'))) { feedback.textContent = 'Salin satu kolom nama saja dari Excel, tanpa kolom tambahan.'; return; }
        const occupied = [...memberRows.querySelectorAll('input')].filter(field => field.value.trim()).length;
        if (occupied + names.length > 100) { feedback.textContent = 'Jumlah daftar melebihi 100 anggota. Bagi daftar menjadi beberapa penyimpanan; baris yang sudah diisi tetap tersedia.'; return; }
        if (names.some(name => [...name].length > 255)) { feedback.textContent = 'Ada nama yang melebihi 255 karakter. Perbaiki daftar terlebih dahulu.'; return; }
        [...memberRows.children].filter(row => !row.querySelector('input').value.trim()).forEach(row => row.remove());
        names.forEach(name => appendName(name)); refresh(); textarea.value = ''; feedback.textContent = `${names.length} nama ditambahkan. Periksa daftar di bawah, lalu simpan semua anggota.`;
    });
    bulkMembers.addEventListener('submit', () => { saveButton.disabled = true; saveButton.textContent = 'Menyimpan anggota…'; });
    window.addEventListener('pageshow', () => { saveButton.disabled = false; saveButton.textContent = 'Simpan semua anggota'; });
    refresh();
}

// Navigation and shortcuts.
const sidebar = document.getElementById('admin-navigation');
const sidebarToggle = document.querySelector('[data-toggle-sidebar]');
const scrim = document.querySelector('.sidebar-scrim');
const mobileNavigation = window.matchMedia('(max-width: 1023px)');
let sidebarReturnFocus;
const syncSidebar = () => {
    if (!sidebar) return;
    const open = mobileNavigation.matches && document.body.classList.contains('sidebar-open');
    scrim.hidden = !open; sidebarToggle.setAttribute('aria-expanded', String(open));
    sidebar.inert = mobileNavigation.matches && !open; sidebar.setAttribute('aria-hidden', String(mobileNavigation.matches && !open));
};
const closeSidebar = () => { document.body.classList.remove('sidebar-open'); syncSidebar(); sidebarReturnFocus?.focus(); };
if (sidebar) {
    try { document.body.classList.toggle('sidebar-compact', localStorage.getItem('vsla-sidebar-compact') === 'true'); } catch {}
    sidebarToggle.addEventListener('click', () => { sidebarReturnFocus = document.activeElement; document.body.classList.add('sidebar-open'); syncSidebar(); sidebar.querySelector('a')?.focus(); });
    document.querySelector('[data-close-sidebar]').addEventListener('click', closeSidebar);
    const collapse = document.querySelector('[data-sidebar-collapse]');
    const syncCollapse = () => { const compact = document.body.classList.contains('sidebar-compact'); collapse.setAttribute('aria-expanded', String(!compact)); collapse.setAttribute('aria-label', compact ? 'Perluas navigasi' : 'Ringkas navigasi'); collapse.title = compact ? 'Perluas navigasi' : 'Ringkas navigasi'; };
    collapse.addEventListener('click', () => { document.body.classList.toggle('sidebar-compact'); syncCollapse(); try { localStorage.setItem('vsla-sidebar-compact', String(document.body.classList.contains('sidebar-compact'))); } catch {} });
    mobileNavigation.addEventListener('change', () => { document.body.classList.remove('sidebar-open'); syncSidebar(); });
    document.addEventListener('keydown', event => {
        if (!document.body.classList.contains('sidebar-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); closeSidebar(); }
        if (event.key === 'Tab') {
            const focusable = [...sidebar.querySelectorAll('a,button')].filter(element => element.getClientRects().length && !element.disabled);
            const first = focusable[0]; const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }); syncCollapse(); syncSidebar();
}
const menuDialog = document.querySelector('[data-quick-menu]');
if (menuDialog) {
    const trigger = document.querySelector('[data-open-quick-menu]'); const search = menuDialog.querySelector('input'); const results = menuDialog.querySelector('[data-menu-results]');
    const links = [...document.querySelectorAll('.sidebar-nav a,.sidebar-bottom a')].map(link => {
        const item = document.createElement('a'); item.href = link.href; item.className = 'quick-menu-link'; item.append(link.querySelector('svg').cloneNode(true), document.createTextNode(link.querySelector('.sidebar-copy').textContent)); results.append(item); return item;
    });
    const filterMenu = () => { const query = search.value.trim().toLocaleLowerCase('id'); links.forEach(link => { link.hidden = !link.textContent.toLocaleLowerCase('id').includes(query); }); menuDialog.querySelector('[data-menu-empty]').hidden = links.some(link => !link.hidden); };
    const openMenu = () => { if (menuDialog.open) return; search.value = ''; filterMenu(); menuDialog.showModal(); search.focus(); };
    trigger.addEventListener('click', openMenu); menuDialog.querySelector('[data-close-quick-menu]').addEventListener('click', () => menuDialog.close()); search.addEventListener('input', filterMenu);
    menuDialog.addEventListener('click', event => { if (event.target === menuDialog) { const r = menuDialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) menuDialog.close(); } });
    search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); links.find(link => !link.hidden)?.click(); } if (event.key === 'ArrowDown') { event.preventDefault(); links.find(link => !link.hidden)?.focus(); } });
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); if (menuDialog.open) menuDialog.close(); else openMenu(); } });
}
for (const button of document.querySelectorAll('[data-dismiss-notice]')) button.addEventListener('click', () => button.closest('.notice').remove());
const passwordToggle = document.querySelector('[data-toggle-password]');
passwordToggle?.addEventListener('click', () => { const input = document.getElementById('password'); const visible = input.type === 'password'; input.type = visible ? 'text' : 'password'; passwordToggle.setAttribute('aria-pressed', String(visible)); passwordToggle.setAttribute('aria-label', visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'); });
for (const form of document.querySelectorAll('[data-report-filter]')) {
    const today = form.dataset.today; const [year, month] = today.split('-').map(Number); const start = form.querySelector('[name=start]'); const end = form.querySelector('[name=end]');
    const dateText = date => date.toISOString().slice(0, 10);
    const starts = { month: `${year}-${String(month).padStart(2, '0')}-01`, quarter: dateText(new Date(Date.UTC(year, month - 3, 1))), year: `${year}-01-01` };
    const buttons = [...form.querySelectorAll('[data-period]')];
    const updatePresets = () => buttons.forEach(button => button.setAttribute('aria-pressed', String(start.value === starts[button.dataset.period] && end.value === today)));
    buttons.forEach(button => button.addEventListener('click', () => { start.value = starts[button.dataset.period]; end.value = today; updatePresets(); form.requestSubmit(); }));
    form.addEventListener('change', updatePresets);
    form.addEventListener('submit', () => { const button = form.querySelector('[data-apply-filter]'); button.disabled = true; button.querySelector('span').textContent = 'Memuat laporan…'; });
    window.addEventListener('pageshow', () => { const button = form.querySelector('[data-apply-filter]'); button.disabled = false; button.querySelector('span').textContent = 'Terapkan filter'; }); updatePresets();
}

import './admin-management';
