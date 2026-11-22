<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/** Plan 98, 2.4a PR C: x-fa::upload, x-fa::segmented/segment, x-fa::page. */
it('x-fa::upload rendert ein Dateifeld mit Wählknopf je Größe und reicht Attribute durch', function () {
    $sm = Blade::render('<x-fa::upload wire:model="logo" accept="image/*" class="block w-full" data-test-upload="" />');
    $md = Blade::render('<x-fa::upload size="md" wire:model="datei" multiple="" />');

    expect($sm)->toContain('type="file"')->toContain('file:h-7')->toContain('file:bg-[var(--fa-surface)]')
        ->toContain('block w-full')->toContain('wire:model="logo"')->toContain('data-test-upload=""')
        ->and($md)->toContain('file:h-9')->toContain('text-[length:var(--fa-text-md)]')->toContain('multiple=""')
        ->and(fn () => Blade::render('<x-fa::upload size="xl" />'))->toThrow(Exception::class, 'size="xl"');
});

it('x-fa::segmented ist eine benannte Gruppe, x-fa::segment trägt den Zustand in aria-pressed und Optik', function () {
    $html = Blade::render(<<<'BLADE'
        <x-fa::segmented label="Ansicht" :breit="true" class="self-start" data-test-seg="">
            <x-fa::segment :an="true" wire:click="a">A</x-fa::segment>
            <x-fa::segment :an="false" size="md" class="flex-1">B</x-fa::segment>
        </x-fa::segmented>
        BLADE);

    expect($html)->toMatch('/<div role="group"\s+aria-label="Ansicht"\s+class="flex p-0\.5 gap-0\.5 rounded-\[var\(--fa-radius-control\)\] bg-\[var\(--fa-neutral-soft\)\] self-start" data-test-seg="">/')
        ->and($html)->toMatch('/aria-pressed="true" class="h-7 px-3 [^"]*bg-\[var\(--fa-surface\)\] text-\[var\(--fa-ink\)\] shadow-sm" wire:click="a"/')
        ->and($html)->toMatch('/aria-pressed="false" class="h-8 px-3 [^"]*text-\[var\(--fa-ink-2\)\] hover:text-\[var\(--fa-ink\)\] flex-1"/');
});

it('x-fa::segment mit an-alpine bindet Klasse und aria-pressed an Alpine', function () {
    $html = Blade::render('<x-fa::segment an-alpine="tab === \'gp\'" x-on:click="tab = \'gp\'">GP</x-fa::segment>');

    expect($html)->toContain('x-bind:class="(tab === &#039;gp&#039;) ? ')
        ->toContain('x-bind:aria-pressed="(tab === &#039;gp&#039;) ? &#039;true&#039; : &#039;false&#039;"')
        ->not->toContain(' aria-pressed="');
});

it('x-fa::page: Standard, eingebettet und ohne Abstand', function () {
    expect(trim(Blade::render('<x-fa::page data-p="">X</x-fa::page>')))
        ->toBe('<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-p="">X</div>')
        ->and(trim(Blade::render('<x-fa::page :eingebettet="true">X</x-fa::page>')))->toBe('<div class="flex flex-col gap-3">X</div>')
        ->and(trim(Blade::render('<x-fa::page :abstand="false" class="pb-6">X</x-fa::page>')))->toBe('<div class="min-h-full bg-[var(--fa-ground)] pb-6">X</div>');
});
