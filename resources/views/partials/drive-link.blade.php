@if(\App\Rules\GoogleDriveUrl::valid($url))
<a class="text-indigo-700 underline" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $label }}</a>
@else
<span class="muted">Belum dilampirkan</span>
@endif
