<?php

namespace Samody\PostmanGenerator;

use Illuminate\Support\ServiceProvider;
use Samody\PostmanGenerator\Commands\ExportPostmanCommand;

class PostmanGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-postman.php' => config_path('api-postman.php'),
            ], 'postman-config');

            $this->publishes([
                __DIR__.'/../config/api-postman.php' => config_path('api-postman.php'),
            ], 'config');
        }

        $this->commands(ExportPostmanCommand::class);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/api-postman.php', 'api-postman'
        );
    }
}
