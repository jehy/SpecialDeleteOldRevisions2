<?php
/*
    Special:DeleteOldRevisions Mediawiki Extension
    By Marc Noirot - marc dot noirot at gmail
    Currently maintained by Jehy - http://jehy.ru
    This extension adds a special page accessible to sysops only
    to permanently delete the history from the wiki.
    This extension is adapted from the scripts found in the 'maintenance'
    folder.

    WARNING: deleting the old revisions is a permanent operation that
    cannot be undone.
    It is strongly recommended that you back up your database before
    attempting to use this script.

   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; either version 2 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License along
   with this program; if not, write to the Free Software Foundation, Inc.,
   51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
   http://www.gnu.org/copyleft/gpl.html

   ------

   Installation:
	* put this directory in your extension folder
	*put the following lines near the end of LocalSettings.php
	// Extension DeleteOldRevisions
	$wgGroupPermissions['sysop']['DeleteOldRevisions'] = true;
	include_once('extensions/SpecialDeleteOldRevisions/SpecialDeleteOldRevisions.php');


   Change-Log:
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
		Added MySQL database detection to avoid crashes when using other databases. Fixed setting wrong page title for pages other then extension's. Also, replaced "distinctrow" with "distinct" for compatibility with Postgres.
	v1.5 September 21, 2010: Jehy
		Fixed compatibility for mediawiki 1.19
		
	ToDo:
		delete groups of 100 objects - SQL query too long
		X to delete images
		
		Delete corresponding pictures
		Delete consecutive user edits within a day after 3 month
		International Messages
		Do not question, if test only.


*/
$wgExtensionCredits['specialpage'][] = array(
	'path'=>__FILE__,
        'name' => 'Special:DeleteOldRevisions',
        'description' => 'adds a [[Special:DeleteOldRevisions|special page]] to DeleteOldRevisions of articles',
        'url' => 'http://jehy.ru/wiki-extensions.en.html',
        'author' => 'Marc Noirot, Gunter Schmidt, Jehy http://jehy.ru/index.en.html',
        'version' => '1.5'
);

$wgSpecialPages['DeleteOldRevisions'] = 'SpecialDeleteOldRevisions2';
$wgAutoloadClasses['SpecialDeleteOldRevisions2'] 		= dirname( __FILE__ ) . '/SpecialDeleteOldRevisions2.php';
$wgAvailableRights[] = 'DeleteOldRevisions';
#$wgGroupPermissions['bureaucrat']['deleteoldrevisions'] = true;
$wgGroupPermissions['sysop']['DeleteOldRevisions'] = true;//sysop only!
?>
