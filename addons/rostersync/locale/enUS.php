<?php

// Button names
$lang['rsync_button1']	= 'Character Update|Synchronize your character with Blizzard\'s Armory';
$lang['rsync_button2']	= 'Guild Members Update|Synchronize your guild\'s characters with Blizzard\'s Armory';
$lang['rsync_button3']	= 'Add A Guild|Update your guild with Blizzard\'s Armory';
$lang['rsync_button4']	= 'Memberslist Update|Update your memberlist with Blizzard\'s Armory';


$lang['admin']['rsync_conf']			= 'RosterSync Config';
$lang['admin']['rsync_ranks']			= 'Sync ranks';
$lang['admin']['rsync_scaning']			= 'Scanning settings';
$lang['admin']['rsync_scan_guild']		= 'Guild Fields';
$lang['admin']['rsync_scan_char']		= 'Char Fields';
$lang['admin']['rsync_access']			= 'Update Access';
$lang['admin']['rsync_debug']			= 'Debug Settings';

$lang['admin']['rsync_host']			= 'Host|Host to Synchronize with';
$lang['admin']['rsync_minlevel']		= 'Minimum Level|Minimum level of characters to synchronize<br>Currently this should be no lower than 10 since<br>the armory doesn\'t list characters lower than level 10';
$lang['admin']['rsync_synchcutofftime']	= 'Sync cutoff time|Time in days<br>All characters not updated in last (x) days will be synchronized';
$lang['admin']['rsync_use_ajax']		= 'Use AJAX|Whether to use AJAX for status update or not.';
$lang['admin']['rsync_reloadwaittime']	= 'Reload wait time|Time in seconds<br>Time in seconds before next synchronization during a sync job 24+ recommended';
$lang['admin']['rsync_fetch_timeout'] 	= 'Armory Fetch timeout|Time in seconds till a fetch of a single XML file is aborted.';
$lang['admin']['rsync_skip_start']		= 'Skip start page|Skip start page on Roster Sync updates.';
$lang['admin']['rsync_status_hide'] 	= 'Hide status windows initially|Hide the status windows of Roster Sync on the first load.';
$lang['admin']['rsync_protectedtitle']	= 'Protected Guild Title|Characters with these guild titles are protected<br />from being deleted by a synchronization against the armory.<br />This problem often occours with bank characters.<br />Multiple values seperated by a comma (,) \"Banker,Stock\"';

$lang['admin']['rsync_scaning']			= 'Scaning Settings';
$lang['admin']['rsync_MinLvl']			= 'Min Level';
$lang['admin']['rsync_MaxLvl']			= 'Max Level';
$lang['admin']['rsync_Rank']			= 'Ranks';
$lang['admin']['rsync_Class']			= 'Classes';

$lang['admin']['rsync_rank_set_order']	= "Guild Rank Set Order|Defines in which order the guild titles will be set.";
$lang['admin']['rsync_rank_0']			= "Title Guild Leader|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_1']			= "Title Rank 1|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_2']			= "Title Rank 2|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_3']			= "Title Rank 3|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_4']			= "Title Rank 4|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_5']			= "Title Rank 5|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_6']			= "Title Rank 6|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_7']			= "Title Rank 7|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_8']			= "Title Rank 8|This title will be set if in WoWRoster for that guild none is defined.";
$lang['admin']['rsync_rank_9']			= "Title Rank 9|This title will be set if in WoWRoster for that guild none is defined.";


