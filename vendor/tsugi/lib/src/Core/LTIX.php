<?php

namespace Tsugi\Core;

use Illuminate\Support\Facades\DB;
use \Tsugi\OAuth\TrivialOAuthDataStore;
use \Tsugi\OAuth\OAuthServer;
use \Tsugi\OAuth\OAuthRequest;

use \Tsugi\Util\LTI;
use \Tsugi\Util\LTIConstants;
use \Tsugi\UI\Output;
use \Tsugi\Core\Settings;
use \Tsugi\OAuth\OAuthUtil;
use \Tsugi\Crypt\SecureCookie;

/**
 * This an opinionated LTI class that defines how Tsugi tools interact with LTI
 *
 * This class deals with all of the session and database/data model
 * details that Tsugi tools make use of during runtime.  This makes use of the
 * lower level \Tsugi\Util\LTI class which is focused on
 * meeting the protocol requirements.
 * Most tools will not use LTI at all - just LTIX.
 */
class LTIX {

    // Indicates that this code requires certain
    // launch data to function
    const CONTEXT = "context_id";
    const USER = "user_id";
    const LINK = "link_id";
    const ALL = "all";
    const NONE = "none";

    /**
     * LTIX constructor.
     */
    public function __construct() {
        global $CFG;
//        $CFG->dbuser = 'root';
//        $CFG->dbpass = 'root';
    }


    /**
     * Get a singleton global connection or set it up if not already set up.
     */
    public static function getConnection() {
        global $PDOX, $CFG;

        if ( isset($PDOX) && is_object($PDOX) && get_class($PDOX) == 'Tsugi\Util\PDOX' ) {
            return $PDOX;
        }

        if ( defined('PDO_WILL_CATCH') ) {
            $PDOX = new \Tsugi\Util\PDOX($CFG->pdo, $CFG->dbuser, $CFG->dbpass);
            $PDOX->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } else {
            try {
                $PDOX = new \Tsugi\Util\PDOX('mysql:host=127.0.0.1;dbname=tsugi', 'ltiuser', 'ltipassword');
                $PDOX->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            } catch(\PDOException $ex) {
                error_log("DB connection: "+$ex->getMessage());
                die($ex->getMessage()); // with error_log
            }
        }
        return $PDOX;
    }

    /**
     * Silently check if this is a launch and if so, handle it and redirect
     * back to ourselves
     */
    public static function launchCheck($needed=self::ALL, $session_object=null,$request_data=false) {
        global $TSUGI_LAUNCH;
        if ( $request_data === false ) $request_data = self::oauth_parameters();
        $needed = self::patchNeeded($needed);
        if ( ! LTI::isRequest($request_data) ) return false;
        $session_id = self::setupSession($needed,$session_object,$request_data);
        if ( $session_id === false ) return false;

        // Redirect back to ourselves...
        $url = self::curPageUrl();

        if ( $session_object !== null ) {
            $TSUGI_LAUNCH->redirect_url = self::curPageUrl();
            return true;
        }

        $location = addSession($url);
        session_write_close();  // To avoid any race conditions...

        if ( headers_sent() ) {
            echo('<p><a href="'.$url.'">Click to continue</a></p>');
        } else {
            header('Location: '.$location);
        }
        exit();
    }

    /**
     * Wrap getting a key from the session
     */
    public static function wrapped_session_get($session_object,$key,$default=null)
    {
        global $TSUGI_SESSION_OBJECT;
        if ( $session_object === null && isset($TSUGI_SESSION_OBJECT) ) $session_object = $TSUGI_SESSION_OBJECT;
        if ( is_object($session_object) ) {
            return $session_object->get($key,$default);
        }
        if ( is_array($session_object) ) {
            if ( isset($session_object[$key]) ) return $session_object[$key];
            return $default;
        }
        if ( ! isset($_SESSION) ) return $default;
        if ( ! isset($_SESSION[$key]) ) return $default;
        return $_SESSION[$key];
    }

    /**
     * Wrap setting a key from the session
     */
    public static function wrapped_session_put(&$session_object,$key,$value)
    {
        global $TSUGI_SESSION_OBJECT;
        if ( $session_object === null && isset($TSUGI_SESSION_OBJECT) ) $session_object = $TSUGI_SESSION_OBJECT;
        if ( is_object($session_object) ) {
            $session_object->put($key,$value);
            return;
        }
        if ( is_array($session_object) ) {
            $session_object[$key] = $value;
            return;
        }
        if ( isset($_SESSION) ) $_SESSION[$key] = $value;
    }

    /**
     * Wrap forgetting a key from the session
     */
    public static function wrapped_session_forget(&$session_object,$key)
    {
        global $TSUGI_SESSION_OBJECT;
        if ( $session_object === null && isset($TSUGI_SESSION_OBJECT) ) $session_object = $TSUGI_SESSION_OBJECT;
        if ( is_object($session_object) ) {
            $session_object->forget($key);
            return;
        }
        if ( is_array($session_object) ) {
            if ( isset($session_object[$key]) ) unset($session_object[$key]);
            return;
        }
        if ( isset($_SESSION) && isset($_SESSION[$key]) ) unset($_SESSION[$key]);
    }

    /**
     * Wrap flushing the session
     */
    public static function wrapped_session_flush(&$session_object)
    {
        global $TSUGI_SESSION_OBJECT;
        if ( $session_object === null && isset($TSUGI_SESSION_OBJECT) ) $session_object = $TSUGI_SESSION_OBJECT;
        if ( is_object($session_object) ) {
            $session_object->flush();
            return;
        }
        if ( is_array($session_object) ) {
            foreach($session_object as $k => $v ) {
                unset($session_object[$k]);
            }
            for ($i = 0; $i < count($session_object); $i++) { 
                unset($session_object[$i]); 
            }
        }
        session_unset();
    }

    /**
     * Pull a keyed variable from the LTI data in the current session with default
     *
     * @deprecated Session access should be through the Launch Object
     */
    public static function ltiParameter($varname, $default=false) {
        if ( ! isset($_SESSION) ) return $default;
        if ( ! isset($_SESSION['lti']) ) return $default;
        $lti = $_SESSION['lti'];
        if ( ! isset($lti[$varname]) ) return $default;
        return $lti[$varname];
    }

    /**
     * Return the original $_POST array
     *
     * @deprecated Session access should be through the Launch Object
     */
    public static function ltiRawPostArray() {
        if ( ! isset($_SESSION) ) return array();
        if ( ! isset($_SESSION['lti_post']) ) return array();
        return($_SESSION['lti_post']);
    }

    /**
     * Pull a keyed variable from the original LTI post data in the current session with default
     *
     * @deprecated Session access should be through the Launch Object
     */
    public static function ltiRawParameter($varname, $default=false) {
        if ( ! isset($_SESSION) ) return $default;
        if ( ! isset($_SESSION['lti_post']) ) return $default;
        $lti_post = $_SESSION['lti_post'];
        if ( ! isset($lti_post[$varname]) ) return $default;
        return $lti_post[$varname];
    }

    /**
     * Pull out a custom variable from the LTIX session. Do not
     * include the "custom_" prefix - this is automatic.
     *
     * @deprecated Session access should be through the Launch Object
     */
    public static function ltiCustomGet($varname, $default=false) {
        return self::ltiRawParameter('custom_'.$varname, $default);
    }

