<?php
/**
 * Copyright 2010 by the Regents of the University of Minnesota,
 * University Libraries - Minitex
 *
 * This file is part of The Research Project Calculator (RPC).
 *
 * RPC is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * RPC is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with The RPC.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once(dirname(__FILE__) . "/../../../inc/rpc_user.inc.php");
require_once(dirname(__FILE__) . "/../../../inc/rpc_smarty.inc.php");
/**
 * Class Native_User
 * RPC users utilizing the 'native' authentication plugin
 *
 * @package RPC
 */
class Native_User extends RPC_User
{
	const ERR_CANNOT_RESET_PASSWORD = 101;
	const ERR_CANNOT_SEND_PASSWORD = 102;
	const ERR_INCORRECT_CREDS = 103;
	const ERR_CANNOT_SET_COOKIE = 104;
	const ERR_PASWORD_COMPLEXITY_UNMET = 105;

	/**
	 * SHA1 sum of password stored
	 *
	 * @var string
	 * @access public
	 */
	public $password_hash;
	/**
	 * Password salt
	 *
	 * @var string
	 * @access private
	 */
	private $salt;
	/**
	 * Authentication session ID used to identify browser session and paired with token
	 * A single user may have several valid sessions in different browser cookies.
	 *
	 * @var mixed
	 * @access public
	 */
	public $session;
	/**
	 * Authentication token, used to validate cookie and changed on every page access
	 * to reduce the effectiveness of cookie theft
	 *
	 * @var string
	 * @access public
	 */
	public $token;
	/**
	 * User has successfully supplied credentials
	 *
	 * @var boolean
	 * @access public
	 */
	public $is_authenticated = FALSE;

	/**
	 * Constructor implements parent constructor, adding password and
	 * authentication information
	 *
	 * @param string $username User to retrieve
	 * @param object $config RPC_Config configuration singleton
	 * @param object $db RPC_DB MySQLi database connection singleton
	 * @access public
	 * @return RPC_User
	 */
	public function __construct($username, $config, $db)
	{
		$this->config = $config;
		$this->db = $db;
		$dbusername = $this->db->real_escape_string(strtoupper($username));
		$qry = sprintf("SELECT userid, username, password, passwordsalt, email, name, usertype, perms, token FROM users WHERE UPPER(username) = '%s'", $dbusername);
		if ($result = $this->db->query($qry))
		{
			// Got results, load object
			if ($row = $result->fetch_assoc())
			{
				$this->id = intval($row['userid']);
				$this->username = htmlentities($row['username'], ENT_QUOTES);
				// If password hasn't been set yet, it will be NULL but $this->set_password expects an empty string,
				// and the empty string must be hashed.
				$this->password_hash = $row['password'] !== NULL ? $row['password'] : sha1("");
				$this->salt = $row['passwordsalt'];
				$this->token = $row['token'];
				$this->name = !empty($row['name']) ? htmlentities($row['name'], ENT_QUOTES) : htmlentities($row['username'], ENT_QUOTES);
				$this->email = htmlentities($row['email'], ENT_QUOTES);
				$this->type = $row['usertype'];
				$this->raw_perms_int = intval($row['perms']);
				// Check user, publisher, and admin bits
				$this->is_user = $this->raw_perms_int & self::RPC_AUTHLEVEL_USER ? TRUE : FALSE;
				$this->is_publisher = $this->raw_perms_int & self::RPC_AUTHLEVEL_PUBLISHER ? TRUE : FALSE;
				$this->is_administrator = $this->raw_perms_int & self::RPC_AUTHLEVEL_ADMINISTRATOR ? TRUE : FALSE;

				// Check if this user is a config.inc.php defined superuser
				// Presently, the superusers have no representation in the database perms.  They are strictly
				// defined in config.inc.php!
				if (in_array($this->username, $this->config->auth_superusers))
				{
					// Set administrator if doesn't already have it.  This should never happen except at installation
					if (!$this->is_administrator)
					{
						$this->grant_permission(RPC_User::RPC_AUTHLEVEL_ADMINISTRATOR);
					}
					$this->is_superuser = TRUE;
					$this->raw_perms_int |= self::RPC_AUTHLEVEL_SUPERUSER;
				}
			}
			// No result, so no such user
			else
			{
				$this->error = self::ERR_NO_SUCH_USER;
			}
		}
		// Some database failure and the query didn't finish
		else
		{
			$this->error = self::ERR_DB_ERROR;
		}
		return;
	}

