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
 * @copyright   2009 - 2012 Sam Moffatt
 * @version     2.5.0
 * @see         JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

defined('_JEXEC') or die();

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
	/** @var    array options
	 *  @since  1.5
	 */
	protected $options;

	/**
	 * Constructor
	 *
	 * @since  1.5
	 */
	public function __construct($options = Array())
	{
		// Import User Source Library Files
		$isLoaded = JPluginHelper::importPlugin('usersource');

		if (!$isLoaded)
		{
			JLog::add(__CLASS__ . '::__construct: Could not load User Source plugins.', JLog::ERROR, 'usersource');
		}
		$this->options = $options;
	}

	/**
	 * Handle creating a user account.
	 *
	 * @param   string  $username  The username to attempt to discover and create.
	 *
	 * @return  ??
	 *
	 * @since   1.5
	 */
	public function doUserCreation($username)
	{
		// Do not create user if they exist already.
		if (intval(JUserHelper::getUserId($username)))
		{
			return true;
		}

		// Load up User Source plugins.
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
				JLog::add(__CLASS__ . '::doUserCreation: Could not load ' . $className, JLog::INFO, 'usersource');
				continue;
			}
		}

		// Try to find user
		$user = $this->discoverUser($username);

		if ($user)
		{
			// If the plugin didn't assign a group, set the default.
			// Note: "Public" doesn't have access to login...
			if (!isset($user->groups) || empty($user->groups) || !count($user->groups))
			{
				$config = JFactory::getConfig();
				$user->groups = array($config->get('access', 1));
			}

			// Get who we are now.
			$originalUser = JFactory::getUser();
			$session = JFactory::getSession();
			$session->set('user', new SuperJUser);

			// save us, now the db is up
			$result = $user->save();
			if (!$result)
			{
				JLog::add(sprintf('User creation failed for %s: %s', $username, $user->getError()), JLog::ERROR, 'usersource');
			}

			// Set back to old value.
			$session->set('user', $originalUser);
			return $result;
		}
		return false;
	}

	/**
	 * Handle synchronising a given usenname.
	 *
	 * @param   string   $username       The username to synchronise.
	 * @param   boolean  $updateSession  Update the session with this user object.
	 *
	 * @return  boolean  If the user was synchronised or not.
	 *
	 * @since    1.5
	 */
	public function doUserSynchronization($username, $updateSession = true)
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
				JLog::add('JAuthUserSource::doUserSynchronization: Could not load ' . $className, JLog::WARNING, 'usersource');
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

				// If we succeeded in finding a user then we're done.
				// The first user source to find the user wins in this case
				// and no other system will overwrite the values.
				// Now we need to save our user.
				// Get who we are now.
				$originalUser = JFactory::getUser();
				$session = JFactory::getSession();
				$session->set('user', new SuperJUser);

				// If the plugin didn't assign a group, set the default.
				// Note: "Public" doesn't have access to login...
				if (!isset($user->groups) || empty($user->groups) || !count($user->groups))
				{
					$config = JFactory::getConfig();
					$user->groups = array($config->get('access', 1));
				}

				// by default we demote users
				// TODO: Need to work out what this means when you can be in more than one group...
				/*
				if (isset($options['demoteuser']) && !$options['demoteuser'])
				{
					// reset the gid if new gid less than old gid or new gid is 29 (public frontend)
					if ($user->get('gid') == 29 && $my->get('gid') < $user->get('gid'))
						$user->set('gid', $my->get('gid'));
				}
				*/

				// save the record now that the checks are disabled
				$result = $user->save();

				if ($updateSession)
				{
					$session->set('user', $user);
				}
				else
				{
					// Restore the original user back.
					$session->set('user', $originalUser);
				}

				// That's all folks.
				return true;
			}
		}

		// We didn't manage to synchronise the user.
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
		if (empty($username))
		{
			throw new InvalidArgumentException('Username cannot be empty.');
		}

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
				JLog::add('JAuthUserSource::discoverUser: Could not load ' . $className, JLog::WARNING, 'usersource');
				continue;
			}

			// Try to find user
			$user = new JUser();

			if (method_exists($plugin, 'getUser') && $plugin->getUser($username, $user))
			{
				// Return the first user we find.
				return $user;
			}
			else
			{
				JLog::add(sprintf('Plugin "%s" failed to find user "%s"', $className, $username), JLog::NOTICE, 'usersource');
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
	public function discoverUsers($username)
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
				JLog::add('JAuthUserSource::discoverUsers: Could not load ' . $className, JLog::NOTICE, 'usersource');
				continue;
			}
			// Try to find user
			$user = new JUser();

			if (method_exists($plugin, 'getUser') && $plugin->getUser($username, $user))
			{
				// Clone the user before putting them into the array.
				$users[$plugin->name] = clone($user);
			}
		}
		return $users;
	}
}

/**
 * Super JUser object to convince JUser to let us do what we want. Looks mostly authentic.
 *
 * @package     JAuthTools
 * @subpackage  UserSource
 */
class SuperJUser extends JUser
{
	/**
	 * Fake authorise method.
	 *
	 * @return  boolean  Returns true. Always.
	 *
	 * @since   2.5
	 */
	public function authorise()
	{
		return true;
	}
}
