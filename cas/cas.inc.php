<?php
/**
 * @param boolean $enforce Should login be enforced?  If FALSE, 
 * all login redirects should be handled in
 * this function call.  If FALSE, existing session
 * or cookies may be used to create a user, but login
 * is not enforced. This us useful for pages allowing
 * guest access
 *
 * @param object $config Global RPC_Config configuration singleton
 *
 * @param object $db Global MySQLi database connection singleton
 *
 * @return string Pipe-delimited string in the format:
 * (OK/FAIL)|username|email|permissions|fail-reason
 *
 *	Optional fields may be empty, but pipe delimiters must not be omitted
 *
 *	OK/FAIL: 		Status of the authentication attempt
 *	username: 		Unique username
 *	email: 			<optional> Email address
 *	permissions:	<optional> Integer representation of authlevel bits defined in RPC_User
 *	fail-reason:	<optional> A human-readable string describing reason for auth failure
 *
 *	** ALTERNATIVE RETURN OBJECT **
 *	Alternatively, you may return a valid and complete RPC_User object
 *	from rpc_authenticate().  If you choose to return a valid RPC_User,
 *	the local users table WILL NOT be checked, and it will be assumed
 *	that your user already exists locally.  Therefore you should only
 *	return an object if you are certain the user exists.
 **/

function rpc_authenticate($enforce=TRUE, $config=NULL, $db=NULL) {
    include_once('/var/www/html/rpc/plugins/auth/phpCAS-1.3.3/CAS.php');
    $CAS_HOSTNAME = 'castest.ucmerced.edu';
    $CAS_PORT = 443;
    $CAS_URL = '/cas';
    $CAS_VERSION = 'S1';
    phpCAS::setDebug('/tmp/casdebug');
    phpCAS::client($CAS_VERSION, $CAS_HOSTNAME, $CAS_PORT, $CAS_URL, false);
    phpCAS::setNoCasServerValidation();

    try
    {
        phpCAS::forceAuthentication();

    } catch (Exception $ex)
    {
        print('CAS exception: ' . $ex . "\n");
        print('Please contact the IT Help Desk.');
        return "FAIL | NULL";
    }

    $isAuth = phpCAS::isSessionAuthenticated();
    $username = phpCAS::getUser();
    return "OK | $username";
}

/**
 * Logout the active user, calling your own necessary logout hooks and procedures
 *
 * @param object $user RPC_User (or derivative) to logout
 *
 * @return void
 */

function rpc_logout($user) {
    //logout locally
    $auth=$this->getAuthService();
    $auth->clearIdentity();
    session_destroy();
    //logout of CAS
    $CAS=$this->getCAS();
    $CAS::client(SAML_VERSION_1_1,"castest.ucmerced.edu",443,"/cas",false);
    $CAS::logoutWithRedirectService("http://" . $_SERVER['SERVER_NAME'] . "/");
}
?>