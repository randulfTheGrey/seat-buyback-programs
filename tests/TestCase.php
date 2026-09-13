<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RandulfTheGrey\Seat\BuybackPrograms\BuybackProgramsServiceProvider;
use Yajra\DataTables\DataTablesServiceProvider;
use Yajra\DataTables\HtmlServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataTablesServiceProvider::class,
            HtmlServiceProvider::class,
            BuybackProgramsServiceProvider::class,
        ];
    }
}
