<?php

namespace App\Http\Controllers;

use App\DatabaseUpdate;
use App\Installation;
use App\Models\Theme;
use App\Models\User;
use App\SetupKey;
use App\SiteAddress;
use Closure;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\StarterFormsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The one-time setup screen: creates the database tables, then the site name
 * and first admin account. Only someone who can read the setup key from the
 * site's files can finish it. Once setup is done, it's gone.
 */
class SetupController extends Controller
{
    public function show(Request $request): View
    {
        abort_if(Installation::isComplete(), 404);

        self::createTables();
        SetupKey::create();

        return view('setup', [
            'httpsSiteAddress' => $request->secure() ? null : 'https://'.$request->getHttpHost().$request->getBaseUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Installation::isComplete(), 404);

        $validated = $request->validate([
            'setup_key' => ['bail', 'required', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (! SetupKey::matches($value)) {
                    $fail('That doesn\'t match the key in '.SetupKey::FILE_NAME.'. Open the file again and copy all of it.');
                }
            }],
            'site_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], attributes: [
            'setup_key' => 'setup key',
            'site_name' => 'site name',
            'name' => 'your name',
            'email' => 'email address',
        ]);

        self::createTables();

        // All or nothing: an admin account alone would count as a finished setup
        $user = DB::transaction(function () use ($validated): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            Theme::current()->update(['site_name' => $validated['site_name']]);
            (new HomePageSeeder)->run();
            (new StarterFormsSeeder)->run();

            return $user;
        });

        SiteAddress::save($request->root());

        Installation::markComplete();
        SetupKey::delete();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    /**
     * Create (or bring up to date) the database tables, unless they already
     * are, so visits to the setup screen (often bots) don't redo the work.
     */
    private static function createTables(): void
    {
        if (! DatabaseUpdate::isNeeded() && Schema::hasTable('users')) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);
        DatabaseUpdate::markApplied();
    }
}
