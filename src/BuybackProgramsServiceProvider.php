<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionDataSynchronizer;
use RandulfTheGrey\Seat\BuybackPrograms\Console\Commands\SyncCompressionDataCommand;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Appraisal\CacheAppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Appraisal\SeatInventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Ccp\CcpCompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Pricing\SeatPricesCoreGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackProgramPolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackQuotePolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackRequestPolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackRulePolicy;
use Seat\Services\AbstractSeatPlugin;

final class BuybackProgramsServiceProvider extends AbstractSeatPlugin
{
    public const CONFIG_KEY = 'seat-buyback-programs';

    public const VIEW_NAMESPACE = 'seat-buyback-programs';

    public const TRANSLATION_NAMESPACE = 'seat-buyback-programs';

    public function register(): void
    {
        $root = dirname(__DIR__);

        $this->mergeConfigFrom($root . '/config/plugin.php', self::CONFIG_KEY);
        $this->mergeConfigFrom($root . '/config/sidebar.php', 'package.sidebar');
        $this->registerPermissions($root . '/config/permissions.php', 'buyback');

        $this->app->singleton(CompressionSdeSource::class, CcpCompressionSdeSource::class);
        $this->app->singleton(CompressionSync::class, CompressionDataSynchronizer::class);
        $this->app->singleton(InventoryTypeResolver::class, SeatInventoryTypeResolver::class);
        $this->app->singleton(AppraisalStore::class, CacheAppraisalStore::class);
        $this->app->singleton(SeatPriceProviderGateway::class, SeatPricesCoreGateway::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);

        Gate::policy(BuybackProgram::class, BuybackProgramPolicy::class);
        Gate::policy(BuybackQuote::class, BuybackQuotePolicy::class);
        Gate::policy(BuybackRequest::class, BuybackRequestPolicy::class);
        Gate::policy(BuybackRule::class, BuybackRulePolicy::class);

        $this->loadRoutesFrom($root . '/routes/web.php');
        $this->loadViewsFrom($root . '/resources/views', self::VIEW_NAMESPACE);
        $this->loadTranslationsFrom($root . '/resources/lang', self::TRANSLATION_NAMESPACE);
        $this->loadMigrationsFrom($root . '/database/migrations');

        $this->commands([SyncCompressionDataCommand::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule
                ->command(SyncCompressionDataCommand::class)
                ->daily()
                ->withoutOverlapping(120);
        });

        $this->publishes([
            $root . '/config/plugin.php' => config_path('seat-buyback-programs.php'),
        ], ['config', 'seat', 'seat-buyback-programs-config']);
    }

    public function getName(): string
    {
        return 'Buyback Programs';
    }

    public function getDescription(): string
    {
        return 'Reusable buyback program management for SeAT.';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/randulfTheGrey/seat-buyback-programs';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-buyback-programs';
    }

    public function getPackagistVendorName(): string
    {
        return 'randulfthegrey';
    }
}
