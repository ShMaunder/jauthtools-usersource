<?php

/**
 * LDAP User Source
 *
 * Connects to LDAP directories and builds JUser objects
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
jimport('joomla.plugin.plugin');

/**
 * LDAP User Source
 * Finds people using LDAP
 *
 * @package     JAuthTools
 * @subpackage  UserSource
 * @version     2.5.0
 */
class plgUserSourceLDAP extends JPlugin
{
	/**
	 * Retrieves a user
	 *
	 * @param   string  $username  Username of target use
	 * @param   JUser   &$user     Reference to user to populate
	 *
	 * @return  boolean  If user was successfully populated into &$user.
	 *
	 * @since   1.5
	 */
	function getUser($username, &$user)
	{
		$params = $this->params;
		if (JPluginHelper::isEnabled('authentication', 'ldap'))
		{
			$ldapplugin = & JPluginHelper::getPlugin('authentication', 'ldap');
			$ldapparams = new JParameter($ldapplugin->params);
			$params->merge($ldapparams);
		}
		else if (file_exists(JPATH_LIBRARIES . DS . 'jauthtools' . DS . 'helper.php'))
		{
			jimport('jauthtools.helper');
			$params->merge(JAuthToolsHelper::getPluginParams('authentication', 'ldap'));
		}
		else
		{
			JError::raiseWarning('0', 'plgUserSourceLDAP::getUser: Failed to get LDAP settings');
			return false;
		}
		$this->params = $params; // reset our internal params to include the merged values
		$ldap = new JClientLdap($params);
		if (!$ldap->connect())
		{
			JError :: raiseWarning('SOME_ERROR_CODE', 'plgUserSourceLDAP::getUser: Failed to connect to LDAP Server ' . $params->getValue('host'));
			return false;
		}

		if (!$ldap->bind())
		{
			JError :: raiseWarning('SOME_ERROR_CODE', 'plgUserSourceLDAP::getUser: Failed to bind to LDAP Server');
			return false;
		}

		return $this->_updateUser($ldap, $username, $user);
	}

	/**
	 * Synchronizes a user.
	 *
	 * @param   string  $username  The username of the user to synchronise.
	 *
	 * @return  JUser  The user to be synchronised.
	 *
	 * @since   1.5
	 */
	function &doUserSync($username)
	{
		$plugin = & JPluginHelper :: getPlugin('usersource', 'ldap');
		$params = new JParameter($plugin->params);
		if (JPluginHelper::isEnabled('authentication', 'ldap'))
		{
			$ldapplugin = & JPluginHelper::getPlugin('authentication', 'ldap');
			$ldapparams = new JParameter($ldapplugin->params);
			$params->merge($ldapparams);
		}
		else if (file_exists(JPATH_LIBRARIES . DS . 'jauthtools' . DS . 'helper.php'))
		{
			// use the jauthtools helper if it exists
			jimport('jauthtools.helper');
			$params->merge(JAuthToolsHelper::getPluginParams('authentication', 'ldap'));
		}
		else
		{
			JError::raiseWarning('0', 'plgUserSourceLDAP::doUserSync: Failed to get LDAP settings');
			return false;
		}
		$this->params = $params; // reset our internal params to include the merged values
		$ldap = new JClientLdap($params);

		if (!$ldap->connect())
		{
			JError :: raiseWarning('SOME_ERROR_CODE', 'plgUserSourceLDAP::doUserSync: Failed to connect to LDAP Server ' . $params->getValue('host'));
			return false;
		}

		if (!$ldap->bind())
		{
			JError :: raiseWarning('SOME_ERROR_CODE', 'plgUserSourceLDAP::doUserSync: Failed to bind to LDAP Server');
			return false;
		}

		$return = false;
		$user = new JUser();
		$user->load(JUserHelper::getUserId($username));
		if ($this->_updateUser($ldap, $username, $user))
		{
			$return = $user;
		}
		return $return;
	}

