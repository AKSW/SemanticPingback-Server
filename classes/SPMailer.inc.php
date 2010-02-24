<?php
/**
 * This file contains a template based mailer for the Semantic Pingback server.
 *
 * @author     Philipp Frischmuth <pfrischmuth@googlemail.com>
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @version    $Id: $
 */

require_once 'classes/SPRdfXmlParser.inc.php';

class SPMailer
{
    private $_config = array();
    
    private $_templateVars = array();
    private $_template = null;
    
    public function __construct($config = array())
    {
        $this->_config = $config;
    }
    
    public function __get($name)
    {
        if (isset($this->_templateVars[$name])) {
            return $this->_templateVars[$name];
        }
        
        return null;
    }
    
    public function sendMail($target, $source, $relation)
    {
        if ($this->_config['mail_send'] === true) {
            // Try to determine a template
            $this->_setTemplate($relation);
            if (null == $this->_template) {
                echo 'No Template.';
                return false;
            }
            
	        if (isset($this->_config['mail_to'])) {
	            $to = $this->_config['mail_to'];
	            $toHeader = '<' . $to . '>';
	        } else {
	            $info = $this->_getPublisherInfo($target, true);
	            #var_dump($info);
	            if (!$info) {
	                return;
	            }
	            
	            $to = $info['mbox'];
	            if (substr($to, 0, 7) === 'mailto:') {
	                    $to = substr($to, 7);
	            }
	            
	            if (isset($info['name'])) {
	                $toHeader = $info['name'] . '<' . $to . '>';
	            } else {
	                $toHeader = '<' . $to . '>';
	            }
	            
	            // To send HTML mail, the Content-type header must be set
                $headers  = 'MIME-Version: 1.0' . "\r\n";
                $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
                $headers .= 'To: ' . $toHeader . "\r\n";
                $headers .= 'From: ' . $this->_config['mail_from'] . "\r\n";
                
                
                
                $sourcePubInfo = $this->_getPublisherInfo($source);
	            #var_dump($sourcePubInfo);
	            
	            $targetInfo = $this->_getResourceInfo($target);
	            #var_dump($targetInfo);
	            
	            $relInfo = $this->_getResourceInfo($relation);
	            #var_dump($relInfo);
	            
	            $sourceInfo = $this->_getResourceInfo($source);
	            #var_dump($sourceInfo);
	            
	            if ($this->_config['mail_copyToSource'] === true) {
                    if (isset($sourcePubInfo['mbox'])) {
                        $cc = $sourcePubInfo['mbox'];
        	            if (substr($cc, 0, 7) === 'mailto:') {
        	                    $cc = substr($cc, 7);
        	            }
                        
                        if (isset($sourcePubInfo['name'])) {
                            $headers .= 'Cc: ' . $sourcePubInfo['name'] . ' <' . $cc . '>';
                        } else {
                            $headers .= '<' . $cc . '>';
                        }
                    }
                }
                
                if (isset($this->_config['mail_linkColor'])) {
                    $this->_templateVars['linkColor'] = $this->_config['mail_linkColor'];
                } else {
                    $this->_templateVars['linkColor'] = '#07c';
                }
                if (isset($this->_config['mail_highlightColor'])) {
                    $this->_templateVars['highlightColor'] = $this->_config['mail_highlightColor'];
                } else {
                    $this->_templateVars['highlightColor'] = '#07c';
                }
                if (isset($this->_config['mail_senderName'])) {
                    $this->_templateVars['senderName'] = $this->_config['mail_senderName'];
                } else {
                    $this->_templateVars['senderName'] = '';
                }
                if (isset($this->_config['mail_bye'])) {
                    $this->_templateVars['bye'] = $this->_config['mail_bye'];
                } else {
                    $this->_templateVars['bye'] = '';
                }
                
                if (isset($info['name'])) {
                    $this->_templateVars['hi'] = 'Hi ' . $info['name'] . ',';
                } else {
                    $this->_templateVars['hi'] = 'Hi,';
                }
                
                if ($sourcePubInfo !== null) {
                    $this->_templateVars['sourcePubInfo'] = $sourcePubInfo;
                } else {
                    $this->_templateVars['sourcePubInfo'] = array();
                }
                if ($sourceInfo !== null) {
                    $this->_templateVars['sourceInfo'] = $sourceInfo;
                } else {
                    $this->_templateVars['sourceInfo'] = array();
                }
                if ($relInfo !== null) {
                    $this->_templateVars['relInfo'] = $relInfo;
                } else {
                    $this->_templateVars['relInfo'] = array();
                }
                if ($targetInfo !== null) {
                    $this->_templateVars['targetInfo'] = $targetInfo;
                } else {
                    $this->_templateVars['targetInfo'] = array();
                }
                    
                $text = $this->_render();    
                mail($to, $this->_config['mail_subject'], $text, $headers);
                return true;
	        }
	    } else {
	        return false;
	    }
    }
    
