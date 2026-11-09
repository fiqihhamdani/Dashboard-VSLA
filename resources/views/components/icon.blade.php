@props(['name' => 'chart'])
@php
$icons = [
 'home'=>['M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z'],
 'groups'=>['M3 21V4h11v17M14 9h7v12M7 8h3M7 12h3M7 16h3M17 13h1M17 17h1M2 21h20'],
 'users'=>['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87','M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
 'wallet'=>['M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v11H5a3 3 0 0 1-3-3V6M20 12h-5v5h5M17 14.5h.01'],
 'loan'=>['M7 7h14v14H7V7ZM3 17V3h14M11 16l6-6M11 10h6v6'],
 'payment'=>['M12 3v12M7 10l5 5 5-5M4 16v5h16v-5'],
 'receipt'=>['M5 3h14v18l-3-2-4 2-4-2-3 2V3ZM9 7h6M9 11h6M9 15h3'],
 'chart'=>['M3 3v18h18M7 14l4-4 4 3 6-7'],
 'arrow-up-right'=>['M7 17 17 7M7 7h10v10'],
 'arrow-right'=>['M4 12h16M14 6l6 6-6 6'],
 'arrow-down'=>['M12 4v16M6 14l6 6 6-6'],
 'calendar'=>['M4 5h16v16H4V5ZM16 3v4M8 3v4M4 10h16M8 14h2M14 14h2M8 17h2'],
 'search'=>['M20 20l-5-5','M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0'],
 'menu'=>['M4 6h16M4 12h16M4 18h16'],
 'close'=>['M6 6l12 12M6 18 18 6'],
 'collapse'=>['M15 6l-6 6 6 6M4 4v16'],
 'plus'=>['M12 5v14M5 12h14'],
 'check'=>['M5 12l4 4L19 6'],
 'shield'=>['M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7l-9-4ZM8 12l3 3 5-5'],
 'sparkles'=>['m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM21 2v4M19 4h4'],
 'download'=>['M12 3v12M7 10l5 5 5-5M4 17v4h16v-4'],
 'logout'=>['M9 21H3V3h6M9 12h12M16 7l5 5-5 5'],
 'eye'=>['M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z','M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0'],
 'filter'=>['M3 4h18l-7 8v7l-4 2v-9L3 4Z'],
 'table'=>['M3 4h18v16H3V4ZM3 10h18M9 4v16'],
 'clock'=>['M12 7v5l3 2','M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'],
 'alert'=>['m12 3 10 18H2L12 3ZM12 9v5M12 17h.01'],
];
@endphp
<svg {{ $attributes->merge(['class'=>'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">@foreach($icons[$name] ?? $icons['chart'] as $path)<path d="{{ $path }}"/>@endforeach</svg>
