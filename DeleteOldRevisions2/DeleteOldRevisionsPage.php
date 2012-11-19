<?php 
/**
 *  The special page itself.
 */
class DeleteOldRevisionsPage extends SpecialPage {

	private $mRequest, $mSaveprefs, $action;

	public function __construct() {
		// Access is restricted by setting $wgGroupPermissions['sysop']['PurgeRevisions'] = true in config
		// __construct( Page Title, Permission Name )
		parent::__construct( 'DeleteOldRevisions', 'PurgeRevisions' );
	}

    public function execute( $par = null ) {
		global $wgOut, $wgRequest, $wgContLang;
	
		if( !$this->userCanExecute( $this->getUser() ) ) {
			$this->displayRestrictionError();
			return;
		}

        $this->setHeaders();
		
		if ( $this->getUser()->isBlocked() ) {
			$block = $this->getUser()->getBlock();
			throw new UserBlockedError( $block );
		}
		$this->checkReadOnly();
        
		$this->mRequest =& $wgRequest;
		$titleObj = Title::makeTitle( NS_SPECIAL, 'DeleteOldRevisions' );
		$this->action = $titleObj->escapeLocalURL();

		$pagename = $this->mRequest->getText( 'wpPageName', '%' );

		// Get the selected namespace. If it is not in the possible range of values, default to NS_MAIN
		$namespace = $wgRequest->getIntOrNull( 'namespace', NS_MAIN);
		$namespaces = $wgContLang->getNamespaces();
		if( !in_array($namespace, array_keys($namespaces)) ) $namespace = NS_MAIN;

		$maxDateT = $this->mRequest->getText( 'wpMaxDate', date ("Y-m-d") );
		#if ($maxDateT == '') $maxDateT = date ("Y-m-d");

		$boxArchive = $this->mRequest->getCheck( 'wpBoxArchive', false );

		$pagenameText = Xml::element( 'input', array('type'=>'text', 'name'=>'wpPageName', 'size'=>'50', 'maxlength'=>'100', 'value'=>$pagename ));

		$namespaceselect = Xml::namespaceSelector($namespace, '');

		// Date format should look like: 'value' => '2006-05-10'
		$maxdateText = Xml::element( 'input', array('type'=>'text', 'name'=>'wpMaxDate', 'size'=>'10', 'maxlength'=>'10','value' => $maxDateT ));

		$archiveBox = (($boxArchive) ?
			Xml::element( 'input', array('type'=>'checkbox', 'name'=>'wpBoxArchive', 'checked' => 1)) :
			Xml::element( 'input', array('type'=>'checkbox', 'name'=>'wpBoxArchive')));

		$checkdbBox = Xml::element( 'input', array('type'=>'checkbox', 'name'  => 'wpBoxCheckDB'));

		//build HTML-Form
		$wgOut->addHTML(
			"<form name=\"uluser\" action=\"$this->action\" method=\"post\" >" .
			'<table style="margin-bottom: 10px;" cellpadding="2" cellspacing="0">
			<tr>
				<td>
					<b>' . wfMsgHtml('articlepage') . ':</b><br>%: deletes all old article revisions!<br>You can use MySQL-placeholders: "Test%" will find all articles beginning with "Test".
				</td>
				<td style="vertical-align: top;">' . $pagenameText . '</td>
			</tr>
			<tr>
				<td><b><label for="namespace">' . wfMsgHtml('namespace') . "</label></b></td>
				<td>$namespaceselect</td>
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
			</table>" .

			Xml::element( 'input', array(
			'type'  => 'submit',
			'name'  => 'delete',
			'value' => 'Delete Old Revisions',
			'onclick' => "return confirm('Are you sure you want to delete all old revisions? This operation cannot be undone!')"
			)) .

			Xml::element( 'input', array(
			'type'  => 'submit',
			'name'  => 'cmdTest',
			'value' => 'Test Only',
			'checked' => 1)) .
			
			'</form>'
		);


		if( $wgRequest->wasPosted() ) {
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
 * Support function for cleaning up redundant text records
 */
function PurgeRedundantText( $delete = false ) {
    global $wgOut;

    // Data should come off the master, wrapped in a transaction
    $dbw =& wfGetDB( DB_MASTER );
	$dbw->begin(); //http://www.mediawiki.org/wiki/Manual:Database_access#Lock_contention

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

    // Get "active" text records from the archive table
    $wgOut->addHTML( "Searching for active text records in archive table (table archive)... " );
    $res = $dbw->query( "SELECT DISTINCT ar_text_id FROM $tbl_arc" );
    while( $row = $dbw->fetchObject( $res ) ) {
        $cur[] = $row->ar_text_id;
    }
    $wgOut->addHTML( "done.\n" );

    // Get the IDs of all text records not in these sets
    $wgOut->addHTML( "Searching for inactive text records (table text)... " );
    $set = implode( ', ', $cur );
    $res = $dbw->query( "SELECT old_id FROM $tbl_txt WHERE old_id NOT IN ( $set )" );
    while( $row = $dbw->fetchObject( $res ) ) {
        $old[] = $row->old_id;
    }
    $wgOut->addHTML( "done.\n" );

    // Inform the user of what we're going to do
    $count = count( $old );
    $wgOut->addHTML( "$count inactive items found.\n" );

    // Delete as appropriate
    if( $delete && $count ) {
        $wgOut->addHTML( "Deleting... " );
        $set = implode( ', ', $old );
        $dbw->query( "DELETE FROM $tbl_txt WHERE old_id IN ( $set )" );
        $wgOut->addHTML( "done.\n" );
    }

    // Done
	$dbw->commit();
}

/**
 * Support function for deleting old revisions
 */
function DeleteOldRevisions( $delete = false, $pagename = '', $namespace = 0, $maxdate = '', $del_archive = false) {
    global $wgOut,$wgDBtype;
	
	$debug=false;

	// $dbr =& wfGetDB( DB_SLAVE );
	// $test[] = $dbr->tableNamesN( 'page', 'pagelinks', 'templatelinks' );
	// list ( $test2 ) = $dbr->tableNamesN( 'page', 'pagelinks', 'templatelinks' );
	// $wgOut->addHTML( "Test: $test2\n" );
	// return;

	// if ( $delete) {
		// # Data should come off the master, wrapped in a transaction
		$dbw =& wfGetDB( DB_MASTER );
		#$dbw->immediateBegin();
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
  $cur=array();
	while( $row = $dbw->fetchObject( $res ) ) {
		$cur[] = $row->page_latest;
		//$page[] = $row->page_id;
	}
	$count = count( $cur );
	if ($debug) $wgOut->addHTML(implode(', ',$sql)."<br>");
	$wgOut->addHTML( "Old revisions of <b>$count pages</b> need to be checked.\n" );

	if ($count) {

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
			$wgOut->addHTML( count($arc) . " remaining grouped archived pages found (newer date).\n" ); // Notice: Undefined variable: arc in SpecialDeleteOldRevisions2.php on line 253

			// Add all current pages, so we do not delete their logging information
			$sql = "SELECT page_title FROM $tbl_pag WHERE page_title like '$pagename'";
			if ( $namespace != -100 ) $sql .= " AND page_namespace = '$namespace'";
			$res = $dbw->query( $sql );
			while( $row = $dbw->fetchObject( $res ) ) {
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

		// Delete old revisions
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
		  $wgOut->addHTML('<p><strong>' . wfMsg('deleteoldrevisions-removalok') . '</strong></p>');
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