    /**
     * The LTI parameter data
     * 
     * This code is taken from OAuthRequest
     */
    public static function oauth_parameters() {
        // Find request headers
        $request_headers = OAuthUtil::get_headers();

        // Parse the query-string to find GET parameters
        if ( isset($_SERVER['QUERY_STRING']) ) {
            $parameters = OAuthUtil::parse_parameters($_SERVER['QUERY_STRING']);
        } else {
            $parameters = array();
        }

        // Add POST Parameters if they exist
        $parameters = array_merge($parameters, $_POST);

        // We have a Authorization-header with OAuth data. Parse the header
        // and add those overriding any duplicates from GET or POST
        if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
            $header_parameters = OAuthUtil::split_header(
                $request_headers['Authorization']
            );
            $parameters = array_merge($parameters, $header_parameters);
        }
        return $parameters;
    }

    /**
     * Extract all of the post data, set up data in tables, and set up session.
     */
    public static function setupSession($needed=self::ALL, $session_object=null, $request_data=false) {
        global $CFG, $TSUGI_LAUNCH, $TSUGI_SESSION_OBJECT;
        if ( $request_data === false ) $request_data = self::oauth_parameters();
        $TSUGI_SESSION_OBJECT = $session_object;

        $needed = self::patchNeeded($needed);
        if ( ! LTI::isRequest($request_data) ) return false;

        // Pull LTI data out of the incoming $request_data and map into the same
        // keys that we use in our database (i.e. like $row)
        $post = self::extractPost($needed, $request_data);
        if ( $post === false ) {
            $pdata = Output::safe_var_dump($request_data);
            echo("\n<pre>\nMissing Post_data\n$pdata\n</pre>");
            error_log('Missing post data: '.$pdata);
            die();
        }

        if ( $post['key'] == '12345' && ! $CFG->DEVELOPER) {
            self::abort_with_error_log('You can only use key 12345 in developer mode');
        }

        // We make up a Session ID Key because we don't want a new one
        // each time the same user launches the same link.
        if ( $session_object === null ) {
            if ( !defined('COOKIE_SESSION') ) {
                $session_id = self::getCompositeKey($post, $CFG->sessionsalt);
                session_id($session_id);
            }
            session_start();
            $session_id = session_id();

            // TODO: Why was this here?
            // header('Content-Type: text/html; charset=utf-8');

            // Since we might reuse session IDs, clean everything out
            if ( !defined('COOKIE_SESSION') ) self::wrapped_session_flush($session_object);
        }

        self::wrapped_session_put($session_object,'LAST_ACTIVITY', time());

        // Copy the tsugi_nav into the session
        if ( isset($request_data['ext_tsugi_top_nav']) ) {
            self::wrapped_session_put($session_object,'tsugi_top_nav', $request_data['ext_tsugi_top_nav']);
        } else {
            self::wrapped_session_forget($session_object,'tsugi_top_nav');
        }

        // Read all of the data from the database with a very long
        // LEFT JOIN and get all the data we have back in the $row variable
        // $row = loadAllData($CFG->dbprefix, false, $post);
        $row = self::loadAllData($CFG->dbprefix, $CFG->dbprefix.'profile', $post);

        $delta = 0;
        if ( isset($request_data['oauth_timestamp']) ) {
            $server_time = $request_data['oauth_timestamp']+0;
            $delta = abs(time() -  $server_time);
            if ( $delta > 480 ) { // More than four minutes is getting close
                error_log('Warning: Time skew, delta='.$delta.' sever_time='.$server_time.' our_time='.time());
            }
        }

        // Check the nonce to make sure there is no reuse
        if ( $row['nonce'] !== null) {
            self::abort_with_error_log('OAuth nonce error key='.$post['key'].' nonce='.$row['nonce']);
        }

        // Use returned data to check the OAuth signature on the
        // incoming data - returns true or an array
        $valid = LTI::verifyKeyAndSecret($post['key'],$row['secret'],self::curPageUrl(), $request_data);

        // If there is a new_secret it means an LTI2 re-registration is in progress and we
        // need to check both the current and new secret until the re-registration is committed
        if ( $valid !== true && strlen($row['new_secret']) > 0 && $row['new_secret'] != $row['secret']) {
            $valid = LTI::verifyKeyAndSecret($post['key'],$row['new_secret'],self::curPageUrl(), $request_data);
            if ( $valid ) {
                $row['secret'] = $row['new_secret'];
            }
            $row['new_secret'] = null;
        }

        if ( isset($TSUGI_LAUNCH) ) $TSUGI_LAUNCH->base_string = LTI::getLastOAuthBodyBaseString();

        // TODO: Might want to add more flows here...
        if ( $valid !== true ) {
            if ( isset($TSUGI_LAUNCH) ) $TSUGI_LAUNCH->error_message = $valid;
            self::abort_with_error_log('OAuth validation fail key='.$post['key'].' delta='.$delta.' error='.$valid[0],$valid[1]);
        }

        $actions = self::adjustData($CFG->dbprefix, $row, $post, $needed);

        $PDOX = self::getConnection();
        // Record the nonce but first probabilistically check
        if ( $CFG->noncecheck > 0 ) {
            if ( (time() % $CFG->noncecheck) == 0 ) {
                $PDOX->queryDie("DELETE FROM {$CFG->dbprefix}lti_nonce WHERE
                    created_at < DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL -{$CFG->noncetime} SECOND)");
                // error_log("Nonce table cleanup done.");
            }
            $PDOX->queryDie("INSERT INTO {$CFG->dbprefix}lti_nonce
                (key_id, nonce) VALUES ( :key_id, :nonce)",
                array( ':nonce' => $post['nonce'], ':key_id' => $row['key_id'])
            );
        }

        // If there is an appropriate role override variable, we use that role
        if ( isset($row['role_override']) && isset($row['role']) &&
            $row['role_override'] > $row['role'] ) {
            $row['role'] = $row['role_override'];
        }

        // Put the information into the row variable
        // TODO: do AES on the secret
        self::wrapped_session_put($session_object, 'lti', $row);
        self::wrapped_session_put($session_object, 'lti_post', $request_data);

        if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
            self::wrapped_session_put($session_object, 'HTTP_USER_AGENT', $_SERVER['HTTP_USER_AGENT']);
        }
        if ( isset($_SERVER['REMOTE_ADDR']) ) {
            self::wrapped_session_put($session_object, 'REMOTE_ADDR', $_SERVER['REMOTE_ADDR']);
        }
        self::wrapped_session_put($session_object, 'CSRF_TOKEN', uniqid());

        // Save this to make sure the user does not wander unless we launched from the root
        $scp = $CFG->getScriptPath();
        if ( strlen($scp) > 0 ) {
            self::wrapped_session_put($session_object, 'script_path', $CFG->getScriptPath());
        }

        // Check if we can auto-login the system user
        if ( Settings::linkGet('dologin', false) && isset($PDOX) && $PDOX !== false ) self::loginSecureCookie();

        $breadcrumb = 'Launch,';
        $breadcrumb .= isset($row['key_id']) ? $row['key_id'] : '';
        $breadcrumb .= ',';
        $breadcrumb .= isset($row['user_id']) ? $row['user_id'] : '';
        $breadcrumb .= ',';
        $breadcrumb .= isset($request_data['user_id']) ? str_replace(',',';', $request_data['user_id']) : '';
        $breadcrumb .= ',';
        if ( $session_object === null ) {
            $breadcrumb .= $session_id;
        }
        $breadcrumb .= ',';
        $breadcrumb .= self::curPageUrl();
        $breadcrumb .= ',';
        $breadcrumb .= self::wrapped_session_get($session_object, 'email',' ');
        error_log($breadcrumb);

        if ( $session_object === null ) {
            return $session_id;
        }
    }

    /**
     * Pull the LTI POST data into our own data structure
     *
     * We follow our naming conventions that match the column names in
     * our lti_ tables.
     */
    public static function extractPost($needed=self::ALL, $input=false) {
        // Unescape each time we use this stuff - someday we won't need this...
        $needed = self::patchNeeded($needed);
        if ( $input === false ) $input = $_POST;
        $FIXED = array();
        foreach($input as $key => $value ) {
            if (get_magic_quotes_gpc()) $value = stripslashes($value);
            if ( strpos($key, "custom_") === 0 ) {
                $newkey = substr($key,7);
                // Need to deal with custom_context_id=$Context.id
                if ( strpos($value,"$") === 0 ) {
                    $short_value = strtolower(substr($value,1));
                    $short_value = str_replace('.','_',$short_value);
                    if ( $newkey == $short_value ) {
                        continue;
                    }
                }
                if ( !isset($FIXED[$newkey]) ) $FIXED[$newkey] = $value;
            }
            $FIXED[$key] = $value;
        }
        $retval = array();
        $retval['key'] = isset($FIXED['oauth_consumer_key']) ? $FIXED['oauth_consumer_key'] : null;
        $retval['nonce'] = isset($FIXED['oauth_nonce']) ? $FIXED['oauth_nonce'] : null;
        $link_id = isset($FIXED['resource_link_id']) ? $FIXED['resource_link_id'] : null;
        $link_id = isset($FIXED['custom_resource_link_id']) ? $FIXED['custom_resource_link_id'] : $link_id;
        $retval['link_id'] = $link_id;

        $user_id = isset($FIXED['person_sourcedid']) ? $FIXED['person_sourcedid'] : null;
        $user_id = isset($FIXED['user_id']) ? $FIXED['user_id'] : $user_id;
        $user_id = isset($FIXED['custom_user_id']) ? $FIXED['custom_user_id'] : $user_id;
        $retval['user_id'] = $user_id;

        $context_id = isset($FIXED['courseoffering_sourcedid']) ? $FIXED['courseoffering_sourcedid'] : null;
        $context_id = isset($FIXED['context_id']) ? $FIXED['context_id'] : $context_id;
        $context_id = isset($FIXED['custom_context_id']) ? $FIXED['custom_context_id'] : $context_id;
        $retval['context_id'] = $context_id;

        // Sanity checks
        if ( ! $retval['key'] ) return false;
        if ( ! $retval['nonce'] ) return false;
        if ( in_array(self::USER, $needed) && ! $retval['user_id'] ) return false;
        if ( in_array(self::CONTEXT, $needed) && ! $retval['context_id'] ) return false;
        if ( in_array(self::LINK, $needed) && ! $retval['link_id'] ) return false;

        // LTI 1.x settings and Outcomes
        $retval['service'] = isset($FIXED['lis_outcome_service_url']) ? $FIXED['lis_outcome_service_url'] : null;
        $retval['sourcedid'] = isset($FIXED['lis_result_sourcedid']) ? $FIXED['lis_result_sourcedid'] : null;

        // LTI 2.x settings and Outcomes
        $retval['result_url'] = isset($FIXED['custom_result_url']) ? $FIXED['custom_result_url'] : null;
        $retval['link_settings_url'] = isset($FIXED['custom_link_settings_url']) ? $FIXED['custom_link_settings_url'] : null;
        $retval['context_settings_url'] = isset($FIXED['custom_context_settings_url']) ? $FIXED['custom_context_settings_url'] : null;

        $retval['context_title'] = isset($FIXED['context_title']) ? $FIXED['context_title'] : null;
        $retval['link_title'] = isset($FIXED['resource_link_title']) ? $FIXED['resource_link_title'] : null;

        // Getting email from LTI 1.x and LTI 2.x
        $retval['user_email'] = isset($FIXED['lis_person_contact_email_primary']) ? $FIXED['lis_person_contact_email_primary'] : null;
        $retval['user_email'] = isset($FIXED['custom_person_email_primary']) ? $FIXED['custom_person_email_primary'] : $retval['user_email'];

        // Displayname from LTI 2.x
        if ( isset($FIXED['person_name_full']) ) {
            $retval['user_displayname'] = $FIXED['custom_person_name_full'];
        } else if ( isset($FIXED['custom_person_name_given']) && isset($FIXED['custom_person_name_family']) ) {
            $retval['user_displayname'] = $FIXED['custom_person_name_given'].' '.$FIXED['custom_person_name_family'];
        } else if ( isset($FIXED['custom_person_name_given']) ) {
            $retval['user_displayname'] = $FIXED['custom_person_name_given'];
        } else if ( isset($FIXED['custom_person_name_family']) ) {
            $retval['user_displayname'] = $FIXED['custom_person_name_family'];

        // Displayname from LTI 1.x
        } else if ( isset($FIXED['lis_person_name_full']) ) {
            $retval['user_displayname'] = $FIXED['lis_person_name_full'];
        } else if ( isset($FIXED['lis_person_name_given']) && isset($FIXED['lis_person_name_family']) ) {
            $retval['user_displayname'] = $FIXED['lis_person_name_given'].' '.$FIXED['lis_person_name_family'];
        } else if ( isset($FIXED['lis_person_name_given']) ) {
            $retval['user_displayname'] = $FIXED['lis_person_name_given'];
        } else if ( isset($FIXED['lis_person_name_family']) ) {
            $retval['user_displayname'] = $FIXED['lis_person_name_family'];
        }

        // Trim out repeated spaces and/or weird whitespace from the user_displayname
        if ( isset($retval['user_displayname']) ) {
            $retval['user_displayname'] = trim(preg_replace('/\s+/', ' ',$retval['user_displayname']));
        }

        // Get the role
        $retval['role'] = 0;
        $roles = '';
        if ( isset($FIXED['custom_membership_role']) ) { // From LTI 2.x
            $roles = $FIXED['custom_membership_role'];
        } else if ( isset($FIXED['roles']) ) { // From LTI 1.x
            $roles = $FIXED['roles'];
        }

        if ( strlen($roles) > 0 ) {
            $roles = strtolower($roles);
            if ( ! ( strpos($roles,'instructor') === false ) ) $retval['role'] = 1;
            if ( ! ( strpos($roles,'administrator') === false ) ) $retval['role'] = 1;
        }
        return $retval;
    }

    // Make sure to include the file in case multiple instances are running
    // on the same Operating System instance and they have not changed the
    // session secret.  Also make these change every 30 minutes
    public static function getCompositeKey($post, $session_secret) {
        $comp = $session_secret .'::'. $post['key'] .'::'. $post['context_id'] .'::'.
            $post['link_id']  .'::'. $post['user_id'] .'::'. intval(time() / 1800) .
            $_SERVER['HTTP_USER_AGENT'] . '::' . __FILE__;
        return md5($comp);
    }

    /**
     * Load the data from our lti_ tables using one long LEFT JOIN
     *
     * This data may or may not exist - hence the use of the long
     * LEFT JOIN.
     */
    public static function loadAllData($p, $profile_table, $post) {
        $PDOX = self::getConnection();
        $errormode = $PDOX->getAttribute(\PDO::ATTR_ERRMODE);
        $PDOX->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sql = "SELECT k.key_id, k.key_key, k.secret, k.new_secret, c.settings_url AS key_settings_url,
            n.nonce,
            c.context_id, c.title AS context_title, context_sha256, c.settings_url AS context_settings_url,
            l.link_id, l.title AS link_title, l.settings AS link_settings, l.settings_url AS link_settings_url,
            u.user_id, u.displayname AS user_displayname, u.email AS user_email, user_key,
            u.subscribe AS subscribe, u.user_sha256 AS user_sha256,
            m.membership_id, m.role, m.role_override,
            r.result_id, r.grade, r.result_url, r.sourcedid";

        if ( $profile_table ) {
            $sql .= ",
            p.profile_id, p.displayname AS profile_displayname, p.email AS profile_email,
            p.subscribe AS profile_subscribe";
        }

        if ( $post['service'] ) {
            $sql .= ",
            s.service_id, s.service_key AS service";
        }

        $sql .="\nFROM {$p}lti_key AS k
            LEFT JOIN {$p}lti_nonce AS n ON k.key_id = n.key_id AND n.nonce = :nonce
            LEFT JOIN {$p}lti_context AS c ON k.key_id = c.key_id AND c.context_sha256 = :context
            LEFT JOIN {$p}lti_link AS l ON c.context_id = l.context_id AND l.link_sha256 = :link
            LEFT JOIN {$p}lti_user AS u ON k.key_id = u.key_id AND u.user_sha256 = :user
            LEFT JOIN {$p}lti_membership AS m ON u.user_id = m.user_id AND c.context_id = m.context_id
            LEFT JOIN {$p}lti_result AS r ON u.user_id = r.user_id AND l.link_id = r.link_id";

        if ( $profile_table ) {
            $sql .= "
            LEFT JOIN {$profile_table} AS p ON u.profile_id = p.profile_id";
        }

        if ( $post['service'] ) {
            $sql .= "
            LEFT JOIN {$p}lti_service AS s ON k.key_id = s.key_id AND s.service_sha256 = :service";
        }

        $sql .= "\nWHERE k.key_sha256 = :key LIMIT 1\n";

        // echo($sql);
        $parms = array(
            ':key' => lti_sha256($post['key']),
            ':nonce' => substr($post['nonce'],0,128),
            ':context' => lti_sha256($post['context_id']),
            ':link' => lti_sha256($post['link_id']),
            ':user' => lti_sha256($post['user_id']));

        if ( $post['service'] ) {
            $parms[':service'] = lti_sha256($post['service']);
        }

        $row = $PDOX->rowDie($sql, $parms);

        // Restore ERRMODE
        $PDOX->setAttribute(\PDO::ATTR_ERRMODE, $errormode);
        return $row;
    }

    /**
     * Make sure that the data in our lti_ tables matches the POST data
     *
     * This routine compares the POST dat to the data pulled from the
     * lti_ tables and goes through carefully INSERTing or UPDATING
     * all the nexessary data in the lti_ tables to make sure that
     * the lti_ table correctly match all the data from the incoming post.
     *
     * While this looks like a lot of INSERT and UPDATE statements,
     * the INSERT statements only run when we see a new user/course/link
     * for the first time and after that, we only update is something
     * changes.   So in a high percentage of launches we are not seeing
     * any new or updated data and so this code just falls through and
     * does absolutely no SQL.
     */
    public static function adjustData($p, &$row, $post, $needed) {

        $PDOX = self::getConnection();

        $errormode = $PDOX->getAttribute(\PDO::ATTR_ERRMODE);
        $PDOX->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $actions = array();
        if ( $row['context_id'] === null) {
            $sql = "INSERT INTO {$p}lti_context
                ( context_key, context_sha256, settings_url, title, key_id, created_at, updated_at ) VALUES
                ( :context_key, :context_sha256, :settings_url, :title, :key_id, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':context_key' => $post['context_id'],
                ':context_sha256' => lti_sha256($post['context_id']),
                ':settings_url' => $post['context_settings_url'],
                ':title' => $post['context_title'],
                ':key_id' => $row['key_id']));
            $row['context_id'] = $PDOX->lastInsertId();
            $row['context_title'] = $post['context_title'];
            $row['context_settings_url'] = $post['context_settings_url'];
            $actions[] = "=== Inserted context id=".$row['context_id']." ".$row['context_title'];
        }

        if ( $row['link_id'] === null && isset($post['link_id']) ) {
            $sql = "INSERT INTO {$p}lti_link
                ( link_key, link_sha256, settings_url, title, context_id, created_at, updated_at ) VALUES
                    ( :link_key, :link_sha256, :settings_url, :title, :context_id, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':link_key' => $post['link_id'],
                ':link_sha256' => lti_sha256($post['link_id']),
                ':settings_url' => $post['link_settings_url'],
                ':title' => $post['link_title'],
                ':context_id' => $row['context_id']));
            $row['link_id'] = $PDOX->lastInsertId();
            $row['link_title'] = $post['link_title'];
            $row['link_settings_url'] = $post['link_settings_url'];
            $actions[] = "=== Inserted link id=".$row['link_id']." ".$row['link_title'];
        }

        $user_displayname = isset($post['user_displayname']) ? $post['user_displayname'] : null;
        $user_email = isset($post['user_email']) ? $post['user_email'] : null;
        if ( $row['user_id'] === null && isset($post['user_id']) ) {
            $sql = "INSERT INTO {$p}lti_user
                ( user_key, user_sha256, displayname, email, key_id, created_at, updated_at ) VALUES
                ( :user_key, :user_sha256, :displayname, :email, :key_id, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':user_key' => $post['user_id'],
                ':user_sha256' => lti_sha256($post['user_id']),
                ':displayname' => $user_displayname,
                ':email' => $user_email,
                ':key_id' => $row['key_id']));
            $row['user_id'] = $PDOX->lastInsertId();
            $row['user_email'] = $user_email;
            $row['user_displayname'] = $user_displayname;
            $row['user_key'] = $post['user_id'];
            $actions[] = "=== Inserted user id=".$row['user_id']." ".$row['user_email'];
        }

        if ( $row['membership_id'] === null && $row['context_id'] !== null && $row['user_id'] !== null ) {
            $sql = "INSERT INTO {$p}lti_membership
                ( context_id, user_id, role, created_at, updated_at ) VALUES
                ( :context_id, :user_id, :role, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':context_id' => $row['context_id'],
                ':user_id' => $row['user_id'],
                ':role' => $post['role']));
            $row['membership_id'] = $PDOX->lastInsertId();
            $row['role'] = $post['role'];
            $actions[] = "=== Inserted membership id=".$row['membership_id']." role=".$row['role'].
                " user=".$row['user_id']." context=".$row['context_id'];
        }

        if ( isset($post['service'])) {
            // We need to handle the case where the service URL changes but we already have a sourcedid
            // This is for LTI 1.x only as service is not used for LTI 2.x
            $oldserviceid = $row['service_id'];
            if ( $row['service_id'] === null && $post['service'] ) {
                $sql = "INSERT INTO {$p}lti_service
                    ( service_key, service_sha256, key_id, created_at, updated_at ) VALUES
                    ( :service_key, :service_sha256, :key_id, NOW(), NOW() )";
                $PDOX->queryDie($sql, array(
                    ':service_key' => $post['service'],
                    ':service_sha256' => lti_sha256($post['service']),
                    ':key_id' => $row['key_id']));
                $row['service_id'] = $PDOX->lastInsertId();
                $row['service'] = $post['service'];
                $actions[] = "=== Inserted service id=".$row['service_id']." ".$post['service'];
            }

            // If we just created a new service entry but we already had a result entry, update it
            // This is for LTI 1.x only as service is not used for LTI 2.x
            if ( $oldserviceid === null && $row['result_id'] !== null && $row['service_id'] !== null && $post['service'] ) {
                $sql = "UPDATE {$p}lti_result SET service_id = :service_id WHERE result_id = :result_id";
                $PDOX->queryDie($sql, array(
                    ':service_id' => $row['service_id'],
                    ':result_id' => $row['result_id']));
                $actions[] = "=== Updated result id=".$row['result_id']." service=".$row['service_id'];
            }
        }

        // We always insert a result row if we have a link - we will store
        // grades locally in this row - even if we cannot send grades
        if ( $row['result_id'] === null && $row['link_id'] !== null && $row['user_id'] !== null ) {
            $sql = "INSERT INTO {$p}lti_result
                ( link_id, user_id, created_at, updated_at ) VALUES
                ( :link_id, :user_id, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':link_id' => $row['link_id'],
                ':user_id' => $row['user_id']));
            $row['result_id'] = $PDOX->lastInsertId();
            $actions[] = "=== Inserted result id=".$row['result_id'];
       }

        // Set these values to null if they were not in the post
        if ( ! isset($post['sourcedid']) ) $post['sourcedid'] = null;
        if ( ! isset($post['service']) ) $post['service'] = null;
        if ( ! isset($post['result_url']) ) $post['result_url'] = null;
        if ( ! isset($row['service']) ) {
            $row['service'] = null;
            $row['service_id'] = null;
        }

        // Here we handle updates to sourcedid or result_url including if we
        // just inserted the result row
        if ( $row['result_id'] != null &&
            ($post['sourcedid'] != $row['sourcedid'] || $post['result_url'] != $row['result_url'] ||
            $post['service'] != $row['service'] )
        ) {
            $sql = "UPDATE {$p}lti_result
                SET sourcedid = :sourcedid, result_url = :result_url, service_id = :service_id
                WHERE result_id = :result_id";
            $PDOX->queryDie($sql, array(
                ':result_url' => $post['result_url'],
                ':sourcedid' => $post['sourcedid'],
                ':service_id' => $row['service_id'],
                ':result_id' => $row['result_id']));
            $row['sourcedid'] = $post['sourcedid'];
            $row['service'] = $post['service'];
            $row['result_url'] = $post['result_url'];
            $actions[] = "=== Updated result id=".$row['result_id']." result_url=".$row['result_url'].
                " sourcedid=".$row['sourcedid']." service_id=".$row['service_id'];
        }

        // Here we handle updates to context_title, link_title, user_displayname, user_email, or role
        if ( isset($post['context_title']) && $post['context_title'] != $row['context_title'] ) {
            $sql = "UPDATE {$p}lti_context SET title = :title WHERE context_id = :context_id";
            $PDOX->queryDie($sql, array(
                ':title' => $post['context_title'],
                ':context_id' => $row['context_id']));
            $row['context_title'] = $post['context_title'];
            $actions[] = "=== Updated context=".$row['context_id']." title=".$post['context_title'];
        }

        if ( isset($post['link_title']) && $post['link_title'] != $row['link_title'] ) {
            $sql = "UPDATE {$p}lti_link SET title = :title WHERE link_id = :link_id";
            $PDOX->queryDie($sql, array(
                ':title' => $post['link_title'],
                ':link_id' => $row['link_id']));
            $row['link_title'] = $post['link_title'];
            $actions[] = "=== Updated link=".$row['link_id']." title=".$post['link_title'];
        }

        if ( isset($post['user_displayname']) && $post['user_displayname'] != $row['user_displayname'] && strlen($post['user_displayname']) > 0 ) {
            $sql = "UPDATE {$p}lti_user SET displayname = :displayname WHERE user_id = :user_id";
            $PDOX->queryDie($sql, array(
                ':displayname' => $post['user_displayname'],
                ':user_id' => $row['user_id']));
            $row['user_displayname'] = $post['user_displayname'];
            $actions[] = "=== Updated user=".$row['user_id']." displayname=".$post['user_displayname'];
        }

        if ( isset($post['user_email']) && $post['user_email'] != $row['user_email'] && strlen($post['user_email']) > 0 ) {
            $sql = "UPDATE {$p}lti_user SET email = :email WHERE user_id = :user_id";
            $PDOX->queryDie($sql, array(
                ':email' => $post['user_email'],
                ':user_id' => $row['user_id']));
            $row['user_email'] = $post['user_email'];
            $actions[] = "=== Updated user=".$row['user_id']." email=".$post['user_email'];
        }

        if ( isset($post['role']) && $post['role'] != $row['role'] ) {
            $sql = "UPDATE {$p}lti_membership SET role = :role WHERE membership_id = :membership_id";
            $PDOX->queryDie($sql, array(
                ':role' => $post['role'],
                ':membership_id' => $row['membership_id']));
            $row['role'] = $post['role'];
            $actions[] = "=== Updated membership=".$row['membership_id']." role=".$post['role'];
        }

        // Restore ERRMODE
        $PDOX->setAttribute(\PDO::ATTR_ERRMODE, $errormode);
        return $actions;
    }

    /**
     * Patch the value for the list of needed features
     *
     * Note - causes no harm if called more than once.
     */
    private static function patchNeeded($needed) {
        if ( $needed == self::NONE ) $needed = array();
        if ( $needed == self::ALL ) {
            $needed = array(self::CONTEXT, self::LINK, self::USER);
        }
        if ( is_string($needed) ) $needed = array($needed);
        return $needed;
    }

    /**
     * Optionally handle launch and/or set up the LTI session and global variables
     *
     * This will set up as much of the $USER, $CONTEXT, $LINK,
     * and $RESULT data as it can including leaving them all null
     * if this is called on a request with no LTI launch and no LTI
     * data in the session.  This functions as and performs a
     * PHP session_start().
     *
     * @return Launch A Tsugi Launch object.
     */
    public static function session_start() {
        return self::requireDataPrivate(self::NONE);
    }

    /**
     * Handle launch and/or set up the LTI session and global variables
     *
     * Make sure we have the values we need in the LTI session
     * This routine will not start a session if none exists.  It will
     * die is there if no session_name() (PHPSESSID) cookie or
     * parameter.  No need to create any fresh sessions here.
     *
     * @param $needed (optional, mixed)  Indicates which of
     * the data structures are * needed. If this is omitted,
     * this assumes that CONTEXT, LINK, and USER data are required.
     * If LTIX::NONE is present, then none of the three are rquired.
     * If some combination of the three are needed, this accepts
     * an array of the LTIX::CONTEXT, LTIX: LINK, and LTIX::USER
     * can be passed in.
     *
     * @return Launch A Tsugi Launch object.
     */
    public static function requireData($needed=self::ALL) {
        return self::requireDataPrivate($needed);
    }

    /**
     * Handle the launch, but with the caller given the chance to override defaults
     *
     * @param $pdox array - aproperly initialized  PDO object.  Can be null.
     * @param $request_data array - This must merge the $_POST, $_GET, 
     * and OAuth Header data (in that order - header data has highest priority).
     * See self::oauth_parameters() for the way this data is normally pulled
     * from the three sources and merged into a single array.  Can be null.
     */
    public static function requireDataOverride($needed,
        $pdox, $session_object, $current_url, $request_data) 
    {
        return self::requireDataPrivate($needed,
            $pdox, $session_object, $current_url, $request_data);
    }

    /**
     * Internal method to handle the data setup
     */
    public static function requireDataPrivate($needed=self::ALL, 
        $pdox=null, $session_object=null, $current_url=null, $request_data=null) 
    {
        global $CFG, $TSUGI_LAUNCH;
        global $OUTPUT, $USER, $CONTEXT, $LINK, $RESULT;

        if ( $request_data == null ) $request_data = self::oauth_parameters();

        $TSUGI_LAUNCH = new \Tsugi\Core\Launch();
        if ( isset($OUTPUT) && is_object($OUTPUT) && get_class($OUTPUT) == 'Tsugi\UI\Output' ) {
            $TSUGI_LAUNCH->output = $OUTPUT;
        } else {
            $TSUGI_LAUNCH->output = new \Tsugi\UI\Output();
        }

        $USER = null;
        $CONTEXT = null;
        $LINK = null;
        $RESULT = null;

        // Make sure to initialize the global connection object
        if ( $pdox === null ) {
            $PDOX = self::getConnection();
        } else {
            $PDOX = $pdox;
        }
        $TSUGI_LAUNCH->pdox = $PDOX;

        $needed = self::patchNeeded($needed);

        // Check if we are processing an LTI launch.  If so, handle it
        $newlaunch = self::launchCheck($needed, $session_object, $request_data);

        // If launchCheck comes back with a true, it means someoine above us
        // needs to do the redirect
        if ( $newlaunch ) {
            return $TSUGI_LAUNCH;
        }
        

        // Check to see if the session already exists.
        if ( $session_object === null ) {
            $sess = session_name();
            if ( ini_get('session.use_cookies') == '0' ) {
                if ( $newlaunch || isset($_POST[$sess]) || isset($_GET[$sess]) ) {
                    // We tried to set a session..
                } else {
                    if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
                        self::send403();
                        self::abort_with_error_log('Missing '.$sess.' from POST data');
                    } else if ( count($needed) > 0 ) {
                        self::send403();
                        self::abort_with_error_log('This tool should be launched from a learning system using LTI');
                    }
                }
            }

            // Start a session if it has not been started..
            if ( session_id() == "" ) {
                session_start();  // Should reassociate
            }
        }

        // This happens from time to time when someone closes and reopens a laptop
        // Or their computer goes to sleep and wakes back up hours later.
        // So it is just a warning - nothing much we can do except tell them.
        if ( count($needed) > 0 && self::wrapped_session_get($session_object, 'lti',false) === false ) {
            self::send403(); error_log('Session expired - please re-launch '.session_id());
            die('Session expired - please re-launch'); // with error_log
        }

        // Check the referrer...
        $trusted = $session_object != null || self::checkReferer() || self::checkCSRF();

        // Check to see if we switched browsers or IP addresses
        // TODO: Change these to warnings once we get more data
        $session_agent = self::wrapped_session_get($session_object, 'HTTP_USER_AGENT', null);
        if ( (!$trusted) && $session_agent != null ) {
            if ( (!isset($_SERVER['HTTP_USER_AGENT'])) ||
                $_SERVER['HTTP_USER_AGENT'] != $session_agent ) {
                self::send403();
                self::abort_with_error_log("Session has expired", " ".session_id()." HTTP_USER_AGENT ".
                    (($session_agent !== null ) ? $session_agent : 'Empty Session user agent') .
                    ' ::: '.
                    (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Empty browser user agent'),
                false,'DIE:');
            }
        }

        // We only check the first three octets as some systems wander throught the addresses on
        // class C - Perhaps it is even NAT - who knows - but we forgive those on the same Class C
        $session_addr = self::wrapped_session_get($session_object, 'REMOTE_ADDR', null);
        if ( (!$trusted) &&  $session_addr != null && isset($_SERVER['REMOTE_ADDR']) ) {
            $sess_pieces = explode('.',$session_addr);
            $serv_pieces = explode('.',$_SERVER['REMOTE_ADDR']);
            if ( count($sess_pieces) == 4 && count($serv_pieces) == 4 ) {
                if ( $sess_pieces[0] != $serv_pieces[0] || $sess_pieces[1] != $serv_pieces[1] ||
                    $sess_pieces[2] != $serv_pieces[2] ) {
                    self::send403();
                    self::abort_with_error_log('Session address has expired', " ".session_id()." REMOTE_ADDR ".
                        $session_addr.' '.$_SERVER['REMOTE_ADDR'], 'DIE:');
                }
            }
        }

        // Check to see if the user has navigated to a new place in the hierarchy
        $session_script = self::wrapped_session_get($session_object, 'script_path', null);
        if ( $session_script !== null &&
            (! endsWith(Output::getUtilUrl(''), $CFG->getScriptPath()) ) &&
            strpos($CFG->getScriptPath(), $session_script ) !== 0 ) {
            self::send403();
            self::abort_with_error_log('Improper navigation detected', " ".session_id()." script_path ".
                $session_script.' /  '.$CFG->getScriptPath(), 'DIE:');
        }

        // Check to see if the session needs to be extended due to this request
        self::checkHeartBeat($session_object);

        // Restart the number of continuous heartbeats
        self::wrapped_session_put($session_object, 'HEARTBEAT_COUNT', 0);

        // We don't have any launch data and don't need it
        $LTI = self::wrapped_session_get($session_object, 'lti', null);
        if ( count($needed) == 0 && $LTI === null ) {
            return $TSUGI_LAUNCH;
        }

        if ( is_array($needed) ) {
            foreach ( $needed as $feature ) {
                if ( isset($LTI[$feature]) ) continue;
                self::abort_with_error_log("This tool requires an LTI launch parameter:".$feature);
            }
        }

        // Populate the $USER $CONTEXT and $LINK objects
        if ( isset($LTI['user_id']) && ! is_object($USER) ) {
            $USER = new \Tsugi\Core\User();
            $USER->launch = $TSUGI_LAUNCH;
            $USER->id = $LTI['user_id'];
            if (isset($LTI['user_email']) ) $USER->email = $LTI['user_email'];
            if (isset($LTI['user_displayname']) ) {
                $USER->displayname = $LTI['user_displayname'];
                $pieces = explode(' ',$USER->displayname);
                if ( count($pieces) > 0 ) $USER->firstname = $pieces[0];
                if ( count($pieces) > 1 ) $USER->lastname = $pieces[count($pieces)-1];
            }
            $USER->instructor = isset($LTI['role']) && $LTI['role'] != 0 ;
            $TSUGI_LAUNCH->user = $USER;
        }

        if ( isset($LTI['context_id']) && ! is_object($CONTEXT) ) {
            $CONTEXT = new \Tsugi\Core\Context();
            $CONTEXT->launch = $TSUGI_LAUNCH;
            $CONTEXT->id = $LTI['context_id'];
            if (isset($LTI['context_title']) ) $CONTEXT->title = $LTI['context_title'];
            $TSUGI_LAUNCH->context = $CONTEXT;
        }

        if ( isset($LTI['link_id']) && ! is_object($LINK) ) {
            $LINK = new \Tsugi\Core\Link();
            $LINK->launch = $TSUGI_LAUNCH;
            $LINK->id = $LTI['link_id'];
            if (isset($LTI['link_title']) ) $LINK->title = $LTI['link_title'];
            $TSUGI_LAUNCH->link = $LINK;
        }

        if ( isset($LTI['result_id']) && ! is_object($RESULT) ) {
            $RESULT = new \Tsugi\Core\Result();
            $RESULT->launch = $TSUGI_LAUNCH;
            $RESULT->id = $LTI['result_id'];
            if (isset($LTI['grade']) ) $RESULT->grade = $LTI['grade'];
            $TSUGI_LAUNCH->result = $RESULT;
        }

        // Return the Launch structure
        return $TSUGI_LAUNCH;
    }

    /**
     * Dump out the internal data structures adssociated with the
     * current launch.  Best if used within a pre tag.
     */
    public static function var_dump() {
        global $USER, $CONTEXT, $LINK, $RESULT;
        echo('$USER:'."\n");
        if ( ! isset($USER) ) {
            echo("Not set\n");
        } else {
            var_dump($USER);
        }
        echo('$CONTEXT:'."\n");
        if ( ! isset($CONTEXT) ) {
            echo("Not set\n");
        } else {
            var_dump($CONTEXT);
        }
        echo('$LINK:'."\n");
        if ( ! isset($LINK) ) {
            echo("Not set\n");
        } else {
            var_dump($LINK);
        }
        echo('$RESULT:'."\n");
        if ( ! isset($RESULT) ) {
            echo("Not set\n");
        } else {
            var_dump($RESULT);
        }
        echo("\n<hr/>\n");
        echo("Session data (low level):\n");
        if ( ! isset($_SESSION) ) {
            echo("Not set\n");
        } else {
            echo(Output::safe_var_dump($_SESSION));
        }
    }

    /**
     * Load the grade for a particular row and update our local copy (Deprecated - moved to Result)
     *
     * Call the right LTI service to retrieve the server's grade and
     * update our local cached copy of the server_grade and the date
     * retrieved. This routine pulls the key and secret from the LTIX
     * session to avoid crossing cross tennant boundaries.
     *
     * TODO: Add LTI 2.x support for the JSON style services to this
     *
     * @param $row An optional array with the data that has the result_id, sourcedid,
     * and service (url) if this is not present, the data is pulled from the LTI
     * session for the current user/link combination.
     * @param $debug_log An (optional) array (by reference) that returns the
     * steps that were taken.
     * Each entry is an array with the [0] element a message and an optional [1]
     * element as some detail (i.e. like a POST body)
     *
     * @return mixed If this work this returns a float.  If not you get
     * a string with an error.
     *
     */
    public static function gradeGet($row=false, &$debug_log=false) {
        global $RESULT;
        if ( isset($RESULT) ) return $RESULT->gradeGet($row,$debug_log);
        return 'LTIX::gradeGet $RESULT not set';
    }

    /**
     * Send a grade and update our local copy (Deprecated - moved to Result)
     *
     * Call the right LTI service to send a new grade up to the server.
     * update our local cached copy of the server_grade and the date
     * retrieved. This routine pulls the key and secret from the LTIX
     * session to avoid crossing cross tennant boundaries.
     *
     * @param $grade A new grade - floating point number between 0.0 and 1.0
     * @param $row An optional array with the data that has the result_id, sourcedid,
     * and service (url) if this is not present, the data is pulled from the LTI
     * session for the current user/link combination.
     * @param $debug_log An (optional) array (by reference) that returns the
     * steps that were taken.
     * Each entry is an array with the [0] element a message and an optional [1]
     * element as some detail (i.e. like a POST body)
     *
     * @return mixed If this works it returns true.  If not, you get
     * a string with an error.
     *
     */
    public static function gradeSend($grade, $row=false, &$debug_log=false) {
        global $RESULT;
        if ( isset($RESULT) ) return $RESULT->gradeSend($grade,$row,$debug_log);
        return 'LTIX::gradeSend $RESULT not set';
    }

    /**
     * Send a grade applying the due date logic and only increasing grades (Deprecated - moved to Result)
     *
     * Puts messages in the session for a redirect.
     *
     * @param $gradetosend - The grade in the range 0.0 .. 1.0
     * @param $oldgrade - The previous grade in the range 0.0 .. 1.0 (optional)
     * @param $dueDate - The due date for this assignment
     */
    public static function gradeSendDueDate($gradetosend, $oldgrade=false, $dueDate=false) {
        global $RESULT;
        if ( isset($RESULT) ) return $RESULT->gradeSendDueDate($gradetosend,$oldgrade,$dueDate);
        return 'LTIX::gradeSendDueDate $RESULT not set';
    }

    /**
     * signParameters - Look up the key and secret and call the underlying code in LTI
     */
    public static function signParameters($oldparms, $endpoint, $method,
        $submit_text = false, $org_id = false, $org_desc = false) {

        $oauth_consumer_key = self::ltiParameter('key_key');
        $oauth_consumer_secret = self::ltiParameter('secret');

        return LTI::signParameters($oldparms, $endpoint, $method, $oauth_consumer_key, $oauth_consumer_secret,
            $submit_text, $org_id, $org_desc);
    }
    /**
      * Send settings to the LMS using the simple JSON approach
      */
    public static function settingsSend($settings, $settings_url, &$debug_log=false) {

        $key_key = self::ltiParameter('key_key');
        $secret = self::ltiParameter('secret');

        $retval = LTI::sendJSONSettings($settings, $settings_url, $key_key, $secret, $debug_log);
        return $retval;
    }

    /**
     * Send a Caliper Body to the correct URL using the key and secret
     *
     * This is not yet a standard or production - it uses the Canvas
     * extension only.
     *
     */
    public static function caliperSend($caliperBody, $content_type='application/json', &$debug_log=false)
    {

        $caliperURL = LTIX::ltiRawParameter('custom_sub_canvas_caliper_url');
        if ( strlen($caliperURL) == 0 ) {
            if ( is_array($debug_log) ) $debug_log[] = array('custom_sub_canvas_caliper_url not found in launch data');
            return false;
        }

        $key_key = self::ltiParameter('key_key');
        $secret = self::ltiParameter('secret');

        $retval = LTI::sendJSONBody("POST", $caliperBody, $content_type,
            $caliperURL, $key_key, $secret, $debug_log);
        return $retval;
    }

    /**
     * Send a JSON Body to a URL after looking up the key and secret
     */
    public static function jsonSend($method, $postBody, $content_type,
        $service_url, &$debug_log=false) {

        $key_key = self::ltiParameter('key_key');
        $secret = self::ltiParameter('secret');

        $retval = LTI::sendJSONBody($method, $postBody, $content_type,
            $service_url, $key_key, $secret, $debug_log);
        return $retval;
    }

    /**
     * ltiLinkUrl - Returns true if we can return LTI Links for this launch
     *
     * @return string The content_item_return_url or false
     */
    public static function ltiLinkUrl($postdata=false) {
        return LTI::ltiLinkUrl(self::ltiRawPostArray());
    }

    /**
     * getKeySecretForLaunch - Retrieve a Key/Secret for a Launch
     *
     * @param $url - The url to lookup
     */
    public static function getKeySecretForLaunch($url) {
        global $CFG, $CONTEXT;

        $PDOX = self::getConnection();
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $key_id = self::ltiParameter('key_id', false);
        if ( $key_id == false ) return false;

        $sql = "SELECT consumer_key, secret FROM {$CFG->dbprefix}lti_domain
            WHERE domain = :DOM AND key_id = :KID";
        $values = array(":DOM" => $host, ":KID" => $key_id);
        if ( isset($CONTEXT->id) ) {
            $sql .= " AND (context_id IS NULL OR context_id = :CID)
                ORDER BY context_id DESC";
            $values[':CID'] = $CONTEXT->id;
        }

        $row = $PDOX->rowDie($sql, $values);
        if ( $row === false ) {
            error_log("Unable to key/secret key_id=$key_id url=$url");
            return false;
        }
        $row['key'] = $row['consumer_key'];
        return $row;
    }


    /**
     * curPageUrl - Returns the URL to the currently executing script with query string
     *
     * This is useful when we want to do OAuth where we need the exact
     * incoming path but our host, protocol, and port might be messed up
     * by a proxy or CDN.
     *
     * URL                              Result
     * http://x.com/data                http://x.com/data
     * http://x.com/data/index.php      http://x.com/data/index.php
     * http://x.com/data/index.php?y=1  http://x.com/data/index.php?y=1
     */
    public static function curPageUrl() {
        return self::curPageUrlBase() .  $_SERVER['REQUEST_URI'];
    }

    /**
     * curPageUrlScript - Returns the URL to the currently executing script
     *
     * This is useful when we want to make a URL to another script at this location.
     * Often we use this with str_replace().
     *
     *     URL                              Result
     *     http://x.com/data                http://x.com/data/index.php
     *     http://x.com/data/index.php      http://x.com/data/index.php
     *     http://x.com/data/index.php?y=1  http://x.com/data/index.php
     *
     *      http://stackoverflow.com/questions/279966/php-self-vs-path-info-vs-script-name-vs-request-uri
     * 
     *      http://example.com/bob
     *      REQUEST_URI = /bob
     *      PHP_SELF = /bob/index.php
     */
    public static function curPageUrlScript() {
        return self::curPageUrlBase() .  $_SERVER['PHP_SELF'];
    }

    /**
     * curPageUrlBase - Returns the protocol, host, and port for the current URL
     *
     * This is useful when we are running behind a proxy like ngrok
     * or CloudFlare.  These proxies will accept with the http or
     * https version of the URL but our web server will likely only
     * se the incoming request as http.  So we need to fall back
     * to $CFG->wwwroot and reconstruct the right URL from there.
     * Since the wwwroot might have some of the request URI, like
     *
     *     http://tsugi.ngrok.com/tsugi
     *
     * We need to parse the wwwroot and put things back together.
     *
     * URL                              Result
     * http://x.com/data                http://x.com
     * http://x.com/data/index.php      http://x.com
     * http://x.com/data/index.php?y=1  http://x.com
     *
     * @return string The current page protocol, host, and optionally port URL
     */

    public static function curPageUrlBase() {
        global $CFG;

        $pieces = parse_url($CFG->wwwroot);

        if ( isset($pieces['scheme']) ) {
            $scheme = $pieces['scheme'];
        } else {
            $scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
                ? 'http' : 'https';
        }

        if ( isset($pieces['port']) ) {
            $port = ':'.$pieces['port'];
        } else {
            $port = '';
            if ( $_SERVER['SERVER_PORT'] != "80" && $_SERVER['SERVER_PORT'] != "443" &&
                strpos(':', $_SERVER['HTTP_HOST']) < 0 ) {
                $port =  ':' . $_SERVER['SERVER_PORT'] ;
            }
        }
        $host = isset($pieces['host']) ? $pieces['host'] : $_SERVER['HTTP_HOST'];

        $http_url = $scheme .  '://' . $host .  $port;
        return $http_url;
    }

    // See if we need to extend our session (heartbeat)
    // http://stackoverflow.com/questions/520237/how-do-i-expire-a-php-session-after-30-minutes
    private static function checkHeartBeat() {
        if ( session_id() == "" ) return;  // This should not start the session

        if ( isset($CFG->sessionlifetime) ) {
            if (isset($_SESSION['LAST_ACTIVITY']) ) {
                $heartbeat = $CFG->sessionlifetime/4;
                $ellapsed = time() - $_SESSION['LAST_ACTIVITY'];
                if ( $ellapsed > $heartbeat ) {
                    $_SESSION['LAST_ACTIVITY'] = time(); // update last activity time stamp
                    // TODO: Remove this after verification
                    $filename = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
                    error_log("Heartbeat ".session_id().' '.$ellapsed.' '.$filename);
                }
            } else {
                $_SESSION['LAST_ACTIVITY'] = time(); // update last activity time stamp
            }
        }
    }

    private static function send403() {
        header("HTTP/1.1 403 Forbidden");
    }

    // Returns true for a good referrer and false if we could not verify it
    private static function checkReferer() {
        global $CFG;
        return isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'],$CFG->wwwroot) === 0 ;
    }

    // Returns true for a good CSRF and false if we could not verify it
    private static function checkCSRF() {
        global $CFG;
        if ( ! isset($_SESSION['CSRF_TOKEN']) ) return false;
        $token = $_SESSION['CSRF_TOKEN'];
        if ( isset($_POST['CSRF_TOKEN']) && $token == $_POST['CSRF_TOKEN'] ) return true;
        $headers = array_change_key_case(apache_request_headers());
        if ( isset($headers['x-csrf-token']) && $token == $headers['x-csrf-token'] ) return true;
        if ( isset($headers['x-csrftoken']) && $token == $headers['x-csrftoken'] ) return true;
        return false;
    }

    // Check the secure cookie and set login information appropriately
    public static function loginSecureCookie() {
        global $CFG, $PDOX;
        $pieces = false;
        $id = false;

        // Only do this if we are not already logged in...
        if ( isset($_SESSION["id"]) || !isset($_COOKIE[$CFG->cookiename]) ||
                !isset($CFG->cookiepad) || $CFG->cookiepad === false) {
            return;
        }

        $ct = $_COOKIE[$CFG->cookiename];
        // error_log("Cookie: $ct \n");
        $pieces = SecureCookie::extract($ct);
        if ( $pieces === false ) {
            error_log('Decrypt fail:'.$ct);
            SecureCookie::delete();
            return;
        }

        // Convert to an integer and check valid
        $user_id = $pieces[0] + 0;
        $userSHA = $pieces[1];
        if ( $user_id < 1 ) {
            $user_id = false;
            $pieces = false;
            error_log('Decrypt bad ID:'.$pieces[0].','.$ct);
            SecureCookie::delete();
            return;
        }

        // The profile table might not even exist yet.
        $stmt = $PDOX->queryReturnError(
            "SELECT P.profile_id AS profile_id, P.displayname AS displayname,
                P.email as email, U.user_id as user_id
                FROM {$CFG->dbprefix}profile AS P
                LEFT JOIN {$CFG->dbprefix}lti_user AS U
                ON P.profile_id = U.profile_id AND user_sha256 = profile_sha256 AND
                    P.key_id = U.key_id
                WHERE profile_sha256 = :SHA AND U.user_id = :UID LIMIT 1",
            array('SHA' => $userSHA, ":UID" => $user_id)
        );

        if ( $stmt->success === false ) return;

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ( $row === false ) {
            error_log("Unable to load user_id=$user_id SHA=$userSHA");
            SecureCookie::delete();
            return;
        }

        $_SESSION["id"] = $row['user_id'];
        $_SESSION["email"] = $row['email'];
        $_SESSION["displayname"] = $row['displayname'];
        $_SESSION["profile_id"] = $row['profile_id'];

        error_log('Autologin:'.$row['user_id'].','.$row['displayname'].','.
            $row['email'].','.$row['profile_id']);

    }

    /**
     * getCoreLaunchData - Get the launch data common across launch types
     */
    public static function getCoreLaunchData()
    {
        global $CFG, $CONTEXT, $USER, $CONTEXT, $LINK;
        $ltiProps = array();
        $ltiProps[LTIConstants::LTI_VERSION] = LTIConstants::LTI_VERSION_1;
        $ltiProps[LTIConstants::CONTEXT_ID] = $CONTEXT->id;
        $ltiProps[LTIConstants::ROLES] = $USER->instructor ? LTIConstants::ROLE_INSTRUCTOR : LTIConstants::ROLE_LEARNER;
        $ltiProps[LTIConstants::USER_ID] = $USER->id;
        $ltiProps[LTIConstants::LIS_PERSON_NAME_FULL] = $USER->displayname;
        $ltiProps[LTIConstants::LIS_PERSON_CONTACT_EMAIL_PRIMARY] = $USER->email;

        $ltiProps['tool_consumer_instance_guid'] = $CFG->product_instance_guid;
        $ltiProps['tool_consumer_instance_description'] = $CFG->servicename;

        return $ltiProps;
    }

    /**
     * getLaunchData - Get the launch data for a normal LTI 1.x launch
     */
    public static function getLaunchData()
    {
        global $CFG, $CONTEXT, $USER, $CONTEXT, $LINK;
        $ltiProps = self::getCoreLaunchData();
        $ltiProps[LTIConstants::LTI_MESSAGE_TYPE] = LTIConstants::LTI_MESSAGE_TYPE_BASICLTILAUNCHREQUEST;
        $ltiProps[LTIConstants::RESOURCE_LINK_ID] = $LINK->id;

        $ltiProps['tool_consumer_instance_guid'] = $CFG->product_instance_guid;
        $ltiProps['tool_consumer_instance_description'] = $CFG->servicename;

        return $ltiProps;
    }

    /**
     * getLaunchData - Get the launch data for am LTI ContentItem launch
     */
    public static function getContentItem($contentReturn, $dataProps)
    {
        global $CFG, $CONTEXT, $USER, $CONTEXT, $LINK;
        $ltiProps = self::getCoreLaunchData();
        $ltiProps[LTIConstants::LTI_MESSAGE_TYPE] = LTIConstants::CONTENT_ITEM_SELECTION_REQUEST;
        $ltiProps[LTIConstants::ACCEPT_MEDIA_TYPES] = LTIConstants::MEDIA_LTILINKITEM;
        $ltiProps[LTIConstants::ACCEPT_PRESENTATION_DOCUMENT_TARGETS] = "iframe,window"; // Nice to add overlay
        $ltiProps[LTIConstants::ACCEPT_UNSIGNED] = "true";
        $ltiProps[LTIConstants::ACCEPT_MULTIPLE] = "false";
        $ltiProps[LTIConstants::ACCEPT_COPY_ADVICE] = "false"; // ???
        $ltiProps[LTIConstants::AUTO_CREATE] = "true";
        $ltiProps[LTIConstants::CAN_CONFIRM] = "false";
        $ltiProps[LTIConstants::CONTENT_ITEM_RETURN_URL] = $contentReturn;
        $ltiProps[LTIConstants::LAUNCH_PRESENTATION_RETURN_URL] = $contentReturn;

        // This is needed to trigger WarpWire to send us back the link
        $ltiProps['tool_consumer_info_product_family_code'] = 'canvas';
        $ltiProps['custom_canvas_course_id'] = $CONTEXT->id;

        return $ltiProps;
    }

    /**
     * getLaunchData - Get the launch data for am LTI ContentItem launch
     */
    public static function getLaunchUrl($endpoint, $debug=false)
    {
        $launchurl = Output::getUtilUrl('/launch.php?debug=');
        $launchurl .= ($debug) ? '1':'0';
        $launchurl .= '&endpoint=';
        $launchurl .= urlencode($endpoint);
        return $launchurl;
    }

    /**
     * getLaunchContent - Get the launch data for am LTI ContentItem launch
     */
    public static function getLaunchContent($endpoint, $debug=false)
    {
            $info = LTIX::getKeySecretForLaunch($endpoint);
            if ( $info === false ) {
                return '<p style="color:red">Unable to load key/secret for '.htmlentities($endpoint)."</p>\n";
            }
            $key = $info['key'];
            $secret = $info['secret'];

            $parms = LTIX::getLaunchData();

            $parms = LTI::signParameters($parms, $endpoint, "POST", $key, $secret, "Button");

            $content = LTI::postLaunchHTML($parms, $endpoint, false);
            return $content;
    }

    /**
     * We are aborting this request.  If this is a launch, redirect back
     */
    private static function abort_with_error_log($msg, $extra=false, $prefix="DIE:") {
        $return_url = isset($_POST['launch_presentation_return_url']) ? $_POST['launch_presentation_return_url'] : null;
        if ($return_url === null) {
            die_with_error_log($msg,$extra,$prefix);
        }
        $return_url .= ( strpos($return_url,'?') > 0 ) ? '&' : '?';
        $return_url .= 'lti_errormsg=' . urlencode($msg);
        if ( $extra !== false ) $return_url .= '&detail=' . urlencode($extra);
        header("Location: ".$return_url); 
        error_log($prefix.' '.$msg.' '.$extra);
        exit();
    }

}
