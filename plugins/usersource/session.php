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
 * @package Joomla! Authentication Tools
 * @author Sam Moffatt <pasamio@gmail.com>
 * @license GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright 2009 Sam Moffatt 
 * @version SVN: $Id:$
 * @see JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

jimport('joomla.event.plugin');


/**
 * SSO Initiation
 * Kicks off SSO Authentication
 */
class plgUserSourceSession extends JPlugin {
	/**
	 * Retrieves a user
	 * @param string username Username of target use
	 * @return JUser object containing the valid user or false
	 */
	function getUser($username,&$user) {
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
					// These two will be overriden in future
					$user->gid = 18;
			 	 	$user->usertype = 'Registered';
					return true;					
				}
			}
		}
		return false;		
	} 

	function &doUserSync($username) {
		return false;
	}
}
