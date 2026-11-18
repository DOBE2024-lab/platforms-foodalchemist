<div class="rezept-fotos">
    @foreach($bilder as $bild)
        @if(!empty($bild['url']))
            <figure><a href="{{ $bild['url'] }}" target="_blank" rel="noopener" data-bild data-caption="{{ $bild['caption'] ?? '' }}" aria-label="Bild vergrößern"><img src="{{ $bild['url'] }}" alt="{{ $bild['caption'] ?? '' }}" loading="lazy"></a>@if(!empty($bild['caption']))<figcaption>{{ $bild['caption'] }}</figcaption>@endif</figure>
        @endif
    @endforeach
</div>
