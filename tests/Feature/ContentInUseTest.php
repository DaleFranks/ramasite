<?php

namespace Tests\Feature;

use App\Filament\Resources\Cards\Pages\EditCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Modules\Pages\EditModule;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Form;
use App\Models\Module;
use App\Models\Slider;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Modules, cards, sliders and forms can't be deleted or renamed while content
 * places them, or that content would show the shortcode as written.
 */
class ContentInUseTest extends TestCase
{
    use RefreshDatabase;

    public function test_usages_name_the_pages_that_place_it_however_the_shortcode_is_written(): void
    {
        $offer = Card::factory()->create(['slug' => 'offer']);
        $other = Card::factory()->create(['slug' => 'offer-two']);
        Entry::factory()->create(['title' => 'Home', 'body' => '[cards: intro, OFFER.md]']);
        Entry::factory()->create(['title' => 'About', 'body' => 'Just text about our offer-two deal.']);

        $this->assertSame(['Page: Home'], $offer->usages()->all());
        $this->assertSame([], $other->usages()->all());
    }

    public function test_a_form_counts_as_used_in_modules_and_cards_but_a_slider_only_in_pages(): void
    {
        $form = Form::factory()->create(['slug' => 'contact']);
        $slider = Slider::factory()->create(['slug' => 'gallery']);
        Module::factory()->create(['name' => 'Footer box', 'body' => '[form:contact]']);
        Card::factory()->create(['name' => 'Gallery card', 'body' => '[slider:gallery]']);

        $this->assertSame(['Module: Footer box'], $form->usages()->all());
        $this->assertSame([], $slider->usages()->all());
    }

    public function test_one_in_use_cannot_be_deleted_and_an_unused_one_can(): void
    {
        $used = Module::factory()->create(['slug' => 'banner']);
        $unused = Module::factory()->create(['slug' => 'spare']);
        Entry::factory()->create(['body' => '[module:banner]']);

        $used->delete();
        $unused->delete();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_the_delete_button_says_where_it_is_used(): void
    {
        $this->actingAs(User::factory()->create());
        $card = Card::factory()->create(['name' => 'Offer', 'slug' => 'offer']);
        Entry::factory()->create(['title' => 'Home', 'body' => '[card:offer]']);

        Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Offer is in use and can\'t be deleted');

        $this->assertModelExists($card);
    }

    public function test_bulk_delete_leaves_the_ones_in_use(): void
    {
        $this->actingAs(User::factory()->create());
        $used = Card::factory()->create(['slug' => 'offer']);
        $unused = Card::factory()->create(['slug' => 'spare']);
        Entry::factory()->create(['body' => '[card:offer]']);

        Livewire::test(ListCards::class)->callTableBulkAction('delete', [$used, $unused]);

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_the_slug_cannot_change_while_in_use_but_everything_else_can(): void
    {
        $this->actingAs(User::factory()->create());
        $module = Module::factory()->create(['name' => 'Banner', 'slug' => 'banner']);
        Entry::factory()->create(['title' => 'Home', 'body' => '[module:banner]']);

        Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])
            ->fillForm(['slug' => 'big-banner'])
            ->call('save')
            ->assertHasFormErrors(['slug']);

        Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])
            ->fillForm(['name' => 'Top banner'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Top banner', 'banner'], [$module->refresh()->name, $module->slug]);
    }

    public function test_an_unused_ones_slug_can_change(): void
    {
        $this->actingAs(User::factory()->create());
        $form = Form::factory()->create(['slug' => 'contact']);

        Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
            ->fillForm(['slug' => 'get-in-touch'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('get-in-touch', $form->refresh()->slug);
    }
}
