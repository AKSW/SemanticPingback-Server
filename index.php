<?php
/**
 * This is the main file of the Semantic Pingback server implementation.
 *
 * @author     Philipp Frischmuth <pfrischmuth@googlemail.com>
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

if (!is_readable('config.inc.php')) {
    echo '<pre>No config.inc.php file or config file not readable.</pre>';
    exit;
}

$config = array();
require_once('config.inc.php');

define('XMLRPC_REQUEST', true);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: X-Requested-With");


// TODO split after ? in REQUEST_URI
$SERVICE_URIsplit = explode ( '?' ,'http://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"] );
define('SERVICE_URI', $SERVICE_URIsplit[0]);

header('X-Pingback: '.SERVICE_URI);

// Some browser-embedded clients send cookies. We don't want them.
$_COOKIE = array();

// A bug in PHP < 5.2.2 makes $HTTP_RAW_POST_DATA not set by default,
// but we can do it ourself.
if ( !isset( $HTTP_RAW_POST_DATA ) ) {
	$HTTP_RAW_POST_DATA = file_get_contents( 'php://input' );
}

// fix for mozBlog and other cases where '<?xml' isn't on the very first line
if ( isset($HTTP_RAW_POST_DATA) ) {
	$HTTP_RAW_POST_DATA = trim($HTTP_RAW_POST_DATA);
}

if ( (isset($HTTP_RAW_POST_DATA)) && (strlen($HTTP_RAW_POST_DATA) > 0 )) {
    // Serve the XML-RPC request.
    require_once('classes/SPServer.inc.php');
    $server = new SPServer($config);

    // here we switch between the classic XMLRPC ping and the post ping
    if (isset($_POST['source']) && isset($_POST['target']) ) {
        // simplified Semantic Pingback
        $result = $server->pingback_ping( array($_POST['source'], $_POST['target'], $_POST['comment']) );
        include 'templates/success.phtml';
        exit;
    } else {
        // XML-RPC request
        $server->serveRequest();
    }

} else if ( isset($_GET['source']) || isset($_GET['target']) || isset($_GET['comment']) ) {
    include 'templates/success.phtml';
    exit;
} else {
    // if it is not a XML-RPC request: serve the webpage

    // Query for all pingbacks to this service
    $servicePingsQuery = 'SELECT s,p,o FROM sp_pingbacks ORDER BY id DESC LIMIT 0 , 10';
    $servicePingsResult = @mysql_query($servicePingsQuery, $config['db']);
    if ($servicePingsResult) {
        while ($row = mysql_fetch_assoc($servicePingsResult)) {
             $data['pings'][] = $row;
	    }
    }
    include 'templates/index.phtml';
}
