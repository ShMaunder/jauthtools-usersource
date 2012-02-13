<?php
/**
 * User Synchronization System
 *
 * This syncs a user from an external source
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
defined('_JEXEC') or die();

jimport('joomla.plugin.plugin');
jimport('jauthtools.usersource');

/**
 * User Synchronization
 * Start remote user synchronization
 *
 * @package     JAuthTools
 * @subpackage  UserSource
 * @version     2.5.0
 */
class plgSystemSync extends JPlugin
{

	/**
	 * onAfterInitialise to trigger user sync for the curently logged in user.
	 *
	 * @return  void
	 *
	 * @since   1.5
	 */
	function onAfterInitialise()
	{
		$plugin = & JPluginHelper :: getPlugin('system', 'sync');
		$params = new JParameter($plugin->params);
		$usersource = new JAuthUserSource(Array('demoteuser' => $params->get('demoteuser', 1)));

		if (($user = JFactory::getUser()))
		{
			if ($user->username)
			{
				$usersource->doUserSynchronization($user->username);
			}
		}
	}

}
