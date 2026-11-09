@extends('layouts.app')
@section('title','Dashboard Publik')
@section('content')
<div class="mb-8 flex flex-wrap items-start justify-between gap-5"><div><p class="mb-3 text-xs font-semibold uppercase tracking-[.2em] text-emerald-700">Transparansi keuangan bersama</p><h1>Dashboard VSLA</h1><p class="mt-3 text-slate-500">Lihat perkembangan kas, simpanan, dan pinjaman kelompok Anda.</p></div><span class="tag mt-2"><span class="mr-1.5 size-1.5 rounded-full bg-emerald-600"></span>Data agregat publik</span></div>
@include('partials.filter')
<div class="mb-4 flex flex-wrap items-center justify-between gap-2"><h2>Ringkasan keuangan</h2><p class="text-xs text-slate-500">{{ \Carbon\Carbon::parse($report['filter']['start'])->translatedFormat('d M Y') }} — {{ \Carbon\Carbon::parse($report['filter']['end'])->translatedFormat('d M Y') }}</p></div>
<div class="mb-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['balance'=>['Saldo kas akhir','Posisi hingga akhir laporan'],'savings'=>['Simpanan bersih','Setoran dikurangi penarikan hingga akhir laporan'],'loans'=>['Pokok pinjaman dicairkan','Selama periode dipilih'],'payments'=>['Angsuran diterima','Selama periode dipilih'],'overdue'=>['Tunggakan jatuh tempo','Sisa tagihan jatuh tempo hingga akhir laporan'],'active_members'=>['Anggota aktif','Kondisi saat ini, bukan historis']] as $key=>[$label,$hint])
<div class="card {{ $key === 'balance' ? '!border-emerald-700 !bg-emerald-700 !text-white' : '' }}"><div class="mb-4 flex items-center justify-between"><h3 class="text-sm font-medium {{ $key === 'balance' ? 'text-emerald-100' : 'text-slate-500' }}">{{ $label }}</h3><svg aria-hidden="true" class="size-5 opacity-50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 17V7m6 10V4m6 13v-6m5 9H2"/></svg></div><p class="number text-2xl font-bold tracking-tight sm:text-3xl">@if($key === 'active_members'){{ $report['summary'][$key] }} <span class="text-base font-normal">orang</span>@else @rupiah($report['summary'][$key]) @endif</p><p class="mt-3 text-xs {{ $key === 'balance' ? 'text-emerald-100' : 'text-slate-400' }}">{{ $hint }}</p></div>
@endforeach
</div>
@if(!$report['has_transactions'])<div class="empty mb-7"><p class="mb-1 font-semibold text-slate-700">Belum ada transaksi untuk ditampilkan</p><p>Grafik akan terisi setelah pengurus mencatat transaksi. Anda juga dapat mencoba kelompok atau periode lain.</p></div>@endif
<script id="monthly-data" type="application/json">{!! \Illuminate\Support\Js::encode($report['months']) !!}</script>
<div class="grid gap-6 lg:grid-cols-2">
@foreach([['cashflow','Arus kas bulanan','Perbandingan penerimaan dan pengeluaran.',['in'=>'Kas masuk','out'=>'Kas keluar']],['balance','Saldo akhir kas','Saldo dibawa ke bulan berikutnya, termasuk bulan tanpa transaksi.',['balance'=>'Saldo kas']],['activity','Aktivitas simpan pinjam','Aktivitas selama periode yang dipilih.',['deposits'=>'Setoran simpanan','loans'=>'Pencairan pinjaman','payments'=>'Angsuran diterima']]] as [$id,$title,$hint,$series])
<section class="card {{ $id === 'activity' ? 'lg:col-span-2' : '' }}"><div class="mb-5"><h2>{{ $title }}</h2><p class="muted mt-1">{{ $hint }}</p></div><div class="trend-chart" data-series="{{ json_encode($series) }}" aria-label="{{ $title }}"></div><p class="mt-3 text-xs text-slate-400">Pilih titik pada grafik untuk melihat nominal rupiah. Gunakan Tab dan Enter pada papan ketik.</p></section>
@endforeach
</div>
<details class="card mt-6"><summary class="cursor-pointer text-sm font-semibold">Lihat data grafik dalam tabel</summary><div class="table-wrap mt-4"><table><thead><tr><th>Bulan</th><th>Kas masuk</th><th>Kas keluar</th><th>Saldo akhir</th><th>Setoran</th><th>Pinjaman</th><th>Angsuran</th></tr></thead><tbody>@foreach($report['months'] as $m)<tr><td>{{ $m['label'] }}</td>@foreach(['in','out','balance','deposits','loans','payments'] as $key)<td class="whitespace-nowrap">@rupiah($m[$key])</td>@endforeach</tr>@endforeach</tbody></table></div></details>
<p class="muted mt-6">Saldo awal periode: <strong>@rupiah($report['summary']['opening'])</strong>. Data anggota dan rincian transaksi hanya dapat dilihat oleh admin.</p>
@endsection
