<?php
/**
 * Created by PhpStorm.
 * User: jharing10
 * Date: 2017/01/18
 * Time: 8:21 PM
 */

namespace JoshHarington\LaravelTsugi\Controllers;

require_once app_path() . "/../packages/LaravelTsugi/vendor/tsugi/lib/config.php";

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tsugi\Laravel\LTIX;

class TsugiController extends Controller {

    public function __construct() {
        $this->middleware(function ($request, $next) {

            $launch = LTIX::laravelSetup($request, LTIX::ALL);
            if ( $launch->redirect_url ) return redirect($launch->redirect_url);
            if ( $launch->send_403 ) return response($launch->error_message, 403);
            ob_start();
//            echo("<pre>\n");
//            echo("\nLaunch:\n");
//            var_dump($launch);
            /*
                echo("\nSession:\n");
                var_dump($request->session());
                echo("\nPost:\n");
                var_dump($_POST);
                global $CFG;
                echo("\nCFG:\n");
                var_dump($CFG);
            */
//            echo("</pre>\n");
            ob_get_clean();

            return $next($request);
        });
    }

}