	/**
	 * Update user
	 */
	function _updateUser(&$ldap, $username, &$user)
	{
		$map = $this->params->getValue('groupMap', null);
		$loginDisabled = $this->params->getValue('ldap_blocked', 'loginDisabled');
		$groupMembership = $this->params->getValue('ldap_groups', 'groupMembership');
		$groupmember = $this->params->getValue('ldap_groupmember', 'member');
		$user->username = $username;
		$userdetails = $ldap->simple_search(str_replace("[search]", $user->username, $this->params->getValue('search_string')));
		// Set default email and name
		$user->email = $user->username;
		$user->name = $user->username;
		// Set default group mapping
		$user->groups = array($this->params->get('defaultgroup', 2));

		// look for LDAP email and full name configuration settings
		$ldap_email = $this->params->getValue('ldap_email', 'mail');
		$ldap_fullname = $this->params->getValue('ldap_fullname', 'fullName');
		// we need at least a DN and a potentially valid email
		if (isset($userdetails[0]['dn']) && isset($userdetails[0][$ldap_email][0]))
		{
			$user->email = $userdetails[0][$ldap_email][0];
			if (isset($userdetails[0][$ldap_fullname][0]))
			{
				$user->name = $this->_convert($userdetails[0][$ldap_fullname][0]);
			}

			$user->block = isset($userdetails[0][$loginDisabled]) ? intval($userdetails[0][$loginDisabled][0]) : 0;

			if ($map)
			{
				$groupMap = $this->_parseGroupMap($map);

				if ($this->params->getValue('authenticategroupsearch', 0))
				{
					// since we are bound as the user, we have to bind as
					// admin in order to search the groups and their attributes
					$ldap_bind_uid = $this->params->get('username');
					$ldap_bind_password = $this->params->get('password');
					$ldap->bind($ldap_bind_uid, $ldap_bind_password, 1);
				}

				// add group memberships for active directory or other systems that don't store it in the user
				if ($this->params->getValue('reversegroupmembership', 0))
				{
					$groupMemberships = Array();
					$cnt = 0;


					foreach ($groupMap as $groupMapEntry)
					{
						$group = $groupMapEntry['groupname'];
						//Build search conext by splitting the cn=GROUPNAME from the search context
						$sSearch = substr($group, 0, strpos($group, ','));
						$sContext = substr($group, strpos($group, ',') + 1, strlen($group));

						//query for the group
						$groupdetails = $ldap->search(array($sSearch), $sContext);
						$groupMembers = isset($groupdetails[0][$groupmember]) ? $groupdetails[0][$groupmember] : Array();

						foreach ($groupMembers as $groupMember)
						{
							if (strtolower($groupMember) == strtolower($userdetails[0]['dn']))
							{
								$groupMemberships[$cnt++] = $group;
							}
						}
					}
					if ($cnt > 0)
					{
						if (!isset($userdetails[0][$groupMembership]))
						{
							// its not set, just overwrite
							$userdetails[0][$groupMembership] = $groupMemberships;
						}
						else
						{
							// its set, try array merge
							$userdetails[0][$groupMembership] = array_merge($userdetails[0][$groupMembership], $groupMemberships);
						}
					}
				}

				// find any recursive group memberships based on existing memberships
				if ($this->params->getValue('recursivegroupmembership', 0))
				{

					if (count($userdetails[0][$groupMembership]))
					{
						$cnt = 0;
						$groupMemberships = Array();
						$userGroups = $this->_getUserGroups($ldap, $userdetails[0]);
						foreach ($groupMap as $groupMapEntry)
						{
							$group = $groupMapEntry['groupname'];
							if ($this->in_array_nocase($group, $userGroups))
							{
								$groupMemberships[$cnt++] = $group;
							}
						}

						if ($cnt > 0)
						{
							if (!isset($userdetails[0][$groupMembership]))
							{
								// its not set, just overwrite
								$userdetails[0][$groupMembership] = $groupMemberships;
							}
							else
							{
								// its set, try array merge
								$userdetails[0][$groupMembership] = array_merge($userdetails[0][$groupMembership], $groupMemberships);
							}
						}
					}
				}

				$this->_reMapUser($user, $userdetails[0], $groupMap, $groupMembership);
			}
			return true;
		}
		return false;
	}

	/**
	 * Group map handler
	 * This is defunct due to core param handling
	 */
	function &_parseGroupMap($map)
	{
		// Process Map
		$groupMap = Array();
		if (!is_array($map))
		{ // we may have got it preprocessed into an array! probably from JParam
			// however its probably a string
			$groups = explode("\n", $map);
			foreach ($groups as $group)
			{
				if (trim($group))
				{
					$details = explode(';', $group);
					$groupMap[] = Array(
					    'groupname' => trim(str_replace("\n", '', $details[0]
						    )), 'gid' => $details[1], 'usertype' => $details[2], 'priority' => $details[3]);
				}
			}
		}
		else
		{
			// preprocessed array! just need to rename things
			foreach ($map as $details)
			{
				$groupMap[] = Array(
				    'groupname' => trim(str_replace("\n", '', $details[0]
					    )),
				    'gid' => $details[1],
				    'usertype' => $details[2],
				    'priority' => $details[3]);
			}
		}
		return $groupMap;
	}

