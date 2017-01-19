<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 1:08 PM
 */

namespace JoshHarington\LaravelTsugi;


use Illuminate\Support\ServiceProvider;

class LaravelTsugiServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->app->singleton( 'laravel_tsugi', function () {
            return new LaravelTsugi;
        });
    }

    public function boot() {
        $this->migrations();
    }

    public function migrations() {
        $this->publishes([__DIR__.'/database/migrations' => database_path('migrations')], 'migrations');
    }

}