<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 1:09 PM
 */

namespace JoshHarington\LaravelTsugi\Facades;


use Illuminate\Support\Facades\Facade;

class LaravelTsugi extends Facade {

    protected static function getFacadeAccessor() {
        return 'laravel_tsugi';
    }

}