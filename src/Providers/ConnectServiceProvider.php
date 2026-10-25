<?php

namespace Modules\Glitter\Connect\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Glitter\Connect\Console\DeliveryCommand;

class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\Glitter\Connect\Services\ConnectorRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DeliveryCommand::class]);
        }
    }
}
