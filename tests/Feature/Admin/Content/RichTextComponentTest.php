<?php

use App\Models\User;

beforeEach(function () {
    seedRolesAndPermissions();
});

/**
 * The config the component hands to the richText Alpine component.
 *
 * @return array<string, mixed>
 */
function richTextConfig(string $html): array
{
    // @js() prints an array as JSON.parse('…') with the JSON escaped as a JavaScript string.
    preg_match("/x-data=\"richText\\(JSON\\.parse\\('(.*?)'\\)\\)\"/s", $html, $matches);

    return json_decode(json_decode('"'.$matches[1].'"', flags: JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

it('binds to the wire:model property without putting wire:model on the page', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $view = $this->blade('<x-admin.rich-text wire:model="form.body" label="Body" locale="uk" :images="[12 => \'/storage/media/a.webp\']" />');

    $view->assertDontSee('wire:model', false)
        ->assertSee('wire:ignore', false)
        ->assertSee('data-test="rich-text-image"', false)
        ->assertSee('role="toolbar"', false)
        ->assertSee(__('content.editor.bold'));

    expect(richTextConfig((string) $view))->toBe([
        'model' => 'form.body',
        'target' => 'rich-text:form.body',
        'images' => ['12' => '/storage/media/a.webp'],
        'readonly' => false,
        'locale' => 'uk',
        'labelledBy' => 'rich-text-form-body-label',
    ]);
});

it('offers images only with media.view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->blade('<x-admin.rich-text wire:model="form.body" />')
        ->assertSee('data-test="rich-text-bold"', false)
        ->assertDontSee('data-test="rich-text-image"', false);
});

it('shows no toolbar and does not listen for picks when read-only', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $view = $this->blade('<x-admin.rich-text wire:model="form.body" :readonly="true" />');

    $view->assertDontSee('role="toolbar"', false)
        ->assertDontSee('media-picker:picked', false)
        ->assertDontSee('data-test="rich-text-link-form"', false);

    expect(richTextConfig((string) $view)['readonly'])->toBeTrue();
});
