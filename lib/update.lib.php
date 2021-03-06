<?php
/**
 * WoWRoster.net WoWRoster
 *
 * LUA updating library
 *
 *
 * @copyright  2002-2011 WoWRoster.net
 * @license	http://www.gnu.org/licenses/gpl.html   Licensed under the GNU General Public License v3.
 * @package	WoWRoster
 * @subpackage LuaUpdate
 */

if ( !defined('IN_ROSTER') )
{
	exit('Detected invalid access to this file!');
}

/**
 * Lua Update handler
 *
 * @package	WoWRoster
 * @subpackage LuaUpdate
 */
class update
{
	var $textmode = false;
	var $uploadData;
	var $addons = array();
	var $files = array();
	var $locale;
	var $blinds = array();

	var $processTime;			// time() starting timestamp for enforceRules

	var $messages = array();
	var $errors = array();
	var $assignstr = '';
	var $assigngem = '';		// 2nd tracking property since we build a gem list while building an items list

	var $membersadded = 0;
	var $membersupdated = 0;
	var $membersremoved = 0;

	var $current_region = '';
	var $current_realm = '';
	var $current_guild = '';
	var $current_member = '';
	var $talent_build_urls = array();

	/**
	 * Collect info on what files are used
	 */
	function fetchAddonData()
	{
		global $roster;

		// Add roster-used tables
		$this->files[] = 'wowrcp';

		if( !$roster->config['use_update_triggers'] )
		{
			return;
		}

		if( !empty($roster->addon_data) )
		{
			foreach( $roster->addon_data as $row )
			{
				$hookfile = ROSTER_ADDONS . $row['basename'] . DIR_SEP . 'inc' . DIR_SEP . 'update_hook.php';

				if( file_exists($hookfile) )
				{
					// Check if this addon is in the process of an upgrade and deny access if it hasn't yet been upgraded
					$installfile = ROSTER_ADDONS . $row['basename'] . DIR_SEP . 'inc' . DIR_SEP . 'install.def.php';
					$install_class = $row['basename'] . 'Install';

					if( file_exists($installfile) )
					{
						include_once($installfile);

						if( class_exists($install_class) )
						{
							$addonstuff = new $install_class;

							// -1 = overwrote newer version
							//  0 = same version
							//  1 = upgrade available
							if( version_compare($addonstuff->version,$row['version']) )
							{
								$this->setError(sprintf($roster->locale->act['addon_upgrade_notice'],$row['basename']),$roster->locale->act['addon_error']);
								continue;
							}
							unset($addonstuff);
						}
					}

					$addon = getaddon($row['basename']);

					include_once($hookfile);

					$updateclass = $row['basename'] . 'Update';

					// Save current locale array
					// Since we add all locales for localization, we save the current locale array
					// This is in case one addon has the same locale strings as another, and keeps them from overwritting one another
					$localetemp = $roster->locale->wordings;

					foreach( $roster->multilanguages as $lang )
					{
						$roster->locale->add_locale_file(ROSTER_ADDONS . $addon['basename'] . DIR_SEP . 'locale' . DIR_SEP . $lang . '.php',$lang);
					}

					$addon['fullname'] = ( isset($roster->locale->act[$addon['fullname']]) ? $roster->locale->act[$addon['fullname']] : $addon['fullname'] );

					if( class_exists($updateclass) )
					{
						$this->addons[$row['basename']] = new $updateclass($addon);
						$this->files = array_merge($this->files,$this->addons[$row['basename']]->files);
					}
					else
					{
						$this->setError('Failed to load update trigger for ' . $row['basename'] . ': Update class did not exist',$roster->locale->act['addon_error']);
					}
					// Restore our locale array
					$roster->locale->wordings = $localetemp;
					unset($localetemp);
				}
			}
		}

		// Remove duplicates
		$this->files = array_unique($this->files);

		// Make all the file names requested lower case
		$this->files = array_flip($this->files);
		$this->files = array_change_key_case($this->files);
		$this->files = array_flip($this->files);
	}

	/**
	*
	*	file error upload handler
	*	returns true/false | sets error message with file name
	*/
	
	function upload_error_check($file)
	{
		global $roster;

		switch($file['error'])
		{
			case UPLOAD_ERR_OK:		  // Value: 0; There is no error, the file uploaded with success.
				return true;
			break;
 
			case UPLOAD_ERR_INI_SIZE:	// Value: 1; The uploaded file exceeds the upload_max_filesize directive in php.ini.
				$this->setError('The uploaded file exceeds the upload_max_filesize directive in php.ini.','File Error ['.$file['name'].']');
				
			case UPLOAD_ERR_FORM_SIZE:   // Value: 2; The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
				$this->setError('The uploaded file exceeds the server maximum filesize allowed.','File Error ['.$file['name'].']');
				return false;
			break;
 
			case UPLOAD_ERR_PARTIAL:	 // Value: 3; The uploaded file was only partially uploaded.
				$this->setError('The uploaded file was only partially uploaded.','File Error ['.$file['name'].']');
				return false;
				break;
 
			case UPLOAD_ERR_NO_FILE:	 // Value: 4; No file was uploaded.
				$this->setError('No file was uploaded.','File Error ['.$file['name'].']');
				return false;
				break;
 
			case UPLOAD_ERR_NO_TMP_DIR:  // Value: 6; Missing a temporary folder.
				$output .= '<li>Missing a temporary folder. Please contact the admin.</li>';
				return false;
				break;
 
			case UPLOAD_ERR_CANT_WRITE:  // Value: 7; Failed to write file to disk.
				$output .= '<li>Failed to write file to disk. Please contact the admin.</li>';
				return false;
				break;
		}
	}
	
	
	/**
	 * Parses the files and put it in $uploadData
	 *
	 * @return string $output | Output messages
	 */
	function parseFiles( )
	{
		global $roster;

		if( !is_array($_FILES) )
		{
			return '<span class="red">Upload failed: No files present</span>' . "<br />\n";
		}

		require_once(ROSTER_LIB . 'luaparser.php');
		$output = $roster->locale->act['parsing_files'] . "<br />\n<ul>";
		foreach( $_FILES as $file )
		{
			if( !empty($file['name']) && $this->upload_error_check($file))
			{
				$filename = explode('.',$file['name']);
				$filebase = strtolower($filename[0]);				
				
				if( in_array($filebase,$this->files))
				{
					// Get start of parse time
					$parse_starttime = format_microtime();
					$luahandler = new lua();
					$data = $luahandler->luatophp( $file['tmp_name'], isset($this->blinds[$filebase]) ? $this->blinds[$filebase] : array() );

					// Calculate parse time
					$parse_totaltime = round((format_microtime() - $parse_starttime), 2);

					if( $data )
					{
						$output .= '<li>' . sprintf($roster->locale->act['parsed_time'],$filename[0],$parse_totaltime) . "</li>\n";
						$this->uploadData[$filebase] = $data;
					}
					else
					{
						$output .= '<li>' . sprintf($roster->locale->act['error_parsed_time'],$filebase,$parse_totaltime) . "</li>\n";
						$output .= ($luahandler->error() != '' ? '<li>' . $luahandler->error() . "</li>\n" : '');
					}
					unset($luahandler);
				}
				else
				{
					$output .= '<li>' . sprintf($roster->locale->act['upload_not_accept'],$file['name']) . "</li>\n";
				}
			}
			else
			{
				$output .= '<li>' . sprintf($roster->locale->act['error_parsed_time'],$file['name'],'0') . "</li>\n";
			}
		}
		$output .= "</ul><br />\n";
		return $output;
	}

	/**
	 * Process the files
	 *
	 * @return string $output | Output messages
	 */
	function processFiles()
	{
		global $roster;
		$this->processTime = time();

		if( !is_array($this->uploadData) )
		{
			return '';
		}
		$output = $roster->locale->act['processing_files'] . "<br />\n";

		$gotfiles = array_keys($this->uploadData);
		//print_r($gotfiles);
//		if( in_array('characterprofiler',$gotfiles) || in_array('wowroster',$gotfiles) )
		if( in_array('wowrcp',$gotfiles) )
		{

			if( $roster->auth->getAuthorized('gp_update') )
			{
				$output .= 'Guild Update access Granted';
				$output .= $this->processGuildRoster();
				$output .= "<br />\n";

				if( $roster->config['enforce_rules'] == '3' )
				{
					$this->enforceRules($this->processTime);
				}
			}
			else
			{
				$output .= 'Guild Update access not Granted';
			}

			if( $roster->auth->getAuthorized('cp_update') )
			{
				$output .= $this->processMyProfile();

				if( $roster->config['enforce_rules'] == '2' )
				{
					$this->enforceRules($this->processTime);
				}
			}

		}

		if( $roster->auth->getAuthorized('lua_update') )
		{
			if( is_array($this->addons) && count($this->addons) > 0 )
			{
				foreach( array_keys($this->addons) as $addon )
				{
					if( count(array_intersect($gotfiles, $this->addons[$addon]->files)) > 0 )
					{
						if( file_exists($this->addons[$addon]->data['trigger_file']) )
						{
							$this->addons[$addon]->reset_messages();
							if( method_exists($this->addons[$addon], 'update') )
							{
								$result = $this->addons[$addon]->update();

								if( $result )
								{
									$output .= $this->addons[$addon]->messages;
								}
								else
								{
									$output .= sprintf($roster->locale->act['error_addon'],$this->addons[$addon]->data['fullname'],'update') . "<br />\n"
											 . $roster->locale->act['addon_messages'] . "<br />\n" . $this->addons[$addon]->messages;
								}
							}
						}
					}
				}
			}

			if( $roster->config['enforce_rules'] == '1' )
			{
				$this->enforceRules($this->processTime);
			}
		}

		return $output;
	}

	/**
	 * Run trigger
	 */
	function addon_hook( $mode , $data , $memberid = '0' )
	{
		global $roster;

		$output = '';
		foreach( array_keys($this->addons) as $addon )
		{
			if( file_exists($this->addons[$addon]->data['trigger_file']) )
			{
				$this->addons[$addon]->reset_messages();
				if( method_exists($this->addons[$addon], $mode) )
				{
					$result = $this->addons[$addon]->{$mode}($data , $memberid);

					if( $result )
					{
						if( $mode == 'guild' )
						{
							$output .= '<li>' . $this->addons[$addon]->messages . "</li>\n";
						}
						else
						{
							$output .= $this->addons[$addon]->messages . "<br />\n";
						}
					}
					else
					{
						if( $mode == 'guild' )
						{
							$output .= '<li>' . sprintf($roster->locale->act['error_addon'],$this->addons[$addon]->data['fullname'],$mode) . "<br />\n"
									 . $roster->locale->act['addon_messages'] . "<br />\n" . $this->addons[$addon]->messages . "</li>\n";
						}
						else
						{
							$output .= sprintf($roster->locale->act['error_addon'],$this->addons[$addon]->data['fullname'],$mode) . "<br />\n"
									 . $roster->locale->act['addon_messages'] . "<br />\n" . $this->addons[$addon]->messages . "<br />\n";
						}
					}
				}
			}
		}

		return $output;
	}

	/**
	 * Process character data
	 */
	function processMyProfile()
	{
		global $roster;
		/**
		 * Rule #1 Deny everything
		 * Rule #2 If it breaks, Zanix did it
		 * Rule #3 This works for both new and old CPs lol
		 * Rule #4 If Zanix yells at you, you deserve it
		 */

		if ( isset($this->uploadData['wowrcp']['cpProfile']) )
		{
			$myProfile = $this->uploadData['wowrcp']['cpProfile'];
		}
		else
		{
			return;
		}

		$output = '';
		$this->resetMessages();

		foreach( $myProfile as $realm_name => $realm )
		{
			$this->current_realm = $realm_name;

			if( isset($realm['Character']) && is_array($realm['Character']) )
			{
				$characters = $realm['Character'];

				// Start update triggers
				if( $roster->config['use_update_triggers'] )
				{
					$output .= $this->addon_hook('char_pre', $characters);
				}

				foreach( $characters as $char_name => $char )
				{
					$this->current_member = $char_name;
					if( $roster->config['use_api_onupdate'] == 1 )
					{
						$char['API'] = $roster->api->Char->getCharInfo($realm_name,$char_name,'ALL');
					}
					

					// CP Version Detection, don't allow lower than minVer
					if( version_compare($char['CPversion'], $roster->config['minCPver'], '>=') )
					{
						// Get the region
						if( isset($char['timestamp']['init']['datakey']) )
						{
							list($region) = explode(':',$char['timestamp']['init']['datakey']);
							$region = strtoupper($region);
						}
						else
						{
							$region = '';
						}
						// Official realms don't trigger this. I looked up and verified the asian ones as well.
						if( strlen($region) > 2 )
						{
							roster_die('You are not playing on an official realm, and your data is incompatible with WoWRoster<br /><br />'
								. 'This message exists because we are getting annoyed by the occasional person who can\'t get WoWRoster to work with a private server, '
								. 'when we clearly state that WoWRoster will not work on private servers.<br />'
								. 'You are on your own if you want WoWRoster to work with a private server. Good luck fixing it!'
								,'Invalid Region/Realm');
						}
						$this->current_region = $region;

						// Get the CP timestamp
						$timestamp = $char['timestamp']['init']['DateUTC'];

						$realm_escape = $roster->db->escape($realm_name);

						// Is this char already in the members table?
						$query = "SELECT `guild_id`, `member_id`"
							   . " FROM `" . $roster->db->table('members') . "`"
							   . " WHERE `name` = '" . $char_name . "'"
							   . " AND `server` = '" . $realm_escape . "'"
							   . " AND `region` = '" . $region . "';";


						if( !$roster->db->query_first($query) )
						{
							// Allowed char detection
							$query = "SELECT `type`, COUNT(`rule_id`)"
								   . " FROM `" . $roster->db->table('upload') . "`"
								   . " WHERE (`type` = 2 OR `type` = 3)"
								   . " AND '" . $char_name . "' LIKE `name`"
								   . " AND '" . $realm_escape . "' LIKE `server`"
								   . " AND '" . $region."' LIKE `region`"
								   . " GROUP BY `type`"
								   . " ORDER BY `type` DESC;";

							/**
							 * This might need explaining. The query potentially returns 2 rows:
							 * First the number of matching deny rows, then the number of matching
							 * accept rows. If there are deny rows, `type`=3 in the first row, and
							 * we reject the upload. If there are no deny rows, but there are accept
							 * rows, `type`=2 in the first row, and we accept the upload. If there are
							 * no relevant rows at all, query_first will return false, and we reject
							 * the upload.
							 */

							if( $roster->db->query_first($query) !== '2' )
							{
								$output .= '<span class="red">' . sprintf($roster->locale->act['not_accepted'], $roster->locale->act['character'], $char_name, $region, $realm_name) . "</span><br />\n";
								continue;
							}
							else
							{
								// Fabricate a guild update
								// We can probably use the $char['Guild'] block for this info instead of Guildless I suppose....
								$guilddata['Faction'] = $char['FactionEn'];
								$guilddata['FactionEn'] = $char['FactionEn'];
								$guilddata['Locale'] = $char['Locale'];
								$guilddata['Info'] = '';
								$guildId = $this->update_guild($realm_name, 'GuildLess-' . substr($char['FactionEn'],0,1), strtotime($timestamp), $guilddata, $region);

								unset($guilddata);

								// Copy the array so we can set Online to 1 until I can find a better way to set last online time
								// We could probably get away with just setting 'Online' in the $char array, but I dont wanna risk tainting the data
								$chartemp = $char;
								$chartemp['Online'] = '1';
								$this->update_guild_member($guildId, $char_name, $realm_name, $region, $chartemp, strtotime($timestamp), array());
								unset($chartemp);
								array_pop($this->messages);
							}
						}
						else
						{
							$guildId = $roster->db->query_first($query);
						}

						$time = $roster->db->query_first("SELECT `dateupdatedutc` FROM `" . $roster->db->table('players') . "`"
							  . " WHERE '" . $char_name . "' LIKE `name`"
							  . " AND '" . $realm_escape . "' LIKE `server`"
							  . " AND '" . $region . "' LIKE `region`;");

						// Check if the profile is old
						if( $time != '' && ( strtotime($time) - strtotime($timestamp) ) > 0 )
						{
							$current = date($roster->locale->act['phptimeformat'], strtotime($time));
							$update = date($roster->locale->act['phptimeformat'], strtotime($timestamp));

							$output .= '<span class="red">' . sprintf($roster->locale->act['not_update_char_time'], $char_name, $update, $current) . "</span><br />\n";
							continue;
						}

						$output .= '<strong>' . sprintf($roster->locale->act['upload_data'], $roster->locale->act['character'], $char_name, $realm_name, $region) . "</strong>\n";

						$memberid = $this->update_char($guildId, $region, $realm_name, $char_name, $char);
						$output .= "<ul>\n" . $this->getMessages() . "</ul>\n";
						$this->resetMessages();

						// Start update triggers
						if( $memberid !== false && $roster->config['use_update_triggers'] )
						{
							$output .= $this->addon_hook('char', $char, $memberid);
						}
					}
					else // CP Version not new enough
					{
						$output .= '<span class="red">' . sprintf($roster->locale->act['not_updating'], 'WoWRoster-Profiler', $char_name, $char['CPversion']) . "</span><br />\n";
						$output .= sprintf($roster->locale->act['CPver_err'], $roster->config['minCPver']) . "\n";
					}
				}

				// Start update triggers
				if( $roster->config['use_update_triggers'] )
				{
					$output .= $this->addon_hook('char_post', $characters);
				}
			}
		}
		return $output;
	}

