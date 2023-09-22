<?php

namespace Fitttech\DataGrid;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DataGridServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('data-grid')
            ->hasRoute('web')
            ->hasMigrations('create_datagrid_table', 'alter_datagrid_table_change_configuration_type_to_text');
    }

    public function packageRegistered()
    {
        $this->app->bind('data-grid', function() {
            return new DataGridService();
        });
    }
}
