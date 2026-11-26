@extends('layouts.app')
@section('title', 'Detail Kelompok')
@section('content')
<h1>{{ $group->name }}</h1><div class="card mt-6 space-y-4"><p>Kode {{ $group->code }} · #{{ $group->id }}</p><p>{{ $group->location }} · {{ $group->is_active ? 'Aktif' : 'Nonaktif' }}</p><p>{{ $group->members_count }} anggota · {{ $group->cash_entries_count }} transaksi kas</p><div>@include('partials.drive-link',['url'=>$group->legality_url,'label'=>'Lihat Legalitas'])</div><div class="flex flex-wrap gap-3"><a class="btn" href="{{ route('admin.groups',['edit'=>$group->id]) }}">Edit kelompok</a><a class="btn-secondary" href="{{ route('admin.members',['group_id'=>$group->id]) }}">Daftar anggota</a><a class="btn-secondary" href="{{ route('admin.groups') }}">Kembali</a></div></div>
@endsection
