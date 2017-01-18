<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 12:51 PM
 */

if(!function_exists('laravel_tsugi')) {
    function laravel_tsugi() {
        return app('laravel_tsugi');
    }
}

if(!function_exists('setup_tsugi')) {
    function setup_tsugi() {
        $tc = new \JoshHarington\LaravelTsugi\Controllers\TsugiController();
        return $tc->setup();
    }
}