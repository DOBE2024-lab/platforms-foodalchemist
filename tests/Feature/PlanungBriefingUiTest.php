<?php

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\AusBrief;
use Platform\FoodAlchemist\Livewire\Planung\Brief;
use Platform\FoodAlchemist\Livewire\Planung\Index;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Support\Planung\DiktatZiele;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase C2, zweite Ebene: Briefing → Leitplanken IN DER OBERFLÄCHE. Bis hierher war die
 * Brücke nur per MCP erreichbar — „ein Produkt sollte das in sich über die UI lösen können"
 * (Dominique 2026-09-02). Diese Tests pinnen genau das, was per MCP nicht geprüft war:
 * dass der Vorschlag in den Reglern DIESES Tabs landet, formtreu, und dass nichts still
 * verschwindet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->stub = function (array $werte) {
        app()->bind(FakeAiProvider::class, fn () => new class($werte) extends FakeAiProvider
        {
            public function __construct(private array $werte)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return [
                    'content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'model' => 'fake-brief',
                    'tool_calls' => null,
                ];
            }
        });
    };
});

it('füllt die Leitplanken DIESES Tabs — und lässt die anderen Tabs unberührt', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'dinner', 'serviceform' => 'tellerservice', 'level' => 'gehoben']]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'Abendessen für 40 Gäste, gehoben, am Platz serviert')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('regler.gericht.occasion'))->toBe('dinner')
        ->and($c->get('regler.gericht.serviceform'))->toBe('tellerservice')
        ->and($c->get('regler.gericht.level'))->toBe('gehoben');

    // Tab-Unabhängigkeit (Kaskaden-Regel: am Go zählt NUR der Start-Tab).
    expect($c->get('regler.rezept.occasion'))->toBe(Index::REGLER_DEFAULT['occasion']);

    $befund = $c->get('leitplankenBefund');
    expect($befund['scope'])->toBe('gericht')
        ->and($befund['gesetzt'])->toContain('occasion');
});

it('Formtreue: ein Multi-Regler bleibt Array, auch wenn die KI einen String liefert', function () {
    // `diaet_hart` ist ein MULTI_REGLER — ein String darin würde reglerPill() zerlegen.
    ($this->stub)(['leitplanken' => ['diaet_hart' => 'vegan']]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'rein pflanzlich')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('regler.gericht.diaet_hart'))->toBe(['vegan']);
});

it('meldet erfundene Werte als verworfen und fremde Felder als »nicht auf diesem Tab«', function () {
    ($this->stub)(['leitplanken' => [
        'occasion' => 'Gala',                                        // erfunden → verworfen
        'serviceform' => 'buffet',                                   // gültig  → gesetzt
        'menue_quote_vegan_pct' => 30,                               // gültiger Sitzungs-Param, kein Regler
    ]]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'Gala-Buffet, 30 % vegan')
        ->call('leitplankenAusBriefing', 'gericht');

    $b = $c->get('leitplankenBefund');
    expect($b['gesetzt'])->toBe(['serviceform'])
        ->and($b['verworfen'])->toContain('occasion=Gala')
        ->and($b['ignoriert'])->toContain('menue_quote_vegan_pct')
        ->and($c->get('regler.gericht.serviceform'))->toBe('buffet');
});

it('Basisrezept-Tab: Pax × Portion wird zur Charge, scope-fremde Felder gemeldet, nur_bestand gesetzt (demo #138)', function () {
    ($this->stub)(['leitplanken' => [
        'pax' => 100, 'ziel_portion_g' => 80, 'level' => 'haute_cuisine',
        'serviceform' => 'tellerservice', 'bestand' => 'nur_bestand',
    ]]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.rezept.brief', 'Petersilienpüree, 100 pax à 80 g, haute cuisine, nur Bestand')
        ->call('leitplankenAusBriefing', 'rezept');

    expect($c->get('regler.rezept.ziel_menge'))->toBe(8.0)
        ->and($c->get('regler.rezept.ziel_einheit'))->toBe('kg')
        ->and($c->get('regler.rezept.bestand'))->toBe('nur_bestand')
        ->and($c->get('regler.rezept.pax'))->toBe(Index::REGLER_DEFAULT['pax'])
        ->and($c->get('regler.rezept.serviceform'))->toBe(Index::REGLER_DEFAULT['serviceform']);

    $b = $c->get('leitplankenBefund');
    expect($b['ignoriert'])->toContain('pax')->toContain('ziel_portion_g')->toContain('serviceform')
        ->and($b['gesetzt'])->toContain('ziel_menge')->toContain('bestand');
});

it('Gericht-Tab: »nur Bestand« aus dem Briefing landet im Kreativ-Modus (sichtbar), nicht im verdeckten Regler', function () {
    ($this->stub)(['leitplanken' => ['bestand' => 'nur_bestand', 'ziel_menge' => 3, 'ziel_einheit' => 'kg']]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'nur aus dem Bestand')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('eingabe.gericht.creative_mode'))->toBe('datenbank')
        ->and($c->get('leitplankenBefund')['ignoriert'])->toContain('ziel_menge');
});

