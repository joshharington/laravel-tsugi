<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitb43d52f92f3f0bd103ad72d7342c2c2e
{
    public static $files = array (
        '565074258e78644957ab0a8e5148536e' => __DIR__ . '/../..' . '/src/Helpers/functions.php',
    );

    public static $prefixLengthsPsr4 = array (
        'T' => 
        array (
            'Tsugi\\' => 6,
        ),
        'J' => 
        array (
            'JoshHarington\\LaravelTsugi\\' => 27,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Tsugi\\' => 
        array (
            0 => __DIR__ . '/..' . '/tsugi/lib/src',
        ),
        'JoshHarington\\LaravelTsugi\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitb43d52f92f3f0bd103ad72d7342c2c2e::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitb43d52f92f3f0bd103ad72d7342c2c2e::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
