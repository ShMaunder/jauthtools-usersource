<?php
/**
 * JAuthTools: User Sources
 *
 * This file handles the retrieval and autocreation of a user
 *
 * PHP4/5
 *
 * Created on Apr 17, 2007
 *
 * @package     JAuthTools
 * @subpackage  UserSource
 * @author      Sam Moffatt <pasamio@gmail.com>
 * @license     GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright   2012 Sam Moffatt
 * @version     2.5.0
 * @see         JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

jimport('joomla.user.helper');
jimport('joomla.utilities.string');
jimport('joomla.base.observable');

/**
 * User Source Provider
 * @package     JAuthTools
 * @subpackage  UserSource
 * @version     2.5.0
 */
class JAuthUserSource extends JObservable
{

	/** @var options array options */
	var $_options;

	/**
	 * Constructor
	 *
	 * @access protected
	 */
	function __construct($options = Array())
	{
		// Import User Source Library Files
		$isLoaded = JPluginHelper :: importPlugin('usersource');
		if (!$isLoaded)
		{
			JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::__construct: Could not load User Source plugins.');
		}
		$this->_options = $options;
	}

	function doUserCreation($username)
	{
		// Do not create user if they exist already
		if (intval(JUserHelper::getUserId($username)))
		{
			return true;
		}

		// Load up User Source plugins
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin)
		{
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className))
			{
				$plugin = new $className($this, (array) $plugin);
			}
			else
			{
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::doUserCreation: Could not load ' . $className);
				continue;
			}
		}

		// Try to find user
		$user = $this->discoverUser($username);
		if ($user)
		{
			$my = & JFactory::getUser(); // get who we are now
			$oldgid = $my->get('gid');
			$my->set('gid', 25);   // and fake things to by pass security
			$result = $user->save();  // save us, now the db is up
			if (!$result)
			{
				JError::raiseNotice(1, 'User creation failed: ' . $user->getError());
			}
			$my->set('gid', $oldgid); // set back to old value
			return $result;
			break;
		}
		return false;
	}

	function doUserSynchronization($username)
	{
		// Load up User Source plugins
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin)
		{
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className))
			{
				$plugin = new $className($this, (array) $plugin);
			}
			else
			{
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::doUserSynchronization: Could not load ' . $className);
				continue;
			}
			// Fire a user sync event; this is done poorly, we will fix this for 1.6
			if (method_exists($plugin, 'doUserSync'))
			{
				$user = $plugin->doUserSync($username);

				if (!$user)
				{
					continue;
				}

				// if we succeeded then lets bail out
				// means the first system gets priority
				// and no other system will overwrite the values
				// but first we need to save our user
				// get who we are now
				$my = & JFactory::getUser();


				// by default we demote users
				/*
				if (isset($options['demoteuser']) && !$options['demoteuser'])
				{
					// reset the gid if new gid less than old gid or new gid is 29 (public frontend)
					if ($user->get('gid') == 29 && $my->get('gid') < $user->get('gid'))
						$user->set('gid', $my->get('gid'));
				}
				*/

				// grab the old "isRoot" value
				$oldRoot = $my->get('isRoot');
				$my->set('isRoot', true);

				// save the record now that the checks are disabled
				$result = $user->save();

				// set back to old value
				$my->set('isRoot', $oldRoot);

				// Contribution from Mark Snead via the forums
				// @see http://forum.joomla.org/viewtopic.php?p=1811943#p1811943
				// UPDATE SESSION ARRAY
				$instance = $my;

				// Get an ACL object
				$acl = & JFactory::getACL();

				// Get the newly updated user group from the ACL
				if ($instance->get('tmp_user') == 1)
				{
					$grp = new JObject;
					// This should be configurable at some point
					$grp->set('name', 'Registered');
				}
				else
				{
					$grp = $acl->getAroGroup($instance->get('id'));
				}

				//Set the usertype and gid based on the ACL group name
				$instance->set('usertype', $grp->name);
				$instance->set('gid', $grp->id);

				// Register the needed session variables
				$session = & JFactory::getSession();
				$session->set('user', $instance);

				return true;    // thats all folks
				break;
			}
		}
		return false;
	}

	/**
	 * Discover a user details given a name and stops at the first one.
	 *
	 * @param   string  $username  The username to detect
	 *
	 * @return  JUser
	 *
	 * @since   1.5
	 */
	function discoverUser($username)
	{
		// Load up User Source plugins
		$plugins = JPluginHelper::getPlugin('usersource');
		foreach ($plugins as $plugin)
		{
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className))
			{
				$plugin = new $className($this, (array) $plugin);
			}
			else
			{
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::discoverUser: Could not load ' . $className);
				continue;
			}
			// Try to find user
			$user = new JUser();
			if (method_exists($plugin, 'getUser') && $plugin->getUser($username, $user))
			{
				return $user; //return the first user we find
				break;
			}
			else
			{
				JError::raiseNotice(1, 'Plugin ' . $className . ' failed to find user');
			}
		}
	}

	/**
	 * Discover all possible users. Useful for debugging.
	 *
	 * @param   string  $username  The username to discover.
	 *
	 * @return  array  An array of JUsers
	 *
	 * @since   2.5.0
	 */
	function discoverUsers($username)
	{
		// Load up User Source plugins
		$users = Array();
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin)
		{
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className))
			{
				$plugin = new $className($this, (array) $plugin);
			}
			else
			{
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::discoverUsers: Could not load ' . $className);
				continue;
			}
			// Try to find user
			$user = new JUser();
			if (method_exists($plugin, 'getUser') && $plugin->getUser($username, $user))
			{
				// clone the user before putting them into the array
				$users[$plugin->name] = clone($user);
			}
		}
		return $users;
	}
}