/*
Player scan settings
*/
$lang['admin']['rsync_char_achievements']	= 'Achievements|A map of achievement data including completion timestamps and criteria information.';
$lang['admin']['rsync_char_appearance']		= 'Appearance|A map of values that describes the face, features and helm/cloak display preferences and attributes.';
$lang['admin']['rsync_char_feed']			= 'Activity Feed|The activity feed of the character.';
$lang['admin']['rsync_char_guild']			= 'Guild Data|A summary of the guild that the character belongs to. If the character does not belong to a guild and this field is requested, this field will not be exposed.';
$lang['admin']['rsync_char_hunterPets']		= 'Hunter Pets|A list of all of the combat pets obtained by the character.';
$lang['admin']['rsync_char_items']			= 'Equipment|A list of items equipted by the character. Use of this field will also include the average item level and average item level equipped for the character.';
$lang['admin']['rsync_char_mounts']			= 'Mounts|A list of all of the mounts obtained by the character.';
$lang['admin']['rsync_char_pets']			= 'Pets|A list of the battle pets obtained by the character.';
$lang['admin']['rsync_char_petSlots']		= 'Battle Pets|Data about the current battle pet slots on this characters account.';
$lang['admin']['rsync_char_professions']	= 'professions|A list of the character\'s professions. It is important to note that when this information is retrieved, it will also include the known recipes of each of the listed professions.';
$lang['admin']['rsync_char_progression']	= 'Raid Progress|A list of raids and bosses indicating raid progression and completedness.';
$lang['admin']['rsync_char_pvp']			= 'PvP|A map of pvp information including arena team membership and rated battlegrounds information.';
$lang['admin']['rsync_char_quests']			= 'Quest data|A list of quests completed by the character.';
$lang['admin']['rsync_char_reputation']		= 'Reputation|A list of the factions that the character has an associated reputation with.';
$lang['admin']['rsync_char_stats']			= 'Stats|A map of character attributes and stats.';
$lang['admin']['rsync_char_talents']		= 'Talents|A list of talent structures.';
$lang['admin']['rsync_char_titles']			= 'Titles|A list of the titles obtained by the character including the currently selected title.';
$lang['admin']['rsync_char_audit']			= 'Audit|Raw character audit data that powers the character audit on the game site';
/*
	guild scan settings
*/
$lang['admin']['rsync_guild_members']		= 'Members|A list of characters that are a member of the guild';
$lang['admin']['rsync_guild_achievements']	= 'Achievements|A set of data structures that describe the achievements earned by the guild.';
$lang['admin']['rsync_guild_news']			= 'News|A set of data structures that describe the news feed of the guild.';
$lang['admin']['rsync_guild_challenge']		= 'Challenges|The top 3 challenge mode guild run times for each challenge mode map.';

/*
Debug Info
*/
$lang['admin']['rsync_debuglevel']		= 'Debug Level|Adjust the debug level for Roster Sync.<br /><br />Quiete - No Messages<br />Base Info - Base messages<br />Armory & Job Method Info - All messages of Armory and Job methods<br />All Methods Info - Messages of all Methods  <b style="color:red;">(Be careful - very much data)</b>';
$lang['admin']['rsync_debugdata']		= 'Debug Data|Raise debug output by methods arguments and returns<br /><b style="color:red;">(Be careful - much more info on high debug level)</b>';
$lang['admin']['rsync_javadebug']		= 'Debug Java|Enable JavaScript debugging.<br />Not implemented yet.';
$lang['admin']['rsync_xdebug_php']		= 'XDebug Session PHP|Enable sending XDEBUG variable with POST.';
$lang['admin']['rsync_xdebug_ajax']		= 'XDebug Session AJAX|Enable sending XDEBUG variable with AJAX POST.';
$lang['admin']['rsync_xdebug_idekey']	= 'XDebug Session IDEKEY|Define IDEKEY for Xdebug sessions.';
$lang['admin']['rsync_sqldebug']		= 'SQL Debug|Enable SQL debugging for Roster Sync.<br />Not useful in combination with roster SQL debugging / duplicate data.';
$lang['admin']['rsync_updateroster']	= "Update Roster|Enable roster updates.<br />Good for debugging<br />Not implemented yet.";


/*
update access
*/
$lang['admin']['rsync_char_update_access'] 				= 'Char Update Access|Who is able to do character updates';
$lang['admin']['rsync_guild_update_access'] 			= 'Guild Update Access|Who is able to do guild updates';
$lang['admin']['rsync_guild_memberlist_update_access'] 	= 'Guild Memberlist Update Access|Who is able to do guild memberlist updates';
$lang['admin']['rsync_realm_update_access'] 			= 'Realm Update Access|Who is able to do realm updates';
$lang['admin']['rsync_guild_add_access'] 				= 'Guild Add Access|Who is able to add new guilds';

$lang['start'] = "Start";
$lang['start_message'] = "You're about to start Roster Sync for %s.<br><br>By doing this, all data for %s will be overwritten<br />with details from Blizzard Api. This can only be undone<br />by uploading a current wowroster.lua.<br /><br />Do you want to start this process yet?";

$lang['start_message_the_char']			= "the character";
$lang['start_message_this_char']		= "this character";
$lang['start_message_the_guild']		= "the guild";
$lang['start_message_this_guild']		= "all characters of this guild";
$lang['start_message_the_memberlist']	= "the Guild Memberslist";
$lang['start_message_this_memberlist']	= "the guild memberslist";

$lang['start_message_the_profile']		= "Profiles";
$lang['start_message_the_gprofile']		= "Guild profiles";
$lang['start_message_the_addguild']		= "Adding a Guild";
$lang['start_message_the_memberlist']	= "Guild Memberslist";

$lang['start_message_this_profile']		= "this character";
$lang['start_message_this_gprofile']	= "the guild characters";
$lang['start_message_this_memberlist']	= "the guild members";
$lang['start_message_this_addguild']	= "Guild data for this guild";