it('der Leitplanken-Prompt erfährt, was im Tab entsteht', function () {
    $gesehen = new ArrayObject();
    app()->bind(FakeAiProvider::class, fn () => new class($gesehen) extends FakeAiProvider
    {
        public function __construct(private ArrayObject $gesehen)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->gesehen[] = json_encode($messages, JSON_UNESCAPED_UNICODE);

            return [
                'content' => json_encode(['werte' => ['leitplanken' => ['level' => 'gehoben']], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake-brief', 'tool_calls' => null,
            ];
        }
    });

    Livewire::test(Brief::class)->set('eingabe.rezept.brief', 'Sauce')->call('leitplankenAusBriefing', 'rezept');

    expect(implode("\n", $gesehen->getArrayCopy()))->toContain('BASISREZEPT')->toContain('ziel_menge');
});

it('offene Punkte werden zur Rückfrage, nicht zur Annahme', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'lunch'], 'unklar' => ['Wie viele Gäste?', 'Budget pro Person?']]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'Mittagessen im Sommer')
        ->call('leitplankenAusBriefing', 'gericht');

    // Nur die Property, nicht das Markup: `Index` rendert den Erstell-Tab erst im
    // Editor-Zustand mit Sitzung — ohne den kommt die Landing-Ansicht. Dass das Panel
    // im Partial existiert, pinnt der letzte Test dieser Datei.
    expect($c->get('leitplankenBefund')['unklar'])->toBe(['Wie viele Gäste?', 'Budget pro Person?'])
        ->and($c->get('leitplankenBefund')['gesetzt'])->toBe(['occasion']);
});

it('ohne Briefing kein Call — Fehler statt leerem KI-Aufruf', function () {
    ($this->stub)(['leitplanken' => ['occasion' => 'dinner']]);

    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', '   ')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('fehler'))->toContain('erst ein Briefing')
        ->and($c->get('leitplankenBefund'))->toBeNull()
        ->and($c->get('regler.gericht.occasion'))->toBe(Index::REGLER_DEFAULT['occasion']);
});

it('Diktat hängt an und überschreibt ein bestehendes Briefing NIE', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'und bitte glutenfrei']);
    expect(app(SttServiceContract::class)->transcribe('BLOB'))->toBe('und bitte glutenfrei');

    // Echter Upload-Pfad: `set` löst den updated-Hook aus, der Hook delegiert. Spec 85 C1 PR-5: der Recorder lebt mit den
    // Ausgabe-Briefings im Kind Planung\AusBrief (Logik in Support\Planung\DiktatZiele).
    $c = Livewire::test(AusBrief::class)
        ->set('fbBrief', 'Tomatensauce, klassisch')
        ->set('diktatZiel', 'fbBrief')
        ->set('briefAudio', UploadedFile::fake()->create('diktat.webm', 1, 'audio/webm'));

    // Angehängt, nicht ersetzt — ein überschriebenes Briefing wäre nicht wiederherstellbar.
    expect($c->get('fbBrief'))->toBe('Tomatensauce, klassisch und bitte glutenfrei')
        ->and($c->get('briefAudio'))->toBeNull();                    // Blob nach der Übernahme freigegeben

    // Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): der Recorder spricht den Agenten nicht an;
    // die Agenten-Kopplung läuft über das Panel-Mikro selbst (VoiceInterfaceTest „updatedAudio() dispatcht …").
    $c->assertNotDispatched('voice.diktat-transkribiert');
});

/**
 * Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): Richtung umgekehrt zu
 * oben — jetzt liefert das Agent-Panel (VoiceModal) sein eigenes Rohtranskript per Event AN
 * Planung\Index, die es genau wie briefDiktatUebernehmen() anhängt statt zu überschreiben.
 */
