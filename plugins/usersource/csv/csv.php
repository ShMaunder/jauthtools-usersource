<?php
/**
 * CSV User Source
 *
 * Loads a CSV file and builds JUser objects
 *
 * Created on Apr 17, 2007
 *
 * @package    JAuthTools
 * @author     Sam Moffatt <pasamio@gmail.com>
 * @license    GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright  2009-2012 Sam Moffatt
 * @see        JoomlaCode Project: http://joomlacode.org/gf/project/jauthtools/
 */

defined('_JEXEC') or die();
jimport('joomla.plugin.plugin');


/**
 * CSV User Source
 * Finds people using a CSV file
 * @package     JAuthTools
 * @subpackage  User-Source
 */
class plgUserSourceCSV extends JPlugin
{
	/**
	 * Retrieves a user.
	 *
	 * @param   string  $username  Username of target use.
	 * @param   JUser   $user      JUse robject containing the valid user details.
	 *
	 * @return  boolean  If user was successfully populated into &$user.
	 *
	 * @since   2.5
	 */
	public function getUser($username,&$user)
	{
		$params = $this->params;
		$sourcefile = $params->get('sourcefile', '');
		if (!file_exists($sourcefile))
		{ 
			JLog::add('Failed to load source file for CSV user source', JLog::WARNING, 'usersource');
			return false;
		}

		$fh = fopen($sourcefile, 'r');

		$fields = array('name', 'username', 'email', 'groups');
		$empty = array_fill(0, count($fields), '');

		while ($row = fgetcsv($fh))
		{
			/* array_combine will barf if the keys and values don't line up.
			 * This could be because:
			 * 1) not enough entries in input array
			 * 2) too many entries in input array
			 *
			 * The "+= $empty" here solves 1 by adding enough fields (albeit empty)
			 * The "array_slice" here solves 2 by removing extraneous fields.
			 */
			$row += $empty;
			$currentUser = array_combine($fields, array_slice($row, 0, count($fields)));

			if ($currentUser['username'] == $username)
			{
				foreach ($currentUser as $key => $value)
				{
					if ($key == 'groups')
					{
						$user->$key = strlen($value) ? array_map('intval', explode('|', $value)) : array(); 
					}
					else
					{
						$user->$key = $value;
					}
				}
				fclose($fh);
				return true;
			}
		}
		fclose($fh);
	} 
	
	/**
	 * Synchronizes a user.
	 *
	 * @param   string  $username  The username to synchronise.
	 *
	 * @return  JUser  The JUser from this sync or false if the user isn't valid.
	 *
	 * @since   2.5
	 */
	public function &doUserSync($username) 
	{
		return false;
	}
}
