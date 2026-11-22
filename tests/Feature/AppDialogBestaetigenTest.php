<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Plan 98, 2.4d (U9): App-Dialog statt nativem confirm() für wire:confirm und x-fa::ki-action confirm
 * (shell/fa-bestaetigen, eingebunden über shell/fa-menu-script). Ohne JS-Runner prüfbar: Einbindung, Vorlage,
 * Sicherheitsvertrag des Hakens und der Versionsgurt gegen Livewire-Updates.
 */
/** Nur das Skript, ohne Kommentare — Zählungen sollen Code treffen, nicht Erklärtext. */
function bestaetigenQuelle(): string
{
    $q = file_get_contents(__DIR__ . '/../../resources/views/components/shell/fa-bestaetigen.blade.php');
    $skript = substr($q, strpos($q, '<script>'));

    return preg_replace(['~/\*.*?\*/~s', '~(^|\s)//[^\n]*~'], ['', '$1'], $skript);
}

it('Versionsgurt: Livewire steht auf 4.3 — sonst App-Dialog-Haken prüfen', function () {
    $version = ltrim((string) InstalledVersions::getPrettyVersion('livewire/livewire'), 'v');
    [$major, $minor] = array_map('intval', array_slice(explode('.', $version), 0, 2)) + [0, 0];

    expect("{$major}.{$minor}")->toBe('4.3', "Livewire {$version}: App-Dialog-Haken in fa-menu-script (shell/fa-bestaetigen) gegen neue Livewire-Version prüfen — el.__livewire_confirm(action, instead) ist Livewire-intern. Danach diesen Test auf die neue Version setzen.");
});

it('fa-menu-script bindet den App-Dialog ein — beide Hüllen und der Host laden genau diese Datei', function () {
    $html = Blade::render('<x-foodalchemist::shell.fa-menu-script />');

    expect($html)->toContain('<template id="fa-bestaetigen-vorlage">')
        ->toContain('role="alertdialog"')
        ->toContain('data-fa-bestaetigen-nein=""')
        ->toContain('data-fa-bestaetigen-ja="gefahr"')
        ->toContain('data-fa-bestaetigen-ja="normal"')
        ->toContain('window.faBestaetigen')
        ->toContain('faMenu')
        ->and(file_get_contents(__DIR__ . '/../../resources/views/layouts/standalone.blade.php'))->toContain('shell.fa-menu-script')
        ->and(file_get_contents(__DIR__ . '/../../resources/views/components/shell/page-navbar.blade.php'))->toContain('shell.fa-menu-script');
});

it('Sicherheitsvertrag: Haken ersetzt nur, wenn Livewires Funktion existiert; Klick wird gestoppt, ausgelöst erst nach Bestätigung', function () {
    $q = bestaetigenQuelle();

    expect($q)->toContain("typeof el.__livewire_confirm !== 'function'")                       // fehlt sie: nichts ersetzen, nativ bleibt
        ->toContain('} catch (err) {')                                                           // Haken wirft: nativ bleibt
        ->toContain('const ersatz = (action, instead) => { instead(); window.faBestaetigen(text, action, el.dataset.faConfirm); };')
        ->toContain("closest('[wire\\\\:confirm]')")                                            // .prompt-Variante (anderer Name) bleibt nativ
        ->toContain('if (window.confirm(text)) ja(); return;')                                   // ohne Vorlage: nativ
        ->toContain('if (ok) ja();')                                                             // ja() nur über „Löschen“/„Bestätigen“
        ->and(substr_count($q, 'action('))->toBe(0)                                             // action nie direkt aufgerufen, nur weitergereicht
        ->and(substr_count($q, 'ja()'))->toBe(2);                                               // nativer Rückfall + bestätigtes Schließen
});

it('Rot: data-fa-confirm gewinnt vor der Textsuche', function () {
    expect(bestaetigenQuelle())->toContain("art === 'gefahr' || (art !== 'normal' && /l(ö|oe)sch|entfern|unwiderruflich/i.test(text))");
});

it('x-fa::ki-action confirm fragt über den App-Dialog, ohne ihn nativ, und gibt data-fa-confirm weiter', function () {
    $html = Blade::render('<x-fa::ki-action action="kiLauf" label="Neu schreiben" confirm="Text ersetzen?" data-fa-confirm="gefahr" />');

    expect($html)->toContain('data-ki-confirm="Text ersetzen?"')
        ->toContain('window.faBestaetigen($el.dataset.kiConfirm')
        ->toContain('if (! window.confirm($el.dataset.kiConfirm)) { return }')
        ->toContain('data-fa-confirm="gefahr"');
});
