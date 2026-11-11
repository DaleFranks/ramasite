<?php

namespace Tests\Feature;

use App\Models\User;
use App\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VersionTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        // A folder of its own for the VERSION file, so the real one is never touched
        $this->basePath = sys_get_temp_dir().'/ramasite-version-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->basePath);
        File::copy(base_path('composer.json'), $this->basePath.'/composer.json');
        $this->app->setBasePath($this->basePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_a_release_reports_the_version_its_build_wrote(): void
    {
        File::put($this->basePath.'/VERSION', "v0.4.0-alpha\n");

        $this->assertSame('v0.4.0-alpha', Version::current());
        $this->assertSame('RamaSite v0.4.0-alpha', Version::label());
    }

    public function test_a_copy_not_from_a_release_is_the_development_version(): void
    {
        $this->assertSame('development', Version::current());
    }

    public function test_the_admin_shows_the_version(): void
    {
        File::put($this->basePath.'/VERSION', "v0.4.0-alpha\n");
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee('RamaSite v0.4.0-alpha');
    }

    public function test_each_version_compiles_its_templates_into_a_folder_of_its_own(): void
    {
        $views = $this->basePath.'/views';
        File::ensureDirectoryExists($views.'/v0.8.0');
        File::put($views.'/v0.8.0/old-template.php', 'compiled by 0.8.0');
        File::put($views.'/older-template.php', 'compiled before versions had folders');
        File::put($views.'/.gitignore', "*\n!.gitignore\n");
        File::put($this->basePath.'/VERSION', "v0.9.0\n");

        $this->assertSame($views.'/v0.9.0', Version::compiledViewsPath($views));

        // Older versions' templates are gone, so none of them can be used by mistake.
        // The folder itself is made by the first template compiled into it.
        $this->assertDirectoryDoesNotExist($views.'/v0.9.0');
        $this->assertDirectoryDoesNotExist($views.'/v0.8.0');
        $this->assertFileDoesNotExist($views.'/older-template.php');
        $this->assertFileExists($views.'/.gitignore');

        // Once the folder exists, its templates are kept
        File::ensureDirectoryExists($views.'/v0.9.0');
        File::put($views.'/v0.9.0/template.php', 'compiled by 0.9.0');
        Version::compiledViewsPath($views);
        $this->assertFileExists($views.'/v0.9.0/template.php');
    }

    public function test_the_site_compiles_templates_into_the_versions_folder(): void
    {
        // The folder is chosen from the real VERSION file when the app starts, before
        // setUp() swaps the base path, so it's read from the config rather than assumed
        $versionFolder = config('view.compiled');

        $this->assertStringEndsWith('/storage/framework/views', dirname($versionFolder));
        $this->assertStringStartsWith(
            $versionFolder.'/',
            app('blade.compiler')->getCompiledPath(resource_path('views/errors/404.blade.php')),
        );
    }
}
