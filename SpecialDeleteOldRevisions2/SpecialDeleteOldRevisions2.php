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

$SDOR_Version = 'Version 1.5, 2012-09-18';

if (!defined('MEDIAWIKI')) die();
$wgExtensionFunctions[] = "wfExtensionSpecialDeleteOldRevisions";
$wgExtensionCredits['specialpage'][] = array(
        'name' => 'Special:DeleteOldRevisions',
        'description' => 'adds a [[Special:DeleteOldRevisions|special page]] to DeleteOldRevisions of articles',
        'url' => 'http://jehy.ru/wiki-extensions.en.html',
        'author' => 'Marc Noirot, Gunter Schmidt, Jehy http://jehy.ru/index.en.html',
        'version' => $SDOR_Version
);

require_once 'DeleteOldRevisions_HTMLForm.php';
require_once $IP.'/includes/SpecialPage.php';

/**
 * Support function for cleaning up redundant text records
 */
function PurgeRedundantText( $delete = false ) {
    global $wgOut;

    # Data should come off the master, wrapped in a transaction
    $dbw =wfGetDB( DB_MASTER );
    //$dbw->begin(); //http://www.mediawiki.org/wiki/Manual:Database_access#Lock_contention
	$dbw->begin();

    $tbl_arc = $dbw->tableName( 'archive' );
    $tbl_rev = $dbw->tableName( 'revision' );
    $tbl_txt = $dbw->tableName( 'text' );

    # Get "active" text records from the revisions table
    $wgOut->addHTML("Searching for active text records in revisions table (table revision)... ");
    $res = $dbw->query( "SELECT DISTINCT rev_text_id FROM $tbl_rev" );
    while( $row = $dbw->fetchObject( $res ) ) {
        $cur[] = $row->rev_text_id;
    }
    $wgOut->addHTML( "done.\n" );

    # Get "active" text records from the archive table
    $wgOut->addHTML( "Searching for active text records in archive table (table archive)... " );
    $res = $dbw->query( "SELECT DISTINCT ar_text_id FROM $tbl_arc" );
    while( $row = $dbw->fetchObject( $res ) ) {
        $cur[] = $row->ar_text_id;
    }
    $wgOut->addHTML( "done.\n" );

    # Get the IDs of all text records not in these sets
    $wgOut->addHTML( "Searching for inactive text records (table text)... " );
    $set = implode( ', ', $cur );
    $res = $dbw->query( "SELECT old_id FROM $tbl_txt WHERE old_id NOT IN ( $set )" );
    while( $row = $dbw->fetchObject( $res ) ) {
        $old[] = $row->old_id;
    }
    $wgOut->addHTML( "done.\n" );

    # Inform the user of what we're going to do
    $count = count( $old );
    $wgOut->addHTML( "$count inactive items found.\n" );

    # Delete as appropriate
    if( $delete && $count ) {
        $wgOut->addHTML( "Deleting... " );
        $set = implode( ', ', $old );
        $dbw->query( "DELETE FROM $tbl_txt WHERE old_id IN ( $set )" );
        $wgOut->addHTML( "done.\n" );
    }

    # Done
    //$dbw->commit();
	$dbw->commit();
}

/**
 * Support function for deleting old revisions
 */
