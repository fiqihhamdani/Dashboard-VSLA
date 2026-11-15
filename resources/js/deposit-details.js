const dialog = document.querySelector('[data-deposit-dialog]');
if (dialog) {
    const money = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
    const status = dialog.querySelector('[data-deposit-status]');
    const content = dialog.querySelector('[data-deposit-content]');
    const retry = dialog.querySelector('[data-retry-deposit]');
    let controller, generation = 0, url, trigger;
    const load = async () => {
        controller?.abort(); controller = new AbortController(); const current = ++generation;
        status.textContent = 'Memuat detail setoran…'; content.hidden = true; retry.hidden = true;
        dialog.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Detail gagal');
            const data = await response.json();
            if (current !== generation || !dialog.open) return;
            if (!data.rows.length) { status.textContent = 'Tidak ada rincian setoran untuk transaksi ini.'; return; }
            status.textContent = '';
            dialog.querySelector('[data-deposit-summary]').textContent = `Transaksi #${data.id} · ${data.date} · ${data.group} · ${data.direction}`;
            dialog.querySelector('[data-deposit-description]').textContent = data.description;
            const rows = dialog.querySelector('[data-deposit-rows]'); rows.replaceChildren();
            for (const row of data.rows) {
                const tr = document.createElement('tr');
                for (const value of [`${row.member} · #${row.member_id}`, row.type, money(row.amount)]) {
                    const td = document.createElement('td'); td.textContent = value; tr.append(td);
                }
                rows.append(tr);
            }
            dialog.querySelector('[data-deposit-count]').textContent = `${data.member_count} orang`;
            dialog.querySelector('[data-deposit-total]').textContent = money(data.total);
            dialog.querySelector('[data-deposit-cash]').textContent = money(data.cash_total);
            dialog.querySelector('[data-deposit-match]').textContent = data.matches ? 'Cocok' : `Tidak cocok · selisih ${money(data.cash_total - data.total)}`;
            content.hidden = false;
        } catch (error) {
            if (current === generation && error.name !== 'AbortError') { status.textContent = 'Detail setoran gagal dimuat. Silakan coba lagi.'; retry.hidden = false; }
        } finally { if (current === generation) dialog.removeAttribute('aria-busy'); }
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-deposit-url]');
        if (!button) return;
        trigger = button; url = button.dataset.depositUrl; dialog.showModal(); load();
    });
    retry.addEventListener('click', load);
    dialog.querySelector('[data-close-deposit]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { generation++; controller?.abort(); dialog.removeAttribute('aria-busy'); trigger?.focus(); });
}
