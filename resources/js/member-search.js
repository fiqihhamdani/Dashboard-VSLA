const instances = new WeakMap();
export function initMemberSearch(root) {
    if (instances.has(root)) return instances.get(root);
    const input = root.querySelector('[data-member-input]');
    const value = root.querySelector('[data-member-value]');
    const panel = root.querySelector('[data-member-panel]');
    const results = root.querySelector('[data-member-results]');
    const status = root.querySelector('[data-member-status]');
    const more = root.querySelector('[data-member-more]');
    let timer, controller, generation = 0, page = 1, options = [], active = -1, query = '';
    const excluded = () => new Set([...root.closest('form').querySelectorAll('[data-saving-row] [data-member-value]')].filter(el => el !== value).map(el => el.value).filter(Boolean));
    const close = () => { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const positionPanel = () => { panel.style.top = `${input.offsetTop + input.offsetHeight}px`; };
    const observer = new ResizeObserver(() => { if (!panel.hidden) positionPanel(); });
    observer.observe(root);
    const invalidate = () => { clearTimeout(timer); controller?.abort(); generation++; root.removeAttribute('aria-busy'); };
    const validate = () => input.setCustomValidity(input.required && !value.value ? 'Pilih anggota dari hasil pencarian.' : '');
    const highlight = index => {
        const buttons = [...results.children];
        active = index;
        buttons.forEach((button, i) => {
            button.setAttribute('aria-selected', String(i === index));
            button.classList.toggle('bg-emerald-50', i === index);
        });
        if (buttons[index]) { input.setAttribute('aria-activedescendant', buttons[index].id); buttons[index].scrollIntoView({ block: 'nearest' }); }
    };
    const render = () => {
        results.replaceChildren(); active = -1; input.removeAttribute('aria-activedescendant');
        const unavailable = excluded();
        for (const member of options.filter(option => !unavailable.has(String(option.id)))) {
            const button = document.createElement('button');
            button.type = 'button'; button.role = 'option'; button.tabIndex = -1;
            button.id = `${results.id}-option-${member.id}`;
            button.className = 'block min-h-11 w-full rounded-lg px-3 py-3 text-left text-sm hover:bg-emerald-50';
            button.textContent = member.label; button.setAttribute('aria-selected', 'false');
            button.addEventListener('mousedown', event => event.preventDefault());
            button.addEventListener('click', () => {
                if (excluded().has(String(member.id))) { status.textContent = 'Anggota ini sudah dipilih pada baris lain.'; return; }
                invalidate(); value.value = member.id; input.value = member.label; validate(); close();
                status.textContent = 'Anggota dipilih.';
                value.dispatchEvent(new Event('change', { bubbles: true }));
                root.dispatchEvent(new CustomEvent('member-selected', { bubbles: true }));
            });
            results.append(button);
        }
        if (!results.children.length) status.textContent = 'Anggota tidak ditemukan';
        else status.textContent = `${results.children.length} hasil. Gunakan panah dan Enter untuk memilih.`;
    };
    const load = async (append = false) => {
        if (!root.dataset.group) return;
        controller?.abort(); controller = new AbortController(); const current = ++generation;
        root.setAttribute('aria-busy', 'true'); status.textContent = 'Memuat anggota…'; more.setAttribute('aria-disabled', 'true');
        panel.hidden = false; positionPanel(); input.setAttribute('aria-expanded', 'true');
        if (!append) { results.replaceChildren(); options = []; page = 1; active = -1; }
        const url = new URL(root.dataset.endpoint, location.origin);
        url.search = new URLSearchParams({ group_id: root.dataset.group, kind: root.dataset.kind, q: query, page });
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Pencarian gagal');
            const data = await response.json();
            if (current !== generation || !root.isConnected) return;
            options = append ? [...options, ...data.data] : data.data;
            render(); more.hidden = !data.has_more;
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            results.replaceChildren(); more.hidden = true; status.textContent = 'Pencarian gagal dimuat. Ketik kembali untuk mencoba.';
        } finally {
            if (current === generation) { root.removeAttribute('aria-busy'); more.removeAttribute('aria-disabled'); }
        }
    };
    input.addEventListener('input', () => {
        invalidate(); value.value = ''; validate(); query = input.value.trim(); close(); more.hidden = true; results.replaceChildren();
        status.textContent = 'Memuat anggota…'; timer = setTimeout(() => load(), 300);
    });
    input.addEventListener('focus', () => { if (root.dataset.group) { query = value.value ? '' : input.value.trim(); load(); } });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); invalidate(); close(); }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (panel.hidden) { query = value.value ? '' : input.value.trim(); load(); return; }
            const count = results.children.length;
            if (count) highlight(active < 0 ? (event.key === 'ArrowDown' ? 0 : count - 1) : (active + (event.key === 'ArrowDown' ? 1 : -1) + count) % count);
        }
        if (event.key === 'Enter' && !panel.hidden) {
            event.preventDefault(); if (active >= 0) results.children[active]?.click();
        }
    });
    root.addEventListener('focusout', event => { if (!root.contains(event.relatedTarget)) { invalidate(); close(); } });
    more.addEventListener('mousedown', event => event.preventDefault());
    more.addEventListener('click', () => { if (root.hasAttribute('aria-busy')) return; page++; load(true); });
    const api = {
        reset(group) {
            invalidate(); root.dataset.group = group || ''; value.value = ''; input.value = ''; options = []; close(); validate();
            input.disabled = !group; input.placeholder = group ? 'Ketik sebagian nama anggota…' : 'Pilih kelompok terlebih dahulu';
            status.textContent = group ? 'Cari nama, lalu pilih anggota dari hasil pencarian.' : 'Pilih kelompok terlebih dahulu';
        },
        destroy() { invalidate(); observer.disconnect(); },
    };
    validate(); instances.set(root, api); return api;
}
for (const root of document.querySelectorAll('[data-member-search]')) initMemberSearch(root);
