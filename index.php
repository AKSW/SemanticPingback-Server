<?php
if (!is_readable('config.inc.php')) {
    echo '<pre>No config.inc.php file or config file not readable.</pre>';
    exit;
}

$config = array();
require_once('config.inc.php');

require_once('classes/SPServer.inc.php');

define('XMLRPC_REQUEST', true);

// Some browser-embedded clients send cookies. We don't want them.
$_COOKIE = array();

// A bug in PHP < 5.2.2 makes $HTTP_RAW_POST_DATA not set by default,
// but we can do it ourself.
if ( !isset( $HTTP_RAW_POST_DATA ) ) {
	$HTTP_RAW_POST_DATA = file_get_contents( 'php://input' );
}

// fix for mozBlog and other cases where '<?xml' isn't on the very first line
if ( isset($HTTP_RAW_POST_DATA) )
	$HTTP_RAW_POST_DATA = trim($HTTP_RAW_POST_DATA);

$server = new SPServer($config);
$server->serveRequest();