	/**
	 * Native users have email and usernames the same.
	 * This overrides RPC_User::set_email() to also update
	 * the username
	 *
	 * @param string $email
	 * @access public
	 * @return boolean
	 */
	public function set_email($email)
	{
		if (self::validate_email($email))
		{
			$qryemail = $this->db->real_escape_string(strtolower($email));
			$qry = sprintf("UPDATE users SET username='%s', email='%s' WHERE userid=%u", $qryemail, $qryemail, $this->id);
			if ($result = $this->db->query($qry))
			{
				$this->username = $email;
				$this->email = $email;
				return TRUE;
			}
		}
		else
		{
			$this->error = self::ERR_INVALID_INPUT;
			return FALSE;
		}

	}

	/**
	 * Authenticate user with a password
	 *
	 * @param string $password
	 * @param boolean $stay_logged_in On successful login, should the login be persistent beyond this session?
	 * @access public
	 * @return boolean
	 */
	public function validate_password($password, $stay_logged_in=FALSE)
	{
		$qry = sprintf("SELECT 1 FROM users WHERE username = '%s' AND password = '%s';",
					$this->db->real_escape_string($this->username),
					$this->db->real_escape_string(sha1($password . $this->salt))
				);
		if ($result = $this->db->query($qry))
		{
			// Successful authentication
			if ($result->num_rows === 1)
			{
				$this->set_authenticated();

				// If salt is blank (legacy user), call _set_password on the current pw
				// This generates a new proper salt.
				if ($this->salt === '')
				{
					if ($this->_set_password($password)) $this->db->commit();
				}
				// Set a permanent cookie if requested
				if ($stay_logged_in)
				{
					$this->start_session();
				}
				return TRUE;
			}
			// Bad password
			else
			{
				$this->error = self::ERR_INCORRECT_CREDS;
				return FALSE;
			}
		}
	}
	/**
	 * Set the user's status to authenticated. Also sets username in $_SESSION
	 *
	 * @access public
	 * @return void
	 */
	public function set_authenticated()
	{
		$this->is_authenticated = TRUE;
		$_SESSION['username'] = $this->username;
	}
	/**
	 * Create a new session id in native_sessions
	 *
	 * @access public
	 * @return void
	 */
	public function start_session()
	{
		// Just remove the old one.
		$this->destroy_session();
		$session = $this->db->real_escape_string(md5(time() . rand()));
		$token = $this->db->real_escape_string(md5(time() . rand()));
		$qry = sprintf("INSERT INTO native_sessions (userid, session, token) VALUES (%u, '%s', '%s');", $this->id, $session, $token);
		if ($result = $this->db->query($qry))
		{
			$this->db->commit();
			$this->session = $session;
			$this->token = $token;
			$this->set_cookie();
		}
	}
	/**
	 * Delete native_sessions record for this cookie login session
	 *
	 * @access public
	 * @return void
	 */
	public function destroy_session()
	{
		$qry = sprintf("DELETE FROM native_sessions WHERE userid = %u AND session = '%s';", $this->id, $this->db->real_escape_string($this->session));
		if ($result = $this->db->query($qry))
		{
			$this->db->commit();
			$this->session = "";
			$this->token = "";
		}
		return;
	}
	/**
	 * Generate a new authentication token for user
	 * Called by self::validate_cookie() so a new token is generated
	 * on each page access.
	 *
	 * @access public
	 * @return void
	 */
	public function set_token()
	{
		$token = md5(time() . rand());
		$qry = sprintf("UPDATE native_sessions SET token = '%s' WHERE userid = %u AND session = '%s';", $this->db->real_escape_string($token), $this->id, $this->db->real_escape_string($this->session));
		if ($result = $this->db->query($qry))
		{
			$this->db->commit();
			$this->token = $token;
		}
		return;
	}
	/**
	 * Set an authentication cookie for this user
	 *
	 * @access public
	 * @return boolean
	 */
	public function set_cookie()
	{
		// User must already be signed in.
		if (!$this->is_authenticated)
		{
			$this->error = self::ERR_CANNOT_SET_COOKIE;
			return FALSE;
		}
		// Cookie value is base64 encoded user|token
		$value = base64_encode($this->username . "|" . $this->session . "|" . $this->token);
		// Expire in ten years
		$expire = time() + (24*3600*356*10);
		$cookie_path = $this->config->app_relative_web_path == "" ? "/" : $this->config->app_relative_web_path;
		$cookie = setcookie('RPCAUTH', $value, $expire, $cookie_path, $_SERVER['SERVER_NAME']);
		return $cookie;
	}
	/**
	 * Remove the authentication cookie
	 *
	 * @access public
	 * @return boolean
	 */
	public function unset_cookie()
	{
		$cookie_path = $this->config->app_relative_web_path == "" ? "/" : $this->config->app_relative_web_path;
		return setcookie('RPCAUTH', '', time() - 86400, $cookie_path, $_SERVER['SERVER_NAME']);
	}
	/**
	 * Validate the RPCAUTH native authentication cookie
	 *
	 * @param object $db MySQLi database connection singleton
	 * @static
	 * @access public
	 * @return string Username of validated user
	 */
	public static function validate_cookie($db)
	{
		if ($cookie = self::parse_cookie())
		{
			$qry = sprintf(<<<QRY
				SELECT 1
				FROM native_sessions JOIN users ON native_sessions.userid = users.userid
				WHERE users.username = '%s' AND native_sessions.session = '%s' AND native_sessions.token = '%s';
QRY
				, $db->real_escape_string($cookie['username']),
				  $db->real_escape_string($cookie['session']),
				  $db->real_escape_string($cookie['token'])
			);
			if ($result = $db->query($qry))
			{
				// Successful cookie validation, return username.
				if ($result->num_rows == 1)
				{
					$result->close();
					return $cookie['username'];
				}
				else
				{
					$result->close();
					return FALSE;
				}
			}
		}
		// Cookie wasn't set or was invalid
		else return FALSE;
	}
	/**
	 * Parse the RPCAUTH native authentication cookie and return an associative array
	 * of the cookie's components
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function parse_cookie()
	{
		if (isset($_COOKIE['RPCAUTH']))
		{
			$decoded = base64_decode($_COOKIE['RPCAUTH']);
			$parts = preg_split("/\|/", $decoded);
			if (count($parts) == 3)
			{
				// Return an associative array of cookie components
				return array('username'=>$parts[0], 'session'=>$parts[1], 'token'=>$parts[2]);
			}
			else return FALSE;
		}
		else return FALSE;
	}
	/**
	 * Set user's password to a SHA1sum of $newpassword
	 * User's old password must also be supplied for verification
	 * Password complexity requirement is minimum 6 characters, at least one digit
	 * This function wraps Native_User->_set_password(), which actually does the database
	 * transaction, without requiring $oldpassword.
	 * Note: This database action does NOT get committed until $this->db->commit() is called!
	 *
	 * @param string $oldpassword
	 * @param string $newpassword
	 * @access public
	 * @return boolean
	 */
	public function set_password($oldpassword, $newpassword)
	{
		if ($this->password_hash !== sha1($oldpassword . $this->salt))
		{
			$this->error = self::ERR_INCORRECT_CREDS;
			return FALSE;
		}
		if (!self::password_meets_complexity($newpassword))
		{
			$this->error = self::ERR_PASWORD_COMPLEXITY_UNMET;
			return FALSE;
		}
		if ($this->_set_password($newpassword))
		{
			return TRUE;
		}
		else return FALSE;
	}
	/**
	 * Set the user's password to a SHA1 hash of $newpassword and create a new salt
	 *
	 * @param string $newpassword
	 * @access private
	 * @return boolean
	 */
	private function _set_password($newpassword)
	{
		$this->salt = self::_make_password();
		$newpassword_hash = $this->db->real_escape_string(sha1($newpassword . $this->salt));
		$qry = sprintf("UPDATE users SET password = '%s', passwordsalt = '%s' WHERE userid = %u;", $newpassword_hash, $this->salt, $this->id);
		if ($result = $this->db->query($qry))
		{
			$this->password_hash = $newpassword_hash;
			return TRUE;
		}
		else
		{
			$this->error = self::ERR_DB_ERROR;
			return FALSE;
		}
	}
	/**
	 * Set a new random password for the user and send
	 * it via a password reminder email
	 * Note: This database action does NOT get committed until $this->db->commit() is called!
	 *
	 * @access public
	 * @return boolean
	 */
	public function recover_password()
	{
		$newpass = self::_make_password();
		$smarty = new RPC_Smarty($this->config);
		$smarty->assign('newpass', $newpass);

		// Set headers
		$version = phpversion();
		$headers = <<<HEADERS
From: {$this->config->app_rfc_email_address}
X-Mailer: PHP/$version
HEADERS;

		// Build mail body from template
		$mail_body = $smarty->global_fetch('notifications/native_pw_recovery.tpl');
		// Update the database
		if ($this->_set_password($newpass))
		{
			// Send the email notification
			if (mail($this->email, $this->config->app_long_name . " password recovery", $mail_body, $headers, "-f{$this->config->app_email_from_address}"))
			{
				// We have to commit here, or the password reset could be only partially completed!
				$this->db->commit();
				return TRUE;
			}
			else
			{
				$this->db->rollback();
				$this->error = self::ERR_CANNOT_SEND_PASSWORD;
				return FALSE;
			}
		}
		else
		{
			$this->error = self::ERR_DB_ERROR;
			return FALSE;
		}
	}

