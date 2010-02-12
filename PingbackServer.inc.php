<?php
require_once 'libraries/class-IXR.php';

$includePath  = get_include_path() . PATH_SEPARATOR;
$includePath .= str_replace('index.php', '', $_SERVER['SCRIPT_FILENAME']) . 'libraries/' . PATH_SEPARATOR;
set_include_path($includePath);

class PingbackServer extends IXR_Server 
{
    var $methods = array();

    var $dbConn = null;
    var $_dbChecked = false;

    var $_config = array();

	function PingbackServer($connection, $config = array()) 
	{
	    $this->dbConn = $connection;
	    
		$this->methods = array(
			'pingback.ping' => 'this:pingback_ping'
		);
		
		$defaultConfig = array(
		    'allow_ext_target' => false,
		    'mail' => false
		);
		
		$this->_config = array_merge($defaultConfig, $config);
	}

	function serveRequest() 
	{
		$this->IXR_Server($this->methods);
	}
	
	function pingback_ping($args) 
	{
        $source = $args[0];
        $target = $args[1];
	
		$source = str_replace('&amp;', '&', $source);
		$target = str_replace('&amp;', '&', $target);
		$target = str_replace('&', '&amp;', $target);

        if (!$this->_config['allow_ext_target']) {
            // Check if the page linked to is in our site
    		$pos1 = strpos($target, ('http://'  . $_SERVER['HTTP_HOST']));
    		$pos2 = strpos($target, ('https://' . $_SERVER['HTTP_HOST']));
    		$pos3 = strpos($target, 'triplify');

    // TODO better check for valid target uris 
    		if (!$pos1 && !$pos2 && !$pos3) {
    		    return new IXR_Error(0, 'Is there no link to us?');
    		}
        }

		$foundPingbackTriples = array();
		
		// Let's check the remote site
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $source);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
		    'Accept: application/rdf+xml'
		));
		$result = curl_exec($curl);
		$info = curl_getinfo($curl);
		curl_close($curl);
		if (($info['http_code'] === 200) && (strtolower($info['content_type']) === 'application/rdf+xml')) {
		    $rdfData = $result;
		    $triples = $this->_getPingbackTriplesFromRdfXmlString($rdfData, $source, $target);
		    if (is_array($triples)) {
                $foundPingbackTriples = $triples;
            }
		}
		
		if (count($foundPingbackTriples) === 0) {
	        $service = 'http://www.w3.org/2007/08/pyRdfa/extract?format=pretty-xml&warnings=false&parser=lax&space-preserve=true&uri=' . urlencode($source);
	        
	        $curl = curl_init();
    		curl_setopt($curl, CURLOPT_URL, $service);
    		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    		$result = curl_exec($curl);
    		$info = curl_getinfo($curl);
    		curl_close($curl);
    		if (($info['http_code'] === 200) && (strtolower($info['content_type']) === 'application/rdf+xml')) {
	            $rdfData = $result;
    		    $triples = $this->_getPingbackTriplesFromRdfXmlString($rdfData, $source, $target);
    		    if (is_array($triples)) {
                    $foundPingbackTriples = $triples;
                }
	        }
	    }
	    
	    if (count($foundPingbackTriples) === 0) {
	        $curl = curl_init();
    		curl_setopt($curl, CURLOPT_URL, $source);
    		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    		$result = curl_exec($curl);
    		$info = curl_getinfo($curl);
    		curl_close($curl);
    		if ($info['http_code'] === 200) {
	            $htmlDoc = new DOMDocument();
                $r = @$htmlDoc->loadHtml($result);
                $aElements = $htmlDoc->getElementsByTagName('a');

                foreach ($aElements as $aElem) {
                    $a  = $aElem->getAttribute('href');
                    if (strtolower($a) === $target) {
                        $foundPingbackTriples[] = array(
                            's' => $source,
                            'p' => 'http://rdfs.org/sioc/ns#links_to',
                            'o' => $target
                        );
                        break;
                    }
                }
	        }
		}
		
// TODO remove non existing pingbacks
        if (count($foundPingbackTriples) === 0) {
            return new IXR_Error(0x0011, 'No links in source document.');
		}
		
		$added = false;
		$addedStmts = array();
		foreach ($foundPingbackTriples as $triple) {
		    if (!$this->_pingbackExists($triple['s'], $triple['p'], $triple['o'])) {
		        $this->_addPingback($triple['s'], $triple['p'], $triple['o']);
		        $added = true;
		        $addedStmts[] = $triple;
		    }
		}
