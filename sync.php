<?php

/**
 * Google Contacts Syncer
 * by Alex Mills (http://www.viper007bond.com/)
 *
 * This script copies all contacts from Google account A to
 * Google account B, wiping out all existing contacts in
 * account B's account in the process.
 *
 * I wrote this script in June 2010 so that I could sync the
 * contacts from my Google Apps account into my regular GMail
 * account that was tied to my Google Voice account.
 *
 * As far as I know, it still works. Just stick the Google
 * Zend library in a "Zend" subfolder. Download it here:
 *
 * http://framework.zend.com/download/gdata
 */

set_time_limit( 0 );

function message( $message ) {
	echo "$message\n";
	ob_flush();
	flush();
}

// Source account (read-only)
$source_user = 'user1@gmail.com';
$source_pass = 'passwordhere';

// Destination account (WARNING!! ALL EXISTING CONTACTS WILL BE DELETED!)
$dest_user = 'user2@gmail.com';
$dest_pass = 'passwordhere';

// Load Zend Gdata libraries
require_once( 'Zend/Loader.php' );
Zend_Loader::loadClass( 'Zend_Gdata' );
Zend_Loader::loadClass( 'Zend_Gdata_ClientLogin' );
Zend_Loader::loadClass( 'Zend_Http_Client' );
Zend_Loader::loadClass( 'Zend_Gdata_Query' );
Zend_Loader::loadClass( 'Zend_Gdata_Feed' );

// Perform source login
$source_client = Zend_Gdata_ClientLogin::getHttpClient( $source_user, $source_pass, 'cp' );
$source_gdata = new Zend_Gdata( $source_client );
$source_gdata->setMajorProtocolVersion( 3 );

// Perform destination login
$dest_client = Zend_Gdata_ClientLogin::getHttpClient( $dest_user, $dest_pass, 'cp' );
$dest_gdata = new Zend_Gdata( $dest_client );
$dest_gdata->setMajorProtocolVersion( 3 );

// Fetch all destination contacts
message( 'Fetching all "My Contacts" from destination account...' );
$dest_query = new Zend_Gdata_Query( 'http://www.google.com/m8/feeds/contacts/default/full' );
$dest_query->maxResults = 99999;
$dest_query->setParam( 'group', 'http://www.google.com/m8/feeds/groups/' . urlencode($dest_user) . '/base/6' ); // "My Contacts" only
$dest_feed = $dest_gdata->getFeed( $dest_query );
message( $dest_feed->totalResults . ' contacts found.' );

// Clear out all existing contacts
if ( (string) $dest_feed->totalResults > 0 ) {
	message( 'Clearing all "My Contacts" from destination account...' );
	foreach ( $dest_feed as $entry ) {

		if ( !$editlink = $entry->getEditLink() )
			continue;

		$entry = $dest_gdata->getEntry( $editlink->getHref() );
		$dest_gdata->delete( $entry );

		message( '  Deleted ' . $entry->title );
	}
	message( 'Existing "My Contacts" cleared from destination account.' );
}

// Fetch all source contacts
message( 'Fetching all "My Contacts" from source account...' );
$source_query = new Zend_Gdata_Query( 'http://www.google.com/m8/feeds/contacts/default/full' );
$source_query->maxResults = 99999;
$source_query->setParam( 'group', 'http://www.google.com/m8/feeds/groups/' . urlencode($source_user) . '/base/6' ); // "My Contacts" only
$source_feed = $source_gdata->getFeed( $source_query );
message( $source_feed->totalResults . ' contacts found.' );

// Add contacts from source account to the destination account
message( 'Creating contacts in destination account...' );
foreach ( $source_feed as $entry ) {
	//if ( 'Test Contact' != $entry->title ) continue;

	// Tweak the entry slightly
	$xml = $entry->getXML();
	$xml = str_replace( urlencode( $source_user ), urlencode( $dest_user ), $xml );

	// Insert the entry into the destination acccount
	$response = $dest_gdata->insertEntry( $xml, 'http://www.google.com/m8/feeds/contacts/default/full' );


	// Make sure we had success
	if ( empty( $response->id ) ) {
		message( '  Failed to add "' . $entry->title . '" to the destination account.' );
		continue;
	}

	// Does the user have a photo?
	$image_url = false;
	foreach ( $entry->link as $link ) {
		// We're only after the photo link
		if ( 'http://schemas.google.com/contacts/2008/rel#photo' !== $link->rel )
			continue;

		// People without a photo will have this link but no "etag" attribute
		if ( empty( $link->extensionAttributes['http://schemas.google.com/g/2005:etag'] ) )
			continue 2;

		$image_url = $link->href;
	}
	if ( !$image_url )
		continue;

	// Find the photo update URL
	$update_url = false;
	foreach ( $response->link as $link ) {
		if ( 'http://schemas.google.com/contacts/2008/rel#photo' !== $link->rel )
			continue;

		$update_url = $link->href;
	}
	if ( !$update_url )
		continue;

	// Fetch the source image
	$image_request = $source_gdata->get( $image_url );
	$image = $image_request->getBody();

	// Save the image to the destination contact
	$dest_gdata->put( $image, $update_url, null, 'image/*', array( 'If-Match' => '*' ) );

	message( '  Created ' . $entry->title );
}

message( "\nAll done!" );