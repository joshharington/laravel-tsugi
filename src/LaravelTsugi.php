<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 1:07 PM
 */

namespace JoshHarington\LaravelTsugi;


use Illuminate\Http\Request;
use Tsugi\Laravel\LTIX;

class LaravelTsugi extends LTIX {

    public static function laravelSetup(Request $request, $needed=LTIX::ALL) {
        $launch = self::requireDataOverride(LTIX::ALL,
            null, /* pdox - default */
            $request->session(),
            null, /* current_url - default */
            null /* request_data - default */
        );
        return $launch;
    }

}