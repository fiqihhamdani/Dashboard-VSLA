import { initMemberSearch } from './member-search';
const money = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const context = document.querySelector('[data-context-form]');
const form = document.querySelector('[data-transaction-form]');
context?.querySelector('[name=group_id]')?.addEventListener('change', event => {
    const group = event.target.value;
    if (!form || form.dataset.kind === 'payment') {
        const member = context.querySelector('[name=member_id]'); if (member) member.value = '';
        context.requestSubmit(); return;
    }
    form.elements.group_id.value = group;
    form.querySelector('[data-submit-transaction]').disabled = !group;
    form.querySelectorAll('[data-member-search]').forEach(root => initMemberSearch(root).reset(group));
});
context?.addEventListener('member-selected', () => context.requestSubmit());
const rows = document.getElementById('saving-rows');
if (rows) {
    let next = Math.max(...[...rows.querySelectorAll('[data-member-value]')].map(el => Number(el.name.match(/\[(\d+)\]/)[1]))) + 1;
    const template = rows.firstElementChild.cloneNode(true);
    const update = () => {
        document.getElementById('saving-total').textContent = money([...rows.querySelectorAll('[data-saving-amount]')].reduce((sum, input) => sum + Number(input.value || 0), 0));
        rows.querySelectorAll('[data-remove-row]').forEach(button => button.disabled = rows.children.length === 1);
        document.getElementById('add-saving-row').disabled = rows.children.length >= 100;
    };
    rows.addEventListener('input', update);
    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-row]');
        if (button && rows.children.length > 1) {
            const row = button.closest('[data-saving-row]'); initMemberSearch(row.querySelector('[data-member-search]')).destroy(); row.remove(); update();
        }
    });
    document.getElementById('add-saving-row').addEventListener('click', () => {
        if (rows.children.length >= 100) return;
        const copy = template.cloneNode(true);
        copy.innerHTML = copy.innerHTML.replace(/saving-member-\d+/g, `saving-member-${next}`).replace(/saving-amount-\d+/g, `saving-amount-${next}`).replace(/rows\[\d+\]/g, `rows[${next}]`);
        copy.querySelectorAll('input').forEach(field => { field.value = ''; field.removeAttribute('aria-invalid'); });
        copy.querySelectorAll('[id$="-error"],.text-red-700').forEach(error => error.remove());
        copy.querySelector('label').textContent = `Anggota baris ${next + 1}`;
        rows.append(copy); next++;
        initMemberSearch(copy.querySelector('[data-member-search]')).reset(form.elements.group_id.value);
        copy.querySelector('[data-member-input]').focus(); update();
    });
    update();
}
window.addEventListener('pageshow', () => {
    const button = form?.querySelector('[data-submit-transaction]');
    if (button) { button.disabled = !form.elements.group_id.value; button.textContent = form.querySelector('[name=_method]')?.value === 'put' ? 'Simpan koreksi' : 'Simpan transaksi'; }
});
