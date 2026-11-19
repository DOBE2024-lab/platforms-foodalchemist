@foreach($nodes as $node)
    @if($node['kind'] === 'paket')
        <details class="paket"><summary class="kap"><span>{{ $node['label'] }}</span></summary>
            <div class="liste">@include('foodalchemist::handbuch._navigation-baum', ['nodes' => $node['children']])</div>
        </details>
    @elseif($node['kind'] === 'header')
        <div class="nav-abschnitt">{{ $node['label'] }}</div>
    @else
        @include('foodalchemist::handbuch._navigation-items', ['items' => [$node]])
    @endif
@endforeach
