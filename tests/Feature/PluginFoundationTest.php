<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use RandulfTheGrey\Seat\BuybackPrograms\BuybackProgramsServiceProvider;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;
use Seat\Services\AbstractSeatPlugin;

final class PluginFoundationTest extends TestCase
{
    public function test_service_provider_boots_as_a_seat_plugin(): void
    {
        $provider = $this->app->getProvider(BuybackProgramsServiceProvider::class);

        self::assertInstanceOf(AbstractSeatPlugin::class, $provider);
        self::assertSame('Buyback Programs', $provider->getName());
        self::assertSame(
            'randulfthegrey/seat-buyback-programs',
            $provider->getPackagistAlias(),
        );
        self::assertSame(
            'https://github.com/randulfTheGrey/seat-buyback-programs',
            $provider->getPackageRepositoryUrl(),
        );
    }

    public function test_configuration_is_loaded_and_publishable(): void
    {
        self::assertSame('buyback', config('seat-buyback-programs.route_prefix'));
        self::assertSame('buyback-manage', config('seat-buyback-programs.manager_route_prefix'));
        self::assertSame('buyback-admin', config('seat-buyback-programs.admin_route_prefix'));

        $paths = ServiceProvider::pathsToPublish(
            BuybackProgramsServiceProvider::class,
            'seat-buyback-programs-config',
        );

        self::assertSame([
            dirname(__DIR__, 2) . '/config/plugin.php' => config_path('seat-buyback-programs.php'),
        ], $paths);
    }

    public function test_exactly_three_independent_permissions_are_registered(): void
    {
        $permissions = config('seat.permissions.buyback');

        self::assertIsArray($permissions);
        self::assertSame(['request', 'manage', 'admin'], array_keys($permissions));

        foreach ($permissions as $permission) {
            self::assertSame(['label', 'description'], array_keys($permission));
        }
    }

    public function test_routes_views_translations_and_migrations_register(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/routes/web.php');
        self::assertSame(
            'Request buybacks',
            trans('seat-buyback-programs::permissions.request.label'),
        );

        $viewHints = $this->app->make('view')->getFinder()->getHints();
        self::assertContains(
            dirname(__DIR__, 2) . '/resources/views',
            $viewHints[BuybackProgramsServiceProvider::VIEW_NAMESPACE],
        );

        $migrationPaths = $this->app->make('migrator')->paths();
        self::assertContains(dirname(__DIR__, 2) . '/database/migrations', $migrationPaths);
    }

    public function test_foundation_has_no_direct_external_provider_or_esi_dependencies(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey('guzzlehttp/guzzle', $composer['require']);
        self::assertArrayNotHasKey('cryptatech/seat-prices-fuzzwork', $composer['require']);
        self::assertArrayNotHasKey('cryptatech/seat-prices-janice', $composer['require']);
        self::assertArrayNotHasKey('eveseat/eseye', $composer['require']);
    }
}