it('Nachtrag: agentDiktatUebernehmen() hängt das Panel-Transkript an — nie überschreiben, nur eigener Scope', function () {
    // Spec 85 C1 PR-5: das Panel steckt im Kind Planung\Brief, das Event kommt direkt dort an.
    $c = Livewire::test(Brief::class)
        ->set('eingabe.gericht.brief', 'Filet vom Kalb')
        ->dispatch('voice.diktat-transkribiert', scope: 'gericht', text: 'mit Kräuterkruste');

    expect($c->get('eingabe.gericht.brief'))->toBe('Filet vom Kalb mit Kräuterkruste');

    // Whitelist: ein unbekannter/fremder Scope-Name schreibt nichts.
    $c2 = Livewire::test(Brief::class)
        ->set('eingabe.rezept.brief', 'Unberührt')
        ->dispatch('voice.diktat-transkribiert', scope: 'unbekannt', text: 'sollte nirgends landen');

    expect($c2->get('eingabe.rezept.brief'))->toBe('Unberührt');

    // Leeres Transkript ist ein No-op, kein leerer Anhängsel-Space.
    $c3 = Livewire::test(Brief::class)
        ->set('eingabe.concept.brief', 'Sommer-Menü')
        ->dispatch('voice.diktat-transkribiert', scope: 'concept', text: '   ');

    expect($c3->get('eingabe.concept.brief'))->toBe('Sommer-Menü');
});

it('Nachtrag: der Erstell-Tab hat pro Scope genau EINEN Diktier-Weg — Recorder aus, Leitplanken-Knopf bleibt', function () {
    $session = app(\Platform\FoodAlchemist\Services\Planung\PlanningSessionService::class)
        ->create($this->rootTeam, ['title' => 'Event']);

    $html = Livewire::test(Brief::class, ['sessionId' => $session->id])->html();   // Spec 85 C1 PR-4: Erstell-Reiter im Kind

    // Kein Mikro-Recorder mehr an den drei Creation-Scope-Briefings (das Panel ist die
    // einzige Diktierfunktion dort) — der Leitplanken-Knopf bleibt unabhängig davon stehen.
    foreach (['rezept', 'gericht', 'concept'] as $scope) {
        expect($html)->not->toContain('data-planung-diktat="eingabe.' . $scope . '.brief"')
            ->and($html)->toContain('data-planung-leitplanken-vorschlag="' . $scope . '"');
    }
});

it('Diktat ohne Blob fasst nichts an', function () {
    $c = Livewire::test(AusBrief::class)   // Spec 85 C1 PR-5
        ->set('fbBrief', 'Tomatensauce, klassisch')
        ->call('briefDiktatUebernehmen');

    expect($c->get('fbBrief'))->toBe('Tomatensauce, klassisch')
        ->and($c->get('fehler'))->toBeNull();
});

it('Die UI-Fläche trägt beide Knöpfe — Diktat und Leitplanken', function () {
    $tab = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/erstellen-tab.blade.php');
    $diktat = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/diktat.blade.php');

    // Die Knöpfe liegen seit dem Umzug im GETEILTEN Baustein (ein Recorder statt sechs
    // Kopien) — der Erstell-Tab bindet ihn nur ein.
    expect($tab)->toContain("partials.diktat")
        ->and($tab)->toContain('data-planung-leitplanken-befund');
    expect($diktat)->toContain('data-planung-diktat')
        ->and($diktat)->toContain('data-planung-leitplanken-vorschlag')
        ->and($diktat)->toContain("wire:click=\"leitplankenAusBriefing('{{ \$mitLeitplanken }}')\"");
});

/*
 * Das Diktat sitzt an ALLEN Briefing-Feldern der Planungsstelle, nicht nur an den drei
 * Erstell-Scopes (Dominique 2026-09-02: „in der planungsstelle" — der Concept-Tab und die
 * fünf Ausgabeformen hatten keinen Knopf). Das Ziel kommt aus dem Blade und damit vom
 * CLIENT — deshalb läuft es gegen eine Whitelist.
 */
it('Diktat trifft jedes Briefing-Feld der Planungsstelle', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Empfang für 80 Gäste']);

    foreach (['fbBrief', 'skBrief', 'spBrief', 'offerBrief', 'fmtBrief'] as $feld) {
        $c = Livewire::test(AusBrief::class)   // Spec 85 C1 PR-5: Ausgabe-Briefings im Kind
            ->set('diktatZiel', $feld)
            ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'));

        expect($c->get($feld))->toBe('Empfang für 80 Gäste', "Diktat erreicht {$feld} nicht");
    }
});