	/**
	 * Process guild data
	 */
	function processGuildRoster()
	{
		global $roster;

		if ( isset($this->uploadData['wowrcp']['cpProfile']) )
		{
			$myProfile = $this->uploadData['wowrcp']['cpProfile'];
		}
		else
		{
			return;
		}

		$output = '';
		$this->resetMessages();

		if( is_array($myProfile) )
		{
			foreach( $myProfile as $realm_name => $realm )
			{
				$this->current_realm = $realm_name;

				if( isset($realm['Guild']) && is_array($realm['Guild']) )
				{
					foreach( $realm['Guild'] as $guild_name => $guild )
					{
						$this->current_guild = $guild_name;
						
						// GP Version Detection, don't allow lower than minVer
						if( version_compare($guild['GPversion'], $roster->config['minGPver'], '>=') )
						{
							// Get the region
							if( isset($guild['timestamp']['init']['datakey']) )
							{
								list($region) = explode(':',$guild['timestamp']['init']['datakey']);
								$region = strtoupper($region);
							}
							else
							{
								$region = '';
							}
							$this->current_region = $region;

							$guild_escape = $roster->db->escape($guild_name);
							$realm_escape = $roster->db->escape($realm_name);

							// Allowed guild detection
							$query = "SELECT `type`, COUNT(`rule_id`)"
								   . " FROM `" . $roster->db->table('upload') . "`"
								   . " WHERE (`type` = 0 OR `type` = 1)"
								   . " AND '" . $guild_escape . "' LIKE `name`"
								   . " AND '" . $realm_escape . "' LIKE `server`"
								   . " AND '" . $region . "' LIKE `region`"
								   . " GROUP BY `type`"
								   . " ORDER BY `type` DESC;";

							/**
							 * This might need explaining. The query potentially returns 2 rows:
							 * First the number of matching deny rows, then the number of matching
							 * accept rows. If there are deny rows, `type`=1 in the first row, and
							 * we reject the upload. If there are no deny rows, but there are accept
							 * rows, `type`=0 in the first row, and we accept the upload. If there are
							 * no relevant rows at all, query_first will return false, and we reject
							 * the upload.
							 */

							if( $roster->db->query_first($query) !== '0' )
							{
								$output .= '<span class="red">' . sprintf($roster->locale->act['not_accepted'], $roster->locale->act['guild'], $guild_name, $region, $realm_name) . "</span><br />\n";
								continue;
							}

							if( count($guild['Members']) > 0 )
							{
								// take the current time and get the offset. Upload must occur same day that roster was obtained
								$currentTimestamp = strtotime($guild['timestamp']['init']['DateUTC']);

								$time = $roster->db->query_first("SELECT `update_time` FROM `" . $roster->db->table('guild')
									  . "` WHERE '" . $guild_escape . "' LIKE `guild_name`"
									  . " AND '" . $realm_escape . "' LIKE `server`"
									  . " AND '" . $region . "' LIKE `region`;");

								// Check if the profile is old
								if( $time != '' && ( strtotime($time) - $currentTimestamp ) > 0 )
								{
									$current = date($roster->locale->act['phptimeformat'], strtotime($time));
									$update = date($roster->locale->act['phptimeformat'], $currentTimestamp);

									$output .= '<span class="red">' . sprintf($roster->locale->act['not_update_guild_time'], $guild_name, $update, $current) . "</span><br />\n";
									continue;
								}

								// Update the guild
								$guildId = $this->update_guild($realm_name, $guild_name, $currentTimestamp, $guild, $region);
								$guild['guild_id'] = $guildId;
								// update and upload guild ranks
								if (isset($guild['Ranks']))
								{
									$this->update_guild_ranks($guild, $guildId);
								}
								$guildMembers = $guild['Members'];

								$guild_output = '';

								// Start update triggers
								if( $roster->config['use_update_triggers'] )
								{
									$guild_output .= $this->addon_hook('guild_pre', $guild);
								}

								// update the list of guild members
								$guild_output .= "<ul><li><strong>" . $roster->locale->act['update_members'] . "</strong>\n<ul>\n";

								foreach(array_keys($guildMembers) as $char_name)
								{
									$this->current_member = $char_name;

									$char = $guildMembers[$char_name];
									/*
										5.4.2? update names now use realm
									*/
									list($cname, $cserver) = explode('-',$char_name);
									$memberid = $this->update_guild_member($guildId, $cname, $cserver, $region, $char, $currentTimestamp, $guild);
									/* end update */
									$guild_output .= $this->getMessages();
									$this->resetMessages();

									// Start update triggers
									if( $memberid !== false && $roster->config['use_update_triggers'] )
									{
										$guild_output .= $this->addon_hook('guild', $char, $memberid);
									}
									$this->setMessage('</ul></li>');
								}

								// Remove the members who were not in this list
								$this->remove_guild_members($guildId, $currentTimestamp);

								$guild_output .= $this->getMessages()."</ul></li>\n";
								$this->resetMessages();

								$guild_output .= "</ul>\n";

								// Start update triggers
								if( $roster->config['use_update_triggers'] )
								{
									$guild_output .= $this->addon_hook('guild_post', $guild);
								}

								$output .= '<strong>' . sprintf($roster->locale->act['upload_data'],$roster->locale->act['guild'],$guild_name,$realm_name,$region) . "</strong>\n<ul>\n";
								$output .= '<li><strong>' . $roster->locale->act['memberlog'] . "</strong>\n<ul>\n"
										 . '<li>' . $roster->locale->act['updated'] . ': ' . $this->membersupdated . "</li>\n"
										 . '<li>' . $roster->locale->act['added'] . ': ' . $this->membersadded . "</li>\n"
										 . '<li>' . $roster->locale->act['removed'] . ': ' . $this->membersremoved . "</li>\n"
										 . "</ul></li></ul>\n";
								$output .= $guild_output;

								// Reset these since we might process another guild
								$this->membersupdated = $this->membersadded = $this->membersremoved = 0;
							}
							else
							{
								$output .= '<span class="red">' . sprintf($roster->locale->act['not_update_guild'], $guild_name, $realm_name, $region) . "</span><br />\n";
								$output .= $roster->locale->act['no_members'];
							}
						}
						else
						// GP Version not new enough
						{
							$output .= '<span class="red">' . sprintf($roster->locale->act['not_updating'], 'WoWRoster-GuildProfiler', $guild_name, $guild['GPversion']) . "</span><br />\n";
							$output .= sprintf($roster->locale->act['GPver_err'], $roster->config['minGPver']);
						}
					}
				}
				else
				{
					$output .= '<span class="red">' . $roster->locale->act['guild_addonNotFound'] . '</span><br />';
				}
			}
		}
		return $output;
	}

	/**
	 * Returns the file input fields for all addon files we need.
	 *
	 * @return string $filefields | The HTML, without border
	 */
	function makeFileFields($blockname='file_fields')
	{
		global $roster;

		if( !is_array($this->files) || (count($this->files) == 0) ) // Just in case
		{
			$roster->tpl->assign_block_vars($blockname, array(
				'TOOLTIP' => '',
				'FILE' => 'No files accepted!'
			));
		}

		$account_dir = '<i>*WOWDIR*</i>\\\\WTF\\\\Account\\\\<i>*ACCOUNT_NAME*</i>\\\\SavedVariables\\\\';

		foreach( $this->files as $file )
		{
			$roster->tpl->assign_block_vars($blockname, array(
				'TOOLTIP' => makeOverlib($account_dir . $file . '.lua', $file . '.lua Location', '', 2, '', ',WRAP'),
				'FILE' => $file
			));
		}
	}

	/**
	 * Adds a message to the $messages array
	 *
	 * @param string $message
	 */
	function setMessage($message)
	{
		$this->messages[] = $message;
	}


	/**
	 * Returns all messages
	 *
	 * @return string
	 */
	function getMessages()
	{
		return implode("\n",$this->messages) . "\n";
	}


	/**
	 * Resets the stored messages
	 *
	 */
	function resetMessages()
	{
		$this->messages = array();
	}


	/**
	 * Adds an error to the $errors array
	 *
	 * @param string $message
	 */
	function setError( $message , $error )
	{
		$this->errors[] = array($message=>$error);
	}


	/**
	 * Gets the errors in wowdb
	 * Return is based on $mode
	 *
	 * @param string $mode
	 * @return mixed
	 */
	function getErrors( $mode='' )
	{
		if( $mode == 'a' )
		{
			return $this->errors;
		}

		$output = '';

		$errors = $this->errors;
		if( !empty($errors) )
		{
			$output = '<table width="100%" cellspacing="0">';
			$steps = 0;
			foreach( $errors as $errorArray )
			{
				foreach( $errorArray as $message => $error )
				{
					if( $steps == 1 )
					{
						$steps = 2;
					}
					else
					{
						$steps = 1;
					}

					$output .= "<tr><td class=\"membersRowRight$steps\">$error<br />\n"
							 . "$message</td></tr>\n";
				}
			}
			$output .= '</table>';
		}
		return $output;
	}

	/**
	 * DB insert code (former WoWDB)
	 */

	/**
	 * Resets the SQL insert/update string holder
	 */
	function reset_values()
	{
		$this->assignstr = '';
	}


	/**
	 * Add a value to an INSERT or UPDATE SQL string
	 *
	 * @param string $row_name
	 * @param string $row_data
	 */
	function add_value( $row_name , $row_data )
	{
		global $roster;

		if( $this->assignstr != '' )
		{
			$this->assignstr .= ',';
		}

		// str_replace added to get rid of non breaking spaces in cp.lua tooltips
		$row_data = str_replace('\n\n','<br>',$row_data);
		$row_data = str_replace(chr(194) . chr(160), ' ', $row_data);
		$row_data = stripslashes($row_data);
		$row_data = $roster->db->escape($row_data);

		$this->assignstr .= " `$row_name` = '$row_data'";
	}


	/**
	 * Verifies existance of variable before attempting add_value
	 *
	 * @param array $array
	 * @param string $key
	 * @param string $field
	 * @param string $default
	 * @return boolean
	 */
	function add_ifvalue( $array , $key , $field=false , $default=false )
	{
		if( $field === false )
		{
			$field = $key;
		}

		if( isset($array[$key]) )
		{
			$this->add_value($field, $array[$key]);
			return true;
		}
		else
		{
			if( $default !== false )
			{
				$this->add_value($field, $default);
			}
			return false;
		}
	}

	/**
	 * Add a gem to an INSERT or UPDATE SQL string
	 * (clone of add_value method--this functions as a 2nd SQL insert placeholder)
	 *
	 * @param string $row_name
	 * @param string $row_data
	 */
	function add_gem( $row_name , $row_data )
	{
		global $roster;

		if( $this->assigngem != '' )
		{
			$this->assigngem .= ',';
		}

		$row_data = "'" . $roster->db->escape($row_data) . "'";

		$this->assigngem .= " `$row_name` = $row_data";
	}


	/**
	 * Add a time value to an INSERT or UPDATE SQL string
	 *
	 * @param string $row_name
	 * @param array $date
	 */
	function add_time( $row_name , $date )
	{
		// 2000-01-01 23:00:00.000
		$row_data = $date['year'] . '-' . $date['mon'] . '-' . $date['mday'] . ' ' . $date['hours'] . ':' . $date['minutes'] . ':' . $date['seconds'];
		$this->add_value($row_name,$row_data);
	}


	/**
	 * Add a time value to an INSERT or UPDATE SQL string
	 *
	 * @param string $row_name
	 * @param string $date | UNIX TIMESTAMP
	 */
	function add_timestamp( $row_name , $date )
	{
		$date = date('Y-m-d H:i:s',$date);
		$this->add_value($row_name,$date);
	}

	/**
	 * Add a rating (base, buff, debuff, total)
	 *
	 * @param string $row_name will be appended _d, _b, _c for debuff, buff, total
	 * @param string $data colon-separated data
	 */
	function add_rating( $row_name , $data )
	{
		$data = explode(':',$data);
		$data[0] = ( isset($data[0]) && $data[0] != '' ? $data[0] : 0 );
		$data[1] = ( isset($data[1]) && $data[1] != '' ? $data[1] : 0 );
		$data[2] = ( isset($data[2]) && $data[2] != '' ? $data[2] : 0 );
		$this->add_value($row_name, round($data[0]));
		$this->add_value($row_name . '_c', round($data[0]+$data[1]+$data[2]));
		$this->add_value($row_name . '_b', round($data[1]));
		$this->add_value($row_name . '_d', round($data[2]));
	}

	/**
	 * Turn the WoW internal icon format into the one used by us
	 * All lower case and spaces converted into _
	 *
	 * @param string $icon_name
	 * @return string
	 */
	function fix_icon( $icon_name )
	{
		$icon_name = basename($icon_name);
		return strtolower(str_replace(' ','_',$icon_name));
	}

	/**
	 * Format tooltips for insertion to the db
	 *
	 * @param mixed $tipdata
	 * @return string
	 */
	function tooltip( $tipdata )
	{
		$tooltip = '';
		//$tipdata = preg_replace('/\|c[a-f0-9]{8}(.+?)\|r/i','$1',$tipdata);
		$tipdata = preg_replace('/\|c([0-9a-f]{2})([0-9a-f]{6})([^\|]+)/','<span style="color:#$2;">$3</span>',$tipdata);
		$tipdata = str_replace('|r', '', $tipdata);

		
		if( is_array($tipdata) )
		{
			$tooltip = implode("<br>",$tipdata);
		}
		else
		{
			$tooltip = $tipdata;//str_replace('<br>',"\n",$tipdata);
		}
		return $tooltip;
	}


