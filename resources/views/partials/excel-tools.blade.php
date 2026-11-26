@php($importModule = $module === 'loans' ? 'loan' : $module)
<div class="mb-5 flex flex-wrap items-center gap-3">
<form method="get" action="{{ route('admin.excel.export', $module) }}" class="flex flex-wrap items-center gap-2">
@foreach(request()->only(['q','group_id','is_active','start','end']) as $key => $value) @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
<label class="sr-only" for="export-scope-{{ $module }}">Cakupan export</label><select id="export-scope-{{ $module }}" name="scope" class="!w-auto"><option value="filtered">Sesuai filter aktif</option><option value="all">Seluruh data</option></select><button class="btn-secondary">Export Excel</button>
</form>
@if(array_key_exists($importModule, \App\Services\ExcelImport::HEADERS))<a class="btn-secondary" href="{{ route('admin.excel.form', $importModule) }}">Import Excel</a><a class="text-indigo-700 underline" href="{{ route('admin.excel.template', $importModule) }}">Unduh template</a>@endif
</div>
