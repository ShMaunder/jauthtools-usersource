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
 * @package JAuthTools
 * @author Sam Moffatt <pasamio@gmail.com>
 * @license GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright 2009 Sam Moffatt
 * @version SVN: $Id:$
 * @see JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

jimport('joomla.user.helper');
jimport('joomla.utilities.string');
jimport('joomla.base.observable');

/**
 * User Source Provider
 * @package JAuthTools
 * @subpackage User-Source
 */
class JAuthUserSource extends JObservable {

	/** @var options array options */
	var $_options;

	/**
	 * Constructor
	 *
	 * @access protected
	 */
	function __construct($options=Array()) {
		// Import User Source Library Files
		$isLoaded = JPluginHelper :: importPlugin('usersource');
		if (!$isLoaded) {
			JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::__construct: Could not load User Source plugins.');
		}
		$this->_options = $options;
	}

	function doUserCreation($username) {
		// Do not create user if they exist already
		if(intval(JUserHelper::getUserId($username))) {
			return true;
		}

		// Load up User Source plugins
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin) {
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className)) {
				$plugin = new $className ($this, (array)$plugin);
			} else {
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::doUserCreation: Could not load ' . $className);
				continue;
			}
		}

		// Try to find user
		if($user = $this->discoverUser($username)) {
			$my =& JFactory::getUser(); // get who we are now
			$oldgid = $my->get('gid');
			$my->set('gid', 25); 		// and fake things to by pass security
			$result = $user->save(); 	// save us, now the db is up
			if(!$result) {
				JError::raiseNotice(1, 'User creation failed: '. $user->getError());
			}
			$my->set('gid', $oldgid);	// set back to old value
			return $result;
			break;
		}
		return false;
	}

	function doUserSynchronization($username) {
		// Load up User Source plugins
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin) {
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className)) {
				$plugin = new $className ($this, (array)$plugin);
			} else {
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::doUserSynchronization: Could not load ' . $className);
				continue;
			}
			// Fire a user sync event; this is done poorly, we will fix this for 1.6
			if(method_exists($plugin, 'doUserSync') && $user = $plugin->doUserSync($username)) {
				// if we succeeded then lets bail out
				// means the first system gets priority
				// and no other system will overwrite the values
				// but first we need to save our user
				$my =& JFactory::getUser(); // get who we are now
				// by default we demote users
				if(isset($options['demoteuser']) && !$options['demoteuser']) {
					// reset the gid if new gid less than old gid or new gid is 29 (public frontend)
					if($user->get('gid') == 29 && $my->get('gid') < $user->get('gid')) $user->set('gid',$my->get('gid'));
				}
				$oldgid = $my->get('gid');	// grab the current gid
				$my->set('gid', 25); 		// and fake things to by pass security
				$result = $user->save(); 	// save us, now the db is up
				$my->set('gid', $oldgid);	// set back to old value

				// Contribution from Mark Snead via the forums
				// @see http://forum.joomla.org/viewtopic.php?p=1811943#p1811943
				// UPDATE SESSION ARRAY
				$instance = $my;

				// Get an ACL object
				$acl =& JFactory::getACL();

				// Get the newly updated user group from the ACL
				if ($instance->get('tmp_user') == 1) {
					$grp = new JObject;
					// This should be configurable at some point
					$grp->set('name', 'Registered');
				} else {
					$grp = $acl->getAroGroup($instance->get('id'));
				}

				// Update the aid to 2 for Authors, Editors, Publishers and Super Administrators into the special access group
				if ($acl->is_group_child_of($grp->name, 'Registered')      ||
				$acl->is_group_child_of($grp->name, 'Public Backend'))    {
					$instance->set('aid', 2);
				}
				//Set the usertype and gid based on the ACL group name
				$instance->set('usertype', $grp->name);
				$instance->set('gid', $grp->id);

				// Register the needed session variables
				$session =& JFactory::getSession();
				$session->set('user', $instance);

				return true;				// thats all folks
				break;
			}
		}
		return false;
	}

	function discoverUser($username) {
		// Load up User Source plugins
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin) {
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className)) {
				$plugin = new $className ($this, (array)$plugin);
			} else {
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::discoverUser: Could not load ' . $className);
				continue;
			}
			// Try to find user
			$user = new JUser();
			if(method_exists($plugin, 'getUser') && $plugin->getUser($username,$user)) {
				return $user; //return the first user we find
				break;
			} else {
				JError::raiseNotice(1, 'Plugin '. $className .' failed to find user');
			}
		}
	}

	function discoverUsers($username) {
		// Load up User Source plugins
		$users = Array();
		$plugins = JPluginHelper :: getPlugin('usersource');
		foreach ($plugins as $plugin) {
			$className = 'plg' . $plugin->type . $plugin->name;
			if (class_exists($className)) {
				$plugin = new $className ($this, (array)$plugin);
			} else {
				JError :: raiseWarning('SOME_ERROR_CODE', 'JAuthUserSource::discoverUsers: Could not load ' . $className);
				continue;
			}
			// Try to find user
			$user = new JUser();
			if(method_exists($plugin, 'getUser') && $plugin->getUser($username,$user)) {
				$users[$plugin->name] = clone($user); // clone the user before putting them into the array
			}
		}
		return $users;
	}
}
