@foreach($items as $it)
    <a href="#{{ $it['key'] }}" data-key="{{ $it['key'] }}">{{ $it['label'] !== '' ? $it['label'] : ($gerichte[$it['key']]['name'] ?? '') }}</a>
@endforeach
