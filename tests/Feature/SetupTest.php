<?php

namespace Tests\Feature;

use App\DatabaseUpdate;
use App\Installation;
use App\Models\Entry;
use App\Models\Form;
use App\Models\Theme;
use App\Models\User;
use App\SetupKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.installation_check' => true]);

        // A storage folder of its own, so the real site's "installed" marker is never touched
        $this->storagePath = sys_get_temp_dir().'/ramasite-setup-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storagePath.'/app');
        $this->app->useStoragePath($this->storagePath);

        // The same for the settings file and setup key, so the real ones are never touched
        $this->app->useEnvironmentPath($this->storagePath);
        File::put($this->storagePath.'/.env', "APP_NAME=Acme\nAPP_URL=http://first-visit.test\nLOG_LEVEL=error\n");

        // The database these tests use is already up to date
        DatabaseUpdate::markApplied();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storagePath);

        parent::tearDown();
    }

    private function setUpDetails(array $overrides = []): array
    {
        SetupKey::create();

        return [
            'setup_key' => $this->setupKey(),
            'site_name' => 'Acme Studio',
            'name' => 'Ann Admin',
            'email' => 'ann@acme.test',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            ...$overrides,
        ];
    }

    private function setupKey(): string
    {
        return trim(File::get($this->keyFile()));
    }

    private function keyFile(): string
    {
        return $this->storagePath.'/'.SetupKey::FILE_NAME;
    }

    public function test_the_setup_screen_creates_the_tables_when_the_database_needs_them(): void
    {
        File::delete($this->storagePath.'/app/database-version');
        Artisan::spy();

        $this->get('/setup')->assertOk();

        Artisan::shouldHaveReceived('call')->with('migrate', ['--force' => true])->once();
        $this->assertFalse(DatabaseUpdate::isNeeded());
    }

    public function test_the_setup_screen_leaves_tables_that_are_already_up_to_date_alone(): void
    {
        Artisan::spy();

        $this->get('/setup')->assertOk();
        $this->get('/setup')->assertOk();

        Artisan::shouldNotHaveReceived('call');
    }

    public function test_until_setup_is_done_every_visit_goes_to_the_setup_screen(): void
    {
        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get('/about')->assertRedirect(route('setup.show'));
        $this->get('/admin/login')->assertRedirect(route('setup.show'));
        $this->get('/up')->assertOk();
    }

    public function test_the_setup_screen_asks_for_the_site_name_and_an_admin_account(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee('Set up your site')
            ->assertSee('name="site_name"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_opening_the_setup_screen_creates_a_key_and_keeps_it_on_later_visits(): void
    {
        $this->get('/setup')->assertOk()->assertSee('name="setup_key"', false)->assertSee(SetupKey::FILE_NAME);

        $key = $this->setupKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);

        $this->get('/setup')->assertOk()->assertDontSee($key);
        $this->assertSame($key, $this->setupKey());
    }

    public function test_a_deleted_key_file_is_replaced_when_the_setup_screen_is_reopened(): void
    {
        $this->get('/setup');
        $oldKey = $this->setupKey();
        File::delete($this->keyFile());

        $this->get('/setup')->assertOk();

        $this->assertNotSame($oldKey, $this->setupKey());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedKeys(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'wrong' => ['0123456789abcdef0123456789abcdef'],
            'not text' => [['a key']],
        ];
    }

    #[DataProvider('refusedKeys')]
    public function test_setup_without_the_right_key_creates_nothing($key): void
    {
        $details = $this->setUpDetails(['setup_key' => $key]);

        $this->from('/setup')->post('/setup', $details)
            ->assertRedirect('/setup')
            ->assertSessionHasErrors('setup_key');

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
        $this->assertFalse(Installation::isComplete());
        $this->assertFileExists($this->keyFile());
        $this->assertStringContainsString('APP_URL=http://first-visit.test', File::get($this->storagePath.'/.env'));
    }

    public function test_setup_is_refused_when_there_is_no_key_file_at_all(): void
    {
        $details = $this->setUpDetails();
        File::delete($this->keyFile());

        $this->from('/setup')->post('/setup', $details)->assertSessionHasErrors('setup_key');

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_wrong_key_shows_a_message_and_keeps_what_was_typed(): void
    {
        $this->from('/setup')->post('/setup', $this->setUpDetails(['setup_key' => 'wrong-key']));

        $this->get('/setup')
            ->assertSee('Open the file again and copy all of it.')
            ->assertSee('value="Acme Studio"', false)
            ->assertSee('value="wrong-key"', false);
    }

    public function test_a_key_pasted_with_spaces_around_it_is_accepted(): void
    {
        $details = $this->setUpDetails();
        $details['setup_key'] = "  {$details['setup_key']}\n";

        $this->post('/setup', $details)->assertRedirect('/admin');

        $this->assertSame(1, User::query()->count());
    }

    public function test_finishing_setup_deletes_the_key_file(): void
    {
        $this->post('/setup', $this->setUpDetails())->assertRedirect('/admin');

        $this->assertFileDoesNotExist($this->keyFile());
    }

    public function test_finishing_setup_replaces_the_first_visits_address_and_keeps_other_settings(): void
    {
        $this->post('https://example.test/setup', $this->setUpDetails());

        $this->assertSame(
            "APP_NAME=Acme\nAPP_URL=https://example.test\nLOG_LEVEL=error\n",
            File::get($this->storagePath.'/.env'),
        );
    }

    public function test_saving_the_address_keeps_the_settings_files_permissions(): void
    {
        chmod($this->storagePath.'/.env', 0600);

        $this->post('https://example.test/setup', $this->setUpDetails());

        clearstatcache();
        $this->assertSame(0600, fileperms($this->storagePath.'/.env') & 0777);
        $this->assertStringContainsString('APP_URL=https://example.test', File::get($this->storagePath.'/.env'));
    }

    public function test_finishing_setup_on_plain_http_saves_the_http_address(): void
    {
        $this->post('http://example.test/setup', $this->setUpDetails());

        $this->assertStringContainsString('APP_URL=http://example.test', File::get($this->storagePath.'/.env'));
        $this->assertNotTrue(config('session.secure'));
    }

    public function test_finishing_setup_in_a_folder_saves_the_folder_in_the_address(): void
    {
        $this->withServerVariables(['SCRIPT_FILENAME' => public_path('index.php'), 'SCRIPT_NAME' => '/blog/index.php', 'PHP_SELF' => '/blog/index.php'])
            ->post('https://example.test/blog/setup', $this->setUpDetails());

        $this->assertStringContainsString('APP_URL=https://example.test/blog', File::get($this->storagePath.'/.env'));
    }

    public function test_finishing_setup_on_https_after_a_plain_http_first_visit_makes_the_login_cookies_https_only(): void
    {
        $response = $this->post('https://example.test/setup', $this->setUpDetails());

        $this->assertTrue(config('session.secure'));

        $rememberCookie = $response->getCookie(auth()->guard()->getRecallerName());
        $this->assertTrue($rememberCookie->isSecure());
    }

    public function test_a_settings_file_that_cant_be_changed_does_not_stop_setup_finishing(): void
    {
        $details = $this->setUpDetails();
        File::delete($this->storagePath.'/.env');

        $this->post('https://example.test/setup', $details)->assertRedirect();

        $this->assertSame(1, User::query()->count());
        $this->assertFileExists($this->storagePath.'/app/installed');
    }

    public function test_on_plain_http_the_setup_screen_checks_for_https_and_can_warn(): void
    {
        $this->get('http://example.test/setup')
            ->assertSee('id="https-warning"', false)
            ->assertSee("doesn't have a working security certificate", false)
            ->assertSee('"https:\/\/example.test"', false);
    }

    public function test_on_https_the_setup_screen_neither_checks_nor_warns(): void
    {
        $this->get('https://example.test/setup')
            ->assertOk()
            ->assertDontSee('https-warning')
            ->assertDontSee('security certificate');
    }

    public function test_finishing_setup_creates_the_account_and_starting_content_and_logs_in(): void
    {
        $this->post('/setup', $this->setUpDetails())->assertRedirect('/admin');

        $user = User::query()->sole();
        $this->assertSame(['Ann Admin', 'ann@acme.test'], [$user->name, $user->email]);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Acme Studio', Theme::current()->site_name);
        $this->assertTrue(Entry::query()->where('path', Entry::HOME_PATH)->exists());
        $this->assertSame(['contact', 'newsletter'], Form::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertFileExists($this->storagePath.'/app/installed');
    }

    public function test_a_setup_that_fails_partway_leaves_nothing_behind_and_can_be_tried_again(): void
    {
        $details = $this->setUpDetails();
        Entry::creating(fn () => throw new RuntimeException('The disk is full.'));

        $this->post('/setup', $details)->assertServerError();

        $this->assertSame(0, User::query()->count());
        $this->assertFalse(Installation::isComplete());
        $this->assertFileExists($this->keyFile());
        $this->get('/setup')->assertOk();
    }

    public function test_once_setup_is_done_the_site_works_and_the_setup_screen_is_gone(): void
    {
        $this->post('/setup', $this->setUpDetails());
        auth()->logout();

        $this->get('/')->assertOk()->assertSee('Acme Studio');
        $this->get('/admin/login')->assertOk();
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->setUpDetails(['email' => 'intruder@evil.test']))->assertNotFound();
        $this->assertSame(1, User::query()->count());
    }

    public function test_mistakes_are_shown_and_nothing_is_created(): void
    {
        $this->from('/setup')
            ->post('/setup', $this->setUpDetails(['site_name' => '', 'email' => 'not-an-email', 'password_confirmation' => 'different']))
            ->assertRedirect('/setup');

        $this->assertSame(0, User::query()->count());
        $this->assertFalse(Installation::isComplete());

        $this->get('/setup')
            ->assertSee('The site name field is required.')
            ->assertSee('The email address field must be a valid email address.')
            ->assertSee('The password field confirmation does not match.');
    }

    public function test_passwords_must_be_at_least_eight_characters(): void
    {
        $this->from('/setup')->post('/setup', $this->setUpDetails(['password' => 'short', 'password_confirmation' => 'short']));

        $this->get('/setup')->assertSee('The password field must be at least 8 characters.');
    }

    public function test_a_site_that_already_has_an_admin_account_counts_as_set_up(): void
    {
        User::factory()->create();

        $this->get('/setup')->assertNotFound();
        $this->assertFileExists($this->storagePath.'/app/installed');
    }

    public function test_reserved_addresses_include_the_setup_screen_and_uploads(): void
    {
        $this->assertContains('setup', Entry::RESERVED_PATHS);
        $this->assertContains('uploads', Entry::RESERVED_PATHS);
        $this->assertContains('forms', Entry::RESERVED_PATHS);
    }
}