it('Erstell-Reiter (auch Konzept) diktieren nur über das Agenten-Panel — kein Recorder-Ziel mehr', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Sommer-Menü, vier Gänge']);

    // Spec 85 C1 PR-5: Recorder-Ziele sind nur noch die fünf Ausgabe-Briefings (DiktatZiele::ZIELE). Die Erstell-Reiter hatten
    // seit der Kurskorrektur 2026-09-19 keinen Recorder mehr (mitRecorder=false); ihr Diktat kommt über voice.diktat-transkribiert.
    expect(DiktatZiele::ZIELE)->not->toContain('eingabe.concept.brief');
    Livewire::test(AusBrief::class)
        ->set('diktatZiel', 'eingabe.concept.brief')
        ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'))
        ->assertSet('fehler', 'Unbekanntes Diktat-Ziel — nichts übernommen.');
    Livewire::test(Brief::class)
        ->dispatch('voice.diktat-transkribiert', scope: 'concept', text: 'Sommer-Menü, vier Gänge')
        ->assertSet('eingabe.concept.brief', 'Sommer-Menü, vier Gänge');
});

it('Whitelist: ein fremdes Diktat-Ziel schreibt NICHTS und meldet es', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'foodalchemist.stt.fake_text' => 'Text']);

    // `diktatZiel` ist eine Livewire-Property und damit vom Client setzbar. Ohne Whitelist
    // liesse sich damit jede beliebige Property der Komponente überschreiben.
    $c = Livewire::test(AusBrief::class)   // Spec 85 C1 PR-5
        ->set('fbTitel', 'Unberührt')
        ->set('diktatZiel', 'fbTitel')
        ->set('briefAudio', UploadedFile::fake()->create('d.webm', 1, 'audio/webm'));

    expect($c->get('fbTitel'))->toBe('Unberührt')
        ->and($c->get('fehler'))->toContain('Diktat-Ziel');
});

it('Die Whitelist deckt genau die Felder ab, die im Blade auch einen Knopf haben', function () {
    // Spec 85 C1 PR-5: die Ausgabe-Briefings mit Recorder liegen in aus-brief.blade; der Concept-Reiter (brief.blade) bindet den
    // Baustein ohne Recorder ein (nur Leitplanken-Knopf) und steht für die Zeilen-Regel unten mit drin.
    $index = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/aus-brief.blade.php');
    $index .= "\n" . file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/brief.blade.php');
    $tab = file_get_contents(__DIR__ . '/../../resources/views/livewire/planung/partials/erstellen-tab.blade.php');

    // Die fünf Ausgabeformen stehen literal im Blade (Recorder-Ziele); Concept literal, rezept/gericht dynamisch über $scope
    // aus dem Erstell-Tab — dort ohne Recorder (Agenten-Panel).
    expect($index)->toContain("'ziel' => 'eingabe.concept.brief'");
    foreach (DiktatZiele::ZIELE as $ziel) {
        // toContain ist VARIADISCH (mehrere Nadeln) — ein zweites Argument wäre ein
        // weiterer Suchbegriff, keine Meldung. Darum die Erwartung nackt und der
        // Kontext im Kommentar. (Selbst hineingelaufen, 2026-09-02.)
        expect($index)->toContain("'ziel' => '{$ziel}'");
    }
    expect($tab)->toContain("'ziel' => 'eingabe.' . \$scope . '.brief'");
    // Und der Baustein muss NACH dem schliessenden </textarea> stehen. Mitten im
    // ÖFFNENDEN Tag hat er die Ein-Wurzel-Regel von Livewire gebrochen („Multiple root
    // elements") — mehrzeilige textarea-Tags sind die Falle, selbst hineingelaufen
    // 2026-09-02. Zeilenweise statt per Regex: lesbar und ohne Escape-Fallen.
    $zeilen = explode("\n", $index);
    foreach ($zeilen as $nr => $zeile) {
        if (! str_contains($zeile, 'partials.diktat')) {
            continue;
        }
        $davor = $zeilen[$nr - 1] ?? '';
        // fa-pass (2026-10-05): das Briefing-Feld steckt jetzt im Baustein x-fa::field (Label + Feld),
        // dessen schliessendes Tag ist dann die Zeile davor. Gleiche Absicht: der Include steht HINTER
        // einem geschlossenen Element, nie in einem offenen Tag.
        expect(str_contains($davor, '</textarea>') || str_contains($davor, '</x-fa::field>'))->toBeTrue(
            'Diktat-Include in Zeile ' . ($nr + 1) . ' steht nicht direkt nach </textarea> bzw. </x-fa::field>');
    }
    // Spec 85 C1 PR-5: rezept/gericht (und concept) diktieren ohne Recorder über das Agenten-Panel → keine Recorder-Ziele.
    expect(DiktatZiele::ZIELE)->not->toContain('eingabe.rezept.brief')->not->toContain('eingabe.gericht.brief');
});