	/**
	 * Remap the user
	 */
	function _reMapUser(&$user, $details, $groupMap, $groupMembership)
	{
		$currentgrouppriority = 0;
		if (isset($details[$groupMembership]))
		{
			foreach ($details[$groupMembership] as $group)
			{
				// Hi there :)
				foreach ($groupMap as $mappedgroup)
				{
					if (strtolower($mappedgroup['groupname']) == strtolower($group))
					{ // darn case sensitivty
						if ($mappedgroup['priority'] > $currentgrouppriority)
						{
							$user->gid = $mappedgroup['gid'];
							$user->usertype = $mappedgroup['usertype'];
							$currentgrouppriority = $mappedgroup['priority'];
						}
					}
				}
			}
		}
		return true;
	}

	/**
	 * Convert the string via iconv as configured.
	 *
	 * @param   string  $string  The string to convert.
	 *
	 * @return  string  The converted string.
	 *
	 * @since   1.5.0
	 */
	function _convert($string)
	{
		if (function_exists('iconv') && $this->params->get('use_iconv', 0))
		{
			return iconv($this->params->get('iconv_to', 'UTF-8'), $this->params->get('iconv_from', 'ISO8859-1'), $string);
		} else
			return $string;
	}

	// 	Functions contributed by David Kamphuis with noted alterations
	/**
	 * Return an Array of all a users group memberships
	 * Used for Active Directory nested groups
	 */
	function _getUserGroups(&$ldap, $user)
	{
		$userBaseGroups = $user[$this->params->getValue('ldap_groups', 'groupMembership')];
		$userGroups = Array();
		foreach ($userBaseGroups as $id => $group)
		{
			$extraGroups = $this->_recurseGroups($ldap, $group);
			$userGroups = array_merge($userGroups, $extraGroups);
		}
		$userBaseGroups = array_merge($userBaseGroups, $userGroups);
		$userBaseGroups = array_unique($userBaseGroups);
		return $userBaseGroups;
	}

	/**
	 * Return an Array of unique nested groups
	 * Used for Active Directory nested groups
	 */
	function _recurseGroups(&$ldap, $group)
	{
		if ($group == NULL)
		{
			return (false);
		}

		$ret_groups = array();
		// Pull this out of the ldap auth params
		$search_string = str_replace('[search]', $this->_simple_name($group), $this->params->getValue('search_string', ''));
		$objGroup = $ldap->simple_search($search_string);
		$groupmembers = $this->params->getValue('ldap_groups', 'groupMembership');
		if ($objGroup && $objGroup[0])
		{
			// TODO: These two as well
			if (isset($objGroup[0][$groupmembers]))
			{
				$objGroups = $objGroup[0][$groupmembers];
				$ret_groups = array_merge($ret_groups, $objGroups);
				foreach ($objGroups as $group_dn)
				{
					$child_groups = $this->_recurseGroups($ldap, $group_dn);
					$ret_groups = array_merge($ret_groups, $child_groups);
					$ret_groups = array_unique($ret_groups);
				}
			}
		}
		return $ret_groups;
	}

	/**
	 * Return the name of an object
	 * ie. given CN=john.smith,ou=users,dc=domain,dc=com
	 * 	it will return john.smith
	 */
	function _simple_name($group)
	{
		$bits = explode(",", $group);
		$eq = strpos($bits[0], '=');
		if ($eq)
		{ // it shouldn't be zero
			$group_name = substr($bits[0], $eq + 1);
		}
		else
		{ // well guess what it was zero
			$group_name = $bits[0]; // return it as it is
		}
		return $group_name;
	}

	/**
	 * Determine if a string is in an array
	 */
	function in_array_nocase($search, &$array)
	{
		$search = strtolower($search);
		foreach ($array as $item)
		{
			if (strtolower($item) == $search)
			{
				return true;
			}
		}
		return false;
	}

}
