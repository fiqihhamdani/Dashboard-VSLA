@extends('layouts.app')
@section('title','Ringkasan')
@section('content')
<div class="page-heading">
    <div><p class="section-overline mb-2">Dashboard / Seluruh kelompok</p><h1>Ringkasan keuangan</h1><p class="muted mt-2">Selamat datang, {{ auth()->user()->name }}.</p></div>
    <div class="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2.5 text-xs text-slate-600"><x-icon name="calendar" class="!size-4"/><time datetime="{{ now()->toDateString() }}">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</time></div>
</div>
<div class="finance-overview">
    <section class="balance-panel" aria-label="Posisi kas">
        <div class="balance-heading"><span><x-icon name="wallet" class="!size-4"/>Saldo kas</span><span class="!text-xs">Hingga {{ now()->locale('id')->translatedFormat('d M Y') }}</span></div>
        <strong class="balance-amount">@rupiah($report['summary']['balance'])</strong>
        <dl class="balance-flows"><div><dt><x-icon name="arrow-down" class="!size-3.5 text-emerald-300"/>Kas masuk bulan ini</dt><dd>@rupiah(array_sum(array_column($report['months'],'in')))</dd></div><div><dt><x-icon name="arrow-up-right" class="!size-3.5 text-brand-300"/>Kas keluar bulan ini</dt><dd>@rupiah(array_sum(array_column($report['months'],'out')))</dd></div></dl>
    </section>
    <div class="summary-grid">
        <section class="summary-cell"><h2>Simpanan bersih</h2><strong class="number">@rupiah($report['summary']['savings'])</strong><a href="{{ route('admin.ledger') }}">Buka buku kas <x-icon name="arrow-up-right" class="!size-3"/></a></section>
        <section class="summary-cell"><h2>Tagihan jatuh tempo</h2><strong class="number {{ $report['summary']['overdue'] > 0 ? '!text-brand-700' : '' }}">@rupiah($report['summary']['overdue'])</strong><a href="{{ route('admin.loans') }}">Periksa tagihan <x-icon name="arrow-up-right" class="!size-3"/></a></section>
        <section class="summary-cell"><h2>Anggota aktif</h2><strong class="number">{{ $report['summary']['active_members'] }} <span class="text-xs font-normal tracking-normal text-slate-500">orang</span></strong><a href="{{ route('admin.members') }}">Daftar anggota <x-icon name="arrow-up-right" class="!size-3"/></a></section>
        <section class="summary-cell"><h2>Kelompok terdaftar</h2><strong class="number">{{ $groupCount }} <span class="text-xs font-normal tracking-normal text-slate-500">kelompok</span></strong><a href="{{ route('admin.groups') }}">Kelola kelompok <x-icon name="arrow-up-right" class="!size-3"/></a></section>
    </div>
</div>
<div class="mb-3 flex items-center justify-between"><h2 class="!text-sm">Mulai pencatatan</h2><p class="hidden text-xs text-slate-500 sm:block">Pilih jenis transaksi</p></div>
<div class="recording-actions">
    @foreach(['saving'=>['Simpanan','Setoran & penarikan','wallet'],'loan'=>['Pencairan pinjaman','Pinjaman & jadwal cicilan','loan'],'payment'=>['Pembayaran angsuran','Tagihan yang masih terbuka','payment'],'other'=>['Kas lainnya','Penerimaan & pengeluaran','receipt']] as $kind=>[$title,$hint,$icon])
    <a href="{{ route('admin.transactions.create',$kind) }}" class="recording-action"><x-icon :name="$icon"/><span class="min-w-0"><strong>{{ $title }}</strong><small>{{ $hint }}</small></span><x-icon name="arrow-up-right" class="action-arrow"/></a>
    @endforeach
