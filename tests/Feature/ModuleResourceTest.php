<?php

namespace Tests\Feature;

use App\BandEdge;
use App\BoxStyle;
use App\Filament\Resources\Modules\Pages\CreateModule;
use App\Filament\Resources\Modules\Pages\EditModule;
use App\Filament\Resources\Modules\Pages\ListModules;
use App\Models\Image;
use App\Models\Module;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ModuleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin/modules')->assertRedirect('/admin/login');
    }

    public function test_the_list_always_includes_the_footer(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListModules::class)
            ->assertCanSeeTableRecords([Module::footer()]);
    }

    public function test_creating_a_module_fills_the_slug_from_the_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateModule::class)
            ->fillForm(['name' => 'Call to Action', 'body' => 'Get in touch.', 'style' => BoxStyle::Dark])
            ->assertSchemaStateSet(['slug' => 'call-to-action'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('modules', ['slug' => 'call-to-action', 'body' => 'Get in touch.', 'style' => 'dark']);
    }

    public function test_slugs_must_be_unique(): void
    {
        $this->actingAs(User::factory()->create());
        Module::factory()->create(['slug' => 'newsletter']);

        Livewire::test(CreateModule::class)
            ->fillForm(['name' => 'Newsletter', 'slug' => 'newsletter'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_the_footers_text_can_be_edited_but_not_its_slug_and_it_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $footer = Module::footer();

        Livewire::test(EditModule::class, ['record' => $footer->getRouteKey()])
            ->assertFormFieldDisabled('slug')
            ->assertFormFieldHidden('style')
            ->assertActionHidden(DeleteAction::class)
            ->fillForm(['body' => '[navigation:multi]'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('[navigation:multi]', $footer->refresh()->body);
        $this->assertSame(Module::FOOTER_SLUG, $footer->slug);
    }

    public function test_a_module_saves_its_background_image_and_edge_shapes(): void
    {
        $this->actingAs(User::factory()->create());
        $image = Image::factory()->create();
        $module = Module::factory()->create();

        Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])
            ->fillForm([
                'background_image_id' => $image->id,
                'top_edge' => BandEdge::Wave->value,
                'bottom_edge' => BandEdge::SlantRight->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $module->refresh();
        $this->assertTrue($module->backgroundImage->is($image));
        $this->assertSame(BandEdge::Wave, $module->top_edge);
        $this->assertSame(BandEdge::SlantRight, $module->bottom_edge);
    }

    public function test_the_footer_has_no_background_image_or_edge_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditModule::class, ['record' => Module::footer()->getRouteKey()])
            ->assertFormFieldHidden('background_image_id')
            ->assertFormFieldHidden('top_edge')
            ->assertFormFieldHidden('bottom_edge');
    }
}
