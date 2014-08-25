<?php
/**
 * WoWRoster.net WoWRoster
 *
 * Roster guild scope functions
 *
 *
 * @copyright  2002-2011 WoWRoster.net
 * @license    http://www.gnu.org/licenses/gpl.html   Licensed under the GNU General Public License v3.
 * @version    SVN: $Id: guild.php 2222 2010-12-05 10:05:37Z c.treyce@gmail.com $
 * @link       http://www.wowroster.net
 * @since      File available since Release 2.1.0
 * @package    WoWRoster
 */

if( !defined('IN_ROSTER') )
{
	exit('Detected invalid access to this file!');
}

class GuildScope
{
	function set_tpl($data)
	{
		global $roster;

		/**
		 * Assigning everything this file may need to the template
		 * The only tpl vars not here are ones that need to be generated in their respective methods
		 */
		$roster->tpl->assign_vars(array(
			'SERVER' => $data['server'],
			'GUILD_NAME' => $data['guild_name'],
			'FACTION_EN' => strtolower($roster->data['factionEn']),
			'FACTION' => $roster->data['faction']
			)
		);
	}
}
