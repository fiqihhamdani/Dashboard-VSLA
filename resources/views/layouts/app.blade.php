<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Dashboard') · Administrasi VSLA</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body>
<a href="#main" class="sr-only focus:not-sr-only">Lewati ke konten</a>
<header class="border-b border-slate-200 bg-white"><div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4 lg:px-8">
<a href="{{ route('dashboard') }}" class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-xl bg-emerald-700 text-white"><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 5 9 15L21 5M7 5l5 8 5-8"/></svg></span><span class="text-lg font-bold tracking-tight">VSLA<span class="ml-2 text-xs font-normal tracking-wide text-slate-500">ADMINISTRASI</span></span></a>
<nav class="flex items-center gap-3 text-sm" aria-label="Navigasi utama"><a href="{{ route('dashboard') }}" class="hidden font-medium text-slate-600 sm:block">Dashboard publik</a>@auth @if(auth()->user()->is_admin)<a href="{{ route('admin.index') }}" class="btn-secondary">Ruang admin</a>@endif<form action="{{ route('logout') }}" method="post">@csrf<button class="text-sm font-medium text-slate-500 hover:text-emerald-800">Keluar</button></form>@else<a href="{{ route('login') }}" class="btn">Masuk Admin <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10"/></svg></a>@endauth</nav>
</div></header>
<div class="mx-auto max-w-7xl px-5 py-7 lg:px-8 lg:py-9 {{ request()->is('admin*') ? 'lg:grid lg:grid-cols-[210px_1fr] lg:gap-8' : '' }}">
@if(request()->is('admin*'))<aside class="mb-7 lg:mb-0"><p class="mb-3 px-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Pengelolaan</p><nav class="flex flex-wrap gap-1 lg:block lg:space-y-1" aria-label="Navigasi admin">
@foreach([['admin.index','Ringkasan'],['admin.groups','1. Kelompok'],['admin.members','2. Anggota'],['admin.loans','Pinjaman & tagihan'],['admin.ledger','Buku kas & laporan']] as [$route,$label])<a class="nav-link {{ request()->routeIs($route) ? 'nav-active' : '' }}" href="{{ route($route) }}">{{ $label }}</a>@endforeach
<p class="mt-5 hidden px-3 pb-2 text-xs font-semibold uppercase tracking-widest text-slate-400 lg:block">3. Catat transaksi</p>
@foreach(['saving'=>'Simpanan','loan'=>'Pencairan pinjaman','payment'=>'Pembayaran angsuran','other'=>'Kas lainnya'] as $key=>$label)<a class="nav-link {{ request()->route('kind') === $key ? 'nav-active' : '' }}" href="{{ route('admin.transactions.create',$key) }}">{{ $label }}</a>@endforeach
</nav><p class="mt-6 hidden px-3 text-xs leading-6 text-slate-400 lg:block">Ruang pengurus<br>Waktu Indonesia Barat</p></aside>@endif
<main id="main" class="min-w-0">
@if(session('status'))<div role="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="mb-2 font-semibold">Periksa kembali isian berikut:</p><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></div>
<footer class="mx-auto flex max-w-7xl flex-wrap justify-between gap-2 border-t border-slate-200 px-5 py-6 text-xs text-slate-400 lg:px-8"><span>Administrasi VSLA · Tumbuh bersama, tercatat dengan baik.</span><span>Nominal dalam rupiah · Asia/Jakarta</span></footer>
</body></html>
