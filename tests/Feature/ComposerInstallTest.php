<?php

namespace Tests\Feature;

use Tests\TestCase;

class ComposerInstallTest extends TestCase
{
    /**
     * `composer create-project` must leave the settings file to the site's
     * first visit, as a zip install does. A .env copied from .env.example kept
     * development settings (debug pages, a queue nothing runs, a localhost
     * address) because the first-run setup only runs when there's no .env.
     */
    public function test_a_composer_install_leaves_the_settings_file_and_database_to_the_first_visit(): void
    {
        $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'];

        $this->assertArrayNotHasKey('post-root-package-install', $scripts);
        $this->assertArrayNotHasKey('post-create-project-cmd', $scripts);
    }
}