#function DeleteOldRevisions( $delete = false, $pagename = '', $namespace = -100, $maxdate = '', $del_archive = false) {
function DeleteOldRevisions( $delete = false, $pagename = '', $namespace = 0, $maxdate = '', $del_archive = false) {
    global $wgOut,$wgDBtype;
	$wgOut->setPageTitle( 'Special Page:DeleteOldRevisions' );
	$debug=false;

	// $dbr =& wfGetDB( DB_SLAVE );
	// $test[] = $dbr->tableNamesN( 'page', 'pagelinks', 'templatelinks' );
	// list ( $test2 ) = $dbr->tableNamesN( 'page', 'pagelinks', 'templatelinks' );
	// $wgOut->addHTML( "Test: $test2\n" );
	// return;

	// if ( $delete) {
		// # Data should come off the master, wrapped in a transaction
		$dbw =wfGetDB( DB_MASTER );
		#$dbw->begin();
	// }
	// else
		// $dbw =& wfGetDB( DB_SLAVE );

    $tbl_pag = $dbw->tableName( 'page' );
    $tbl_rev = $dbw->tableName( 'revision' );
    $tbl_rec = $dbw->tableName( 'recentchanges' );
    $tbl_txt = $dbw->tableName( 'text' );
    $tbl_arc = $dbw->tableName( 'archive' );
	$tbl_log = $dbw->tableName( 'logging' );

	#Select data

	# Get "active" revisions from the page table
	$sql = array("page_title LIKE '$pagename'");
	if ( $namespace != -100 ) $sql += array('page_namespace' => $namespace);
	#$res = $dbw->select( array('page'), array('page_id', 'page_latest'), $sql );
	$res = $dbw->select( array('page'), array('page_latest'), $sql );
	while( $row = $dbw->fetchObject( $res ) ) {
		$cur[] = $row->page_latest;
		//$page[] = $row->page_id;
	}
	$count = count( $cur );
	if ($debug) $wgOut->addHTML(implode(', ',$sql)."<br>");
	$wgOut->addHTML( "Old revisions of <b>$count pages</b> need to be checked.\n" );

	if ($count) {

		// # Get all old revisions that belong to the article
		// $set = implode( ', ', $cur );
		// $pset = implode( ', ', $page );
		// #$res = $dbw->query( "SELECT rev_id FROM $tbl_rev WHERE rev_id NOT IN ( $set ) AND rev_page IN ( $pset) AND rev_timestamp <= '$maxdate'" );
		// $res = $dbw->select(array('revision'), array('rev_id'), array("rev_id NOT IN ( $set ) AND rev_page IN ( $pset) AND rev_timestamp <= '$maxdate'"));
		// while( $row = $dbw->fetchObject( $res ) ) {
			// $old[] = $row->rev_id;
		// }
		// #if ($debug) $wgOut->addHTML(implode(', ',$sql)."<br>");
		// $wgOut->addHTML( "<b>" . count( $old) . " old revisions found.</b>\n" );
		// unset($pset); // free some memory
		//unset ($old);

		# Get all old revisions that belong to the article
		$set = implode( ', ', $cur );
		$sql = array("rev_id NOT IN ( $set ) AND rev_timestamp <= '$maxdate'");
		$sql[] = "rev_page = page_id AND page_title LIKE '$pagename'";
		if ( $namespace != -100 ) $sql += array('page_namespace' => $namespace);
		$res = $dbw->select(array('revision', 'page'), array('rev_id'), $sql );
		while( $row = $dbw->fetchObject( $res ) ) {
			$old[] = $row->rev_id;
		}
		if ($debug) $wgOut->addHTML(implode(', ',$sql)."<br>");
		$wgOut->addHTML( "<b>" . count( $old) . " old revisions found.</b>\n" );
		if (count( $old)) {
			asort($old);
			if ($debug) $wgOut->addHTML(implode(', ',$old)."<br>");
		}

	}

	# archive table
	if ($del_archive) {

		$sql = array("ar_title like '$pagename'");
		if ( $namespace != -100 ) $sql += array('ar_namespace'=> $namespace);
		if ( $maxdate != '' ) $sql[] = "ar_timestamp <= '$maxdate'";
		$res = $dbw->select( array('archive'), array('ar_text_id'), $sql );
		while( $row = $dbw->fetchObject( $res ) ) {
			$arc[] = $row->ar_text_id;
		}
		if ($debug) $wgOut->addHTML(implode(', ',$sql)."<br>");
		$wgOut->addHTML( "<b>" . count($arc) . " archived pages found.</b>\n" );

	}

    # Delete selection
    if( $delete ) {

		$dbw->begin();

		// archive
		if (count( $arc )) {
			$wgOut->addHTML( "\nDeleting data!\n\nDeleting deleted (archived) articles:\n" );
			$set = implode( ', ', $arc );
			// delete text for archive entries
			$res = $dbw->query( "SELECT COUNT(*) AS C FROM $tbl_txt WHERE old_id IN ( $set )" );
			$row = $dbw->fetchObject( $res );
			$count = $row->C;
			$dbw->query( "DELETE FROM $tbl_txt WHERE old_id IN ( $set )" );
			$wgOut->addHTML( "Deleted $count texts for archived revisions (table text).\n" );

			// delete archive
			$sql = "ar_title like '$pagename'";
			if ( $namespace != -100 ) $sql .= " AND ar_namespace = '$namespace'";
			if ( $maxdate != '' ) $sql .= " AND ar_timestamp <= '$maxdate'";
			$res = $dbw->query( "SELECT COUNT(*) AS C FROM $tbl_arc WHERE " . $sql);
			$row = $dbw->fetchObject( $res );
			$count = $row->C;
			$dbw->query( "DELETE FROM $tbl_arc WHERE " . $sql );
			$wgOut->addHTML( "Deleted $count archived revisions (table archive).\n" );
		}

		if ($del_archive && count ($arc)) {
			//delete logging
			// Since we may not delete all archived texts (maxdate), we need to find all pages that have not been deleted.
			$arc = null;
			$sql = "SELECT ar_title FROM $tbl_arc WHERE ar_title like '$pagename'";
			if ( $namespace != -100 ) $sql .= " AND ar_namespace = '$namespace'";
			// if ( $maxdate != '' ) $sql .= " AND ar_timestamp <= '$maxdate'"; // no, must not be included
			$sql .= " GROUP BY ar_title";
			$res = $dbw->query( $sql );
			while( $row = $dbw->fetchObject( $res ) ) {
				$arc[] = "'" . $row->ar_title . "'";
			}
			$wgOut->addHTML( count($arc) . " remaining grouped archived pages found (newer date).\n" );

			// Add all current pages, so we do not delete their logging information
			$sql = "SELECT page_title FROM $tbl_pag WHERE page_title like '$pagename'";
			if ( $namespace != -100 ) $sql .= " AND page_namespace = '$namespace'";
			$res = $dbw->query( $sql );
			while( $row = $dbw->fetchObject( $res ) ) {
				#$arc[] = "'" . $row->page_title . "'";
				$arc[] = $dbw->addQuotes($row->page_title);
			}
			if ($debug) $wgOut->addWikiText("<"."pre>$sql<"."/pre>");
			$wgOut->addHTML( count($arc) . " remaining grouped archived and current text pages found.\n" );

			if ( count($arc) ) {
				// delete only some pages, keep the ones we still have in the archive table
				$set = implode( ', ', $arc );
				$sql = "log_type = 'delete' AND log_title like '$pagename' AND log_title NOT IN ( $set ) AND log_namespace = '$namespace'";
			}
			else
				// delete all pages, since we do not have any in table archive
				$sql = "log_type = 'delete' AND log_title like '$pagename' AND log_namespace = '$namespace'";

			if ( $maxdate != '' ) $sql .= " AND log_timestamp <= '$maxdate'";
			$res = $dbw->query( "SELECT COUNT(*) AS C FROM $tbl_log WHERE " . $sql );
			$row = $dbw->fetchObject( $res );
			$count = $row->C;
			$res = $dbw->query( "DELETE FROM $tbl_log WHERE " . $sql );
			if ($debug) $wgOut->addWikiText("<"."pre>$sql<"."/pre>");
			$wgOut->addHTML( "Deleted $count logs for archived revisions (table logging).\n" );

		}

		// DeleteOldRevisions
		$wgOut->addHTML( "\nDeleting old revisions:\n" );

		if (count( $old )) {
			$set = implode( ', ', $old );

			// recent changes
	        #$wgOut->addHTML( "Deleting recent changes (table recentchanges)... " );
	        $dbw->query( "DELETE FROM $tbl_rec WHERE rc_this_oldid IN ( $set )" );

			// find corresponding texts
		    $res = $dbw->query( "SELECT rev_text_id FROM $tbl_rev WHERE rev_id IN ( $set )" );
		    while( $row = $dbw->fetchObject( $res ) ) {
		        $oldText[] = $row->rev_text_id;
		    }

			// delete revisions
			$wgOut->addHTML( "Deleting revisions (table revision)... " );
			$dbw->query( "DELETE FROM $tbl_rev WHERE rev_id IN ( $set )" );
			$wgOut->addHTML( "done.\n" );
		}

		// delete found texts
		$count = count( $oldText );
		$wgOut->addHTML( "<b>$count old texts found.</b>\n" );
		if( $count ) {
	        $set = implode( ', ', $oldText );

			// watch out for moved articles
			if (count ($cur)) {
				$set2 = implode( ', ', $cur );
				$sql .= " AND rev_id NOT IN ( $set2 )"; // otherwise we delete the text of moved articles
			    $res = $dbw->query( "SELECT rev_text_id FROM $tbl_rev WHERE rev_id IN ( $set2 )" );
			    while( $row = $dbw->fetchObject( $res ) ) {
			        $curText[] = $row->rev_text_id;
				}
			}

			$sql = "old_id IN ( $set )";
			if ( count ($curText) ) {
				$set2 = implode( ', ', $curText );
				$sql .= " AND old_id NOT IN ( $set2 )";
			}
			$res = $dbw->query( "SELECT COUNT(*) AS C FROM $tbl_txt WHERE " . $sql);
			$row = $dbw->fetchObject( $res );
			$count = $row->C;
			$dbw->query( "DELETE FROM $tbl_txt WHERE " . $sql );
			$wgOut->addHTML( "Deleted $count texts (table text).\n" );

		}
		$dbw->commit();

		// finished message
		if($wgDBtype== "mysql")
		{
		  $wgOut->addHTML('MySQL database detected.');
		  $wgOut->addHTML('<p><strong>' . wfMsg('The old revisions were successfully deleted.') . '</strong></p>');
		  $wgOut->addHTML('Optimizing tables...');
		  $res = $dbw->query('show tables');
		  while( $row = $dbw->fetchRow( $res ) )
		    $dbw->query('OPTIMIZE TABLE '.$row[0]);
		  $wgOut->addHTML('Tables optimized.');
		}
	}
	else {
		$wgOut->addHTML('<p><strong>' . 'No changes have been made.' . '</strong></p>');
	}

}

