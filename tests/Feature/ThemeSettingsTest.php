<?php

namespace Tests\Feature;

use App\Filament\Pages\ThemeSettings;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin/theme-settings')->assertRedirect('/admin/login');
    }

    public function test_the_theme_can_be_saved(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm([
                'site_name' => 'Acme Studio',
                'color_primary' => '#ff0000',
                'font_headings' => 'Playfair Display',
                'footer_style' => 'light',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $theme = Theme::current();
        $this->assertSame('Acme Studio', $theme->site_name);
        $this->assertSame('#ff0000', $theme->color_primary);
        $this->assertSame('Playfair Display', $theme->font_headings);
        $this->assertSame('light', $theme->footer_style->value);
    }

    public function test_switching_footer_style_keeps_the_colors_chosen_for_the_other_styles(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['footer_style' => 'light', 'options' => ['footer_light_background' => '#eeeeee']]);

        Livewire::test(ThemeSettings::class)
            ->fillForm(['footer_style' => 'dark', 'options.footer_dark_background' => '#111111'])
            ->call('save')
            ->assertHasNoFormErrors();

        $options = Theme::current()->options;
        $this->assertSame('#eeeeee', $options['footer_light_background'] ?? null);
        $this->assertSame('#111111', $options['footer_dark_background'] ?? null);
    }

    public function test_the_site_timezone_is_saved_and_must_be_a_real_timezone(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['timezone' => 'Mars/Olympus_Mons'])
            ->call('save')
            ->assertHasFormErrors(['timezone']);

        Livewire::test(ThemeSettings::class)
            ->fillForm(['timezone' => 'America/Los_Angeles'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('America/Los_Angeles', Theme::current()->timezone);
    }

    public function test_the_theme_screen_looks_up_the_saved_theme_only_a_few_times(): void
    {
        $this->actingAs(User::factory()->create());
        DB::enableQueryLog();

        Livewire::test(ThemeSettings::class)->set('data.color_primary', '#123456');

        $themeQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "themes"'));
        $this->assertLessThanOrEqual(10, $themeQueries->count());
    }

    public function test_a_color_must_be_a_hex_code(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['color_primary' => 'red; } body { display: none'])
            ->call('save')
            ->assertHasFormErrors(['color_primary']);
    }

    public function test_only_fonts_from_the_list_can_be_chosen(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['font_body' => 'Comic Sans MS'])
            ->call('save')
            ->assertHasFormErrors(['font_body']);
    }

    public function test_a_jpg_can_be_the_browser_tab_icon(): void
    {
        Storage::fake('uploads');
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['favicon_path' => UploadedFile::fake()->image('icon.jpg', 64, 64)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringEndsWith('.jpg', Theme::current()->favicon_path);
    }

    public function test_reset_restores_the_default_look_but_keeps_the_site_name(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['site_name' => 'Acme Studio', 'color_primary' => '#ff0000', 'custom_css' => 'p {}']);

        Livewire::test(ThemeSettings::class)
            ->callAction('resetToDefaults')
            ->assertSchemaStateSet(['color_primary' => Theme::DEFAULTS['color_primary']]);

        $theme = Theme::current();
        $this->assertSame('Acme Studio', $theme->site_name);
        $this->assertSame(Theme::DEFAULTS['color_primary'], $theme->color_primary);
        $this->assertNull($theme->custom_css);
    }

    public function test_saving_a_screen_opened_before_the_theme_changed_saves_nothing_and_asks(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['color_primary' => '#111111', 'color_secondary' => '#222222']);

        $staleScreen = Livewire::test(ThemeSettings::class);

        Theme::current()->update(['color_secondary' => '#333333']);

        $staleScreen
            ->fillForm(['color_primary' => '#ff0000'])
            ->call('save')
            ->assertActionMounted('settingsChanged');

        $theme = Theme::current();
        $this->assertSame('#111111', $theme->color_primary);
        $this->assertSame('#333333', $theme->color_secondary);
    }

    public function test_a_stale_screen_can_load_the_latest_theme_and_then_save_normally(): void
    {
        $this->actingAs(User::factory()->create());

        $staleScreen = Livewire::test(ThemeSettings::class);

        Theme::current()->update(['color_secondary' => '#333333']);

        $staleScreen
            ->fillForm(['color_primary' => '#ff0000'])
            ->call('save')
            ->call('mountAction', 'loadLatestSettings')
            ->callMountedAction()
            ->assertActionNotMounted('settingsChanged')
            ->assertSchemaStateSet(['color_primary' => Theme::DEFAULTS['color_primary'], 'color_secondary' => '#333333'])
            ->fillForm(['color_primary' => '#00ff00'])
            ->call('save')
            ->assertActionNotMounted('settingsChanged');

        $theme = Theme::current();
        $this->assertSame('#00ff00', $theme->color_primary);
        $this->assertSame('#333333', $theme->color_secondary);
    }

    public function test_a_stale_screen_can_save_its_version_anyway(): void
    {
        $this->actingAs(User::factory()->create());

        $staleScreen = Livewire::test(ThemeSettings::class);

        Theme::current()->update(['color_secondary' => '#333333']);

        $staleScreen
            ->fillForm(['color_primary' => '#ff0000'])
            ->call('save')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $theme = Theme::current();
        $this->assertSame('#ff0000', $theme->color_primary);
        $this->assertSame(Theme::DEFAULTS['color_secondary'], $theme->color_secondary);
    }

    public function test_saving_repeatedly_and_after_a_reset_doesnt_warn(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['color_primary' => '#ff0000'])
            ->call('save')
            ->assertActionNotMounted('settingsChanged')
            ->fillForm(['color_primary' => '#00ff00'])
            ->call('save')
            ->assertActionNotMounted('settingsChanged')
            ->callAction('resetToDefaults')
            ->fillForm(['color_primary' => '#0000ff'])
            ->call('save')
            ->assertActionNotMounted('settingsChanged');

        $this->assertSame('#0000ff', Theme::current()->color_primary);
    }

    public function test_a_form_with_mistakes_shows_them_before_any_warning(): void
    {
        $this->actingAs(User::factory()->create());

        $staleScreen = Livewire::test(ThemeSettings::class);

        Theme::current()->update(['color_secondary' => '#333333']);

        $staleScreen
            ->fillForm(['color_primary' => 'red'])
            ->call('save')
            ->assertHasFormErrors(['color_primary'])
            ->assertActionNotMounted('settingsChanged');
    }

    public function test_the_admin_has_readable_hints(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/theme-settings')
            ->assertOk()
            ->assertSee('select.fi-select-input:has(> option[value=""]:checked)', false);
    }
}
