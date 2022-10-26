<?php namespace Frc\Brightcove;

use Illuminate\Support\ServiceProvider;

class BrightcoveServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/brightcove.php', 'brightcove');

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->bind('brightcove', function () {
            $default = config('brightcove.default');
            $config = config("brightcove.accounts.$default");

            return new BrightcoveApi(collect($config));
        });
    }
}