/**
 *  The simple form used to delete the revisions.
 */
class DeleteOldRevisionsForm extends DeleteOldRevisions_HTMLForm {

    var $mPosted, $mRequest, $mSaveprefs, $action;

    function DeleteOldRevisionsForm($request) {
        $this->mPosted = $request->wasPosted();
        $this->mRequest =& $request;
        $this->mName = "DeleteOldRevisions";
        $titleObj = Title::makeTitle( NS_SPECIAL, 'DeleteOldRevisions' );
        $this->action = $titleObj->escapeLocalURL();
    }

    function execute($par = null) {
        global $wgOut, $wgRequest, $wgContLang, $SDOR_Version;

		$pagename = $this->mRequest->getText( 'wpPageName', '%' );

		$namespace = $wgRequest->getIntOrNull( 'namespace', NS_MAIN);
		$namespaces = $wgContLang->getNamespaces();
		if( !in_array($namespace, array_keys($namespaces)) ) $namespace = NS_MAIN;

		$maxDateT = $this->mRequest->getText( 'wpMaxDate', date ("Y-m-d") );
		#if ($maxDateT == '') $maxDateT = date ("Y-m-d");

		#$boxArchive = 'checked'; #$this->mRequest->getCheck( 'wpBoxArchive' )
		$boxArchive = $this->mRequest->getCheck( 'wpBoxArchive', false );

		#if ($this->mRequest->getCheck( 'wpBoxCheckDB' )) {

		$pagenameText = Xml::element( 'input', array('type'  => 'text', 'name'  => 'wpPageName', 'size'  => '50', 'maxlength' => '100',
                        'value' => $pagename
						));

		$namespaceselect = Xml::namespaceSelector($namespace, '');

		$maxdateText = Xml::element( 'input', array('type'  => 'text', 'name'  => 'wpMaxDate', 'size'  => '10', 'maxlength' => '10',
						'value' => $maxDateT
						#'value' => '2006-05-10'
						));

		#$boxArchive = true;
		$archiveBox = (($boxArchive) ?
			Xml::element( 'input', array('type'  => 'checkbox', 'name'  => 'wpBoxArchive', 'checked' => 1)) :
			Xml::element( 'input', array('type'  => 'checkbox', 'name'  => 'wpBoxArchive')));

		$checkdbBox = Xml::element( 'input', array('type'  => 'checkbox', 'name'  => 'wpBoxCheckDB',
						#'checked' => 'checked'
						));

		//build HTML-Form
		#								<p><b>" . wfMsgHtml('articlepage') . ":</b><br>%: deletes all old article revisions!<br>(Please use name from browser address line, special characters are not converted)<br>You can use MySQL-placeholders: \"Test%\" will find all articles beginning with \"Test\". </p>
		#"onsubmit=\"return confirm('" . wfMsg(' sure you want to delete all the selected revisions ?\nThis operation cannot be undone.') . "')\"
        $wgOut->addHTML("<form name=\"uluser\" action=\"$this->action\" method=\"post\" >\n" .
						#'<p>' . wfMsg('Click on 'Delete' to delete the selected wiki's old revisions.') . '</p>' .
						"<table style=\"text-align: left; width: 100%;\" border=\"0\" cellpadding=\"2\" cellspacing=\"0\">
						<tbody>
						<tr>
							<td>
								<p><b>" . wfMsgHtml('articlepage') . ":</b><br>%: deletes all old article revisions!<br>You can use MySQL-placeholders: \"Test%\" will find all articles beginning with \"Test\". </p>
							</td>
							<td valign = \"Top\">$pagenameText</td>
						</tr>

						<tr>
							<td align='left'><b><label for='namespace'>" . wfMsgHtml('namespace') . "</label></b></td>
							<td align='left'>$namespaceselect</td>
						</tr>

						<tr>
							<td><b>Up to date</b> (yyyy-mm-dd):</td>
							<td>$maxdateText</td>
						</tr>

						<tr>
							<td>Delete <b>archived articles</b>, too:</td>
							<td>$archiveBox</td>
						</tr>

						<tr>
							<td>Run DB-Integrity Check: </td>
							<td>$checkdbBox</td>
						</tr>

						</tbody></table><br>" .

						Xml::element( 'input', array(
                        'type'  => 'submit',
                        'name'  => 'delete',
                        'value' => ('Delete history'),
						'onclick' => "return confirm('" . ('Are you sure you want to delete all the selected revisions ?\nThis operation cannot be undone.') . "')"
						)) .

						Xml::element( 'input', array(
                        'type'  => 'submit',
                        'name'  => 'cmdTest',
                        'value' => 'Test only',
						'checked' => 1)) .

						'<p><br>Extension ' . $SDOR_Version . '</p>' .

						"</form>\n"
						);


        if( $this->mPosted ) {
            global $wgOut;
			$cmdTest = $this->mRequest->getCheck( 'cmdTest' );
			#$cmdTest = true; //Debug
			$pagename = $this->mRequest->getText( 'wpPageName' );
			// some conversion for the database name, should be somewhere in MediaWiki...
			$pagename = str_replace(" ", "_", $pagename);
			$pagename = str_replace("'", "''", $pagename);

			$namespace = $wgRequest->getIntOrNull( 'namespace' );
			if (is_null( $namespace )) $namespace = -100;
			$maxDate = str_replace('-','',$this->mRequest->getText( 'wpMaxDate' )) . '235959';
			if (strlen($maxDate)>14) $maxDate = substr($maxDate,0,14);

            $wgOut->addHTML('<pre>');
			$delInfo = ($cmdTest) ? "Test only" : "Delete";
			$wgOut->addHTML('Article name: ' . $pagename . "<br>Namespace: " . $namespace . "<br>Modus: " . $delInfo . "\n\n" );
			if ($pagename != '')
				DeleteOldRevisions(!$cmdTest, $pagename, $namespace, $maxDate, $this->mRequest->getCheck( 'wpBoxArchive' ));
			else
				$wgOut->addHTML('Article name must not be empty!');
            $wgOut->addHTML('</pre>');


			if ($this->mRequest->getCheck( 'wpBoxCheckDB' )) {
			    # Purge redundant text records (integrity check)
				$wgOut->addHTML('<pre>');
			    PurgeRedundantText( true );
				$wgOut->addHTML('</pre>');
			}
        }
    }
}

