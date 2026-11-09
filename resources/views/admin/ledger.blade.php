@extends('layouts.app')
@section('title','Buku Kas & Laporan')
@section('content')
<div class="mb-7 flex flex-wrap items-start justify-between gap-4"><div><h1>Buku kas & laporan</h1><p class="muted mt-3">Setiap pergerakan uang, tercatat satu kali.</p></div><a class="btn" href="{{ route('admin.report',$report['filter']) }}">↓ Unduh Excel</a></div>
@include('partials.filter')
<div class="mb-6 grid gap-4 sm:grid-cols-2"><div class="card"><p class="muted">Saldo awal periode</p><p class="mt-2 text-2xl font-bold">@rupiah($report['summary']['opening'])</p></div><div class="card"><p class="muted">Saldo kas akhir</p><p class="mt-2 text-2xl font-bold text-emerald-700">@rupiah($report['summary']['balance'])</p></div></div>
@if($ledger->isEmpty())<div class="empty">Belum ada transaksi kas selama periode ini. Saldo awal tetap diperhitungkan.</div>@else<div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Kelompok</th><th>Uraian</th><th>Kas masuk</th><th>Kas keluar</th><th>Saldo</th></tr></thead><tbody>@foreach($ledger as $row)<tr><td class="whitespace-nowrap">{{ $row['date'] }}</td><td>{{ $row['group'] }}</td><td>{{ $row['description'] }}</td><td class="whitespace-nowrap text-emerald-700">@rupiah($row['in'])</td><td class="whitespace-nowrap">@rupiah($row['out'])</td><td class="whitespace-nowrap font-medium">@rupiah($row['balance'])</td></tr>@endforeach</tbody></table></div>@endif
<p class="muted mt-5">Excel berisi tiga lembar: Ringkasan, Rekap Bulanan, dan Buku Kas. Filter serta aturan hitungnya sama dengan dashboard publik.</p>
@endsection
