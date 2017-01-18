<?php

require_once($CFG->dirroot."/../../autoload.php");

function lmsDie($message=false) {
    global $CFG, $DEBUG_STRING;
    if($message !== false) {
        echo($message);
        error_log($message);
    }
    if ( $CFG->DEVELOPER === TRUE ) {
        if ( strlen($DEBUG_STRING) > 0 ) {
            echo("\n<pre>\n");
            echo(htmlentities($DEBUG_STRING));
            echo("\n</pre>\n");
        }
    }
    die();  // lmsDie
}

function line_out($output) {
    echo(htmlent_utf8($output)."<br/>\n");
    flush();
}

function error_out($output) {
    echo('<span style="color:red"><strong>'.htmlent_utf8($output)."</strong></span><br/>\n");
    flush();
}

function success_out($output) {
    echo('<span style="color:green"><strong>'.htmlent_utf8($output)."</strong></span><br/>\n");
    flush();
}

$OUTPUT = new \Tsugi\UI\Output();

// http://stackoverflow.com/questions/834303/startswith-and-endswith-functions-in-php
if ( ! function_exists('startsWith') ) {
function startsWith($haystack, $needle) {
    // search backwards starting from haystack length characters from the end
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
}
}

if ( ! function_exists('endsWith') ) {
function endsWith($haystack, $needle) {
    // search forward starting from end minus needle length characters
    return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== FALSE);
}
}

// No trailer

