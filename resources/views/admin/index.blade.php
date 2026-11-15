@extends('layouts.app')
@section('title','Ringkasan')
@section('content')
<div class="page-heading">
    <div><p class="section-overline mb-3">Ruang pengurus / Ringkasan</p><h1>Ringkasan keuangan</h1><p class="muted mt-3">Selamat datang, {{ auth()->user()->name }}. Berikut posisi keuangan kelompok Anda.</p></div>
    <a href="{{ route('admin.transactions.create','saving') }}" class="btn"><x-icon name="plus" class="!size-4"/>Catat simpanan</a>
</div>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3"><p class="text-sm font-medium text-slate-600">Seluruh kelompok <span class="mx-2 text-slate-300">/</span> Posisi hingga hari ini</p><time class="text-xs text-slate-500" datetime="{{ now()->toDateString() }}">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</time></div>
<div class="overview-stats">
    <div class="overview-stat"><div class="flex items-center justify-between gap-2"><p class="text-sm font-medium">Saldo kas</p><x-icon name="wallet" class="!size-4 opacity-70"/></div><strong class="number">@rupiah($report['summary']['balance'])</strong><p class="stat-note muted !text-xs">Akumulasi kas masuk dan keluar</p></div>
    <div class="overview-stat"><p class="text-sm text-slate-500">Simpanan bersih</p><strong class="number text-slate-900">@rupiah($report['summary']['savings'])</strong><p class="stat-note text-slate-500">Setoran dikurangi penarikan</p></div>
    <div class="overview-stat"><p class="text-sm text-slate-500">Tagihan jatuh tempo</p><strong class="number {{ $report['summary']['overdue'] > 0 ? 'text-amber-800' : 'text-slate-900' }}">@rupiah($report['summary']['overdue'])</strong><a href="{{ route('admin.loans') }}" class="stat-note text-emerald-700">Periksa pinjaman & tagihan <x-icon name="arrow-right" class="!size-3.5"/></a></div>
    <div class="overview-stat"><p class="text-sm text-slate-500">Anggota aktif</p><strong class="number text-slate-900">{{ $report['summary']['active_members'] }} <span class="text-sm font-normal tracking-normal text-slate-500">orang</span></strong><a href="{{ route('admin.members') }}" class="stat-note text-emerald-700">Lihat daftar anggota <x-icon name="arrow-right" class="!size-3.5"/></a></div>
</div>
<div class="overview-columns">
    <section class="panel" data-recent-activity>
        <div class="panel-heading"><div><h2>Aktivitas terbaru</h2><p class="mt-1 text-xs text-slate-500">Maksimal enam transaksi, diurutkan dari tanggal terbaru.</p></div><a href="{{ route('admin.ledger') }}" class="inline-flex items-center gap-2 text-xs font-semibold text-emerald-700">Buka buku kas <x-icon name="arrow-up-right" class="!size-4"/></a></div>
        @if($recentTransactions->isNotEmpty())
        <div class="activity-toolbar"><div class="segmented-control" aria-label="Filter aktivitas terbaru"><button type="button" data-activity-filter="all" aria-pressed="true">Semua</button><button type="button" data-activity-filter="in" aria-pressed="false">Kas masuk</button><button type="button" data-activity-filter="out" aria-pressed="false">Kas keluar</button></div><p class="text-xs text-slate-500" role="status" data-activity-count>{{ $recentTransactions->count() }} transaksi</p></div>
        @foreach($recentTransactions as $cash)
        <a href="{{ route('admin.cash.show',$cash) }}" class="activity-row" data-activity-direction="{{ $cash->direction }}">
            <span class="activity-mark {{ $cash->direction === 'out' ? 'outgoing' : '' }}"><x-icon :name="$cash->direction === 'in' ? 'arrow-down' : 'arrow-up-right'" class="!size-4"/></span>
            <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-slate-800">{{ $cash->description }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ $cash->group->name }} <span class="mx-1">·</span> {{ $cash->date->format('d M Y') }} <span class="mx-1">·</span> #{{ $cash->id }}</p></div>
            <span class="number {{ $cash->direction === 'in' ? 'text-emerald-700' : 'text-slate-700' }}">{{ $cash->direction === 'in' ? '+' : '−' }}@rupiah($cash->amount)</span>
        </a>
        @endforeach
        <p class="p-7 text-center text-sm text-slate-500" data-activity-empty hidden>Tidak ada transaksi dengan arah kas ini pada enam catatan terbaru.</p>
        @else
        <div class="px-6 py-12"><x-icon name="receipt" class="mb-4 !size-7 text-slate-400"/><h3 class="text-base font-semibold text-slate-800">Buku kas Anda dimulai di sini.</h3><p class="muted mt-2 max-w-md">Belum ada transaksi tercatat. Tambahkan kelompok dan anggota, lalu catat setoran pertama.</p><a href="{{ route($groupCount ? 'admin.transactions.create' : 'admin.groups', $groupCount ? 'saving' : []) }}" class="btn-secondary mt-5">{{ $groupCount ? 'Catat transaksi pertama' : 'Tambah kelompok' }}<x-icon name="arrow-right" class="!size-4"/></a></div>
        @endif
    </section>
    <div>
        <section class="panel"><div class="panel-heading"><h2>Catat transaksi</h2><x-icon name="plus" class="!size-4 text-slate-400"/></div>
            @foreach(['saving'=>['Simpanan','Setoran atau penarikan anggota','wallet'],'loan'=>['Pencairan pinjaman','Pinjaman dan jadwal cicilan','loan'],'payment'=>['Pembayaran angsuran','Bayar tagihan yang masih terbuka','payment'],'other'=>['Kas lainnya','Penerimaan atau pengeluaran lain','receipt']] as $kind=>[$title,$hint,$icon])
            <a href="{{ route('admin.transactions.create',$kind) }}" class="workflow-link"><x-icon :name="$icon"/><span><strong>{{ $title }}</strong><small>{{ $hint }}</small></span><x-icon name="arrow-right" class="action-arrow"/></a>
            @endforeach
        </section>
        <div class="overview-counts">@foreach([['Kelompok',$groupCount,'admin.groups'],['Anggota',$memberCount,'admin.members'],['Transaksi',$cashCount,'admin.ledger']] as [$label,$count,$route])<a href="{{ route($route) }}"><strong class="number">{{ number_format($count,0,',','.') }}</strong><span>{{ $label }}</span></a>@endforeach</div>
        <a href="{{ route('admin.ledger') }}" class="mt-5 flex items-center justify-between gap-4 text-sm font-medium text-slate-700"><span class="flex items-center gap-2"><x-icon name="download" class="!size-4"/>Laporan & unduhan Excel</span><x-icon name="arrow-up-right" class="!size-4"/></a>
    </div>
</div>
@if(!$groupCount || !$memberCount)
<div class="mt-8 border-t border-slate-200 pt-6"><p class="section-overline mb-4">Persiapan pencatatan</p><div class="grid gap-4 sm:grid-cols-2"><a href="{{ route('admin.groups') }}" class="onboarding-step"><span class="step-number">01 / KELOMPOK</span><h2 class="!text-base">Daftarkan kelompok</h2><p class="muted mt-2">Nama, kode, dan lokasi kelompok yang dikelola.</p></a><a href="{{ route('admin.members.create') }}" class="onboarding-step"><span class="step-number">02 / ANGGOTA</span><h2 class="!text-base">Tambahkan anggota</h2><p class="muted mt-2">Daftarkan anggota satu per satu atau sekaligus.</p></a></div></div>
@endif
@endsection
