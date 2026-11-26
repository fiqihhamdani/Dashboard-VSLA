@extends('layouts.app')
@section('title', 'Pratinjau Import')
@section('content')
<h1>Pratinjau import</h1><p class="muted mt-3 mb-6">{{ count($rows) }} baris · {{ count($rows)-count($rowErrors) }} valid · {{ count($rowErrors) }} bermasalah. Belum ada data yang disimpan.</p>
@if($rowErrors)<div role="alert" class="notice notice-error">Perbaiki seluruh baris bermasalah dan unggah ulang file. Tidak ada penyimpanan parsial.</div>@else<p class="muted mb-5">Pratinjau berlaku 30 menit dan akan divalidasi ulang saat dikonfirmasi.</p>@endif
<div class="table-wrap"><table><thead><tr><th>Baris Excel</th>@foreach(\App\Services\ExcelImport::HEADERS[$module] as $header)<th>{{ $header }}</th>@endforeach<th>Hasil validasi</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['number'] }}</td>@foreach($row['data'] as $cell)<td>{{ $cell }}</td>@endforeach<td>@if(isset($rowErrors[$row['number']]))<ul class="text-red-700">@foreach($rowErrors[$row['number']] as $error)<li>{{ $error }}</li>@endforeach</ul>@else Valid @endif</td></tr>@endforeach
</tbody></table></div>
@if($token)<form class="card mt-6 space-y-4" method="post" action="{{ route('admin.excel.commit',$module) }}" data-busy-form>@csrf<input type="hidden" name="token" value="{{ $token }}"><label class="flex items-center gap-3"><input type="checkbox" class="!w-auto" name="confirmed" value="1" required>Saya sudah memeriksa pratinjau dan menyetujui penyimpanan seluruh baris.</label><button class="btn">Konfirmasi dan simpan import</button></form>@endif
<a class="btn-secondary mt-6" href="{{ route('admin.excel.form',$module) }}">Kembali / unggah ulang</a>
@endsection
