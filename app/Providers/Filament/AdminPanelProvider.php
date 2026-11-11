<?php

namespace App\Providers\Filament;

use App\Backup;
use App\Filament\Auth\AppAuthentication;
use App\Filament\Auth\EmailAuthentication;
use App\Filament\Auth\RequestPasswordReset;
use App\Http\Controllers\BackupDownloadController;
use App\Models\Theme;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Dates in the admin are shown and entered in the site's timezone; they're stored in UTC
            ->bootUsing(fn () => FilamentTimezone::set(Theme::siteTimezone()))
            // "RamaSite Admin: <site name>" at the top of every admin page and in its tab title
            ->brandName(fn (): string => self::brandName())
            // The RamaSite mark beside that name, and as the admin's tab icon
            ->brandLogo(fn (): View => view('filament.brand', ['brandName' => self::brandName()]))
            ->brandLogoHeight('auto')
            ->favicon(fn (): string => 'data:image/svg+xml;base64,'.base64_encode(view('filament.ramasite-mark', ['standalone' => true])->render()))
            ->login()
            // "Forgot password?" on the login screen, sent with the site's Email settings
            ->passwordReset(RequestPasswordReset::class)
            // Name, email and password, changed from the user menu; a new password needs the current one
            ->profile(isSimple: false)
            // Optional two-step login, which each admin turns on from their profile
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
            // Cool slate grays and blue, matching the public site's default look
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            // Hints in form boxes that are dark enough to read
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('filament.readable-hints'))
            // Lets the Insert image popup put its shortcode at the cursor in the editor
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.insert-into-editor'))
            // Which version this is, at the bottom of the menu
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): View => view('filament.version'))
            // A warning while a database update is waiting because it failed
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View => view('filament.database-update-warning'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('backup/download/{file}', BackupDownloadController::class)
                    ->where('file', Backup::ZIP_NAME_PATTERN)
                    ->name('backup.download');
            });
    }

    /**
     * Such as "RamaSite Admin: Dale Franks", or "RamaSite Admin" while the site
     * has no name or its settings can't be read.
     */
    public static function brandName(): string
    {
        $siteName = trim((string) rescue(fn (): ?string => Theme::current()->site_name, null, report: false));

        return $siteName === '' ? 'RamaSite Admin' : 'RamaSite Admin: '.$siteName;
    }
}
