<!DOCTYPE html>
{{--
  Spec 86 — Öffentliches Handbuch (Standards zum Nachschlagen). Eigenständige Seite ohne Login,
  rendert NUR aus dem eingefrorenen Snapshot. Mischung aus Editor und Küchenmonitor: Editor-Größe
  (13 px), Wandmonitor-Palette (dunkelblau, Segment-Reiter, Kennzahl-Chips), umschaltbar auf hell.
  Tablet-first, Handy einspaltig. Kein Build-Schritt: CSS + wenige Zeilen JS inline.
--}}
@php
    $gerichte = $snapshot['gerichte'] ?? [];
    $basis = $snapshot['basisrezepte'] ?? [];
    $legende = $snapshot['legende'] ?? null;
    $personen = (int) ($snapshot['personen'] ?? 0) ?: 1;
    $codeLabel = [];
    foreach (['allergene', 'zusatzstoffe'] as $gruppe) {
        foreach (($legende[$gruppe] ?? []) as $e) {
            $codeLabel[$e['code']] = $e['label'];
        }
    }
    $freie = $snapshot['freie_kapitel'] ?? [];
    $nKapitel = count($snapshot['sections'] ?? []);
    $nSpeisen = count($gerichte);
    $hatVerteilung = false;
    foreach ($snapshot['sections'] ?? [] as $s) { foreach ($s['items'] ?? [] as $it) { if (array_key_exists('menge_pro_gast', $it)) { $hatVerteilung = true; } } }
    $fmtMenge = fn ($v) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, $v < 10 ? 3 : 1, ',', ''), '0'), ',');
@endphp
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $snapshot['title'] ?? 'Handbuch' }} · Handbuch</title>
    <link rel="stylesheet" href="{{ route('foodalchemist.presentation.handbuch_css', ['v' => $cssVersion ?? 1]) }}">
