{{-- 98 · 2.4d (U9): App-Dialog statt nativem confirm() für alle wire:confirm und x-fa::ki-action confirm.
     Eingebunden über shell/fa-menu-script, das BEIDE Hüllen laden (Modul-standalone, Host food-alchemist.de per @include,
     Plattform-Modus über shell/page-navbar). Darum hier und nicht im Layout.

     Wie: Livewire 4.3 fragt bei wire:confirm el.__livewire_confirm(action, instead). Ein Klick-Haken in der Capture-Phase
     ersetzt diese Funktion vor Livewires eigenem Klick durch den App-Dialog; „Löschen“/„Bestätigen“ ruft action() auf.
     wire:confirm.prompt bleibt nativ (anderer Attributname, wird nicht erfasst).

     SICHERHEIT (Leitplanke Kurator): Livewire-intern, kann bei einem Update still wegfallen. Darum ersetzt der Haken NUR,
     wenn el.__livewire_confirm eine Funktion ist; fehlt sie oder wirft der Haken, bleibt Livewires natives confirm
     unberührt. Es gibt keinen Weg, auf dem ohne Bestätigung ausgelöst wird: Der Ersatz ruft instead() (stoppt den Klick)
     und action() erst nach „Löschen“/„Bestätigen“. Fehlt die Vorlage, fragt faBestaetigen nativ.
     Versionsgurt: tests/Feature/AppDialogBestaetigenTest.php schlägt bei Livewire-Major/Minor-Wechsel fehl.

     Rot: data-fa-confirm="gefahr" | "normal" am Auslöser gewinnt; sonst Textsuche (löschen, entfernen, unwiderruflich). --}}
<template id="fa-bestaetigen-vorlage">
    <div class="fixed inset-0 flex items-center justify-center p-4 bg-black/40" data-fa-bestaetigen>
        <div role="alertdialog" aria-modal="true" aria-labelledby="fa-bestaetigen-text" class="fa-surface shadow-2xl w-full max-w-md p-5 flex flex-col gap-4">
            <p id="fa-bestaetigen-text" class="fa-t-body whitespace-pre-line" data-fa-bestaetigen-text></p>
            <div class="flex justify-end gap-2">
                <x-fa::button data-fa-bestaetigen-nein="">Abbrechen</x-fa::button>
                <x-fa::button variant="danger" data-fa-bestaetigen-ja="gefahr">Löschen</x-fa::button>
                <x-fa::button variant="primary" data-fa-bestaetigen-ja="normal">Bestätigen</x-fa::button>
            </div>
        </div>
    </div>
</template>
<script>
  (() => {
    if (window.faBestaetigen) return;
    const istGefahr = (text, art) => art === 'gefahr' || (art !== 'normal' && /l(ö|oe)sch|entfern|unwiderruflich/i.test(text));

    // faBestaetigen(text, ja, art?) — öffnet den App-Dialog; ja() nur nach Bestätigung. Ohne Vorlage: natives confirm.
    window.faBestaetigen = (text, ja, art) => {
      const vorlage = document.getElementById('fa-bestaetigen-vorlage');
      if (! vorlage || ! vorlage.content || ! vorlage.content.firstElementChild) { if (window.confirm(text)) ja(); return; }
      const huelle = vorlage.content.firstElementChild.cloneNode(true);
      huelle.style.zIndex = '1100';   // über Modals (z-[190]) und Toast (z-[200])
      huelle.querySelector('[data-fa-bestaetigen-text]').textContent = text;
      const gefahr = istGefahr(text, art);
      huelle.querySelector('[data-fa-bestaetigen-ja="' + (gefahr ? 'normal' : 'gefahr') + '"]').remove();
      const nein = huelle.querySelector('[data-fa-bestaetigen-nein]');
      const jaKnopf = huelle.querySelector('[data-fa-bestaetigen-ja]');
      const vorher = document.activeElement;
      const taste = (e) => {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); zu(false); }
        else if (e.key === 'Tab') { e.preventDefault(); (document.activeElement === nein ? jaKnopf : nein).focus(); }
      };
      const zu = (ok) => {
        huelle.remove();
        document.removeEventListener('keydown', taste, true);
        if (vorher && vorher.focus) vorher.focus();
        if (ok) ja();
      };
      nein.addEventListener('click', () => zu(false));
      jaKnopf.addEventListener('click', () => zu(true));
      huelle.addEventListener('click', (e) => { if (e.target === huelle) zu(false); });
      document.body.appendChild(huelle);
      document.addEventListener('keydown', taste, true);
      nein.focus();
    };

    // Haken: ersetzt Livewires Bestätigung NUR, wenn es sie gibt (sonst bleibt alles nativ).
    document.addEventListener('click', (e) => {
      try {
        const el = e.target && e.target.closest ? e.target.closest('[wire\\:confirm]') : null;
        if (! el || typeof el.__livewire_confirm !== 'function' || el.__livewire_confirm.__fa) return;
        const text = (el.getAttribute('wire:confirm') || '').replaceAll('\\n', '\n') || 'Wirklich fortfahren?';
        const ersatz = (action, instead) => { instead(); window.faBestaetigen(text, action, el.dataset.faConfirm); };
        ersatz.__fa = true;
        el.__livewire_confirm = ersatz;
      } catch (err) { /* Livewire-natives confirm bleibt */ }
    }, true);
  })();
</script>
