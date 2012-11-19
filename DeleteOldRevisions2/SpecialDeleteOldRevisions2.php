<?php
/*
    Special:DeleteOldRevisions2 Mediawiki Extension
    Based on original DeleteOldRevisions by Marc Noirot - marc dot noirot at gmail

    This extension adds a special page accessible to sysops only to permanently delete 
	the history from the wiki.
    This extension is adapted from the scripts found in the 'maintenance' folder.

    WARNING: deleting the old revisions is a permanent operation that
    cannot be undone. It is strongly recommended that you back up your database before attempting to use 
	this script.
	
	LICENSE: This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or 
	(at your option) any later version.
	http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later

	------

	INSTALLATION:
	* put this directory in your extension folder
	* put the following lines near the end of LocalSettings.php
		// Extension DeleteOldRevisions
		$wgGroupPermissions['sysop']['DeleteOldRevisions'] = true;
		include_once('extensions/SpecialDeleteOldRevisions/SpecialDeleteOldRevisions2.php');


	CHANGELOG:
	V1.2 Feb. 11, 2007:  Gunter Schmidt
		Runs only with 1.8 and above (I think)
		Added deletion of recent changes, text and archive table
		Added selection on specific article name, namespace and date of revision
		Fixed bug with moved articles

	V1.3 Feb. 24, 2007: Gunter Schmidt
		Small interface changes
		Bug with articles containing ' resolved
		Permission reworked

	V1.4 Nov. 12, 2008: Jehy
		Took maintenance, fixed bugs for mediawiki 1.13

	v1.4.1 March 23, 2010: Jehy
		Fixed for last wiki release. Thanks to http://www.mediawiki.org/wiki/User:Athinker
		Changed class name from "HTMLForm" to "DeleteOldRevisions_HTMLForm" for compatibility with other plugins
		Also fixed one timestamp bug.
		
	v1.4.2 ???
		Some very anonymous guy made several fixes. Looked through those - those are fine. Also, he added French translation. Thanks to him.
		
	v1.4.3 September 12, 2010: Jehy
		Several little fixes: for strict warning, page title. Added automatic table optimization.
		
	v1.4.4 September 21, 2010: Jehy
		Added MySQL database detection to avoid crashes when using other databases. 
		Fixed setting wrong page title for pages other then extension's. Also, replaced "distinctrow" with "distinct" for compatibility with Postgres.
		
	v1.5 September 20, 2012: Jehy
		Fixed compatibility for mediawiki 1.19
		
	v1.6 September 21, 2012: Philip Nicolcev
		Removed reference to DeleteOldRevisions_HTMLForm.php since it wasn't being used.
		Implemented i18n language support.
		Renamed main file to SpecialDeleteOldRevisions2 and the SpecialPage class file to DeleteOldRevisionsPage.
		Misc cleanup.
    
	v1.6.1 November 19, 2012: jehy
		Fixed description to show i18n string, added russian language support.
		
	TODO:
	Delete groups of 100 objects - SQL query too long
	Option to delete images, delete corresponding pictures

*/

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

$dir = dirname(__FILE__) . '/';

$wgExtensionMessagesFiles['DeleteOldRevisions'] = $dir . 'DeleteOldRevisions.i18n.php';

$wgGroupPermissions['sysop']['PurgeRevisions'] = true;
$wgAvailableRights[] = 'PurgeRevisions';

$wgAutoloadClasses['DeleteOldRevisionsPage'] = $dir . 'DeleteOldRevisionsPage.php';
$wgSpecialPages['DeleteOldRevisions'] = 'DeleteOldRevisionsPage';
$wgSpecialPageGroups['DeleteOldRevisions'] = 'pagetools';

$wgExtensionCredits['specialpage'][] = array(
	'path'			=> __FILE__,
	'name'			=> 'DeleteOldRevisions',
	'descriptionmsg'	=> 'deleteoldrevisions-desc',
	'url' 			=> 'http://www.mediawiki.org/wiki/Extension:SpecialDeleteOldRevisions2',
	'author' 		=> array( 'Marc Noirot','Gunter Schmidt','[http://jehy.ru/wiki-extensions.en.html Jehy]','[http://cyclical.ca/ Philip Nicolcev]' ),
	'version' 		=> '1.6.1'
);