</head>
<body class="dunkel">
<div class="app" id="app">
    <div class="top">
        <div class="kopf">
            <div class="klein">Handbuch · {{ ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'angebot' => 'Angebot'][$snapshot['quelle'] ?? 'foodbook'] ?? '' }}@if(!empty($snapshot['subtitle'])) · {{ $snapshot['subtitle'] }}@endif</div>
            <h1>{{ $snapshot['title'] ?? 'Handbuch' }}</h1>
        </div>
        <span class="kpi"><b>{{ $nKapitel }}</b> Kapitel</span>
        <span class="kpi"><b>{{ $nSpeisen }}</b> Speisen</span>
        <span class="sp"></span>
        <span class="logo">Food<span>.Alchemist</span></span>
        <span class="sp"></span>
        <button type="button" class="ico" id="dk" aria-label="Zwischen dunkel und hell umschalten" title="Dunkel / Hell"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 2a6 6 0 0 1 0 12z" fill="currentColor"/></svg></button>
    </div>

    <div class="body">
        <nav aria-label="Gerichte">
            @if($hatVerteilung)<label class="gaeste-rechner">Gesamtbedarf für <input type="number" id="handbuch-gaeste" min="1" max="1000000" step="1" placeholder="Anzahl" value="{{ $snapshot['personen'] ?? '' }}"> Gäste</label>@endif
            @foreach($snapshot['sections'] ?? [] as $sec)
                <details class="kapw">
                    <summary class="kap"><span>{{ $sec['title'] !== '' ? $sec['title'] : 'Speisen' }}</span><span class="n">{{ count($sec['items']) }}</span></summary>
                    @if(!empty($sec['text']))<div class="kap-text">{{ $sec['text'] }}</div>@endif
                    <div class="liste">
                        @if(!empty($sec['groups']))
                            @foreach($sec['groups'] as $gruppe)
                                <details class="konzept">
                                    <summary class="kap"><span>{{ $gruppe['title'] }}</span><span class="n">{{ count($gruppe['items']) }}</span></summary>
                                    <div class="liste">
                                        @if(!empty($gruppe['nodes']))
                                            @include('foodalchemist::handbuch._navigation-baum', ['nodes' => $gruppe['nodes']])
                                        @else
                                            @include('foodalchemist::handbuch._navigation-items', ['items' => $gruppe['items']])
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        @else
                            @include('foodalchemist::handbuch._navigation-items', ['items' => $sec['items']])
                        @endif
                    </div>
                </details>
            @endforeach
            @if($freie !== [])
                <details class="kapw">
                    <summary class="kap"><span>Allgemein</span><span class="n">{{ count($freie) }}</span></summary>
                    <div class="liste">
                        @foreach($freie as $i => $fk)<a href="#k{{ $i }}" data-key="k{{ $i }}">{{ $fk['title'] }}</a>@endforeach
                    </div>
                </details>
            @endif
        </nav>

        <div class="detail-toolbar">
            <button type="button" class="back" id="zurueck">‹ Alle Speisen</button>
        </div>
        <main>
            <div class="start">Format oder Konzept öffnen und eine Speise auswählen.</div>
            @foreach($gerichte as $key => $g)
                @php
                    $portion = $g['portion'] ?? [];
                    $teile = array_filter([
                        $portion['form'] ?? null,
                        ($portion['gramm'] ?? null) ? $fmtMenge($portion['gramm']) . ' g' : null,
                        $portion['geschirr'] ?? null,
                        $portion['vehikel'] ?? null,
                    ]);
                    $z = $g['zutaten'] ?? ['basis' => 'ansatz', 'zeilen' => []];
                    $skaliert = ($z['basis'] ?? '') === 'portion';
                @endphp
                <article class="gericht" id="g-{{ $key }}" data-key="{{ $key }}" data-name="{{ $g['name'] }}">
                    <div class="head">
                        @if(!empty($g['foto_url']))<a class="foto-link" href="{{ $g['foto_url'] }}" data-bild data-caption="{{ $g['name'] }}" aria-label="Gerichtsbild vergrößern"><img class="foto" src="{{ $g['foto_url'] }}" alt="{{ $g['name'] }}" loading="lazy"></a>@endif
                        <div>
                            <h2>{{ $g['name'] }}</h2>
                            @if($teile)<div class="meta">{{ implode(' · ', $teile) }}</div>@endif
                            @php
                                $spuren = array_values(array_filter($g['codes'] ?? [], fn ($c) => str_ends_with($c, '*')));
                                $enthalten = array_values(array_filter($g['codes'] ?? [], fn ($c) => ! str_ends_with($c, '*')));
                            @endphp
                            <div class="allergene" aria-label="Enthaltene Allergene und Zusatzstoffe">
                                @foreach($enthalten as $c)<span class="chip">{{ $c }}@if($codeLabel[$c] ?? null) · {{ $codeLabel[$c] }}@endif</span>@endforeach
                            </div>
                            @if($spuren !== [])
                                <details class="spuren"><summary>Spuren möglich ({{ count($spuren) }})</summary>
                                    @foreach($spuren as $c)<span class="chip">{{ $c }}@if($codeLabel[rtrim($c, '*')] ?? null) · {{ $codeLabel[rtrim($c, '*')] }}@endif (Spuren)</span>@endforeach
                                </details>
                            @endif
                        </div>
                    </div>

                    @if(!empty($g['bilder']))<div class="allgemein-fotos">@include('foodalchemist::handbuch._bilder', ['bilder' => $g['bilder']])</div>@endif
                    <div class="tabs" role="tablist">
                        <button type="button" class="tab on" data-pane="zutaten">Zutaten</button>
                        <button type="button" class="tab" data-pane="zubereitung">Zubereitung</button>
                        <button type="button" class="tab" data-pane="anrichten">Anrichten</button>
                        <button type="button" class="tab" data-pane="regeneration">Regeneration</button>
                        <button type="button" class="tab" data-pane="bestandteile">Bestandteile</button>
                    </div>

                    <div class="pane on" data-pane="zutaten">
                        @if(!empty($z['zeilen']) && $skaliert)
                            <div class="stepper stepper-pane">Portionen <button type="button" data-step="-1" aria-label="weniger">−</button><input class="px" type="number" min="1" max="1000000" step="1" inputmode="numeric" value="{{ $personen }}" aria-label="Portionen"><button type="button" data-step="1" aria-label="mehr">+</button></div>
                        @endif
                        @if(!empty($z['zeilen']))
                            <table>
                                <tr><th>Zutat</th><th class="r">Menge</th></tr>
                                @foreach($z['zeilen'] as $zi)
                                    <tr>
                                        <td>
                                            @if(!empty($zi['sub']) && isset($basis[$zi['sub']]))
                                                <button type="button" class="sub" data-sub="{{ $key }}-{{ $zi['sub'] }}-{{ $loop->index }}" aria-expanded="false" aria-controls="sb-{{ $key }}-{{ $zi['sub'] }}-{{ $loop->index }}">{{ $zi['name'] }}</button> <span class="meta">(Basisrezept)</span>
                                            @else
                                                {{ $zi['name'] }}
                                            @endif
                                        </td>
                                        <td class="r" @if($skaliert && $zi['menge'] !== null) data-b="{{ $zi['menge'] }}" data-u="{{ $zi['einheit'] }}" @endif>{{ $zi['menge'] !== null ? $fmtMenge($zi['menge']) . ' ' . $zi['einheit'] : '–' }}</td>
                                    </tr>
                                    @if(!empty($zi['sub']) && isset($basis[$zi['sub']]))
                                        <tr class="subrow"><td colspan="2">
                                            <div class="subbox" data-faktor="{{ $zi['sub_faktor'] ?? '' }}" id="sb-{{ $key }}-{{ $zi['sub'] }}-{{ $loop->index }}">
                                                @include('foodalchemist::handbuch._basisvorschau', ['b' => $basis[$zi['sub']], 'basisKey' => $zi['sub'], 'bedarfFaktor' => $zi['sub_faktor'] ?? null])
                                            </div>
                                        </td></tr>
                                    @endif
                                @endforeach
                            </table>
                            <div class="hint">
                                @if($skaliert) Mengen für <b class="pxs">{{ $personen }}</b> Portionen · Basisrezepte antippen zeigt die Kurzvorschau; dort das vollständige Rezept öffnen.
                                @else Mengen im Ansatz{{ !empty($z['ansatz_kg']) ? ' (' . $fmtMenge($z['ansatz_kg']) . ' kg)' : '' }}; für dieses Gericht ist kein Portionsgewicht hinterlegt.
                                @endif
                            </div>
                        @else
                            <div class="leer">Keine Zutaten hinterlegt.</div>
                        @endif
                    </div>

                    @foreach(['zubereitung' => 'Keine Zubereitung hinterlegt.', 'anrichten' => 'Kein Anrichten hinterlegt.'] as $pane => $leerText)
                        <div class="pane" data-pane="{{ $pane }}">
                            @forelse($g[$pane] as $s)
                                <div class="step">
                                    <div class="nr">{{ $s['nr'] }}</div>
                                    <div class="step-inhalt">
                                        <div>{{ $s['text'] }}</div>
                                        @if(!empty($s['fotos']))
                                            @include('foodalchemist::handbuch._bilder', ['bilder' => $s['fotos']])
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="leer">{{ $leerText }}</div>
                            @endforelse
                        </div>
                    @endforeach

                    <div class="pane" data-pane="regeneration">
                        @if($g['regeneration'])
                            <table>
                                <tr><th>Komponente</th><th>Gerät</th><th class="r">°C</th><th class="r">min</th><th class="r">Kern</th></tr>
                                @foreach($g['regeneration'] as $r)
                                    <tr>
                                        <td>{{ $r['komponente'] }}@if(!empty($r['hinweis']))<div class="meta">{{ $r['hinweis'] }}</div>@endif</td>
                                        @if($r['kalt'])
                                            <td colspan="4">Kalt servieren</td>
                                        @else
                                            <td>{{ $r['geraet'] ?? '–' }}</td><td class="r">{{ $r['temp_c'] ?? '–' }}</td><td class="r">{{ $r['dauer_min'] ?? '–' }}</td><td class="r">{{ $r['kern_c'] ?? '–' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </table>
                        @else
                            <div class="leer">Keine Regeneration hinterlegt.</div>
                        @endif
                        @if(!empty($g['behaelter']))
                            <div class="hint">@foreach($g['behaelter'] as $bh)<b>{{ $bh['zweck'] }}:</b> {{ $bh['name'] }}@if(!$loop->last) · @endif @endforeach</div>
                        @endif
                    </div>

                    <div class="pane" data-pane="bestandteile">
                        @forelse($g['bestandteile'] as $b)
                            <table><tr><td>{{ $b['name'] }}</td><td class="r">{{ $b['garnitur'] ? 'Garnitur' : '' }}</td></tr></table>
                        @empty
                            <div class="leer">Keine Bestandteile hinterlegt.</div>
                        @endforelse
                    </div>
                </article>
            @endforeach

            @foreach($basis as $basisKey => $b)
                <article class="gericht basiskarte" id="g-basis-{{ $basisKey }}" data-key="basis-{{ $basisKey }}" data-name="{{ $b['name'] }}">
                    <button type="button" class="basis-zurueck" data-basis-zurueck>‹ Zurück</button>
                    @include('foodalchemist::handbuch._basisrezept', ['b' => $b, 'fmtMenge' => $fmtMenge, 'basis' => $basis])
                </article>
            @endforeach

            @foreach($freie as $i => $fk)
                <article class="gericht" id="g-k{{ $i }}" data-key="k{{ $i }}">
                    <h2>{{ $fk['title'] }}</h2>
                    <div class="freitext">{!! nl2br(e($fk['text'])) !!}</div>
                </article>
            @endforeach

        </main>
    </div>
</div>

<dialog id="bilddialog" class="bild-dialog" aria-label="Vergrößertes Rezeptbild">
    <button type="button" class="bild-schliessen" autofocus>Schließen</button><img alt=""><p></p>
</dialog>
<script>
(function () {
    var app = document.getElementById('app'), body = document.body, main = document.querySelector('main');
    var links = Array.from(document.querySelectorAll('nav .liste a'));
    var arts = Array.from(document.querySelectorAll('.gericht')), aktiv = '';
    var gaesteFeld = document.getElementById('handbuch-gaeste');
    function zahl(v) { return Math.min(1000000, Math.max(1, parseInt(v, 10) || 1)); }
    function dezimal(v) { var s = String(v == null ? '' : v).trim().replace(',', '.'); return s === '' ? NaN : Number(s); }
    function anzahl(a, v) { return a.classList.contains('basiskarte') || a._ausVerteilung ? Math.min(1000000, Math.max(0, dezimal(v) || 0)) : zahl(v); }
    function aktuell(a) { var p = a.querySelector('.px, .basis-px'); return anzahl(a, p && p.value); }
    function eingabe(v) { return String(Number(v.toFixed(6))).replace('.', ','); }
    function fmt(v, u) {
        if (u === 'Teile' && v === 1) { u = 'Teil'; }
        if (u === 'g' && v >= 1000) { v /= 1000; u = 'kg'; }
        else if (u === 'ml' && v >= 1000) { v /= 1000; u = 'l'; }
        var s = v >= 100 ? v.toFixed(0) : (v >= 10 ? v.toFixed(1) : v.toFixed(2));
        // Ganze Zahlen behalten ihre Nullen: 120 g bleiben 120 g.
        if (s.indexOf('.') !== -1) { s = s.replace(/0+$/, '').replace(/\.$/, ''); }
        return s.replace('.', ',') + ' ' + (u || '');
    }
    function mengen(root, faktor) {
        var t = root.querySelector(':scope > table[data-basis-werte]');
        if (t) { t.querySelectorAll('td[data-original]').forEach(function (td) { td.textContent = fmt(parseFloat(td.dataset.original) * faktor, td.dataset.u); }); }
        root.querySelectorAll(':scope > p [data-bedarf-menge]').forEach(function (e) { e.textContent = fmt(parseFloat(e.dataset.ansatz) * faktor, e.dataset.einheit); });
    }
    function bezug(a) {
        if (a._zielFaktor != null && !a._original && a._bedarf && a._bedarf.faktor > 0) { return {faktor:a._bedarf.faktor, n:a._zielFaktor / a._bedarf.faktor, name:a._bedarf.name, einheit:a._bedarf.einheit}; }
        if (a._zielFaktor != null && !a._original) { return {faktor:a._zielFaktor, n:1, name:a.dataset.name, einheit:'Ansätze'}; }
        if (a._bedarf && !a._original) { return {faktor:a._bedarf.faktor, n:aktuell(a), name:a._bedarf.name, einheit:a._bedarf.einheit}; }
        return {faktor:1, n:a.classList.contains('basiskarte') ? 1 : aktuell(a), name:a.dataset.name, einheit:a.querySelector('.px') ? 'Portionen' : 'Ansätze'};
    }
    function vorschauen(a) {
        var q = bezug(a);
        a.querySelectorAll('.subbox').forEach(function (box) {
            var f = parseFloat(box.dataset.faktor), klar = Number.isFinite(f) && f >= 0;
            mengen(box, klar ? f * q.faktor * q.n : 1);
            box.querySelector('[data-vorschau-modus]').textContent = klar ? 'Bedarf für ' + eingabe(q.n) + ' ' + q.einheit : 'Originalansatz';
            box.querySelector('[data-bedarf-fehlt]').hidden = klar;
        });
    }
    function basisRechnen(a, merken, zielNeu) {
        var q = a._bedarf, bedarf = !!q && !a._original && !(a._zielFaktor != null && q.faktor === 0);
        var n = aktuell(a), f = a._original ? 1 : (a._zielFaktor != null ? a._zielFaktor : (bedarf ? q.faktor * n : 1));
        a.querySelector('.basis-rechner').hidden = !bedarf;
        a.querySelector('[data-rezeptmodus]').textContent = bedarf ? 'Bedarf für ' + eingabe(n) + ' ' + q.einheit : (a._zielFaktor != null && !a._original ? 'Gewünschte Rezeptmenge' : 'Originalansatz');
        a.querySelector('[data-basis-quelle]').textContent = q ? 'Bezug: ' + q.name : '';
        a.querySelector('[data-basis-einheit]').textContent = q ? q.einheit : 'Portionen';
        a.querySelector('[data-schritt-mengen]').hidden = !(bedarf || (a._zielFaktor != null && !a._original));
        var b = a.querySelector('.original-umschalter'); b.hidden = !q && a._zielFaktor == null;
        b.textContent = a._original ? 'Zur benötigten Menge' : 'Originalansatz anzeigen';
        mengen(a, f); vorschauen(a);
        var ziel = a.querySelector('.menge-rechner');
        if (ziel) {
            var u = ziel.querySelector('.basis-ziel-einheit').value;
            var feld = ziel.querySelector('.basis-ziel');
            if (zielNeu || document.activeElement !== feld) { feld.value = eingabe(parseFloat(ziel.dataset.basisAnsatz) * f * (u === 'g' ? 1000 : 1)); }
            ziel.querySelector('[data-basis-ziel-fehler]').hidden = true;
        }
        if (merken && aktiv === a.dataset.key) {
            if (q) { q.n = n; }
            history.replaceState(Object.assign({}, history.state, {handbuchBasis:q, handbuchOriginal:a._original, handbuchZielFaktor:a._zielFaktor}), '', location.href);
        }
    }
    function show(key) {
        aktiv = key;
        arts.forEach(function (a) { a.classList.toggle('on', a.dataset.key === key); });
        var navIndex = history.state && history.state.handbuchNav;
        var ziel = links[navIndex] && links[navIndex].dataset.key === key ? links[navIndex] : links.find(function (l) { return l.dataset.key === key; });
        links.forEach(function (l) {
            var an = l === ziel; l.classList.toggle('on', an);
            if (an) { for (var e = l.parentElement; e && e !== app; e = e.parentElement) { if (e.tagName === 'DETAILS') { e.open = true; } } }
        });
        app.classList.toggle('detail', !!key); app.classList.toggle('has-selection', !!key);
        var a = document.getElementById('g-' + key);
        if (a && a.classList.contains('basiskarte')) {
            var q = history.state && history.state.handbuchBasis;
            a._bedarf = q && Number.isFinite(q.faktor) && q.faktor >= 0 ? Object.assign({}, q) : null;
            a._original = !a._bedarf || !!(history.state && history.state.handbuchOriginal);
            var zf = history.state && history.state.handbuchZielFaktor;
            a._zielFaktor = Number.isFinite(zf) && zf >= 0 ? zf : null;
            if (a._zielFaktor != null) { a._original = false; }
            a.querySelector('.basis-px').value = a._bedarf ? anzahl(a, a._bedarf.n) : 1;
            basisRechnen(a, false);
        }
        var state = history.state;
        main.scrollTop = state && state.handbuchKey === key ? state.handbuchScroll || 0 : 0;
        document.getElementById('zurueck').textContent = key.indexOf('basis-') === 0 && state && state.handbuchVon ? '‹ Zurück zum Rezept' : '‹ Alle Speisen';
    }
    function fromHash() { var key = location.hash.slice(1); show(document.getElementById('g-' + key) ? key : ''); }
    links.forEach(function (l, index) { l.addEventListener('click', function (e) {
        var a = document.getElementById('g-' + l.dataset.key), gaeste = dezimal(gaesteFeld && gaesteFeld.value), rate = dezimal(l.dataset.portionenProGast);
        if (a && a.querySelector('.px') && !a._portionenEigen && Number.isFinite(rate) && rate >= 0 && Number.isInteger(gaeste) && gaeste >= 1 && gaeste <= 1000000) {
            a._ausVerteilung = true; var p = a.querySelector('.px'); p.min = '0'; p.step = 'any'; p.value = Math.min(1000000, gaeste * rate); rechne(a);
        }
        e.preventDefault(); history.pushState({handbuchNav:index}, '', l.getAttribute('href')); fromHash();
    }); });
    function verteilung() {
        var n = dezimal(gaesteFeld && gaesteFeld.value), ok = Number.isInteger(n) && n >= 1 && n <= 1000000;
        document.querySelectorAll('[data-menge-pro-gast]').forEach(function (e) { e.textContent = ok ? ' · gesamt ' + fmt(parseFloat(e.dataset.mengeProGast) * n, e.dataset.u) : ''; });
    }
    if (gaesteFeld) { gaesteFeld.addEventListener('input', verteilung); verteilung(); }
    window.addEventListener('hashchange', fromHash); window.addEventListener('popstate', fromHash);
    function zurueck() {
        if (aktiv.indexOf('basis-') === 0 && history.state && history.state.handbuchVon) { history.back(); return; }
        history.pushState(null, '', location.pathname + location.search); show('');
    }
    document.getElementById('zurueck').addEventListener('click', zurueck);
    document.querySelectorAll('[data-basis-zurueck]').forEach(function (b) { b.addEventListener('click', zurueck); });
    document.querySelectorAll('.basis-link').forEach(function (l) {
        l.addEventListener('click', function (e) {
            e.preventDefault();
            var q = bezug(l.closest('.gericht')), f = parseFloat(l.dataset.faktor);
            var kontext = Number.isFinite(f) && f >= 0 ? {faktor:q.faktor * f, n:q.n, name:q.name, einheit:q.einheit} : null;
            history.replaceState(Object.assign({}, history.state, {handbuchKey:aktiv, handbuchScroll:main.scrollTop}), '', location.href);
            history.pushState({handbuchVon:aktiv, handbuchBasis:kontext, handbuchNav:history.state && history.state.handbuchNav}, '', l.getAttribute('href')); fromHash();
        });
    });
    arts.forEach(function (a) {
        var tabs = Array.from(a.querySelectorAll('.tab')), panes = Array.from(a.children).filter(function (e) { return e.classList.contains('pane'); });
        tabs.forEach(function (t) { t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
            panes.forEach(function (p) { p.classList.toggle('on', p.dataset.pane === t.dataset.pane); });
        }); });
        if (a.classList.contains('basiskarte')) {
            var bp = a.querySelector('.basis-px');
            function portionenRechnen() { a._zielFaktor = null; a._original = false; basisRechnen(a, true); }
            a.querySelectorAll('[data-basis-step]').forEach(function (b) { b.addEventListener('click', function () { bp.value = anzahl(a, aktuell(a) + parseInt(b.dataset.basisStep, 10)); portionenRechnen(); }); });
            bp.addEventListener('input', portionenRechnen);
            bp.addEventListener('change', function () { bp.value = aktuell(a); basisRechnen(a, true); });
            a.querySelector('.original-umschalter').addEventListener('click', function () { a._zielFaktor = null; a._original = !a._original; basisRechnen(a, true); });
            var ziel = a.querySelector('.menge-rechner');
            if (ziel) {
                ziel.querySelector('.basis-ziel-einheit').addEventListener('change', function () { basisRechnen(a, false, true); });
                ziel.querySelector('.basis-ziel').addEventListener('blur', function () { basisRechnen(a, false); });
                ziel.querySelector('.basis-ziel').addEventListener('input', function () {
                    var wert = dezimal(this.value), u = ziel.querySelector('.basis-ziel-einheit').value;
                    var f = wert / ((u === 'g' ? 1000 : 1) * parseFloat(ziel.dataset.basisAnsatz));
                    if (!Number.isFinite(wert) || wert < 0 || wert > 1000000 || (a._bedarf && a._bedarf.faktor > 0 && f / a._bedarf.faktor > 1000000)) { ziel.querySelector('[data-basis-ziel-fehler]').hidden = false; return; }
                    a._zielFaktor = f; a._original = false;
                    if (a._bedarf && a._bedarf.faktor > 0) { bp.value = Number((f / a._bedarf.faktor).toFixed(6)); }
                    basisRechnen(a, true);
                });
            }
        }
    });
    document.querySelectorAll('[data-sub]').forEach(function (b) { b.addEventListener('click', function () {
        var box = document.getElementById('sb-' + b.dataset.sub);
        if (box) { var offen = box.classList.toggle('open'); box.closest('.subrow').classList.toggle('open', offen); b.setAttribute('aria-expanded', String(offen)); vorschauen(b.closest('.gericht')); }
    }); });
    function rechne(a) {
        var n = aktuell(a);
        a.querySelectorAll('.pxs').forEach(function (e) { e.textContent = n; });
        a.querySelectorAll('td[data-b]').forEach(function (td) { td.textContent = fmt(parseFloat(td.dataset.b) * n, td.dataset.u); });
        vorschauen(a);
    }
    arts.forEach(function (a) {
        var p = a.querySelector('.px'); if (!p) { return; }
        a.querySelectorAll('[data-step]').forEach(function (b) { b.addEventListener('click', function () { a._portionenEigen = true; p.value = anzahl(a, aktuell(a) + parseInt(b.dataset.step, 10)); rechne(a); }); });
        p.addEventListener('input', function () { a._portionenEigen = true; rechne(a); });
        p.addEventListener('change', function () { p.value = aktuell(a); rechne(a); }); rechne(a);
    });
    var bilddialog = document.getElementById('bilddialog');
    document.querySelectorAll('a[data-bild]').forEach(function (a) { a.addEventListener('click', function (e) {
        if (typeof bilddialog.showModal !== 'function') { return; }
        e.preventDefault(); var img = bilddialog.querySelector('img');
        img.src = a.href; img.alt = a.dataset.caption || 'Rezeptbild';
        bilddialog.querySelector('p').textContent = a.dataset.caption || ''; bilddialog.showModal();
    }); });
    bilddialog.querySelector('button').addEventListener('click', function () { bilddialog.close(); });
    bilddialog.addEventListener('click', function (e) { if (e.target === bilddialog) { bilddialog.close(); } });
    var dk = document.getElementById('dk');
    function dunkel(an) { body.classList.toggle('dunkel', an); try { localStorage.setItem('fa-handbuch-dunkel', an ? '1' : '0'); } catch (e) {} }
    try { if (localStorage.getItem('fa-handbuch-dunkel') === '0') { dunkel(false); } } catch (e) {}
    dk.addEventListener('click', function () { dunkel(!body.classList.contains('dunkel')); });
    var breitQuery = window.matchMedia('(min-width: 801px)');
    function layout() { app.classList.toggle('detail', !breitQuery.matches && aktiv !== ''); }
    if (breitQuery.addEventListener) { breitQuery.addEventListener('change', layout); } else { breitQuery.addListener(layout); }
    fromHash(); layout();
})();
</script>
</body>
</html>