	/**
	 * Inserts an reagent into the database
	 *
	 * @param string $item
	 * @return bool
	 */
	function insert_reagent( $memberId , $reagents , $locale )
	{
		global $roster;
		//echo'<pre>';
		//print_r($reagents);

		foreach ($reagents as $ind => $reagent)
		{
			$this->reset_values();
			$this->add_value('member_id', $memberId);
			$this->add_value('reagent_id', $reagent['Item']);
			$this->add_ifvalue($reagent, 'Name', 'reagent_name');
			$this->add_ifvalue($reagent, 'Count', 'reagent_count');
			$this->add_ifvalue($reagent, 'Color', 'reagent_color');

			// Fix icon
			if( !empty($reagent['Icon']) )
			{
				$reagent['Icon'] = $this->fix_icon($reagent['Icon']);
			}
			else
			{
				$reagent['Icon'] = 'inv_misc_questionmark';
			}

			// Fix tooltip
			if( !empty($reagent['Tooltip']) )
			{
				$reagent['item_tooltip'] = $this->tooltip($reagent['Tooltip']);
			}
			else
			{
				$reagent['item_tooltip'] = $reagent['Name'];
			}

			$this->add_value('reagent_texture', $reagent['Icon']);
			$this->add_value('reagent_tooltip', $reagent['Tooltip']);

			$this->add_value('locale', $locale);

/*			$level = array();
			if( isset($reagent_data['reqLevel']) && !is_null($reagent_data['reqLevel']) )
			{
				$this->add_value('level', $reagent_data['reqLevel']);
			}
			else if( preg_match($roster->locale->wordings[$locale]['requires_level'],$reagent['item_tooltip'],$level))
			{
				$this->add_value('level', $level[1]);
			}

			// gotta see of the reagent is in the db already....
*/
			$querystra = "SELECT * FROM `" . $roster->db->table('recipes_reagents') . "` WHERE `reagent_id` = " . $reagent['Item'] . ";";
			$resulta = $roster->db->query($querystra);
			$num = $roster->db->num_rows($resulta);

			if ($num < '1')
			{
			$querystr = "INSERT INTO `" . $roster->db->table('recipes_reagents') . "` SET " . $this->assignstr . ";";
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Item [' . $reagent['Name'] . '] could not be inserted',$roster->db->error());
			}
			}

		}
	}



	/**
	 * Inserts an item into the database
	 *
	 * @param string $item
	 * @return bool
	 */
	function insert_item( $item , $locale )
	{
		global $roster;
		// echo '<pre>';
		//print_r($item);

		$this->reset_values();
		$this->add_ifvalue($item, 'member_id');
		$this->add_ifvalue($item, 'item_name');
		$this->add_ifvalue($item, 'item_parent');
		$this->add_ifvalue($item, 'item_slot');
		$this->add_ifvalue($item, 'item_color');
		$this->add_ifvalue($item, 'item_id');
		$this->add_ifvalue($item, 'item_texture');
		$this->add_ifvalue($item, 'item_quantity');
		$this->add_ifvalue($item, 'item_tooltip');
		$this->add_ifvalue($item, 'item_level');
		$this->add_ifvalue($item, 'item_type');
		$this->add_ifvalue($item, 'item_subtype');
		$this->add_ifvalue($item, 'item_rarity');
		$this->add_ifvalue($item, 'json');
		$this->add_value('locale', $locale);

/*
		$level = array();
		if( isset($item_data['reqLevel']) && !is_null($item_data['reqLevel']) )
		{
			$this->add_value('level', $item_data['reqLevel']);
		}
		else if( preg_match($roster->locale->wordings[$locale]['requires_level'],$item['item_tooltip'],$level))
		{
			$this->add_value('level', $level[1]);
		}
 */
		$querystr = "INSERT INTO `" . $roster->db->table('items') . "` SET " . $this->assignstr . ";";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Item [' . $item['item_name'] . '] could not be inserted',$roster->db->error());
		}
	}

	/**
	 * Inserts mail into the Database
	 *
	 * @param array $mail
	 */
	function insert_mail( $mail )
	{
		global $roster;

		$this->reset_values();
		$this->add_ifvalue($mail, 'member_id');
		$this->add_ifvalue($mail, 'mail_slot', 'mailbox_slot');
		$this->add_ifvalue($mail, 'mail_icon', 'mailbox_icon');
		$this->add_ifvalue($mail, 'mail_coin', 'mailbox_coin');
		$this->add_ifvalue($mail, 'mail_coin_icon', 'mailbox_coin_icon');
		$this->add_ifvalue($mail, 'mail_days', 'mailbox_days');
		$this->add_ifvalue($mail, 'mail_sender', 'mailbox_sender');
		$this->add_ifvalue($mail, 'mail_subject', 'mailbox_subject');

		$querystr = "INSERT INTO `" . $roster->db->table('mailbox') . "` SET " . $this->assignstr . ";";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Mail [' . $mail['mail_subject'] . '] could not be inserted',$roster->db->error());
		}
	}


	/**
	 * Inserts a recipe into the Database
	 *
	 * @param array $recipe
	 * @param string $locale
	 */
	function insert_recipe( $recipe , $locale )
	{
		global $roster;

		$this->reset_values();
		$this->add_ifvalue($recipe, 'member_id');
		$this->add_ifvalue($recipe, 'recipe_id');
		$this->add_ifvalue($recipe, 'item_id');
		$this->add_ifvalue($recipe, 'recipe_name');
		$this->add_ifvalue($recipe, 'recipe_type');
		$this->add_ifvalue($recipe, 'recipe_sub_type');
		$this->add_ifvalue($recipe, 'skill_name');
		$this->add_ifvalue($recipe, 'difficulty');
		$this->add_ifvalue($recipe, 'item_color');
		$this->add_ifvalue($recipe, 'reagent_list','reagents');
		$this->add_ifvalue($recipe, 'recipe_texture');
		$this->add_ifvalue($recipe, 'recipe_tooltip');

		$level = array();
		if( preg_match($roster->locale->wordings[$locale]['requires_level'],$recipe['recipe_tooltip'],$level))
		{
			$this->add_value('level',$level[1]);
		}

		$querystra = "SELECT * FROM `" . $roster->db->table('recipes') . "` WHERE `member_id` = '" . $recipe['member_id'] . "' and `recipe_name` = '".addslashes($recipe['recipe_name'])."' and `skill_name` = '".addslashes($recipe['skill_name'])."';";
		$resulta = $roster->db->query($querystra);
		$num = $roster->db->num_rows($resulta);

		if ($num <=0)
		{
			$querystr = "INSERT INTO `" . $roster->db->table('recipes') . "` SET " . $this->assignstr . ";";
			$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Recipe [' . $recipe['recipe_name'] . '] could not be inserted',$roster->db->error());
		}
		}
	}


	/**
	 * Formats quest data and inserts into the DB
	 *
	 * @param array $quest
	 * @param int $member_id
	 * @param string $zone
	 * @param array $data
	 */
	function insert_quest( $quest , $member_id , $zone , $slot , $data )
	{
		global $roster;

		// Fix quest name since many 'quest' addons cause the level number to be added to title
		while( substr($quest['Title'],0,1) == '[' )
		{
			$quest['Title'] = ltrim(substr($quest['Title'],strpos($quest['Title'],']')+1));
		}

		// Insert this quest into the quest data table, db normalization is great huh?
		$this->reset_values();
		$this->add_ifvalue($quest, 'QuestId', 'quest_id');
		$this->add_value('quest_name', $quest['Title']);
		$this->add_ifvalue($quest, 'Level', 'quest_level');
		$this->add_ifvalue($quest, 'Tag', 'quest_tag');
		$this->add_ifvalue($quest, 'Group', 'group');
		$this->add_ifvalue($quest, 'Daily', 'daily');
		$this->add_ifvalue($quest, 'RewardMoney', 'reward_money');

		if( isset($quest['Description']) )
		{
			$description = str_replace('\n',"\n",$quest['Description']);
			$description = str_replace($data['Class'],'<class>',$description);
			$description = str_replace($data['Name'],'<name>',$description);

			$this->add_value('description', $description);

			unset($description);
		}

		if( isset($quest['Objective']) )
		{
			$objective = str_replace('\n',"\n",$quest['Objective']);
			$objective = str_replace($data['Class'],'<class>',$objective);
			$objective = str_replace($data['Name'],'<name>',$objective);

			$this->add_value('objective', $objective);

			unset($objective);
		}

		$this->add_value('zone', $zone);
		$this->add_value('locale', $data['Locale']);

		$querystr = "REPLACE INTO `" . $roster->db->table('quest_data') . "` SET " . $this->assignstr . ";";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Quest Data [' . $quest['QuestId'] . ' : ' . $quest['Title'] . '] could not be inserted',$roster->db->error());
		}
