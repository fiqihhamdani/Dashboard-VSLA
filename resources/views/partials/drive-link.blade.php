@if(\App\Rules\GoogleDriveUrl::valid($url))
<a class="btn-secondary" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $label }}</a>
@else
<span class="muted">Belum dilampirkan</span>
@endif