</div>
<div class="overview-columns">
    <section class="panel" data-recent-activity>
        <div class="panel-heading"><div><h2>Transaksi terbaru</h2><p class="mt-1 text-xs text-slate-500">Maksimal enam catatan terbaru dari seluruh kelompok.</p></div><a href="{{ route('admin.ledger') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-700">Lihat semua <x-icon name="arrow-right" class="!size-3.5"/></a></div>
        @if($recentTransactions->isNotEmpty())
        <div class="activity-toolbar"><div class="segmented-control" aria-label="Filter aktivitas terbaru"><button type="button" data-activity-filter="all" aria-pressed="true">Semua</button><button type="button" data-activity-filter="in" aria-pressed="false">Kas masuk</button><button type="button" data-activity-filter="out" aria-pressed="false">Kas keluar</button></div><p class="text-xs text-slate-500" role="status" data-activity-count>{{ $recentTransactions->count() }} transaksi</p></div>
        <div class="overflow-x-auto"><table><thead><tr><th>Transaksi</th><th class="hidden sm:table-cell">Kelompok / Tanggal</th><th class="text-right">Nominal</th><th aria-label="Detail transaksi"></th></tr></thead><tbody>
        @foreach($recentTransactions as $cash)
        <tr data-activity-direction="{{ $cash->direction }}"><td><p class="mb-2 min-w-32 text-sm font-medium text-slate-800">{{ $cash->description }}</p><span class="status-label {{ $cash->direction === 'in' ? 'active' : '' }}"><x-icon :name="$cash->direction === 'in' ? 'arrow-down' : 'arrow-up-right'" class="!size-3"/>{{ $cash->direction === 'in' ? 'Kas masuk' : 'Kas keluar' }}</span><p class="mt-2 text-[11px] text-slate-500 sm:hidden">{{ $cash->group->name }} · {{ $cash->date->format('d/m/Y') }}</p></td><td class="hidden sm:table-cell"><p class="whitespace-nowrap text-xs font-medium">{{ $cash->group->name }}</p><p class="mt-2 whitespace-nowrap text-xs text-slate-500">{{ $cash->date->format('d/m/Y') }}</p></td><td class="whitespace-nowrap text-right font-semibold {{ $cash->direction === 'in' ? 'money-in' : 'text-slate-800' }}">{{ $cash->direction === 'in' ? '+' : '−' }}@rupiah($cash->amount)</td><td><a href="{{ route('admin.cash.show',$cash) }}" class="icon-button !size-8" aria-label="Lihat transaksi {{ $cash->id }}"><x-icon name="arrow-up-right" class="!size-4"/></a></td></tr>
        @endforeach
        </tbody></table></div>
        <p class="p-7 text-center text-sm text-slate-500" data-activity-empty hidden>Tidak ada transaksi dengan arah kas ini pada enam catatan terbaru.</p>
        @else
        <div class="px-6 py-10"><x-icon name="receipt" class="mb-4 !size-7 text-slate-400"/><h3 class="text-base font-semibold text-slate-800">Buku kas Anda dimulai di sini.</h3><p class="muted mt-2">Belum ada transaksi tercatat. Mulai dengan kelompok dan anggota, lalu catat setoran pertama.</p><a href="{{ route($groupCount ? 'admin.transactions.create' : 'admin.groups', $groupCount ? 'saving' : []) }}" class="btn-secondary mt-5">{{ $groupCount ? 'Catat transaksi pertama' : 'Tambah kelompok' }}<x-icon name="arrow-right" class="!size-4"/></a></div>
        @endif
    </section>
    <aside class="space-y-5" aria-label="Pengelolaan dan laporan">
        <section class="panel"><div class="panel-heading"><h2>Pengelolaan</h2></div><a href="{{ route('admin.members') }}" class="workflow-link"><x-icon name="users"/><span><strong>Keanggotaan</strong><small>{{ number_format($memberCount,0,',','.') }} anggota terdaftar</small></span><x-icon name="arrow-right" class="action-arrow"/></a><a href="{{ route('admin.loans') }}" class="workflow-link"><x-icon name="loan"/><span><strong>Pinjaman & tagihan</strong><small>Jadwal dan riwayat pembayaran</small></span><x-icon name="arrow-right" class="action-arrow"/></a><a href="{{ route('admin.ledger') }}" class="workflow-link"><x-icon name="receipt"/><span><strong>Buku kas</strong><small>{{ number_format($cashCount,0,',','.') }} transaksi tercatat</small></span><x-icon name="arrow-right" class="action-arrow"/></a></section>
        <section class="card"><x-icon name="download" class="mb-3 !size-5 text-brand-600"/><h2 class="!text-base">Laporan kelompok</h2><p class="muted mt-2 !text-xs">Tinjau periode, cocokkan saldo, dan unduh laporan keuangan dalam format Excel.</p><a href="{{ route('admin.ledger') }}" class="btn-secondary mt-5 w-full !text-xs">Buka laporan <x-icon name="arrow-up-right" class="!size-3.5"/></a></section>
    </aside>
</div>
@if(!$groupCount || !$memberCount)
<div class="mt-7 grid gap-4 sm:grid-cols-2"><a href="{{ route('admin.groups') }}" class="onboarding-step"><span class="step-number">01 / KELOMPOK</span><h2 class="!text-base">Daftarkan kelompok</h2><p class="muted mt-2">Nama, kode, dan lokasi kelompok yang dikelola.</p></a><a href="{{ route('admin.members.create') }}" class="onboarding-step"><span class="step-number">02 / ANGGOTA</span><h2 class="!text-base">Tambahkan anggota</h2><p class="muted mt-2">Daftarkan anggota satu per satu atau sekaligus.</p></a></div>
@endif
@endsection