/*
		// Now process tasks
		   NOT PROCESSING, BUT CODE AND TABLE LAYOUT IS HERE FOR LATER
		   The reason is that the task number is in the name
		   and this is not good for a normalized table

# --------------------------------------------------------
### Quest Tasks

DROP TABLE IF EXISTS `renprefix_quest_task_data`;
CREATE TABLE `renprefix_quest_task_data` (
  `quest_id` int(11) NOT NULL default '0',
  `task_id` int(11) NOT NULL default '0',
  `note` varchar(128) NOT NULL default '',
  `type` varchar(32) NOT NULL default '',
  `locale` varchar(4) NOT NULL default '',
  PRIMARY KEY  (`quest_id`,`task_id`,`locale`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

		if( isset($quest['Tasks']) && !empty($quest['Tasks']) && is_array($quest['Tasks']) )
		{
			$tasks = $quest['Tasks'];

			foreach( array_keys($tasks) as $task )
			{
				$taskInfo = $tasks[$task];

				$this->reset_values();
				$this->add_ifvalue($quest, 'QuestId', 'quest_id');
				$this->add_value('task_id', $task);

				if( isset($taskInfo['Note']) )
				{
					$note = explode(':',$taskInfo['Note']);
					$this->add_value('note', $note[0]);
					unset($note);
				}
				$this->add_ifvalue($taskInfo, 'Type', 'type');
				$this->add_value('locale', $data['Locale']);

				$querystr = "REPLACE INTO `" . $roster->db->table('quest_task_data') . "` SET " . $this->assignstr . ";";
				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError('Quest Task [' . $taskInfo['Note'] . '] for Quest Data [' . $quest['QuestId'] . ' : ' . $quest['Title'] . '] could not be inserted',$roster->db->error());
				}
			}
		}
*/

		// Insert this quest id for the character
		$this->reset_values();
		$this->add_value('member_id', $member_id);
		$this->add_ifvalue($quest, 'QuestId', 'quest_id');
		$this->add_value('quest_index', $slot);
		$this->add_ifvalue($quest, 'Difficulty', 'difficulty');
		$this->add_ifvalue($quest, 'Complete', 'is_complete');

		$querystr = "INSERT INTO `" . $roster->db->table('quests') . "` SET " . $this->assignstr . ";";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Quest [' . $quest['Title'] . '] could not be inserted',$roster->db->error());
		}
	}


	/**
	 * Formats mail data to be inserted to the db
	 *
	 * @param array $mail_data
	 * @param int $memberId
	 * @param string $slot_num
	 * @return array
	 */
	function make_mail( $mail_data , $memberId , $slot_num )
	{
		$mail = array();
		$mail['member_id'] = $memberId;
		$mail['mail_slot'] = $slot_num;
		$mail['mail_icon'] = $this->fix_icon($mail_data['MailIcon']);
		$mail['mail_coin'] = ( isset($mail_data['Coin']) ? $mail_data['Coin'] : 0 );
		$mail['mail_coin_icon'] = ( isset($mail_data['CoinIcon']) ? $this->fix_icon($mail_data['CoinIcon']) : '' );
		$mail['mail_days'] = $mail_data['Days'];
		$mail['mail_sender'] = $mail_data['Sender'];
		$mail['mail_subject'] = $mail_data['Subject'];

		return $mail;
	}


	/**
	 * Formats item data to be inserted into the db
	 *
	 * @param array $item_data
	 * @param int $memberId
	 * @param string $parent
	 * @param string $slot_name
	 * @return array
	 */
	function make_item( $item_data , $memberId , $parent , $slot_name )
	{
		$item = array();
		$item['member_id'] = $memberId;
		$item['item_name'] = $item_data['Name'];
		$item['item_parent'] = $parent;
		$item['item_slot'] = $slot_name;
		$item['item_color'] = ( isset($item_data['Color']) ? $item_data['Color'] : 'ffffff' );
		$item['item_id'] = ( isset($item_data['Item']) ? $item_data['Item'] : '0:0:0:0:0:0:0:0' );
		$item['item_texture'] = ( isset($item_data['Icon']) ? $this->fix_icon($item_data['Icon']) : 'inv_misc_questionmark' );
		$item['item_quantity'] = ( isset($item_data['Quantity']) ? $item_data['Quantity'] : 1 );
		$item['level'] = ( isset($item_data['reqLevel']) ? $item_data['reqLevel'] : null );
		$item['item_level'] = ( isset($item_data['iLevel']) ? $item_data['iLevel'] : '' );
		$item['item_type'] = ( isset($item_data['Type']) ? $item_data['Type'] : '' );
		$item['item_subtype'] = ( isset($item_data['SubType']) ? $item_data['SubType'] : '' );
		$item['item_rarity'] = ( isset($item_data['Rarity']) ? $item_data['Rarity'] : '' );
		$item['json'] = ( isset($item_data['json']) ? $item_data['json'] : '' );

		if( !empty($item_data['Tooltip']) )
		{
			$item['item_tooltip'] = $this->tooltip($item_data['Tooltip']);
		}
		else
		{
			$item['item_tooltip'] = $item_data['Name'];
		}

		if( !empty($item_data['Gem']))
		{
			$this->do_gems($item_data['Gem'], $item_data['Item']);
		}

		return $item;
	}

	/**
	 * Formats gem data to be inserted into the database
	 *
	 * @param array $gem_data
	 * @param int $socket_id
	 * @return array $gem if successful else returns false
	 */
	function make_gem( $gem_data , $socket_id )
	{
		global $roster;

		$gemtt = explode( '<br>', $gem_data['Tooltip'] );

		if( $gemtt[0] !== '' )
		{
			foreach( $gemtt as $line )
			{
				$colors = array();
				$line = preg_replace('/\|c[a-f0-9]{8}(.+?)\|r/i','$1',$line); // CP error? strip out color
				// -- start the parsing
				if( preg_match('/'.$roster->locale->wordings[$this->locale]['tooltip_boss'] . '|' . $roster->locale->wordings[$this->locale]['tooltip_source'] . '|' . $roster->locale->wordings[$this->locale]['tooltip_droprate'].'/', $line) )
				{
					continue;
				}
				elseif( preg_match('/%|\+|'.$roster->locale->wordings[$this->locale]['tooltip_chance'].'/', $line) )  // if the line has a + or % or the word Chance assume it's bonus line.
				{
					$gem_bonus = $line;
				}
				elseif( preg_match($roster->locale->wordings[$this->locale]['gem_preg_meta'], $line) )
				{
					$gem_color = 'meta';
				}
				elseif( preg_match($roster->locale->wordings[$this->locale]['gem_preg_multicolor'], $line, $colors) )
				{
					if( $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['red'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['blue'] || $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['blue'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['red'] )
					{
						$gem_color = 'purple';
					}
					elseif( $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['yellow'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['red'] || $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['red'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['yellow'] )
					{
						$gem_color = 'orange';
					}
					elseif( $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['yellow'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['blue'] || $colors[1] == $roster->locale->wordings[$this->locale]['gem_colors']['blue'] && $colors[2] == $roster->locale->wordings[$this->locale]['gem_colors']['yellow'] )
					{
						$gem_color = 'green';
					}
				}
				elseif( preg_match($roster->locale->wordings[$this->locale]['gem_preg_singlecolor'], $line, $colors) )
				{
					$tmp = array_flip($roster->locale->wordings[$this->locale]['gem_colors']);
					$gem_color = $tmp[$colors[1]];
				}
				elseif( preg_match($roster->locale->wordings[$this->locale]['gem_preg_prismatic'], $line) )
				{
					$gem_color = 'prismatic';
				}
			}
			//get gemid and remove the junk
			list($gemid) = explode(':', $gem_data['Item']);

			$gem = array();
			$gem['gem_name'] 	= $gem_data['Name'];
			$gem['gem_tooltip'] = $this->tooltip($gem_data['Tooltip']);
			$gem['gem_bonus'] 	= $gem_bonus;
			$gem['gem_socketid']= $gem_data['gemID'];//$socket_id;  // the ID the gem holds when socketed in an item.
			$gem['gem_id'] 		= $gemid; // the ID of gem when not socketed.
			$gem['gem_texture'] = $this->fix_icon($gem_data['Icon']);
			$gem['gem_color'] 	= $gem_color;  //meta, prismatic, red, blue, yellow, purple, green, orange.

			return $gem;
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Formats each gem found in each slot of item and inserts into database.
	 *
	 * @param array $gems
	 * @param string $itemid_data
	 */
	function do_gems( $gems , $itemid_data )
	{
		$itemid = explode(':', $itemid_data);
		foreach($gems as $key => $val)
		{
			$socketid = $itemid[(int)$key+1];
			$gem = $this->make_gem($val, $socketid);
			if( $gem )
			{
				$this->insert_gem($gem);
			}
		}
	}
	/**
	 * Inserts a gem into the database
	 *
	 * @param array $gem
	 * @return bool | true on success, false if error
	 */
	function insert_gem( $gem )
	{
		global $roster;

		$this->assigngem='';
		$this->add_gem('gem_id', $gem['gem_socketid']);//$gem['gem_id']);
		$this->add_gem('gem_name', $gem['gem_name']);
		$this->add_gem('gem_color', $gem['gem_color']);
		$this->add_gem('gem_tooltip', $gem['gem_tooltip']);
		$this->add_gem('gem_bonus', $gem['gem_bonus']);
		$this->add_gem('gem_socketid', $gem['gem_id']);//$gem['gem_socketid']);
		$this->add_gem('gem_texture', $gem['gem_texture']);
		$this->add_gem('locale', $this->locale);

		$querystr = "REPLACE INTO `" . $roster->db->table('gems') . "` SET ".$this->assigngem . "  ";//WHERE `gem_socketid` = '".$gem['gem_socketid']."'";
		$result = $roster->db->query($querystr);
		if ( !$result )
		{
			return false;
		}
		else
		{
			return true;
		}
	}
	

	/**
	 * Formats recipe data to be inserted into the db
	 *
	 * @param array $recipe_data
	 * @param int $memberId
	 * @param string $parent
	 * @param string $recipe_type
	 * @param string $recipe_name
	 * @return array
	 */
	function make_recipe( $recipe_data , $memberId , $parent , $recipe_type , $recipe_sub_type , $recipe_name )
	{
		$recipe = array();
		$recipe['member_id'] = $memberId;
		$recipe['recipe_name'] = $recipe_name;
		$recipe['recipe_type'] = $recipe_type;
		$recipe['recipe_sub_type'] = $recipe_sub_type;
		$recipe['skill_name'] = $parent;

		// Fix Difficulty since it's now a string field
		if( !is_numeric($recipe_data['Difficulty']) )
		{
			switch($recipe_data['Difficulty'])
			{
				case 'difficult':
					$recipe['difficulty'] = 5;
					break;

				case 'optimal':
					$recipe['difficulty'] = 4;
					break;

				case 'medium':
					$recipe['difficulty'] = 3;
					break;

				case 'easy':
					$recipe['difficulty'] = 2;
					break;

				case 'trivial':
				default:
					$recipe['difficulty'] = 1;
					break;
			}
		}
		else
		{
			$recipe['difficulty'] = $recipe_data['Difficulty'];
		}

		$recipe['item_color'] = isset($recipe_data['Color']) ? $recipe_data['Color'] : '';
		$recipe['item_id'] = isset($recipe_data['Item']) ? $recipe_data['Item'] : '';
		$recipe['recipe_id'] = isset($recipe_data['RecipeID']) ? $recipe_data['RecipeID'] : '';

		$recipe['reagent_data'] = $recipe_data['Reagents'];
		$recipe['reagent_list'] = array();

		foreach( $recipe_data['Reagents'] as $d => $reagent )
		{
			//aprint($reagent);
			$id = explode(':', $reagent['Item']);
			if(isset($reagent['Quantity']))
			{
				$count = $reagent['Quantity'];
			}
			elseif (isset($reagent['Count']))
			{
				$count = $reagent['Count'];
			}
			else
			{
				$count = '1';
			}
			$recipe['reagent_list'][] = $id[0] . ':' . $count;
		}
		$recipe['reagent_list'] = implode('|',$recipe['reagent_list']);

		$recipe['recipe_texture'] = $this->fix_icon($recipe_data['Icon']);

		if( !empty($recipe_data['Tooltip']) )
		{
			$recipe['recipe_tooltip'] = $this->tooltip( $recipe_data['Tooltip'] );
		}
		else
		{
			$recipe['recipe_tooltip'] = $recipe_name;
		}

		return $recipe;
	}


	/**
	 * Handles formating and insertion of buff data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_buffs( $data , $memberId )
	{
		global $roster;

		// Delete the stale data
		$querystr = "DELETE FROM `" . $roster->db->table('buffs') . "` WHERE `member_id` = '$memberId';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Buffs could not be deleted',$roster->db->error());
			return;
		}

		if( isset($data['Attributes']['Buffs']) )
		{
			$buffs = $data['Attributes']['Buffs'];
		}

		if( !empty($buffs) && is_array($buffs) )
		{
			// Then process buffs
			$buffsnum = 0;
			foreach( $buffs as $buff )
			{
				if( is_null($buff) || !is_array($buff) || empty($buff) )
				{
					continue;
				}
				$this->reset_values();

				$this->add_value('member_id', $memberId);
				$this->add_ifvalue($buff, 'Name', 'name');

				if( isset($buff['Icon']) )
				{
					$this->add_value('icon', $this->fix_icon($buff['Icon']));
				}

				$this->add_ifvalue($buff, 'Rank', 'rank');
				$this->add_ifvalue($buff, 'Count', 'count');

				if( !empty($buff['Tooltip']) )
				{
					$this->add_value('tooltip', $this->tooltip($buff['Tooltip']));
				}
				else
				{
					$this->add_ifvalue($buff, 'Name', 'tooltip');
				}

				$querystr = "INSERT INTO `" . $roster->db->table('buffs') . "` SET " . $this->assignstr . ";";
				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError('Buff [' . $buff['Name'] . '] could not be inserted',$roster->db->error());
				}

				$buffsnum++;
			}
			$this->setMessage('<li>Updating Buffs: ' . $buffsnum . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Buffs</li>');
		}
	}


	/**
	 * Handles formating and insertion of quest data
	 *
	 * @param array $data
	 * @param int $member_id
	 */
	function do_quests( $data , $member_id )
	{
		global $roster;

		if( isset($data['Quests']) && !empty($data['Quests']) && is_array($data['Quests']) )
		{
			$quests = $data['Quests'];
		}
		else
		{
			$this->setMessage('<li>No Quest Data</li>');
			return;
		}

		// Delete the stale data
		$querystr = "DELETE FROM `" . $roster->db->table('quests') . "` WHERE `member_id` = '$member_id';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Quests could not be deleted',$roster->db->error());
			return;
		}

		// Then process quests
		$questnum = 0;
		foreach( array_keys($quests) as $zone )
		{
			$zoneInfo = $quests[$zone];
			foreach( array_keys($zoneInfo) as $slot)
			{
				$slotInfo = $zoneInfo[$slot];
				if( is_null($slotInfo) || !is_array($slotInfo) || empty($slotInfo) )
				{
					continue;
				}
				$this->insert_quest($slotInfo, $member_id, $zone, $slot, $data);
				$questnum++;
			}
		}
		$this->setMessage('<li>Updating Quests: ' . $questnum . '</li>');
	}


	/**
	 * Handles formating and insertion of recipe data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_recipes( $data , $memberId )
	{
		global $roster;

		if(isset($data['Professions']))
		{
			$prof = $data['Professions'];
		}

		if( !empty($prof) && is_array($prof) )
		{
			$messages = '<li>Updating Professions';

			// Delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('recipes') . "` WHERE `member_id` = '$memberId';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Professions could not be deleted',$roster->error());
				return;
			}

			// Then process Professions
			foreach( array_keys($prof) as $skill_name )
			{
				$messages .= " : $skill_name";

				$skill = $prof[$skill_name];
				foreach( array_keys($skill) as $recipe_type )
				{
					$item = $skill[$recipe_type];
					foreach(array_keys($item) as $recipe_name)
					{
						$recipeDetails = $item[$recipe_name];
						if (!isset($item[$recipe_name]["RecipeID"]))
						{
							$subitem = $item[$recipe_name];
							foreach(array_keys($subitem) as $recipe_name2)
							{
								$recipeDetail = $subitem[$recipe_name2];
								if( is_null($recipeDetails) || !is_array($recipeDetails) || empty($recipeDetails) )
								{
									continue;
								}
								$recipe = $this->make_recipe($recipeDetail, $memberId, $skill_name, $recipe_type,$recipe_name, $recipe_name2);
								$this->insert_recipe($recipe,$data['Locale']);
								$this->insert_reagent($memberId,$recipe['reagent_data'],$data['Locale']);
							}
						}
						else
						{
							if( is_null($recipeDetails) || !is_array($recipeDetails) || empty($recipeDetails) )
							{
								continue;
							}
							$recipe = $this->make_recipe($recipeDetails, $memberId, $skill_name, $recipe_type, '', $recipe_name);
							$this->insert_recipe($recipe,$data['Locale']);
							$this->insert_reagent($memberId,$recipe['reagent_data'],$data['Locale']);
						}
					}
				}
				/*
				foreach( array_keys($skill) as $recipe_type )
				{
					$item = $skill[$recipe_type];
					foreach(array_keys($item) as $recipe_name)
					{
						$recipeDetails = $item[$recipe_name];
						if( is_null($recipeDetails) || !is_array($recipeDetails) || empty($recipeDetails) )
						{
							continue;
						}
						$recipe = $this->make_recipe($recipeDetails, $memberId, $skill_name, $recipe_type, $recipe_name);
						$this->insert_recipe($recipe,$data['Locale']);
						$this->insert_reagent($memberId,$recipe['reagent_data'],$data['Locale']);
					}
				}
				*/
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Recipe Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of equipment data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_equip( $data , $memberId )
	{
		global $roster;

		// Update Equipment Inventory
		$equip = $data['Equipment'];
		if( !empty($equip) && is_array($equip) )
		{
			$messages = '<li>Updating Equipment ';

			$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND `item_parent` = 'equip';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Equipment could not be deleted',$roster->db->error());
				return;
			}
			foreach( array_keys($equip) as $slot_name )
			{
				$messages .= '.';

				$slot = $equip[$slot_name];
				if( is_null($slot) || !is_array($slot) || empty($slot) )
				{
					continue;
				}
				$item = $this->make_item($slot, $memberId, 'equip', $slot_name);
				$this->insert_item($item,$data['Locale']);
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Equipment Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of inventory data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_inventory( $data , $memberId )
	{
		global $roster;

		// Update Bag Inventory
		$inv = $data['Inventory'];
		if( !empty($inv) && is_array($inv) )
		{
			$messages = '<li>Updating Inventory';

			$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND UPPER(`item_parent`) LIKE 'BAG%' AND `item_parent` != 'bags';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Inventory could not be deleted',$roster->db->error());
				return;
			}

			$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND `item_parent` = 'bags' AND UPPER(`item_slot`) LIKE 'BAG%';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Inventory could not be deleted',$roster->db->error());
				return;
			}

			foreach( array_keys($inv) as $bag_name )
			{
				$messages .= " : $bag_name";

				$bag = $inv[$bag_name];
				if( is_null($bag) || !is_array($bag) || empty($bag) )
				{
					continue;
				}
				$item = $this->make_item($bag, $memberId, 'bags', $bag_name);

				// quantity for a bag means number of slots it has
				$item['item_quantity'] = $bag['Slots'];
				$this->insert_item($item,$data['Locale']);

				if (isset($bag['Contents']) && is_array($bag['Contents']))
				{
					foreach( array_keys($bag['Contents']) as $slot_name )
					{
						$slot = $bag['Contents'][$slot_name];
						if( is_null($slot) || !is_array($slot) || empty($slot) )
						{
							continue;
						}
						$item = $this->make_item($slot, $memberId, $bag_name, $slot_name);
						$this->insert_item($item,$data['Locale']);
					}
				}
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Inventory Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of bank data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_bank( $data , $memberId )
	{
		global $roster;

		// Update Bank Inventory
		if(isset($data['Bank']))
		{
			$inv = $data['Bank'];
		}

		if( !empty($inv) && is_array($inv) )
		{
			$messages = '<li>Updating Bank';

			// Clearing out old items
			$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND UPPER(`item_parent`) LIKE 'BANK%';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Bank could not be deleted',$roster->db->error());
				return;
			}

			$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND `item_parent` = 'bags' AND UPPER(`item_slot`) LIKE 'BANK%';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Bank could not be deleted',$roster->db->error());
				return;
			}

			foreach( array_keys($inv) as $bag_name )
			{
				$messages .= " : $bag_name";

				$bag = $inv[$bag_name];
				if( is_null($bag) || !is_array($bag) || empty($bag) )
				{
					continue;
				}

				$dbname = 'Bank ' . $bag_name;
				$item = $this->make_item($bag, $memberId, 'bags', $dbname);

				// Fix bank bag icon
				if( $bag_name == 'Bag0' )
				{
					$item['item_texture'] = 'inv_misc_bag_15';
				}

				// quantity for a bag means number of slots it has
				$item['item_quantity'] = $bag['Slots'];
				$this->insert_item($item,$data['Locale']);

				if (isset($bag['Contents']) && is_array($bag['Contents']))
				{
					foreach( array_keys($bag['Contents']) as $slot_name )
					{
						$slot = $bag['Contents'][$slot_name];
						if( is_null($slot) || !is_array($slot) || empty($slot) )
						{
							continue;
						}
						$item = $this->make_item($slot, $memberId, $dbname, $slot_name);
						$this->insert_item($item,$data['Locale']);
					}
				}
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Bank Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of mailbox data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_mailbox( $data , $memberId )
	{
		global $roster;

		if(isset($data['MailBox']))
		{
			$mailbox = $data['MailBox'];
		}

		// If maildate is newer than the db value, wipe all mail from the db...someday
		//if(  )
		//{
		$querystr = "DELETE FROM `" . $roster->db->table('mailbox') . "` WHERE `member_id` = '$memberId';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Mail could not be deleted',$roster->db->error());
			return;
		}
		//}

		// Delete any attachments too
		$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` = '$memberId' AND UPPER(`item_parent`) LIKE 'MAIL%';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Mail could not be deleted',$roster->db->error());
			return;
		}

		if( !empty($mailbox) && is_array($mailbox) )
		{
			foreach( $mailbox as $mail_num => $mail )
			{
				if( is_null($mail) || !is_array($mail) || empty($mail) )
				{
					continue;
				}
				$dbmail = $this->make_mail($mail, $memberId, $mail_num);
				$this->insert_mail($dbmail);

				if( isset($mail['Contents']) && is_array($mail['Contents']) )
				{
					foreach( $mail['Contents'] as $attach_num => $attach )
					{
						if( is_null($attach) || !is_array($attach) || empty($attach) )
						{
							continue;
						}
						$item = $this->make_item($attach, $memberId, 'Mail ' . $mail_num, $attach_num);
						$this->insert_item($item,$data['Locale']);
					}
				}
			}
			$this->setMessage('<li>Updating Mailbox: ' . count($mailbox) . '</li>');
		}
		else
		{
			$this->setMessage('<li>No New Mail</li>');
		}
	}


	/**
	 * Handles formating and insertion of rep data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_reputation( $data , $memberId )
	{
		global $roster;

		if( isset($data['Reputation']) )
		{
			$repData = $data['Reputation'];
		}

		if( !empty($repData) && is_array($repData) )
		{
			$messages = '<li>Updating Reputation ';

			//first delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('reputation') . "` WHERE `member_id` = '$memberId';";

			if( !$roster->db->query($querystr) )
			{
				$this->setError('Reputation could not be deleted',$roster->db->error());
				return;
			}

			$count = $repData['Count'];
			$key = '';

			foreach ($repData as $cat => $factions)
			{
				if ($cat != 'Count')
				{
					foreach ($factions as $faction => $data)
					{
						if ($faction != 'AtWar' & $faction != 'Standing' & $faction != 'Value' & $faction != 'Description' )
						{
							if (is_array($data))
							{
								$sub_x = $faction;
								foreach ($data as $name => $v)
								{
									if ($name != 'AtWar' & $name != 'Standing' & $name != 'Value' & $name != 'Description' )
									{
										$this->reset_values();
										if( !empty($memberId) )
										{
											$this->add_value('member_id', $memberId );
										}
										if( !empty($cat) )
										{
											$this->add_value('faction', $cat );
										}
										if( !empty($faction) )
										{
											$this->add_value('parent', $faction );
										}
										if( !empty($name) )
										{
											$this->add_value('name', $name );
										}

										if( !empty($v['Value']) )
										{
											list($level, $max) = explode(':',$v['Value']);
											$this->add_value('curr_rep', $level );
											$this->add_value('max_rep', $max );
										}

										$this->add_ifvalue( $v, 'AtWar' );
										$this->add_ifvalue( $v, 'Standing' );
										$this->add_ifvalue( $v, 'Description' );

										$messages .= '.';

										$querystr = "INSERT INTO `" . $roster->db->table('reputation') . "` SET " . $this->assignstr . ";";

										$result = $roster->db->query($querystr);
										if( !$result )
										{
											$this->setError('Reputation for ' . $name . ' could not be inserted',$roster->db->error());
										}
										if (isset($v['Value']))
										{
											$key = $faction;
										}
									}
								}
							}

							$this->reset_values();
							if( !empty($memberId) )
							{
								$this->add_value('member_id', $memberId );
							}
							if( !empty($cat) )
							{
								$this->add_value('faction', $cat );
							}
							if( !empty($key) )
							{
								$this->add_value('parent', $key );
							}
							if( !empty($faction) )
							{
								$this->add_value('name', $faction );
							}

							if( !empty($data['Value']) )
							{
								list($level, $max) = explode(':',$data['Value']);
								$this->add_value('curr_rep', $level );
								$this->add_value('max_rep', $max );
							}

							$this->add_ifvalue( $data, 'AtWar' );
							$this->add_ifvalue( $data, 'Standing' );
							$this->add_ifvalue( $data, 'Description' );

							$messages .= '.';

							$querystr = "INSERT INTO `" . $roster->db->table('reputation') . "` SET " . $this->assignstr . ";";
							$result = $roster->db->query($querystr);
							if( !$result )
							{
								$this->setError('Reputation for ' . $faction . ' could not be inserted',$roster->db->error());
							}
							$key = '';
						}
					}
				}
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Reputation Data</li>');
		}
	}

	/**
	 * Handles formating and insertion of currency data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_currency( $data , $memberId )
	{
		global $roster;

		if( !empty($data['Currency']) && is_array($data['Currency']) )
		{
			$currencyData = $data['Currency'];

			$messages = '<li>Updating Currency ';

			// delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('currency') . "` WHERE `member_id` = '$memberId';";

			if( !$roster->db->query($querystr) )
			{
				$this->setError('Currency could not be deleted', $roster->db->error());
				return;
			}

			$order = 0;
			foreach( array_keys($currencyData) as $category ) // eg. 'Miscellaneous, Player vs. Player, Dungeon and Raid
			{
				$categoryData = $currencyData[$category];

				foreach( array_keys($categoryData) as $currency ) // eg. Arena Points, Badge of Justice, Emblem of Valor
				{
					$itemData = $categoryData[$currency];
					$this->reset_values();

					$this->add_value('member_id', $memberId);
					$this->add_value('order', $order);
					$this->add_value('category', $category);
					$this->add_ifvalue($itemData, 'Name', 'name');
					$this->add_ifvalue($itemData, 'Count', 'count');
					$this->add_ifvalue($itemData, 'Type', 'type');

					if( !empty($itemData['Tooltip']) )
					{
						$this->add_value('tooltip', $this->tooltip($itemData['Tooltip']));
					}
					if( !empty($itemData['Icon']) )
					{
						$this->add_value('icon', $this->fix_icon($itemData['Icon']));
					}

					$messages .= '.';

					$querystr = "INSERT INTO `" . $roster->db->table('currency') . "` SET " . $this->assignstr . ';';

					$result = $roster->db->query($querystr);
					if( !$result )
					{
						$this->setError('Currency for ' . $currency . ' could not be inserted', $roster->db->error());
					}
					$order++;
				}
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Currency Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of skills data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_skills( $data , $memberId )
	{
		global $roster;

		if( isset($data['Skills']) )
		{
			$skillData = $data['Skills'];
		}

		if( !empty($skillData) && is_array($skillData) )
		{
			$messages = '<li>Updating Skills ';

			//first delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('skills') . "` WHERE `member_id` = '$memberId';";

			if( !$roster->db->query($querystr) )
			{
				$this->setError('Skills could not be deleted',$roster->db->error());
				return;
			}

			foreach( array_keys($skillData) as $skill_type )
			{
				$sub_skill = $skillData[$skill_type];
				$order = $sub_skill['Order'];
				foreach( array_keys($sub_skill) as $skill_name )
				{
					if( $skill_name != 'Order' )
					{
						$this->reset_values();
						$this->add_value('member_id', $memberId);
						$this->add_value('skill_type', $skill_type);
						$this->add_value('skill_name', $skill_name);
						$this->add_value('skill_order', $order);
						$this->add_ifvalue($sub_skill, $skill_name, 'skill_level');

						$messages .= '.';

						$querystr = "INSERT INTO `" . $roster->db->table('skills') . "` SET " . $this->assignstr . ";";

						$result = $roster->db->query($querystr);
						if( !$result )
						{
							$this->setError('Skill [' . $skill_name . '] could not be inserted',$roster->db->error());
						}
					}
				}
			}
			$this->setMessage($messages . '</li>');
		}
		else
		{
			$this->setMessage('<li>No Skill Data</li>');
		}
	}


	/**
	 * Handles formating and insertion of spellbook data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_spellbook( $data , $memberId )
	{
		global $roster;

		$spellBuildData = array();

		if( isset($data['SpellBook']) && !empty($data['SpellBook']) && is_array($data['SpellBook']) )
		{
			$spellBuildData[0] = $data['SpellBook'];
		}
		else
		{
			$this->setMessage('<li>No Spellbook Data</li>');
			return;
		}

		$messages = '<li>Updating Spellbook';

		// then process spellbook
		foreach( $spellBuildData as $build => $spellbook )
		{
			// Delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('spellbook') . "` WHERE `member_id` = '$memberId' AND `spell_build` = " . $build . ";";
			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Spells could not be deleted',$roster->db->error());
				return;
			}

			// then process Spellbook Tree
			$querystr = "DELETE FROM `" . $roster->db->table('spellbooktree') . "` WHERE `member_id` = '$memberId' AND `spell_build` = " . $build . ";";
			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Spell Trees could not be deleted',$roster->db->error());
				return;
			}

			foreach( array_keys($spellbook) as $spell_type )
			{
				$messages .= " : $spell_type";

				$data_spell_type = $spellbook[$spell_type];
				foreach( array_keys($data_spell_type) as $spell )
				{
					$data_spell = $data_spell_type[$spell];

					if( is_array($data_spell) )
					{
						foreach( array_keys($data_spell) as $spell_name )
						{
							$data_spell_name = $data_spell[$spell_name];

							$this->reset_values();
							$this->add_value('member_id', $memberId);
							$this->add_value('spell_build', $build);
							$this->add_value('spell_type', $spell_type);
							$this->add_value('spell_name', $spell_name);

							if( !empty($data_spell_name['Icon']) )
							{
								$this->add_value('spell_texture', $this->fix_icon($data_spell_name['Icon']));
							}
							if( isset($data_spell_name['Rank']) )
							{
								$this->add_value('spell_rank', $data_spell_name['Rank']);
							}

							if( !empty($data_spell_name['Tooltip']) )
							{
								$this->add_value('spell_tooltip', $this->tooltip($data_spell_name['Tooltip']));
							}
							else
							{
								$this->add_value('spell_tooltip', $spell_name . ( isset($data_spell_name['Rank']) ? "\n" . $data_spell_name['Rank'] : '' ));
							}

							$querystr = "INSERT INTO `" . $roster->db->table('spellbook') . "` SET " . $this->assignstr;
							$result = $roster->db->query($querystr);
							if( !$result )
							{
								$this->setError($roster->locale->act['talent_build_' . $build] . ' Spell [' . $spell_name . '] could not be inserted',$roster->db->error());
							}
						}
					}
				}
				$this->reset_values();
				$this->add_value('member_id', $memberId);
				$this->add_value('spell_build', $data_spell_type['OffSpec']);
				$this->add_value('spell_type', $spell_type);
				$this->add_value('spell_texture', $this->fix_icon($data_spell_type['Icon']));

				$querystr = "INSERT INTO `" . $roster->db->table('spellbooktree') . "` SET " . $this->assignstr;
				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError($roster->locale->act['talent_build_' . $build] . ' Spell Tree [' . $spell_type . '] could not be inserted',$roster->db->error());
				}
			}
		}
		$this->setMessage($messages . '</li>');
	}


	/**
	 * Handles formating and insertion of pet spellbook data
	 *
	 * @param array $data
	 * @param int $memberId
	 * @param int $petID
	 */
	function do_pet_spellbook( $data , $memberId , $petID )
	{
		global $roster;

		if( isset($data['SpellBook']['Spells']) &&  !empty($data['SpellBook']['Spells']) && is_array($data['SpellBook']['Spells']) )
		{
			$spellbook = $data['SpellBook']['Spells'];
		}
		else
		{
			$this->setMessage('<li>No Spellbook Data</li>');
			return;
		}

		$messages = '<li>Updating Spellbook';

		// first delete the stale data
		$querystr = "DELETE FROM `" . $roster->db->table('pet_spellbook') . "` WHERE `pet_id` = '$petID';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Spells could not be deleted',$roster->db->error());
			return;
		}

		// then process spellbook

		foreach( array_keys($spellbook) as $spell )
		{
			$messages .= '.';
			$data_spell = $spellbook[$spell];

			if( is_array($data_spell) )
			{
				$this->reset_values();
				$this->add_value('member_id', $memberId);
				$this->add_value('pet_id', $petID);
				$this->add_value('spell_name', $spell);
				$this->add_value('spell_texture', $this->fix_icon($data_spell['Icon']));
				$this->add_ifvalue($data_spell, 'Rank', 'spell_rank');

				if( !empty($data_spell['Tooltip']) )
				{
					$this->add_value('spell_tooltip', $this->tooltip($data_spell['Tooltip']));
				}
				elseif( !empty($spell) || !empty($data_spell['Rank']) )
				{
					$this->add_value('spell_tooltip', $spell . "\n" . $data_spell['Rank']);
				}

				$querystr = "INSERT INTO `" . $roster->db->table('pet_spellbook') . "` SET " . $this->assignstr;
				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError('Pet Spell [' . $spell . '] could not be inserted',$roster->db->error());
				}
			}
		}

		$this->setMessage($messages . '</li>');
	}


	/**
	 * Handles formating and insertion of companions
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_companions( $data , $memberId )
	{
		global $roster;

		if( !empty( $data['Companions'] ) && is_array($data['Companions']) )
		{
			$companiondata = $data['Companions'];
		}
		else
		{
			$this->setMessage('<li>No Companions</li>');
			return;
		}

		$messages = '<li>Updating Companions<ul>';

		// delete the stale data
		$querystr = "DELETE FROM `" . $roster->db->table('companions') . "` WHERE `member_id` = '$memberId';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Companions could not be deleted',$roster->db->error());
			return;
		}

		foreach( $companiondata as $type => $companion )
		{
			$messages .= '<li>' . $type;

			foreach( $companion as $id => $data )
			{
				$messages .= '.';

				$this->reset_values();

				$this->add_value('member_id', $memberId);
				$this->add_value('name', $data['Name']);
				$this->add_value('type', $type);
				$this->add_value('slot', $id);
				$this->add_value('spellid', $data['SpellId']);
				$this->add_value('tooltip', $data['Tooltip']);
				$this->add_value('creatureid', $data['CreatureID']);

				if( !empty($data['Icon']) )
				{
					$this->add_value('icon', $this->fix_icon($data['Icon']) );
				}

				$querystr = "INSERT INTO `" . $roster->db->table('companions') . "` SET " . $this->assignstr . ";";
				$result = $roster->db->query($querystr);

				if( !$result )
				{
					$this->setError('Companion [' . $data['Name'] . '] could not be inserted',$roster->db->error());
				}
			}
			$messages .= '</li>';
		}

		$this->setMessage($messages . '</ul></li>');
	}


	/**
	 * Handles formating and insertion of glyphs
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_glyphs( $data , $memberId )
	{
		global $roster;

		$glyphBuildData = array();

		$messages = '<li>Updating Glyphs: ';
		foreach( $data['Talents'] as $build => $talentData )
		{
			if( isset($talentData['Glyphs']) && !empty($talentData['Glyphs']) && is_array($talentData['Glyphs']) )
			{
				$querystr = "DELETE FROM `" . $roster->db->table('glyphs') . "` WHERE `member_id` = '$memberId' AND `glyph_build` = " . $build . ";";

				if( !$roster->db->query($querystr) )
				{
					$this->setError($roster->locale->act['talent_build_' . $build] . ' Glyphs could not be deleted',$roster->db->error());
					return;
				}
				$messages .= ':'.$talentData['Name'].' - ';
			}
			else
			{
				$messages .= ':'.$talentData['Name'].' - No Glyph Data ';
			}
			
			foreach ($talentData['Glyphs'] as $idx => $glyph )
			{
				$this->reset_values();
				$this->add_value('member_id', $memberId);
				$this->add_ifvalue($glyph, 'Name', 'glyph_name');
				$this->add_ifvalue($glyph, 'Type', 'glyph_type');
				$this->add_value('glyph_build', $build);

				if( isset($glyph['Icon']) )
				{
					$this->add_value('glyph_icon', $this->fix_icon($glyph['Icon']));
				}
				if( isset($glyph['Tooltip']) )
				{
					$this->add_value('glyph_tooltip', $this->tooltip($glyph['Tooltip']));
				}

				//$this->add_value('glyph_order', $glyphOrder);

				$messages .= '.';

				$querystr = "INSERT INTO `" . $roster->db->table('glyphs') . "` SET " . $this->assignstr . ";";

				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError($roster->locale->act['talent_build_' . $build] . ' Glyph [' . $glyph['glyph_name'] . '] could not be inserted', $roster->db->error());
				}
			}
			$messages .= '';
		}
		$this->setMessage($messages . '</li>');
	}


	/**
	 * Handles formating and insertion of talent data
	 * Also handles dual build talent data
	 *
	 * @param array $data
	 * @param int $memberId
	 */
	function do_talents( $data , $memberId )
	{
		global $roster;

		$talentBuildData = array();

		if( isset($data['Talents']) && !empty($data['Talents']) && is_array($data['Talents']) )
		{
			$talentBuildData = $data['Talents'];
		}
		else
		{
			$this->setMessage('<li>No Talent Data</li>');
			return;
		}
		//echo'<pre>';print_r($talentBuildData);echo'</pre>';
		// Check for dual talent build
		// removed for MOp auti scanning now used...

		$messages = '<li>Updating Talents';

		// first delete the stale data
			$querystr = "DELETE FROM `" . $roster->db->table('talents') . "` WHERE `member_id` = '$memberId';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talents could not be deleted',$roster->db->error());
				return;
			}

			$querystr = "DELETE FROM `" . $roster->db->table('talenttree') . "` WHERE `member_id` = '$memberId';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talent Trees could not be deleted',$roster->db->error());
				return;
			}
			$querystr = "DELETE FROM `" . $roster->db->table('talent_builds') . "` WHERE `member_id` = '$memberId';";
			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talent build could not be deleted',$roster->db->error());
				return;
			}
		// Update Talents
		foreach( $talentBuildData as $build => $talentData )
		{


			
			//"Role" "Name" "Active" "Talents" "Background" "Icon" "Desc" 
			$messages .= " : ".$build."-".$talentData["Name"]." ";
			$tree_pointsspent = 0;
			$burl = array();
			$burl2 = '';

				$tid = $data['ClassId'].'0';

				$tx = 0;
			foreach ($talentData['Talents'] as $t_name => $info )
			{
				//$rank = (int)$info['Selected'];//'0';
				//echo $rank;
				$location = explode(':', $info['Location']);
				
				if (!$info['Selected'])
				{
					$rank = '0';
				}
				else
				{
					$rank = '1';
				}

				$this->reset_values();
				$this->add_value('member_id', $memberId);
				$this->add_value('name', $info["Name"]);
				$this->add_value('tree', $talentData["Name"]);
				$this->add_value('build', $build);

				if( !empty($info['Tooltip']) )
				{
					$this->add_value('tooltip', $this->tooltip($info['Tooltip']));
				}
				else
				{
					$this->add_value('tooltip', $info["Name"]);
				}

				if( !empty($info['Texture']) )
				{
					$this->add_value('texture', $this->fix_icon($info['Texture']));
				}

				if ($info["Selected"])
				{
					$tree_pointsspent++;
					$burl[] = $location[0].'-'.$location[1];
					$burl2 .= $rank;
					
				}
				$this->add_value('row', $location[0]);
				$this->add_value('column', $location[1]);
				$this->add_value('rank', $rank);
				$this->add_value('maxrank', '1');

				unset($location);

				$querystr = "INSERT INTO `" . $roster->db->table('talents') . "` SET " . $this->assignstr;
				$result = $roster->db->query($querystr);
				if( !$result )
				{
					$this->setError($roster->locale->act['talent_build_' . $build] . ' Talent [' . $talent_skill . '] could not be inserted',$roster->db->error());
				}
			}

			$values = array(
				'tree'       => $talentData["Name"],
				'order'      => '1',
				'class_id'   => $data['ClassId'],
				'background' => strtolower($this->fix_icon($talentData["Background"])),
				'icon'       => $this->fix_icon($talentData["Icon"]),
				'roles'		 => $talentData["Role"],
				'desc'		 => $talentData['Desc'],
				'tree_num'   => '1'
			);
			
			$querystr = "DELETE FROM `" . $roster->db->table('talenttree_data') . "` WHERE `class_id` = '" . $data['ClassId'] . "' and `tree` = '".$talentData["Name"]."';";
			if (!$roster->db->query($querystr))
			{
				$roster->set_message('Talent Tree Data Table could not be emptied.', '', 'error');
				$roster->set_message('<pre>' . $roster->db->error() . '</pre>', 'MySQL Said', 'error');
				return;
			}
	
			$querystr = "INSERT INTO `" . $roster->db->table('talenttree_data') . "` "
				. $roster->db->build_query('INSERT', $values) . "
				;";
			$result = $roster->db->query($querystr);
			
			$this->reset_values();
			$this->add_value('member_id', $memberId);
			$this->add_value('tree', $talentData["Name"]);
			$this->add_value('background', $this->fix_icon($talentData["Background"]));
			$this->add_value('pointsspent', $tree_pointsspent);
			$this->add_value('order', ($talentData["Active"] ? 1 : 2));
			$this->add_value('build', $build);

			$querystr = "INSERT INTO `" . $roster->db->table('talenttree') . "` SET " . $this->assignstr;
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talent Tree [' . $talentData["Name"] . '] could not be inserted',$roster->db->error());
			}
		

			$build_url = $this->_talent_layer_url( $memberId, $build);
			$this->reset_values();
			$messages .= " - ".$build_url." ";
			$this->reset_values();
			$this->add_value('build', $build);
			$this->add_value('member_id', $memberId);
			$this->add_value('tree', $build_url);
			$this->add_value('spec', $talentData["Name"]);
			$querystr = "INSERT INTO `" . $roster->db->table('talent_builds') . "` SET " . $this->assignstr;

			$result = $roster->db->query($querystr);

			if( !$result )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talent Tree [' . $talent_tree . '] could not be inserted',$roster->db->error());
			}
/*
			$querystr = "DELETE FROM `" . $roster->db->table('talents') . "` WHERE `member_id` = '$memberId' AND `build` = " . $build . ";";

			if( !$roster->db->query($querystr) )
			{
				$this->setError($roster->locale->act['talent_build_' . $build] . ' Talents could not be deleted',$roster->db->error());
				return;
			}
*/ 
		}
		$this->setMessage($messages . '</li>');
	}

	function _talent_layer_url( $memberId , $build )
	{
		global $roster;

			$sqlquery = "SELECT * FROM `" . $roster->db->table('talents') . "` WHERE `member_id` = '" . $memberId . "' AND `build` = '" . $build . "' ORDER BY `row` ASC , `column` ASC";
			$result = $roster->db->query($sqlquery);

			$returndataa = '';

			while( $talentdata = $roster->db->fetch($result) )
			{
				$returndataa .= $talentdata['rank'];
			}
		return $returndataa;
		//return true;
	}

	/**
	 * Handles formating and insertion of pet talent data
	 *
	 * @param array $data
	 * @param int $memberId
	 * @param int $petID
	 */
	function do_pet_talents( $data , $memberId , $petID )
	{
		global $roster;

		if( isset($data['Talents']) && !empty($data['Talents']) && is_array($data['Talents']))
		{
			$talentData = $data['Talents'];
		}
		else
		{
			$this->setMessage('<li>No Talents Data</li>');
			return;
		}

		$messages = '<li>Updating Talents';

		// first delete the stale data
		$querystr = "DELETE FROM `" . $roster->db->table('pet_talents') . "` WHERE `member_id` = '$memberId' AND `pet_id` = '$petID';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Talents could not be deleted',$roster->db->error());
			return;
		}

		// then process Talents
		$querystr = "DELETE FROM `" . $roster->db->table('pet_talenttree') . "` WHERE `member_id` = '$memberId' AND `pet_id` = '$petID';";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Talent Trees could not be deleted',$roster->db->error());
			return;
		}

		// Update Talents
		foreach( array_keys($talentData) as $talent_tree )
		{
			$messages .= " : $talent_tree";

			$data_talent_tree = $talentData[$talent_tree];
			foreach( array_keys($data_talent_tree) as $talent_skill )
			{
				$data_talent_skill = $data_talent_tree[$talent_skill];
				if( $talent_skill == 'Order' )
				{
					$tree_order = $data_talent_skill;
				}
				elseif( $talent_skill == 'PointsSpent' )
				{
					$tree_pointsspent = $data_talent_skill;
				}
				elseif( $talent_skill == 'Background' )
				{
					$tree_background = $data_talent_skill;
				}
				else
				{
					$this->reset_values();
					$this->add_value('member_id', $memberId);
					$this->add_value('pet_id', $petID);
					$this->add_value('name', $talent_skill);
					$this->add_value('tree', $talent_tree);

					if( !empty($data_talent_skill['Tooltip']) )
					{
						$this->add_value('tooltip', $this->tooltip($data_talent_skill['Tooltip']));
					}
					else
					{
						$this->add_value('tooltip', $talent_skill);
					}

					if( !empty($data_talent_skill['Icon']) )
					{
						$this->add_value('icon', $this->fix_icon($data_talent_skill['Icon']));
					}

					$location = explode(':', $data_talent_skill['Location']);
					$rank = explode(':', $data_talent_skill['Rank']);

					$this->add_value('row', $location[0]);
					$this->add_value('column', $location[1]);
					$this->add_value('rank', $rank[0]);
					$this->add_value('maxrank', $rank[1]);

					unset($location,$rank);

					$querystr = "INSERT INTO `" . $roster->db->table('pet_talents') . "` SET " . $this->assignstr;
					$result = $roster->db->query($querystr);
					if( !$result )
					{
						$this->setError('Pet Talent [' . $talent_skill . '] could not be inserted',$roster->db->error());
					}
				}
			}
			$this->reset_values();

			$this->add_value('member_id', $memberId);
			$this->add_value('pet_id', $petID);
			$this->add_value('tree', $talent_tree);
			$this->add_value('background', $this->fix_icon($tree_background));
			$this->add_value('pointsspent', $tree_pointsspent);
			$this->add_value('order', $tree_order);

			$querystr = "INSERT INTO `" . $roster->db->table('pet_talenttree') . "` SET " . $this->assignstr;
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Pet Talent Tree [' . $talent_tree . '] could not be inserted',$roster->db->error());
			}
		}
		$this->setMessage($messages . '</li>');
	}



	/**
	 * Delete Members in database not matching the upload rules
	 */
	function enforceRules( $timestamp )
	{
		global $roster;

		$messages = '';
		// Select and delete all non-matching guilds
		$query = "SELECT *"
			. " FROM `" . $roster->db->table('guild') . "` guild"
			. " WHERE `guild_name` NOT LIKE 'guildless-_';";
		$result = $roster->db->query($query);
		while( $row = $roster->db->fetch($result) )
		{
			$query = "SELECT `type`, COUNT(`rule_id`)"
				   . " FROM `" . $roster->db->table('upload') . "`"
				   . " WHERE (`type` = 0 OR `type` = 1)"
				   . " AND '" . $roster->db->escape($row['guild_name']) . "' LIKE `name` "
				   . " AND '" . $roster->db->escape($row['server']) . "' LIKE `server` "
				   . " AND '" . $roster->db->escape($row['region']) . "' LIKE `region` "
				   . " GROUP BY `type` "
				   . " ORDER BY `type` DESC;";
			if( $roster->db->query_first($query) !== '0' )
			{
				$messages .= '<ul><li>Deleting guild "' . $row['guild_name'] . '" and setting its members guildless.</li>';
				// Does not match rules
				$this->deleteGuild($row['guild_id'], $timestamp);
				$messages .= '</ul>';
			}
		}

		// Select and delete all non-matching guildless members
		$messages .= '<ul>';
		$inClause=array();

		$query = "SELECT *"
			. " FROM `" . $roster->db->table('members') . "` members"
			. " INNER JOIN `" . $roster->db->table('guild') . "` guild"
				. " USING (`guild_id`)"
			. " WHERE `guild_name` LIKE 'guildless-_';";
		$result = $roster->db->query($query);

		while( $row = $roster->db->fetch($result) )
		{
			$query = "SELECT `type`, COUNT(`rule_id`)"
				   . " FROM `" . $roster->db->table('upload') . "`"
				   . " WHERE (`type` = 2 OR `type` = 3)"
				   . " AND '" . $roster->db->escape($row['name']) . "' LIKE `name` "
				   . " AND '" . $roster->db->escape($row['server']) . "' LIKE `server` "
				   . " AND '" . $roster->db->escape($row['region']) . "' LIKE `region` "
				   . " GROUP BY `type` "
				   . " ORDER BY `type` DESC;";
			if( $roster->db->query_first($query) !== '2' )
			{
				$messages .= '<li>Deleting member "' . $row['name'] . '".</li>';
				// Does not match rules
				$inClause[] = $row['member_id'];
			}
		}

		if( count($inClause) == 0 )
		{
			$messages .= '<li>No members deleted.</li>';
		}
		else
		{
			$this->deleteMembers(implode(',', $inClause));
		}
		$this->setMessage($messages . '</ul>');
	}


	/**
	 * Update Memberlog function
	 *
	 */
	function updateMemberlog( $data , $type , $timestamp )
	{
		global $roster;

		$this->reset_values();
		$this->add_ifvalue($data, 'member_id');
		$this->add_ifvalue($data, 'name');
		$this->add_ifvalue($data, 'server');
		$this->add_ifvalue($data, 'region');
		$this->add_ifvalue($data, 'guild_id');
		$this->add_ifvalue($data, 'class');
		$this->add_ifvalue($data, 'classid');
		$this->add_ifvalue($data, 'level');
		$this->add_ifvalue($data, 'note');
		$this->add_ifvalue($data, 'guild_rank');
		$this->add_ifvalue($data, 'guild_title');
		$this->add_ifvalue($data, 'officer_note');
		$this->add_time('update_time', getDate($timestamp));
		$this->add_value('type', $type);

		$querystr = "INSERT INTO `" . $roster->db->table('memberlog') . "` SET " . $this->assignstr . ";";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Member Log [' . $data['name'] . '] could not be inserted',$roster->db->error());
		}
	}


	/**
	 * Delete Guild from database. Doesn't directly delete members, because some of them may have individual upload permission (char based)
	 *
	 * @param int $guild_id
	 * @param string $timestamp
	 */
	function deleteGuild( $guild_id , $timestamp )
	{
		global $roster;

		$query = "SELECT (`guild_name` LIKE 'Guildless-%') FROM `" . $roster->db->table('guild') . "` WHERE `guild_id` = '" . $guild_id . "';";

		if( $roster->db->query_first($query) )
		{
			$this->setError('Guildless- guilds have a special meaning internally. You cannot explicitly delete them, they will be deleted automatically once the last member is deleted. To delete the guildless guild, delete all its members');
		}

		// Set all members as left
		$query = "UPDATE `" . $roster->db->table('members') . "` SET `active` = 0 WHERE `guild_id` = '" . $guild_id . "';";
		$roster->db->query($query);

		// Set those members guildless. After that the guild will be empty, and remove_guild_members will call deleteEmptyGuilds to clean that up.
		$this->remove_guild_members($guild_id, $timestamp);
	}

	/**
	 * Clean up empty guilds.
	 */
	function deleteEmptyGuilds()
	{
		global $roster;

		$query = "DELETE FROM `" . $roster->db->table('guild') . "` WHERE `guild_id` NOT IN (SELECT DISTINCT `guild_id` FROM `" . $roster->db->table('members') . "`);";
		$roster->db->query($query);

	}

	/**
	 * Delete Members in database using inClause
	 * (comma separated list of member_id's to delete)
	 *
	 * @param string $inClause
	 */
	function deleteMembers( $inClause )
	{
		global $roster;

		$messages = '<li>';

		$messages .= 'Character Data..';

		$messages .= 'Skills..';
		$querystr = "DELETE FROM `" . $roster->db->table('skills') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Skill Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Inventory..';
		$querystr = "DELETE FROM `" . $roster->db->table('items') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Inventory Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Quests..';
		$querystr = "DELETE FROM `" . $roster->db->table('quests') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Quest Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Professions..';
		$querystr = "DELETE FROM `" . $roster->db->table('recipes') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Recipe Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Talents..';
		$querystr = "DELETE FROM `" . $roster->db->table('talents') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Talent Data could not be deleted',$roster->db->error());
		}

		$querystr = "DELETE FROM `" . $roster->db->table('talenttree') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Talent Tree Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Glyphs..';
		$querystr = "DELETE FROM `" . $roster->db->table('glyphs') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Talent Tree Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Spellbook..';
		$querystr = "DELETE FROM `" . $roster->db->table('spellbook') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Spell Data could not be deleted',$roster->db->error());
		}

		$querystr = "DELETE FROM `" . $roster->db->table('spellbooktree') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Spell Tree Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Pets..';
		$querystr = "DELETE FROM `" . $roster->db->table('pets') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Data could not be deleted',$roster->db->error());
		}

		$querystr = "DELETE FROM `" . $roster->db->table('companions') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Companion Data could not be deleted',$roster->db->error());
		}

		$messages .= 'Pet Spells..';
		$querystr = "DELETE FROM `" . $roster->db->table('pet_spellbook') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Spell Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Pet Talents..';
		$querystr = "DELETE FROM `" . $roster->db->table('pet_talents') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Talent Data could not be deleted',$roster->db->error());
		}

		$querystr = "DELETE FROM `" . $roster->db->table('pet_talenttree') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Pet Talent Tree Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Reputation..';
		$querystr = "DELETE FROM `" . $roster->db->table('reputation') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Reputation Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Currency..';
		$querystr = "DELETE FROM `" . $roster->db->table('currency') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Currency Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Mail..';
		$querystr = "DELETE FROM `" . $roster->db->table('mailbox') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Mail Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Membership..';
		$querystr = "DELETE FROM `" . $roster->db->table('members') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Member Data could not be deleted',$roster->db->error());
		}


		$messages .= 'Final Character Cleanup..';
		$querystr = "DELETE FROM `" . $roster->db->table('players') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Player Data could not be deleted',$roster->db->error());
		}

		$querystr = "DELETE FROM `" . $roster->db->table('buffs') . "` WHERE `member_id` IN ($inClause)";
		if( !$roster->db->query($querystr) )
		{
			$this->setError('Player Buff Data could not be deleted',$roster->db->error());
		}
		if( $roster->config['use_update_triggers'] )
		{
			$messages .= $this->addon_hook('char_delete', $inClause);
		}

		$this->deleteEmptyGuilds();

		$this->setMessage($messages . '</li>');
	}

	/**
	 * Removes guild members with `active` = 0
	 *
	 * @param int $guild_id
	 * @param string $timestamp
	 */
	function remove_guild_members( $guild_id , $timestamp )
	{
		global $roster;

		$querystr = "SELECT * FROM `" . $roster->db->table('members') . "` WHERE `guild_id` = '$guild_id' AND `active` = '0';";

		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Members could not be selected for deletion',$roster->db->error());
			return;
		}

		$num = $roster->db->num_rows($result);
		if( $num > 0 )
		{
			// Get guildless guild for this realm
			$query = "SELECT * FROM `" . $roster->db->table('guild') . "` WHERE `guild_id` = '$guild_id';";
			$result2 = $roster->db->query($query);
			$row = $roster->db->fetch($result2);
			$roster->db->free_result($result2);

			$query = "SELECT `guild_id` FROM `" . $roster->db->table('guild') . "` WHERE `server` = '" . $roster->db->escape($row['server']) . "' AND `region` = '" . $roster->db->escape($row['region']) . "' AND `factionEn` = '" . $roster->db->escape($row['factionEn']) . "' AND `guild_name` LIKE 'guildless-%';";
			$guild_id = $roster->db->query_first($query);

			if( !$guild_id )
			{
				$guilddata['Faction'] = $row['factionEn'];
				$guilddata['FactionEn'] = $row['factionEn'];
				$guilddata['Locale'] = $row['Locale'];
				$guilddata['Info'] = '';
				$guild_id = $this->update_guild($row['server'],'GuildLess-' . substr($row['factionEn'],0,1),strtotime($timestamp),$guilddata,$row['region']);
				unset($guilddata);
			}

			$inClause = array();
			while( $row = $roster->db->fetch($result) )
			{
				$this->setMessage('<li><span class="red">[</span> ' . $row[1] . ' <span class="red">] - Removed</span></li>');
				$this->setMemberLog($row,0,$timestamp);

				$inClause[] = $row[0];
			}
			$inClause = implode(',',$inClause);

			// now that we have our inclause, set them guildless
			$this->setMessage('<li><span class="red">Setting ' . $num . ' member' . ($num > 1 ? 's' : '') . ' to guildless</span></li>');

			$roster->db->free_result($result);

			$this->reset_values();
			$this->add_value('guild_id',$guild_id);
			$this->add_value('note','');
			$this->add_value('guild_rank',0);
			$this->add_value('guild_title','');
			$this->add_value('officer_note','');

			$querystr = "UPDATE `" . $roster->db->table('members') . "` SET " . $this->assignstr . " WHERE `member_id` IN ($inClause);";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Guild members could not be set guildless',$roster->db->error());
			}

			$this->reset_values();
			$this->add_value('guild_id',$guild_id);

			$querystr = "UPDATE `" . $roster->db->table('players') . "` SET " . $this->assignstr . " WHERE `member_id` IN ($inClause);";
			if( !$roster->db->query($querystr) )
			{
				$this->setError('Guild members could not be set guildless',$roster->db->error());
			}
		}

		$this->deleteEmptyGuilds();
	}

	/**
	 * Gets guild info from database
	 * Returns info as an array
	 *
	 * @param string $realmName
	 * @param string $guildName
	 * @return array
	 */
	function get_guild_info( $realmName , $guildName , $region='' )
	{
		global $roster;

		$guild_name_escape = $roster->db->escape($guildName);
		$server_escape = $roster->db->escape($realmName);

		if( !empty($region) )
		{
			$region = " AND `region` = '" . $roster->db->escape($region) . "'";
		}

		$querystr = "SELECT * FROM `" . $roster->db->table('guild') . "` WHERE `guild_name` = '$guild_name_escape' AND `server` = '$server_escape'$region;";
		$result = $roster->db->query($querystr) or die_quietly($roster->db->error(),'WowDB Error',__FILE__ . '<br />Function: ' . (__FUNCTION__),__LINE__,$querystr);

		if( $roster->db->num_rows() > 0 )
		{
			$retval = $roster->db->fetch($result);
			$roster->db->free_result($result);

			return $retval;
		}
		else
		{
			return false;
		}
	}

	function get_guild_rank( $guild_id )
	{
		global $roster;

		$querystr = "SELECT * FROM `" . $roster->db->table('guild_rank') . "` WHERE `guild_id` = '$guild_id';";
		$result = $roster->db->query($querystr) or die_quietly($roster->db->error(),'WowDB Error',__FILE__ . '<br />Function: ' . (__FUNCTION__),__LINE__,$querystr);

		if( $roster->db->num_rows() > 0 )
		{
			$retval = $roster->db->fetch_all($result);
			$roster->db->free_result($result);

			return $retval;
		}
		else
		{
			return null;
		}
	}

	/**
	 * Function to prepare the memberlog data
	 *
	 * @param array $data | Member info array
	 * @param multiple $type | Action to update ( 'rem','del,0 | 'add','new',1 )
	 * @param string $timestamp | Time
	 */
	function setMemberLog( $data , $type , $timestamp )
	{
		if ( is_array($data) )
		{
			switch ($type)
			{
				case 'del':
				case 'rem':
				case 0:
					$this->membersremoved++;
					$this->updateMemberlog($data,0,$timestamp);
					break;

				case 'add':
				case 'new':
				case 1:
					$this->membersadded++;
					$this->updateMemberlog($data,1,$timestamp);
					break;
			}
		}
	}

	/**
	 * Updates or creates the guild rank database
	 *
	 * @param array $guild
	 */
	function update_guild_ranks($guild , $guild_id )
	{
		global $roster;
		
		$guild_ranks = $this->get_guild_rank($guild_id);
		$ranks = array();
		if (is_array($guild_ranks))
		{
			foreach($guild_ranks as $r => $rw)
			{
				$ranks[$rw['rank']] = array('title' => $rw['title'],'control' => $rw['control']);
			}
		}
		
		foreach($guild['Ranks'] as $id => $d)
		{
			$this->reset_values();
			$this->add_value('rank', $id);
			$this->add_value('guild_id', $guild_id);
			$this->add_ifvalue($d, 'Title', 'title');
			$this->add_ifvalue($d, 'Control', 'control');

			if( isset($ranks[$id]['title']) && $ranks[$id]['title'] == $d['Title'] )
			{
				$querystra = "UPDATE `" . $roster->db->table('guild_rank') . "` SET " . $this->assignstr . " WHERE `rank` = '" . $id . "' AND `guild_id` = '" . $guild_id . "';";
			}
			else
			{
				$querystra = "INSERT INTO `" . $roster->db->table('guild_rank') . "` SET " . $this->assignstr;
			}

			$roster->db->query($querystra) or die_quietly($roster->db->error(),'WowDB Error',__FILE__ . '<br />Function: ' . (__FUNCTION__),__LINE__,$querystra);
		}
	}
	
	/**
	 * Updates or creates an entry in the guild table in the database
	 * Then returns the guild ID
	 *
	 * @param string $realmName
	 * @param string $guildName
	 * @param array $currentTime
	 * @param array $guild
	 * @return string
	 */
	function update_guild( $realmName , $guildName , $currentTime , $guild , $region )
	{
		global $roster;
		$guildInfo = $this->get_guild_info($realmName,$guildName,$region);

		$this->locale = $guild['Locale'];

		$this->reset_values();

		$this->add_value('guild_name', $guildName);

		$this->add_value('server', $realmName);
		$this->add_value('region', $region);
		$this->add_ifvalue($guild, 'Faction', 'faction');
		$this->add_ifvalue($guild, 'FactionEn', 'factionEn');
		$this->add_ifvalue($guild, 'Motd', 'guild_motd');

		$this->add_ifvalue($guild, 'NumMembers', 'guild_num_members');
		$this->add_ifvalue($guild, 'NumAccounts', 'guild_num_accounts');

		$this->add_ifvalue($guild, 'GuildXP', 'guild_xp');
		$this->add_ifvalue($guild, 'GuildXPCap', 'guild_xpcap');
		$this->add_ifvalue($guild, 'GuildLevel', 'guild_level');

		$this->add_timestamp('update_time', $currentTime);

		$this->add_ifvalue($guild, 'DBversion');
		$this->add_ifvalue($guild, 'GPversion');
		if (is_array($guild['Info']))
		{
			$this->add_value('guild_info_text', str_replace('\n',"<br />",$guild['Info']));
		}

		if( is_array($guildInfo) )
		{
			$querystra = "UPDATE `" . $roster->db->table('guild') . "` SET " . $this->assignstr . " WHERE `guild_id` = '" . $guildInfo['guild_id'] . "';";
			$output = $guildInfo['guild_id'];
		}
		else
		{
			$querystra = "INSERT INTO `" . $roster->db->table('guild') . "` SET " . $this->assignstr;
		}

		$roster->db->query($querystra) or die_quietly($roster->db->error(),'WowDB Error',__FILE__ . '<br />Function: ' . (__FUNCTION__),__LINE__,$querystra);

		if( is_array($guildInfo) )
		{
			$querystr = "UPDATE `" . $roster->db->table('members') . "` SET `active` = '0' WHERE `guild_id` = '" . $guildInfo['guild_id'] . "';";
			$roster->db->query($querystr) or die_quietly($roster->db->error(),'WowDB Error',__FILE__ . '<br />Function: ' . (__FUNCTION__),__LINE__,$querystr);
		}

		if( !is_array($guildInfo) )
		{
			$guildInfo = $this->get_guild_info($realmName,$guildName);
			$output = $guildInfo['guild_id'];
		}

		return $output;
	}


	/**
	 * Updates or adds guild members
	 *
	 * @param int $guildId	| Character's guild id
	 * @param string $name	| Character's name
	 * @param array $char	| LUA data
	 * @param array $currentTimestamp
	 * @return mixed		| False on error, memberid on success
	 */
	function update_guild_member( $guildId , $name , $server , $region , $char , $currentTimestamp , $guilddata )
	{
		global $roster;

		$name_escape = $roster->db->escape($name);
		$server_escape = $roster->db->escape($server);
		$region_escape = $roster->db->escape($region);

		$querystr = "SELECT `member_id` "
			. "FROM `" . $roster->db->table('members') . "` "
			. "WHERE `name` = '$name_escape' "
			. "AND `server` = '$server_escape' "
			. "AND `region` = '$region_escape';";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Member could not be selected for update',$roster->db->error());
			return false;
		}

		$memberInfo = $roster->db->fetch( $result );
		if( $memberInfo )
		{
			$memberId = $memberInfo['member_id'];
		}

		$roster->db->free_result($result);

		$this->reset_values();

		$this->add_value('name', $name);
		$this->add_value('server', $server);
		$this->add_value('region', $region);
		$this->add_value('guild_id', $guildId);
		$this->add_ifvalue($char, 'Class', 'class');
		$this->add_ifvalue($char, 'ClassId', 'classid');
		$this->add_ifvalue($char, 'Level', 'level');
		$this->add_ifvalue($char, 'Note', 'note', '');
		$this->add_ifvalue($char, 'Rank', 'guild_rank');

		if( isset($char['Rank']) && isset($guilddata['Ranks'][$char['Rank']]['Title']) )
		{
			$this->add_value('guild_title', $guilddata['Ranks'][$char['Rank']]['Title']);
		}
		else if( isset($char['RankEn']) )
		{
			$this->add_value('guild_title', $char['RankEn']);
		}

		if( isset($guilddata['ScanInfo']) && $guilddata['ScanInfo']['HasOfficerNote'] )
		{
			$this->add_ifvalue($char, 'OfficerNote', 'officer_note', '');
		}

		$this->add_ifvalue($char, 'Zone', 'zone', '');
		$this->add_ifvalue($char, 'Status', 'status', '');
		$this->add_value('active', '1');

		if( isset($char['Online']) && $char['Online'] == '1' )
		{
			$this->add_value('online', 1);
			$this->add_time('last_online', getDate($currentTimestamp));
		}
		else
		{
			$this->add_value('online', 0);
			list($lastOnlineYears,$lastOnlineMonths,$lastOnlineDays,$lastOnlineHours) = explode(':',$char['LastOnline']);

			# use strtotime instead
			#	  $lastOnlineTime = $currentTimestamp - 365 * 24* 60 * 60 * $lastOnlineYears
			#						- 30 * 24 * 60 * 60 * $lastOnlineMonths
			#						- 24 * 60 * 60 * $lastOnlineDays
			#						- 60 * 60 * $lastOnlineHours;
			$timeString = '-';
			if ($lastOnlineYears > 0)
			{
				$timeString .= $lastOnlineYears . ' Years ';
			}
			if ($lastOnlineMonths > 0)
			{
				$timeString .= $lastOnlineMonths . ' Months ';
			}
			if ($lastOnlineDays > 0)
			{
				$timeString .= $lastOnlineDays . ' Days ';
			}
			$timeString .= max($lastOnlineHours,1) . ' Hours';

			$lastOnlineTime = strtotime($timeString,$currentTimestamp);
			$this->add_time('last_online', getDate($lastOnlineTime));
		}

		if( isset($memberId) )
		{
			$querystr = "UPDATE `" . $roster->db->table('members') . "` SET " . $this->assignstr . " WHERE `member_id` = '$memberId';";
			$this->setMessage('<li>[ ' . $name . ' ]<ul>');
			$this->membersupdated++;

			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError($name . ' could not be inserted',$roster->db->error());
				return false;
			}
		}
		else
		{
			$querystr = "INSERT INTO `" . $roster->db->table('members') . "` SET " . $this->assignstr . ';';
			//$this->setMessage('<li><span class="green">[</span> ' . $name . ' <span class="green">] - Added</span></li>');
			$this->setMessage('<li><span class="green">[</span> ' . $name . ' <span class="green">] - Added</span><ul>');

			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError($name . ' could not be inserted',$roster->db->error());
				return false;
			}

			$memberId = $roster->db->insert_id();

			$querystr = "SELECT * FROM `" . $roster->db->table('members') . "` WHERE `member_id` = '$memberId';";
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Member could not be selected for MemberLog',$roster->db->error());
			}
			else
			{
				$row = $roster->db->fetch($result);
				$this->setMemberLog($row,1,$currentTimestamp);
			}
		}

		// We may have added the last member of the guildless guild to a real guild, so check for empty guilds
		$this->deleteEmptyGuilds();

		return $memberId;
	}

	/**
	 * Updates/Inserts pets into the db
	 *
	 * @param int $memberId
	 * @param array $data
	 */
	function update_pet( $memberId , $data )
	{
		global $roster;

		if (!empty($data['Name']))
		{
			$querystr = "SELECT `pet_id`
				FROM `" . $roster->db->table('pets') . "`
				WHERE `member_id` = '$memberId' AND `name` LIKE '" . $roster->db->escape($data['Name']) . "'";

			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Cannot select Pet Data',$roster->db->error());
				return;
			}

			if( $roster->db->num_rows($result) == 1 )
			{
				$update = true;
				$petID = $roster->db->fetch($result);
				$petID = $petID['pet_id'];
			}
			else
			{
				$update = false;
			}
			$roster->db->free_result($result);

			$this->reset_values();

			$this->add_value('member_id', $memberId);

			$this->add_ifvalue($data, 'Name', 'name');
			$this->add_ifvalue($data, 'Slot', 'slot', '0');

			// BEGIN STATS
			if( !empty( $data['Attributes']['Stats'] ) )
			{
				$main_stats = $data['Attributes']['Stats'];

				$this->add_rating('stat_int', $main_stats['Intellect']);
				$this->add_rating('stat_agl', $main_stats['Agility']);
				$this->add_rating('stat_sta', $main_stats['Stamina']);
				$this->add_rating('stat_str', $main_stats['Strength']);
				$this->add_rating('stat_spr', $main_stats['Spirit']);

				unset($main_stats);
			}
			// END STATS

			// BEGIN DEFENSE
			if( !empty($data['Attributes']['Defense']) )
			{
				$main_stats = $data['Attributes']['Defense'];

				$this->add_ifvalue($main_stats, 'DodgeChance', 'dodge');
				$this->add_ifvalue($main_stats, 'ParryChance', 'parry');
				$this->add_ifvalue($main_stats, 'BlockChance', 'block');
				$this->add_ifvalue($main_stats, 'ArmorReduction', 'mitigation');

				$this->add_rating('stat_armor', $main_stats['Armor']);
				$this->add_rating('stat_def', $main_stats['Defense']);
				$this->add_rating('stat_block', $main_stats['BlockRating']);
				$this->add_rating('stat_parry', $main_stats['ParryRating']);
				$this->add_rating('stat_defr', $main_stats['DefenseRating']);
				$this->add_rating('stat_dodge', $main_stats['DodgeRating']);

				$this->add_ifvalue($main_stats['Resilience'], 'Ranged', 'stat_res_ranged');
				$this->add_ifvalue($main_stats['Resilience'], 'Spell', 'stat_res_spell');
				$this->add_ifvalue($main_stats['Resilience'], 'Melee', 'stat_res_melee');
			}
			// END DEFENSE

			// BEGIN RESISTS
			if( !empty($data['Attributes']['Resists']) )
			{
				$main_res = $data['Attributes']['Resists'];

				$this->add_rating('res_holy', $main_res['Holy']);
				$this->add_rating('res_frost', $main_res['Frost']);
				$this->add_rating('res_arcane', $main_res['Arcane']);
				$this->add_rating('res_fire', $main_res['Fire']);
				$this->add_rating('res_shadow', $main_res['Shadow']);
				$this->add_rating('res_nature', $main_res['Nature']);

				unset($main_res);
			}
			// END RESISTS

			// BEGIN MELEE
			if( !empty($data['Attributes']['Melee']) )
			{
				$attack = $data['Attributes']['Melee'];

				if( isset($attack['AttackPower']) )
				{
					$this->add_rating('melee_power', $attack['AttackPower']);
				}
				if( isset($attack['HitRating']) )
				{
					$this->add_rating('melee_hit', $attack['HitRating']);
				}
				if( isset($attack['CritRating']) )
				{
					$this->add_rating('melee_crit', $attack['CritRating']);
				}
				if( isset($attack['HasteRating']) )
				{
					$this->add_rating('melee_haste', $attack['HasteRating']);
				}

				$this->add_ifvalue($attack, 'CritChance', 'melee_crit_chance');
				$this->add_ifvalue($attack, 'AttackPowerDPS', 'melee_power_dps');

				if( is_array($attack['MainHand']) )
				{
					$hand = $attack['MainHand'];

					$this->add_ifvalue($hand, 'AttackSpeed', 'melee_mhand_speed');
					$this->add_ifvalue($hand, 'AttackDPS', 'melee_mhand_dps');
					$this->add_ifvalue($hand, 'AttackSkill', 'melee_mhand_skill');

					list($mindam, $maxdam) = explode(':',$hand['DamageRange']);
					$this->add_value('melee_mhand_mindam', $mindam);
					$this->add_value('melee_mhand_maxdam', $maxdam);
					unset($mindam, $maxdam);

					$this->add_rating( 'melee_mhand_rating', $hand['AttackRating']);
				}

				if( isset($attack['DamageRangeTooltip']) )
				{
					$this->add_value( 'melee_range_tooltip', $this->tooltip($attack['DamageRangeTooltip']) );
				}
				if( isset($attack['AttackPowerTooltip']) )
				{
					$this->add_value( 'melee_power_tooltip', $this->tooltip($attack['AttackPowerTooltip']) );
				}

				unset($hand, $attack);
			}
			// END MELEE

			$this->add_ifvalue($data, 'Level', 'level', 0);
			$this->add_ifvalue($data, 'Health', 'health', 0);
			$this->add_ifvalue($data, 'Mana', 'mana', 0);
			$this->add_ifvalue($data, 'Power', 'power', 0);

			$this->add_ifvalue($data, 'Experience', 'xp', 0);
			$this->add_ifvalue($data, 'TalentPoints', 'totaltp', 0);
			$this->add_ifvalue($data, 'Type', 'type', '');
			if( !empty($data['Icon']) )
			{
				$this->add_value('icon', $this->fix_icon($data['Icon']));
			}

			if( $update )
			{
				$this->setMessage('<li>Updating pet [' . $data['Name'] . ']<ul>');
				$querystr = "UPDATE `" . $roster->db->table('pets') . "` SET " . $this->assignstr . " WHERE `pet_id` = '$petID'";
				$result = $roster->db->query($querystr);
			}
			else
			{
				$this->setMessage('<li>New pet [' . $data['Name'] . ']<ul>');
				$querystr = "INSERT INTO `" . $roster->db->table('pets') . "` SET " . $this->assignstr;
				$result = $roster->db->query($querystr);
				$petID = $roster->db->insert_id();
			}

			if( !$result )
			{
				$this->setError('Cannot update Pet Data',$roster->db->error());
				return;
			}
			$this->do_pet_spellbook($data,$memberId,$petID);
			$this->do_pet_talents($data,$memberId,$petID);

			$this->setMessage('</ul></li>');
		}
	}


	/**
	 * Handles formatting an insertion of Character Data
	 *
	 * @param int $guildId
	 * @param string $region
	 * @param string $name
	 * @param array $data
	 * @return mixed False on failure | member_id on success
	 */
	function update_char( $guildId , $region , $server , $name , $data )
	{
		global $roster;

		$name_escape = $roster->db->escape($name);
		$server_escape = $roster->db->escape($server);
		$region_escape = $roster->db->escape($region);

		$querystr = "SELECT `member_id` "
			. "FROM `" . $roster->db->table('members') . "` "
			. "WHERE `name` = '$name_escape' "
			. "AND `server` = '$server_escape' "
			. "AND `region` = '$region_escape';";

		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Cannot select member_id for Character Data',$roster->db->error());
			return false;
		}

		$memberInfo = $roster->db->fetch($result);
		$roster->db->free_result($result);

		if (isset($memberInfo) && is_array($memberInfo))
		{
			$memberId = $memberInfo['member_id'];
		}
		else
		{
			$this->setMessage('<li>Missing member id for ' . $name . '</li>');
			return false;
		}

		// update level in members table
		$querystr = "UPDATE `" . $roster->db->table('members') . "` SET `level` = '" . $data['Level'] . "' WHERE `member_id` = '$memberId' LIMIT 1;";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Cannot update Level in Members Table',$roster->db->error());
		}


		$querystr = "SELECT `member_id` FROM `" . $roster->db->table('players') . "` WHERE `member_id` = '$memberId';";
		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Cannot select member_id for Character Data',$roster->db->error());
			return false;
		}

		$update = $roster->db->num_rows($result) == 1;
		$roster->db->free_result($result);

		$this->reset_values();

		$this->add_value('name', $name);
		$this->add_value('guild_id', $guildId);
		$this->add_value('server', $server);
		$this->add_value('region', $region);

		$this->add_ifvalue($data, 'Level', 'level');

		// BEGIN HONOR VALUES
		if( isset($data['Honor']) && is_array($data['Honor']) && count($data['Honor']) > 0 )
		{
			$honor = $data['Honor'];

			$this->add_ifvalue($honor['Session'], 'HK', 'sessionHK', 0);
			$this->add_ifvalue($honor['Session'], 'CP', 'sessionCP', 0);
			$this->add_ifvalue($honor['Yesterday'], 'HK', 'yesterdayHK', 0);
			$this->add_ifvalue($honor['Yesterday'], 'CP', 'yesterdayContribution', 0);
			$this->add_ifvalue($honor['Lifetime'], 'HK', 'lifetimeHK', 0);
			$this->add_ifvalue($honor['Lifetime'], 'Rank', 'lifetimeHighestRank', 0);
			$this->add_ifvalue($honor['Lifetime'], 'Name', 'lifetimeRankname', '');
			$this->add_ifvalue($honor['Current'], 'HonorPoints', 'honorpoints', 0);
			$this->add_ifvalue($honor['Current'], 'ArenaPoints', 'arenapoints', 0);

			unset($honor);
		}
		// END HONOR VALUES
		if ( isset($data['Attributes']['Melee']) && is_array($data['Attributes']['Melee']) )
		{
			$this->add_ifvalue($data['Attributes']['Melee'], 'CritChance', 'crit', 0);
		}
		// BEGIN STATS
		if( isset($data['Attributes']['Stats']) && is_array($data['Attributes']['Stats']) )
		{
			$main_stats = $data['Attributes']['Stats'];

			$this->add_rating('stat_int', $main_stats['Intellect']);
			$this->add_rating('stat_agl', $main_stats['Agility']);
			$this->add_rating('stat_sta', $main_stats['Stamina']);
			$this->add_rating('stat_str', $main_stats['Strength']);
			$this->add_rating('stat_spr', $main_stats['Spirit']);

			unset($main_stats);
		}
		// END STATS

		// BEGIN DEFENSE
		if( isset($data['Attributes']['Defense']) && is_array($data['Attributes']['Defense']) )
		{
			$main_stats = $data['Attributes']['Defense'];

			$this->add_ifvalue($main_stats, 'DodgeChance', 'dodge');
			$this->add_ifvalue($main_stats, 'ParryChance', 'parry');
			$this->add_ifvalue($main_stats, 'BlockChance', 'block');
			$this->add_ifvalue($main_stats, 'ArmorReduction', 'mitigation');
			$this->add_ifvalue($main_stats, 'PvPPower', 'pvppower');
			$this->add_ifvalue($main_stats, 'PvPPowerBonus', 'pvppower_bonus');

			$this->add_rating('stat_armor', $main_stats['Armor']);
			$this->add_rating('stat_def', $main_stats['Defense']);
			$this->add_rating('stat_block', $main_stats['BlockRating']);
			$this->add_rating('stat_parry', $main_stats['ParryRating']);
			$this->add_rating('stat_defr', $main_stats['DefenseRating']);
			$this->add_rating('stat_dodge', $main_stats['DodgeRating']);

			$this->add_ifvalue($main_stats['Resilience'], 'Ranged', 'stat_res_ranged');
			$this->add_ifvalue($main_stats['Resilience'], 'Spell', 'stat_res_spell');
			$this->add_ifvalue($main_stats['Resilience'], 'Melee', 'stat_res_melee');
		}
		// END DEFENSE

		// BEGIN RESISTS
		if( isset($data['Attributes']['Resists']) && is_array($data['Attributes']['Resists']) )
		{
			$main_res = $data['Attributes']['Resists'];

			$this->add_rating('res_holy', $main_res['Holy']);
			$this->add_rating('res_frost', $main_res['Frost']);
			$this->add_rating('res_arcane', $main_res['Arcane']);
			$this->add_rating('res_fire', $main_res['Fire']);
			$this->add_rating('res_shadow', $main_res['Shadow']);
			$this->add_rating('res_nature', $main_res['Nature']);

			unset($main_res);
		}
		// END RESISTS

		// BEGIN MELEE
		if( isset($data['Attributes']['Melee']) && is_array($data['Attributes']['Melee']) )
		{
			$attack = $data['Attributes']['Melee'];

			$this->add_rating('melee_power', $attack['AttackPower']);
			$this->add_rating('melee_hit', $attack['HitRating']);
			$this->add_rating('melee_crit', $attack['CritRating']);
			$this->add_rating('melee_haste', $attack['HasteRating']);

			if (isset($attack['Expertise']))
			{
				$this->add_rating('melee_expertise', $attack['Expertise']);
			}

			$this->add_ifvalue($attack, 'CritChance', 'melee_crit_chance');
			$this->add_ifvalue($attack, 'AttackPowerDPS', 'melee_power_dps');

			if( isset($attack['MainHand']) && is_array($attack['MainHand']) )
			{
				$hand = $attack['MainHand'];

				$this->add_ifvalue($hand, 'AttackSpeed', 'melee_mhand_speed');
				$this->add_ifvalue($hand, 'AttackDPS', 'melee_mhand_dps');
				$this->add_ifvalue($hand, 'AttackSkill', 'melee_mhand_skill');

				list($mindam, $maxdam) = explode(':',$hand['DamageRangeBase']);
				$this->add_value('melee_mhand_mindam', $mindam);
				$this->add_value('melee_mhand_maxdam', $maxdam);
				unset($mindam, $maxdam);

				$this->add_rating('melee_mhand_rating', $hand['AttackRating']);
			}

			if( isset($attack['OffHand']) && is_array($attack['OffHand']) )
			{
				$hand = $attack['OffHand'];

				$this->add_ifvalue($hand, 'AttackSpeed', 'melee_ohand_speed');
				$this->add_ifvalue($hand, 'AttackDPS', 'melee_ohand_dps');
				$this->add_ifvalue($hand, 'AttackSkill', 'melee_ohand_skill');

				list($mindam, $maxdam) = explode(':',$hand['DamageRangeBase']);
				$this->add_value('melee_ohand_mindam', $mindam);
				$this->add_value('melee_ohand_maxdam', $maxdam);
				unset($mindam, $maxdam);

				$this->add_rating('melee_ohand_rating', $hand['AttackRating']);
			}
			else
			{
				$this->add_value('melee_ohand_speed', 0);
				$this->add_value('melee_ohand_dps', 0);
				$this->add_value('melee_ohand_skill', 0);

				$this->add_value('melee_ohand_mindam', 0);
				$this->add_value('melee_ohand_maxdam', 0);

				$this->add_rating('melee_ohand_rating', 0);
			}

			if( isset($attack['DamageRangeTooltip']) )
			{
				$this->add_value('melee_range_tooltip', $this->tooltip($attack['DamageRangeTooltip']));
			}
			if( isset($attack['AttackPowerTooltip']) )
			{
				$this->add_value('melee_power_tooltip', $this->tooltip($attack['AttackPowerTooltip']));
			}

			unset($hand, $attack);
		}
		// END MELEE

		// BEGIN RANGED
		if( isset($data['Attributes']['Ranged']) && is_array($data['Attributes']['Ranged']) )
		{
			$attack = $data['Attributes']['Ranged'];

			$this->add_rating('ranged_power', ( isset($attack['AttackPower']) ? $attack['AttackPower'] : '0' ));
			$this->add_rating('ranged_hit', $attack['HitRating']);
			$this->add_rating('ranged_crit', $attack['CritRating']);
			$this->add_rating('ranged_haste', $attack['HasteRating']);

			$this->add_ifvalue($attack, 'CritChance', 'ranged_crit_chance');
			$this->add_ifvalue($attack, 'AttackPowerDPS', 'ranged_power_dps', 0);

			$this->add_ifvalue($attack, 'AttackSpeed', 'ranged_speed');
			$this->add_ifvalue($attack, 'AttackDPS', 'ranged_dps');
			$this->add_ifvalue($attack, 'AttackSkill', 'ranged_skill');

			list($mindam, $maxdam) = explode(':',$attack['DamageRangeBase']);
			$this->add_value('ranged_mindam', $mindam);
			$this->add_value('ranged_maxdam', $maxdam);
			unset($mindam, $maxdam);

			$this->add_rating( 'ranged_rating', $attack['AttackRating']);

			if( isset($attack['DamageRangeTooltip']) )
			{
				$this->add_value('ranged_range_tooltip', $this->tooltip($attack['DamageRangeTooltip']));
			}
			if( isset($attack['AttackPowerTooltip']) )
			{
				$this->add_value('ranged_power_tooltip', $this->tooltip($attack['AttackPowerTooltip']));
			}
			unset($attack);
		}
		// END RANGED

		if( isset($data['Attributes']['ITEMLEVEL']))
		{
			$this->add_value('ilevel', $data['Attributes']['ITEMLEVEL']);
		}
		// BEGIN mastery
		if( isset($data['Attributes']['Mastery']) && is_array($data['Attributes']['Mastery']) )
		{
			$attack = $data['Attributes']['Mastery'];

			$this->add_ifvalue($attack, 'Percent', 'mastery');
			//$this->add_ifvalue($attack, 'Tooltip', 'mastery_tooltip');
			$this->add_value('mastery_tooltip', $this->tooltip($data['Attributes']['Mastery']['Tooltip']));

			unset($attack);
		}
		// END Mastery

		// BEGIN SPELL
		if( isset($data['Attributes']['Spell']) && is_array($data['Attributes']['Spell']) )
		{
			$spell = $data['Attributes']['Spell'];

			$this->add_rating('spell_hit', $spell['HitRating']);
			$this->add_rating('spell_crit', $spell['CritRating']);
			$this->add_rating('spell_haste', $spell['HasteRating']);

			$this->add_ifvalue($spell, 'CritChance', 'spell_crit_chance');

			list($not_cast, $cast) = explode(':',$spell['ManaRegen']);
			$this->add_value('mana_regen', $not_cast);
			$this->add_value('mana_regen_cast', $cast);
			unset($not_cast, $cast);

			$this->add_ifvalue($spell, 'Penetration', 'spell_penetration');
			$this->add_ifvalue($spell, 'BonusDamage', 'spell_damage');
			$this->add_ifvalue($spell, 'BonusHealing', 'spell_healing');

			if( isset($spell['SchoolCrit']) && is_array($spell['SchoolCrit']) )
			{
				$schoolcrit = $spell['SchoolCrit'];

				$this->add_ifvalue($schoolcrit, 'Holy', 'spell_crit_chance_holy');
				$this->add_ifvalue($schoolcrit, 'Frost', 'spell_crit_chance_frost');
				$this->add_ifvalue($schoolcrit, 'Arcane', 'spell_crit_chance_arcane');
				$this->add_ifvalue($schoolcrit, 'Fire', 'spell_crit_chance_fire');
				$this->add_ifvalue($schoolcrit, 'Shadow', 'spell_crit_chance_shadow');
				$this->add_ifvalue($schoolcrit, 'Nature', 'spell_crit_chance_nature');

				unset($schoolcrit);
			}

			if( isset($spell['School']) && is_array($spell['School']) )
			{
				$school = $spell['School'];

				$this->add_ifvalue($school, 'Holy', 'spell_damage_holy');
				$this->add_ifvalue($school, 'Frost', 'spell_damage_frost');
				$this->add_ifvalue($school, 'Arcane', 'spell_damage_arcane');
				$this->add_ifvalue($school, 'Fire', 'spell_damage_fire');
				$this->add_ifvalue($school, 'Shadow', 'spell_damage_shadow');
				$this->add_ifvalue($school, 'Nature', 'spell_damage_nature');

				unset($school);
			}

			unset($spell);
		}
		// END SPELL

		$this->add_ifvalue($data, 'TalentPoints', 'talent_points');

		//$this->add_ifvalue('money_c', $data['Money']['Copper']);
		//$this->add_ifvalue('money_s', $data['Money']['Silver']);
		//$this->add_ifvalue('money_g', $data['Money']['Gold']);
		if (isset($data['Money']))
		{
		$this->add_ifvalue($data['Money'], 'Silver', 'money_s');
		$this->add_ifvalue($data['Money'], 'Copper', 'money_c');
		$this->add_ifvalue($data['Money'], 'Gold', 'money_g');
		}

		$this->add_ifvalue($data, 'Experience', 'exp');
		$this->add_ifvalue($data, 'Race', 'race');
		$this->add_ifvalue($data, 'RaceId', 'raceid');
		$this->add_ifvalue($data, 'RaceEn', 'raceEn');
		$this->add_ifvalue($data, 'Class', 'class');
		$this->add_ifvalue($data, 'ClassId', 'classid');
		$this->add_ifvalue($data, 'ClassEn', 'classEn');
		$this->add_ifvalue($data, 'Health', 'health');
		$this->add_ifvalue($data, 'Mana', 'mana');
		$this->add_ifvalue($data, 'Power', 'power');
		$this->add_ifvalue($data, 'Sex', 'sex');
		$this->add_ifvalue($data, 'SexId', 'sexid');
		$this->add_ifvalue($data, 'Hearth', 'hearth');

		$this->add_ifvalue($data['timestamp']['init'], 'DateUTC','dateupdatedutc');

		$this->add_ifvalue($data, 'DBversion');
		$this->add_ifvalue($data, 'CPversion');

		$this->add_ifvalue($data,'TimePlayed','timeplayed',0);
		$this->add_ifvalue($data,'TimeLevelPlayed','timelevelplayed',0);

		// Capture mailbox update time/date
		if( isset($data['timestamp']['MailBox']) )
		{
			$this->add_timestamp('maildateutc',$data['timestamp']['MailBox']);
		}

		// Capture client language
		$this->add_ifvalue($data, 'Locale', 'clientLocale');

		$this->setMessage('<li>About to update player</li>');

		if( $update )
		{
			$querystr = "UPDATE `" . $roster->db->table('players') . "` SET " . $this->assignstr . " WHERE `member_id` = '$memberId';";
		}
		else
		{
			$this->add_value('member_id', $memberId);
			$querystr = "INSERT INTO `" . $roster->db->table('players') . "` SET " . $this->assignstr . ";";
		}

		$result = $roster->db->query($querystr);
		if( !$result )
		{
			$this->setError('Cannot update Character Data',$roster->db->error());
			return false;
		}

		$this->locale = $data['Locale'];

		if ( isset($data['Equipment']) && is_array($data['Equipment']) )
		{
			$this->do_equip($data, $memberId);
		}
		if ( isset($data['Inventory']) && is_array($data['Inventory']) )
		{
			$this->do_inventory($data, $memberId);
		}
		$this->do_bank($data, $memberId);
		$this->do_mailbox($data, $memberId);
		$this->do_skills($data, $memberId);
		$this->do_recipes($data, $memberId);
		$this->do_spellbook($data, $memberId);
		$this->do_glyphs($data, $memberId);
		$this->do_talents($data, $memberId);
		$this->do_reputation($data, $memberId);
		$this->do_currency($data, $memberId);
		$this->do_quests($data, $memberId);
		$this->do_buffs($data, $memberId);
		$this->do_companions($data, $memberId);

		// Adding pet info
		// Quick fix for DK multiple pet error, we only scan the pets section for hunters and warlocks
		if( (strtoupper($data['ClassEn']) == 'HUNTER' || strtoupper($data['ClassEn']) == 'WARLOCK') && isset($data['Pets']) && !empty($data['Pets']) && is_array($data['Pets']) )
		{
			$petsdata = $data['Pets'];
			foreach( $petsdata as $pet )
			{
				$this->update_pet($memberId, $pet);
			}
		}
		else
		{
			$querystr = "DELETE FROM `" . $roster->db->table('pets') . "` WHERE `member_id` = '$memberId';";
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Cannot delete Pet Data',$roster->db->error());
			}

			$querystr = "DELETE FROM `" . $roster->db->table('pet_spellbook') . "` WHERE `member_id` = '$memberId';";
			$result = $roster->db->query($querystr);
			if( !$result )
			{
				$this->setError('Cannot delete Pet Spell Data',$roster->db->error());
			}
		}

		return $memberId;

	} //-END function update_char()
}
