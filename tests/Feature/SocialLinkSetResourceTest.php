<?php

namespace Tests\Feature;

use App\Filament\Resources\SocialLinkSets\Pages\CreateSocialLinkSet;
use App\Filament\Resources\SocialLinkSets\Pages\EditSocialLinkSet;
use App\Filament\Resources\SocialLinkSets\Pages\ListSocialLinkSets;
use App\Filament\Resources\SocialLinkSets\Schemas\SocialLinkSetForm;
use App\Models\Image;
use App\Models\SocialLinkSet;
use App\Models\User;
use App\SocialLogoStyle;
use App\SocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SocialLinkSetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        auth()->logout();

        $this->get('/admin/social-links')->assertRedirect('/admin/login');
    }

    public function test_a_new_site_has_no_social_links(): void
    {
        $this->assertSame(0, SocialLinkSet::query()->count());
        $this->get('/admin/social-links')->assertOk();
    }

    public function test_the_list_shows_sets(): void
    {
        $sets = SocialLinkSet::factory()->count(2)->create();

        Livewire::test(ListSocialLinkSets::class)->assertCanSeeTableRecords($sets);
    }

    public function test_a_set_is_built_with_its_links_in_order(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Footer Icons',
                'logo_style' => SocialLogoStyle::Light,
                'logo_size' => 40,
                'opens_in_new_tab' => false,
                'links' => [
                    ['service' => SocialService::Instagram, 'url' => 'https://www.instagram.com/example'],
                    ['service' => SocialService::Email, 'url' => 'hello@example.com'],
                ],
            ])
            ->assertSchemaStateSet(['slug' => 'footer-icons'])
            ->call('create')
            ->assertHasNoFormErrors();

        $set = SocialLinkSet::query()->where('slug', 'footer-icons')->sole();
        $this->assertSame([SocialLogoStyle::Light, 40, false], [$set->logo_style, $set->logo_size, $set->opens_in_new_tab]);
        $this->assertSame(
            ['https://www.instagram.com/example', 'mailto:hello@example.com'],
            $set->socialLinks()->pluck('href')->all(),
        );
    }

    public function test_a_set_needs_at_least_one_link(): void
    {
        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm(['name' => 'Empty', 'links' => []])
            ->call('create')
            ->assertHasFormErrors(['links']);
    }

    public function test_links_must_be_web_addresses_and_email_links_email_addresses(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Main',
                'links' => [
                    ['service' => SocialService::Facebook, 'url' => 'javascript:alert(1)'],
                    ['service' => SocialService::X, 'url' => 'x.com/example'],
                    ['service' => SocialService::Email, 'url' => 'not an address'],
                    ['service' => SocialService::GitHub, 'url' => 'https://github.com/example'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['links.0.url', 'links.1.url', 'links.2.url'])
            ->assertHasNoFormErrors(['links.3.url']);
    }

    public function test_a_custom_style_needs_a_color(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Main',
                'logo_style' => SocialLogoStyle::Custom,
                'logo_color' => null,
                'links' => [['service' => SocialService::Facebook, 'url' => 'https://www.facebook.com/example']],
            ])
            ->call('create')
            ->assertHasFormErrors(['logo_color' => 'required']);
    }

    public function test_the_logo_size_must_be_within_its_range(): void
    {
        $this->fakeRepeaters();

        foreach ([SocialLinkSet::MINIMUM_LOGO_SIZE - 1, SocialLinkSet::MAXIMUM_LOGO_SIZE + 1] as $size) {
            Livewire::test(CreateSocialLinkSet::class)
                ->fillForm([
                    'name' => 'Main',
                    'logo_size' => $size,
                    'links' => [['service' => SocialService::Facebook, 'url' => 'https://www.facebook.com/example']],
                ])
                ->call('create')
                ->assertHasFormErrors(['logo_size']);
        }
    }

    public function test_a_linkedin_link_needs_a_logo_from_the_image_library(): void
    {
        $this->fakeRepeaters();
        $logo = Image::factory()->create();

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Main',
                'links' => [['service' => SocialService::LinkedIn, 'url' => 'https://www.linkedin.com/in/example']],
            ])
            ->assertSee('LinkedIn needs its own logo file.')
            ->assertSee(SocialLinkSetForm::LINKEDIN_DOWNLOADS_URL)
            ->call('create')
            ->assertHasFormErrors(['links.0.image_id' => 'required']);

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Main',
                'links' => [['service' => SocialService::LinkedIn, 'url' => 'https://www.linkedin.com/in/example', 'image_id' => $logo->id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($logo->id, SocialLinkSet::query()->sole()->socialLinks()->first()['image']->id);
    }

    public function test_the_linkedin_notice_shows_only_for_linkedin(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateSocialLinkSet::class)
            ->fillForm([
                'name' => 'Main',
                'links' => [['service' => SocialService::Facebook, 'url' => 'https://www.facebook.com/example']],
            ])
            ->assertDontSee('LinkedIn needs its own logo file.');
    }

    public function test_searching_services_looks_at_their_names_only(): void
    {
        $this->assertSame(['patreon', 'tripadvisor'], array_keys(SocialLinkSetForm::serviceOptions('tr')));
        $this->assertCount(count(SocialService::cases()), SocialLinkSetForm::serviceOptions());
    }

    public function test_an_unused_set_can_be_deleted(): void
    {
        $set = SocialLinkSet::factory()->create();

        Livewire::test(EditSocialLinkSet::class, ['record' => $set->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($set);
    }
}
