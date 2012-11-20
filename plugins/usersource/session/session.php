<?php
/**
 * Session User Source
 * 
 * Grabs users details out of the session
 * This is designed to optimise access where the SSO
 * system will typically pull the information out
 * including the users details (e.g. JAuthTools'
 * Google Auth System)
 * 
 * PHP4/5
 *  
 * Created on July 3, 2008
 * 
 * @package     JAuthTools
 * @subpackage  UserSource
 * @author      Sam Moffatt <pasamio@gmail.com>
 * @license     GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright   2012 Sam Moffatt 
 * @version     2.5.0 
 * @see         JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

jimport('joomla.event.plugin');

/**
 * SSO Initiation
 * Kicks off SSO Authentication
 *
 * @package     JAuthTools
 * @subpackage  UserSource
 */
class plgUserSourceSession extends JPlugin {
	/**
	 * Retrieves a user
	 *
	 * @param   string  $username  Username of target use
	 * @param   JUser   &$user     Reference to user to populate
	 *
	 * @return  JUser object containing the valid user or false
	 *
	 * @since   1.5
	 */
	public function getUser($username,&$user) {
		$session =& JFactory::getSession();
		$details = $session->get('UserSourceDetails',null);
		if($details) {
			$session->set('UserSourceDetails',null); // kill the variable
			if(!is_array($details)) {
				$details = Array($details); // wrap in array
			}

			foreach($details as $detail) {
				if(is_object($detail) && $detail->username == $username) {
					// These three should be set by the plugin
					$user->username = $detail->username;
					$user->name = $detail->name;
					$user->email = $detail->email;
					$user->groups = array(2);
					return true;					
				}
			}
		}
		return false;		
	} 

	/**
	 * Handle user synchronisation for this plugin
	 *
	 * @param   string  $username  The username to sync.
	 *
	 * @return  JUser  The JUser from this sync.
	 *
	 * @since   1.5
	 */
	public function &doUserSync($username) {
		return false;
	}
}