// TODO remove old pingbacks	
		if (!$added) {
            return new IXR_Error(0x0030, 'Already exists.');
        }
       
        if ($this->_config['mail']) {
            // Get a mail address for target...
            // Let's check the remote site
    		$curl = curl_init();
    		curl_setopt($curl, CURLOPT_URL, $target);
    		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
    		    'Accept: application/rdf+xml'
    		));
    		$result = curl_exec($curl);
    		$info = curl_getinfo($curl);
    		curl_close($curl);
    		$mail = null;
    		$name = null;
    		$triples = null;
    		if (($info['http_code'] === 200) && (strtolower($info['content_type']) === 'application/rdf+xml')) {
    		    $rdfData = $result;
    		    require_once 'Erfurt/Syntax/RdfParser.php';
        	    $parser = Erfurt_Syntax_RdfParser::rdfParserWithFormat('rdfxml');
        	    try {
        	        $triples = $parser->parse($rdfData, Erfurt_Syntax_RdfParser::LOCATOR_DATASTRING);
        	    } catch (Exception $e) {
        	        $triples = $e->getMessage();
        	    }
    		    
    		    if (is_array($triples)) {
                    if (isset($triples[$target])) {
                        $pArray = $triples[$target];
                        foreach ($pArray as $p => $oArray) {
                            if ($p === 'http://xmlns.com/foaf/0.1/mbox') {
                                if ($mail === null) {
                                    $mail = $oArray[0]['value'];
                                }
                            } else if ($p === 'http://xmlns.com/foaf/0.1/name') {
                                    if ($name === null) {
                                        $name = $oArray[0]['value'];
                                    }
                            }
                        }
                    }
                }
    		}
            
            if ($mail !== null) {
                // To send HTML mail, the Content-type header must be set
                $headers  = 'MIME-Version: 1.0' . "\r\n";
                $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

                // Additional headers
                $to = '';
                if ($name !== null) {
                    $to .= $name . ' ';
                }
                $to .= '<'. $mail . '>';
                
                $headers .= 'To: ' . $to . "\r\n";
                $headers .= 'From: AKSW Pingback Service <noreply@aksw.informatik.uni-leipzig.de>' . "\r\n";
                //$headers .= 'Cc: birthdayarchive@example.com' . "\r\n";
                //$headers .= 'Bcc: birthdaycheck@example.com' . "\r\n";
                
                
                $text = 'Hi, ' . PHP_EOL . PHP_EOL . ' a Pingback was requested with target <a href="' . $target. '"><pre>' .
                $target . '</pre></a> and source <a href="' . $source . '"><pre>' . $source . '</pre></a>.' . PHP_EOL . PHP_EOL . 'Yours, AKSW';
                
                mail($mail, 'Pingback requested', $text, $headers);
            } else {
                mail('pfrischmuth@googlemail.com', 'test', serialize($triples));
            }
        }
       
        return 'Pingback registered.';
	}
	
	function _getPingbackTriplesFromRdfXmlString($rdfXml, $sourceUri, $targetUri)
	{
	    require_once 'Erfurt/Syntax/RdfParser.php';
	    $parser = Erfurt_Syntax_RdfParser::rdfParserWithFormat('rdfxml');
	    try {
	        $result = $parser->parse($rdfxml, Erfurt_Syntax_RdfParser::LOCATOR_DATASTRING);
	    } catch (Exception $e) {
	        return false;
	    }
        
        $foundTriples = array();
        foreach ($result as $s => $pArray) {
            foreach ($pArray as $p => $oArray) {
                foreach ($oArray as $oSpec) {
                    if ($s === $sourceUri) {
                        if (($o['type'] === 'uri') && ($o['value'] === $targetUri)) {
                            $foundTriples[] = array(
                                's' => $s,
                                'p' => $p,
                                'o' => $o['value']
                            );
                        }
                    } else if (($o['type'] === 'uri') && ($o === $sourceUri)) {
                        // Try to find inverse property for $p
                        $inverseProp = $this->_determineInverseProperty($p);
                        if ($inverseProp !== null) {
                            $foundTriples[] = array(
                                's' => $o['value'],
                                'p' => $inverseProp,
                                'o' => $s
                            );
                        }
                    }
                }
            }
        }
        
        return $foundTriples;
	}
	
	function _pingbackExists($s, $p, $o)
	{
	    $this->_checkDb();
	    
	    $sql = 'SELECT * FROM triplify_pingbacks WHERE s="' . $s . '" AND p="' . $p . '" AND o="' . $o . '"';
	    $result = $this->_query($sql);
	    if (!$result) {
	        return false;
	    } else {
	        return true;
	    }
	    
	}
	
	function _addPingback($s, $p, $o)
	{
	    $this->_checkDb();
	    
	    $sql = 'INSERT INTO triplify_pingbacks (s, p, o) VALUES ("' . $s . '", "' . $p . '", "' . $o . '");';
	    $this->_query($sql);
	}
	
	function _checkDb()
	{
	    if ($this->_dbChecked) {
	        return;
	    }
	    
	    $sql = 'SELECT * FROM triplify_pingbacks LIMIT 1';
	    $result = mysql_query($sql, $this->dbConn);
	    if ($result === false) {
	        $this->_createTable();
	    }
	    
	    $this->_dbChecked = true;
	}
	
	function _createTable()
	{
	    $sql = 'CREATE TABLE IF NOT EXISTS triplify_pingbacks (
	        id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
	        s  VARCHAR(255) COLLATE ascii_bin NOT NULL,
	        p  VARCHAR(255) COLLATE ascii_bin NOT NULL,
	        o  VARCHAR(255) COLLATE ascii_bin NOT NULL
	    );';
	    
	    return mysql_query($sql, $this->dbConn);
	}
	
	function _query($sql)
	{
	    $result = mysql_query($sql, $this->dbConn);
	    if (!$result) {
	        return false;
	    }
	    $returnValue = array();
	    while ($row = mysql_fetch_assoc($result)) {
	        $returnValue[] = $row;
	    }
	    
	    if (count($returnValue) === 0) {
	        return false;
	    }
	    
	    return $returnValue;
	}
}
