<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 8:21 PM
 */

namespace JoshHarington\LaravelTsugi\Controllers;

use App\Http\Controllers\Controller;
use Tsugi\Laravel\LTIX;

class TsugiController extends Controller {

    public function __construct() {
        $this->middleware(function ($request, $next) {
//            $this->require_config();
            $launch = LTIX::laravelSetup($request, LTIX::ALL);
            if ( $launch->redirect_url ) return redirect($launch->redirect_url);
            if ( $launch->send_403 ) return response($launch->error_message, 403);
            ob_start();
            ob_get_clean();

            return $next($request);
        });
    }

    function require_config() {
        require_once "config.php";
    }

}