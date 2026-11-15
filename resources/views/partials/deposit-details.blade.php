<dialog class="quick-menu !max-w-3xl" style="width:min(48rem,calc(100% - 2rem))" data-deposit-dialog aria-labelledby="deposit-title">
    <div class="flex items-center justify-between border-b border-slate-100 p-5"><h2 id="deposit-title">Detail setoran</h2><button type="button" class="btn-secondary" data-close-deposit>Tutup</button></div>
    <div class="p-5"><p role="status" aria-live="polite" data-deposit-status></p><div data-deposit-content hidden>
        <p class="mb-2 text-sm" data-deposit-summary></p><p class="mb-4 text-sm" data-deposit-description></p>
        <div class="table-wrap"><table><thead><tr><th>Anggota</th><th>Jenis simpanan</th><th>Nominal</th></tr></thead><tbody data-deposit-rows></tbody></table></div>
        <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2"><div><dt>Anggota unik</dt><dd class="font-semibold" data-deposit-count></dd></div><div><dt>Total rincian</dt><dd class="font-semibold" data-deposit-total></dd></div><div><dt>Total kas terkait</dt><dd class="font-semibold" data-deposit-cash></dd></div><div><dt>Pencocokan</dt><dd class="font-semibold" data-deposit-match></dd></div></dl>
    </div><button type="button" class="btn-secondary mt-4" data-retry-deposit hidden>Coba lagi</button></div>
</dialog>
