@extends('layouts.app')
@section('title', 'Detail Anggota')
@section('content')
<h1>{{ $member->name }}</h1><div class="card mt-6 space-y-4"><p>ID anggota #{{ $member->id }} · {{ $member->is_active ? 'Aktif' : 'Nonaktif' }}</p><p><a class="text-indigo-700 underline" href="{{ route('admin.groups.show',$member->group) }}">{{ $member->group->name }} · {{ $member->group->code }}</a></p><p>{{ $member->loans_count }} pinjaman · {{ $member->savings_count }} rincian simpanan</p><div class="flex flex-wrap gap-3"><a class="btn" href="{{ route('admin.members.edit',$member) }}">Edit anggota</a><a class="btn-secondary" href="{{ route('admin.members') }}">Kembali ke daftar</a></div></div>
@endsection