/**
 *  The special page itself.
 */
class DeleteOldRevisionsPage extends SpecialPage {

    function DeleteOldRevisionsPage() {
        SpecialPage::SpecialPage('DeleteOldRevisions', 'DeleteOldRevisions'); 
    }

    function execute() {
        global $wgUser, $wgOut;

		if ( ! $wgUser->isAllowed("DeleteOldRevisions") ) {
			$wgOut->permissionRequired( 'DeleteOldRevisions' );
			return;
		}

        $this->setHeaders();

        global $wgRequest;
        $form = new DeleteOldRevisionsForm($wgRequest);
        $form->execute();
    }
}

/**
 *  The extension entry-point.
 *  Supported languages: french and english.
 */
function wfExtensionSpecialDeleteOldRevisions() {
    global $wgMessageCache,$wgOut;	
    /*$wgMessageCache->addMessage('DeleteOldRevisions', 'DeleteOldRevisions');
    $wgMessageCache->addMessage('Click on 'Delete' to delete the selected wiki's old revisions.', 'Click on \'Delete\' to delete the selected wiki\'s old revisions.');
    $wgMessageCache->addMessage('Delete history', 'Delete history');
    $wgMessageCache->addMessage('Are you sure you want to delete all the selected revisions ?\nThis operation cannot be undone.', "Are you sure you want to delete all the selected revisions ?\\nThis operation cannot be undone.");
    $wgMessageCache->addMessage('The old revisions were successfully deleted.', "The old revisions were successfully deleted.");
*/
    SpecialPage::addPage(new DeleteOldRevisionsPage());
}
?>