    private function _getResourceInfo($uri)
	{
	    $triples = $this->_loadRdfXml($uri);
	    if ($triples === null) {
	        return array(
	            'uri' => $s
	        );
	    }
	    if (!isset($triples[$uri])) {
	        return array(
	            'uri' => $s
	        );
	    }
	    
	    $s = $uri;
	    $pArray = $triples[$uri];
	    $info = array();
    	foreach ($pArray as $p => $oArray) {
    	    if ($p === 'http://xmlns.com/foaf/0.1/name') {
                $info['title'] = $oArray[0]['value'];
            } else if ($p === 'http://www.w3.org/2000/01/rdf-schema#label') {
                $info['title'] = $oArray[0]['value'];
            }
    	}
	    
		$info['uri'] = $s;
		
		return $info;
	}

	private function _getPublisherInfo($uri, $mboxMandatory = false)
	{
	    $triples = $this->_loadRdfXml($uri);
	    if ($triples === null) {
	        return false;
	    }
	    if (!isset($triples[$uri])) {
	        return false;
	    }
	    
	    $s = $uri;
	    $info = $this->_getInfo($triples[$uri]);
		// If no mbox is found yet... try "creator" relations
		if (($mboxMandatory && !isset($info['mbox'])) || (count($info) === 0)) {
		   $pArray = $triples[$uri];
		   $creatorUri = null;
		   foreach ($pArray as $p => $oArray) {
	           if ($p === 'http://xmlns.com/foaf/0.1/maker') {
	               $creatorUri = $oArray[0]['value'];
                   break;
               } else if ($p === 'http://rdfs.org/sioc/ns#has_creator') {
                   $creatorUri = $oArray[0]['value'];
                   break;
               } else if ($p === 'http://purl.org/dc/terms/creator') {
                   $creatorUri = $oArray[0]['value'];
                   break;
               } else if ($p === 'http://rdfs.org/sioc/ns#has_owner') {
                   $creatorUri = $oArray[0]['value'];
                   break;
               }
	       }
	       
	       if ($creatorUri === null) {
	           return false;
	       }
	       
	       $s = $creatorUri;
	       
	       if (isset($triples[$creatorUri])) {
               $info = $this->_getInfo($triples[$creatorUri]);
	       } 
	       
	       // Still no mbox? try to fetch creatirUri
	       if (($mboxMandatory && !isset($info['mbox'])) || (count($info) === 0)) {
               $triples = $this->_loadRdfXml($creatorUri);
               if (!is_array($triples) || !isset($triples[$creatorUri])) {
                   return false;
               } else {
                   $info = $this->_getInfo($triples[$creatorUri]);
                   if ($mboxMandatory && !isset($info['mbox'])) {
                       return false;
                   }
               }
           }
		}
		
		$info['uri'] = $s;
		
		return $info;
	}
	
	private function _getInfo($pArray)
	{
	    $info = array();
	    foreach ($pArray as $p => $oArray) {
		    if ($p === 'http://xmlns.com/foaf/0.1/mbox') {
                $info['mbox'] = $oArray[0]['value'];
            } else if ($p === 'http://xmlns.com/foaf/0.1/name') {
                $info['name'] = $oArray[0]['value'];
            } else if ($p === 'http://xmlns.com/foaf/0.1/depiction') {
                $info['depiction'] = $oArray[0]['value'];
            }
		}
		
		return $info;
	}
	
	private function _loadRdfXml($uri)
	{
	    if (isset($this->_loadCache[$uri])) {
	        return $this->_loadCache[$uri];
	    }
	    
	    $curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $uri);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
		    'Accept: application/rdf+xml'
		));
		$result = curl_exec($curl);
		$info = curl_getinfo($curl);
		curl_close($curl);
		
		$triples = null;
		if (($info['http_code'] === 200) && ($info['content_type'] === 'application/rdf+xml')) {
    	    $parser = new SPRdfXmlParser();
    	    try {
    	        $triples = $parser->parse($result);
    	    } catch (Exception $e) {
    	        $this->_loadCache[$uri] = null;
    	        return null;
    	    }
	    }
	    
	    $this->_loadCache[$uri] = $triples;
	    
	    return $triples;
	}
	
	private function _render()
	{
	    if (null == $this->_template) {
	        return null;
	    }
	    
	    $file = 'templates/' . $this->_template . '.phtml';
	    if (!is_readable($file)) {
	        return null;
	    }
	    
	    ob_start();
	    include $file;
	    return ob_get_clean();
	}
	
	private function _setTemplate($relation)
	{
	    if (isset($this->_config['mail_templates'][$relation])) {
	        $this->_template = $this->_config['mail_templates'][$relation];
	    } else if (isset($this->_config['mail_templates']['generic'])) {
	        $this->_template = $this->_config['mail_templates']['generic'];
	    }
	}
}