	/**
	 * Return an error string
	 *
	 * @access public
	 * @return string
	 */
	public function get_error()
	{
		if (!empty($this->error))
		{
			switch ($this->error)
			{
				case self::ERR_CANNOT_RESET_PASSWORD: return "Could not reset password.";
				case self::ERR_CANNOT_SEND_PASSWORD: return "Could not send password recovery message.";
				case self::ERR_PASWORD_COMPLEXITY_UNMET: return "New password does not meet minimum complexity requirements.";
				case self::ERR_INCORRECT_CREDS: return "Password was incorrect.";
				case self::ERR_CANNOT_SET_COOKIE: return "Could not set login cookie.";
				default: return parent::get_error();
			}
		}
		else return "";
	}

	/**
	 * Return TRUE if $password meets complexity requirements
	 * Minimum six characters, at least one digit
	 *
	 * @param string $password
	 * @static
	 * @access public
	 * @return boolean
	 */
	public static function password_meets_complexity($password)
	{
		return preg_match('/^.*(?=.{6,})(?=.*\d).*$/', $password);
	}
	/**
	 * Create a new random passowrd, used for both password recovery and salt
	 *
	 * @access private
	 * @return string
	 */
	private static function _make_password()
	{
		$newpass = '';
		$arr_pass = array();
		// Choose some random upper/lower letters
		for ($i = 0; $i < 4; $i++)
		{
			// Case is a 0 or 1 multiplier to push chr() into the lower case ASCII range
			$case = rand(0,1);
			$arr_pass[] = chr(rand(65, 90) + ($case * 32));
		}
		// And some random digits
		for ($i = 0; $i < 6; $i++)
		{
			$arr_pass[] = strval(rand(0, 9));
		}
		// Mix it all up
		shuffle($arr_pass);
		// Stick it together as a string.
		$newpass = implode('', $arr_pass);
		return $newpass;
	}
}
?>
