<?php
/**
 * This file contains a RDF/XML parser. The parser class is derived from
 * the Erfurt Semantic Web API.
 *
 * @author     Philipp Frischmuth <pfrischmuth@googlemail.com>
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @version    $Id: $
 */

define('RDF_NS', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
define('XML_NS', 'http://www.w3.org/XML/1998/namespace');
define('RDF_TYPE', RDF_NS.'type');
define('RDF_NIL', RDF_NS.'nil');
define('RDF_REST', RDF_NS.'rest');
define('RDF_FIRST', RDF_NS.'first');

class SPRdfXmlParser
{    
    protected $_data = null;
    protected $_offset = 0;
    protected $_currentElementIsEmpty = false;
    
    const BNODE_PREFIX = 'node';
    
    protected $_bnodeCounter = 0;
    
    protected $_elementStack = array();
    protected $_currentXmlLang = null;
    
    protected $_statements = array();
    
    protected $_xmlParser = null;
    protected $_baseUri = null;
    
    protected $_currentCharData = null;
    
    protected $_rdfElementParsed = false;
    
    protected $_namespaces = array();
    
    public function parse($dataString, $baseUri = null)
    {
        if (null !== $baseUri) {
            $this->_baseUri = $baseUri;
        }
        
        $xmlParser = $this->_getXmlParser();
        
        $this->_data = $dataString;
        xml_parse($xmlParser, $dataString);
        
        if (xml_get_error_code($xmlParser) !== 0) {
            throw new Exception(
                'Parsing failed: ' . xml_error_string(xml_get_error_code($xmlParser))
            );
        }
        
        return $this->_statements;
    }
    
    /**
     * Call this method after parsing only. The function parseToStore will add namespaces automatically.
     * This method is just for situations, where the namespaces are needed to after a in-memory parsing.
     * 
     * @return array
     */
    public function getNamespaces()
    {
        return $this->_namespaces;
    }
    
    protected function _startElement($parser, $name, $attrs)
    {
        if (strpos($name, ':') === false) {
            throw new Exception('Invalid element name: ' . $name . '.');
        } 
        
        if ($name === RDF_NS.'RDF') {
            if (isset($attrs[(XML_NS . 'base')])) {
                $this->_baseUri = $attrs[(XML_NS . 'base')];
            }
            return;
        }
        
        $idx = xml_get_current_byte_index($parser) - $this->_offset*4096;
        if (($idx >= 0) && ($this->_data[$idx].$this->_data[$idx+1]) === '/>') {
            $this->_currentElementIsEmpty = true;
        } else {
            $this->_currentElementIsEmpty = false;
        }
        
        if (isset($attrs['http://www.w3.org/XML/1998/namespacelang'])) {
            $this->_currentXmlLang = $attrs['http://www.w3.org/XML/1998/namespacelang'];
        }
        
        if ($this->_topElemIsProperty()) {
            // In this case the surrounding element is a property, so this element is a s and/or o.
            $this->_processNode($name, $attrs);
        } else {
            // This element is a property.
            $this->_processProperty($name, $attrs);
        }
    }
    
    protected function _topElemIsProperty()
    {
        if (count($this->_elementStack) === 0 || 
            $this->_peekStack(0) instanceof SPRdfXmlParser_PropertyElement) {
        
            return true;
        } else {
            return false;
        }
    }
    
    protected function _endElement($parser, $name)
    {
        $this->_handleCharDataStatement();
        
        if ($this->_currentElementIsEmpty) {
            $this->_currentElementIsEmpty = false;
            return;   
        }
        
        if ($name === RDF_NS.'RDF') {
            return;
        }
        
        $topElement = $this->_peekStack(0);

        if ($topElement instanceof SPRdfXmlParser_NodeElement) {
            if ($topElement->isVolatile()) {
                array_pop($this->_elementStack);
            }
        } else {
            if (null === $topElement) {
                return;
            }

            if ($topElement->parseAsCollection()) {
                $lastListResource = $topElement->getLastListResource();
                
                if (null === $lastListResource) {
                    $subject = $this->_peekStack(1);
                    
                    $this->_addStatement($subject->getResource(), $topElement->getUri(), RDF_NIL, 'uri');
                    $this->_handleReification(RDF_NIL);
                } else {
                    $this->_addStatement($lastListResource, RDF_REST, RDF_NIL, 'uri');
                }
            }
        }
        
        array_pop($this->_elementStack);
        $this->_currentXmlLang = null;
    }
    
    protected function _characterData($parser, $data)
    {
        if (null !== $this->_currentCharData) {
            $this->_currentCharData .= $data;
        } else {
            $this->_currentCharData = $data;
        }        
    }
    
    protected function _handleCharDataStatement()
    {
        if (null !== $this->_currentCharData) {
            if (trim($this->_currentCharData) === '') {
                $this->_currentCharData = null;
                return;
            }
            
            if (!$this->_topElemIsProperty()) {
                $this->_throwException('Unexpected literal.');
            }

            $propElem = $this->_peekStack(0);
            if (null === $propElem) {
                return;
            }
            $dt = $propElem->getDatatype();

            $subjectElem = $this->_peekStack(1);
            $this->_addStatement($subjectElem->getResource(), $propElem->getUri(), trim($this->_currentCharData), 'literal', 
                $this->_currentXmlLang, $dt);

            $this->_handleReification(trim($this->_currentCharData));
            
            $this->_currentCharData = null;
        }
    }
    
    protected function _processNode($name, &$attrs)
    {
        $nodeResource = $this->_getNodeResource($attrs);
        if (null === $nodeResource) {
            return;
        }
        
        $nodeElem = new SPRdfXmlParser_NodeElement($nodeResource);
        
        if (count($this->_elementStack) > 0) {
            // Node can be the object or part of an rdf:List
            $subject   = $this->_peekStack(1);
            $predicate = $this->_peekStack(0);
            
            if ($predicate->parseAsCollection()) {
                $lastListResource = $predicate->getLastListResource();
                $newListResource = $this->_createBNode();
                
                if (null === $lastListResource) {
                    // This is the first element in the list.
                    $this->_addStatement($subject->getResource(), $predicate->getUri(), $newListResource);
                    $this->_handleReification($newListResource);
                } else {
                    // Not the first element in the list.
                    $this->_addStatement($lastListResource, RDF_REST, $newListResource);
                }
                
                $this->_addStatement($newListResource, RDF_FIRST, $nodeResource);
                $predicate->setLastListResource($newListResource);
            } else {
                $this->_addStatement($subject->getResource(), $predicate->getUri(), $nodeResource);
                $this->_handleReification($nodeResource);
            }
        }
        
        if ($name !== RDF_NS.'Description') {
            // Element name is the type of the uri.
            $this->_addStatement($nodeResource, RDF_TYPE, $name, 'uri');
        }
        
        $type = $this->_removeAttribute($attrs, RDF_TYPE);
        if (null !== $type) {
            $className = $this->_resolveUri($type);
            $this->_addStatement($nodeResource, RDF_TYPE, $className, 'uri');
        }
        
        // Process all remaining attributes of this element.
        $this->_processSubjectAttributes($nodeResource, $attrs);
        
        if (!$this->_currentElementIsEmpty) {
            $this->_elementStack[] = $nodeElem;
        }
    }
    
    protected function _processProperty($name, &$attrs)
    {
        $propUri = $name;

        // List expansion rule
        if ($propUri === RDF_NS.'li') {
            $subject = $this->_peekStack(0);
            $propUri = RDF_NS . '_' . $subject->getNextLiCounter();
        }
        
        // Push the property on the stack.
        $predicate = new SPRdfXmlParser_PropertyElement($propUri);
        $this->_elementStack[] = $predicate;
        
        // Check, whether the prop has a reification id.
        $id = $this->_removeAttribute($attrs, RDF_NS.'ID');
        if (null !== $id) {
            $uri = $this->_buildUriFromId($id);
            $predicate->setReificationUri($uri);
        }
        
        // Check for rdf:parseType attribute.
        $parseType = $this->_removeAttribute($attrs, RDF_NS.'parseType');
        if (null !== $parseType) {
            switch ($parseType) {
                case 'Resource':
                    $objectResource = $this->_createBNode();
                    $subject = $this->_peekStack(1);
                    
                    $this->_addStatement($subject->getResource(), $propUri, $objectResource, 'bnode');
                    
                    if ($this->_currentElementIsEmpty) {
                        $this->_handleReification($objectResource);
                    } else {
                        $object = new SPRdfXmlParser_NodeElement($objectResource);
                        $object->setIsVolatile(true);
                        $this->_elementStack[] = $object;
                    }
                    
                    break;
                case 'Collection':
                    if ($this->_currentElementIsEmpty) {
                        $subject = $this->_peekStack(1);
                        $this->_addStatement($subject->getResource(), $propUri, RDF_NIL, 'uri');
                        $this->_handleReification(RDF_NIL);
                    } else {
                        $predicate->setParseAsCollection(true);
                    }
                
                    break;
            
                case 'Literal':
                    if ($this->_currentElementIsEmpty) {
                        $subject = $this->_peekStack(1);
                        $this->_addStatement($subject->getResource(), $propUri, 
                            '', 'literal', null, RDF_NS.'XmlLiteral');
                        $this->_handleReification('');
                    } else {
                        $predicate->setDatatype($value);
                    }
                    
                    break;       
            }
        } else {
            // No parseType
            
            if ($this->_currentElementIsEmpty) {
                if (count($attrs) === 0 || (count($attrs) === 1 && isset($attrs[RDF_NS.'datatype']))) {
                    // Element has no attributes, or only the optional
                    // rdf:ID and/or rdf:datatype attributes.
                    $subject = $this->_peekStack(1);
                    
                    $dt = null;
                    if (isset($attrs[RDF_NS.'datatype'])) {
                        $dt = $attrs[RDF_NS.'datatype'];
                    }
                    
                    $this->_addStatement($subject->getResource(), $propUri, '', 'literal', $this->_currentXmlLang, $dt);
                    $this->_handleReification('');
                } else {    
                    $resourceRes = $this->_getPropertyResource($attrs);
               
                    if (null === $resourceRes) {
                        return;
                    }
                    
                    $subject = $this->_peekStack(1);
                    
                    $this->_addStatement($subject->getResource(), $propUri, $resourceRes);
                    $this->_handleReification($resourceRes);
                    
                    $type = $this->_removeAttribute($attrs, RDF_TYPE);
                    if (null !== $type) {
                        $className = $this->_resolveUri($type);
                        
                        $this->_addStatement($resourceRes, RDF_TYPE, $className);
                    }
                    
                    $this->_processSubjectAttributes($resourceRes, $attrs);
                }
            } else {
                // Not an empty element.
               
                $datatype = $this->_removeAttribute($attrs, RDF_NS.'datatype');
                if (null !== $datatype) {
                    $predicate->setDatatype($datatype);
                }
            }
        }
        if ($this->_currentElementIsEmpty) {
            array_pop($this->_elementStack);
        }   
    }
    
    protected function _getPropertyResource(&$attrs)
    {
        $resource = $this->_removeAttribute($attrs, RDF_NS.'resource');
        $nodeId   = $this->_removeAttribute($attrs, RDF_NS.'nodeID');
     
        if (null !== $resource) {
            return $this->_resolveUri($resource);
        } else if (null !== $nodeId) {
            return $this->_createBNode($nodeId);
        } else {
            return $this->_createBNode();
        } 
    }
    
    protected function _processSubjectAttributes($subject, &$attrs)
    {
        foreach ($attrs as $key=>$value) {            
            $this->_addStatement($subject, $key, $value, 'literal', $this->_currentXmlLang, null);
        }
    }
    
    protected function _addStatement($s, $p, $o, $oType = null, $lang = null, $dType = null)
    {        
        if (!isset($this->_statements["$s"])) {
            $this->_statements["$s"] = array();
        }
        if (!isset($this->_statements["$s"]["$p"])) {
            $this->_statements["$s"]["$p"] = array();
        }
        
        if (null === $oType) {
            if (substr($o, 0, 2) === '_:') {
                $oType = 'bnode';
            } else {
                $oType = 'uri';
            }
        }
        
        $objectArray = array(
            'type'  => $oType,
            'value' => $o
        );
        
        // If we have a language we use that language and datatype is string implicit.
        if ($oType === 'literal' && null !== $lang) {
            $objectArray['lang'] = $lang;
        } else if ($oType === 'literal' && null !== $dType) {
            $objectArray['datatype'] = $dType;
        }
        
        $this->_statements["$s"]["$p"][] = $objectArray;
    }
        
    protected function _handleReification($value)
    {
        $predicate = $this->_peekStack(0);
        
        if ($predicate->isReified()) {
            $subject = $this->_peekStack(1);
            $reifRes = $predicate->getReificationUri();
            $this->_reifyStatement($reifRes, $subject->getResource(), $predicate->getUri(), $value);
        }
    }
    
    protected function _reifyStatement($reifNode, $s, $p, $o)
    {
        // TODO handle literals and bnodes the right way...
        
        $this->_addStatement($reifNode, RDF_TYPE, RDF_NS.'Statement');
        $this->_addStatement($reifNode, RDF_NS.'subject', $s);
        $this->_addStatement($reifNode, RDF_NS.'predicate', $p);
        $this->_addStatement($reifNode, RDF_NS.'object', $o);
    }
    
    protected function _getNodeResource(&$attrs)
    {
        $id     = $this->_removeAttribute($attrs, RDF_NS.'ID');
        $about  = $this->_removeAttribute($attrs, RDF_NS.'about');
        $nodeId = $this->_removeAttribute($attrs, RDF_NS.'nodeID');
        
        // We could throw an exception if more than one of the above attributes
        // are given, but we want to be as tolerant as possible, so we use the
        // first given.
        
        if (null !== $id) {
            return $this->_buildUriFromId($id);
        } else if (null !== $about) {
            return $this->_resolveUri($about);
        } else if (null !== $nodeId) {
            return $this->_createBNode($nodeId);
        } else  {
            // Nothing given, so create a new BNode.
            return $this->_createBNode();
        }
    }
    
    protected function _removeAttribute(&$attrs, $name)
    {
        if (isset($attrs[$name])) {
            $value = $attrs[$name];
            unset($attrs[$name]);
            return $value;
        } else {
            return null;
        }
    }
    
    protected function _buildUriFromId($id)
    {
        return $this->_resolveUri('#'.$id);
    }
    
    protected function _resolveUri($about)
    {
        if ($this->_checkSchemas($about)) {
            return $about;
        }
        
// TODO Handle all relative URIs the right way...
        if (substr($about, 0, 1) === '#' || $about === '' || strpos($about, '/') === false) {
            // Relative URI... Resolve against the base URI.
            if ($this->_getBaseUri()) {
                // prevent double hash (e.g. http://www.w3.org/TR/owl-guide/wine.rdf Issue 604)
                if ( substr($about,0,1) === '#' && substr($this->_getBaseUri(),-1) === '#' ) {
                    $about = substr($about,1);
                } 
                return $this->_getBaseUri() . $about;
            }
        } 
        
        // Absolute URI... Return it.
        return $about;
    }
    
    protected function _checkSchemas($about)
    {
        $schemataArray = array(
            'aaa', 'aaas', 'acap', 'cap', 'cid', 'crid', 'data', 'dav', 'dict', 'dns', 'fax', 'file',
            'ftp', 'go', 'gopher', 'h323', 'http', 'https', 'iax', 'icap', 'im', 'imap', 'info', 'ipp',
            'iris', 'iris.beep', 'iris.xpc', 'iris.xpcs', 'iris.lwz', 'ldap', 'mailto', 'mid', 'modem',
            'msrp', 'msrps', 'mtqp', 'mupdate', 'news', 'nfs', 'nntp', 'opaquelocktoken', 'pop', 'pres', 
            'rtsp', 'service', 'shttp', 'sieve', 'sip', 'sips', 'snmp', 'soap.beep', 'soap.beeps', 'tag', 
            'tel', 'telnet', 'tftp', 'thismessage', 'tip', 'tv', 'urn', 'vemmi', 'xmlrpc.beep', 'xmlrpc.beeps', 
            'xmpp', 'z39.50r', 'z39.50s', 'afs', 'dtn', 'mailserver', 'pack', 'tn3270', 'prospero', 'snews', 
            'videotex', 'wais', 'ldaps', 'icq'
        );
        
		$regExp = '/^(' . implode(':|', $schemataArray) . ').*$/';
		if (preg_match($regExp, $about)) {
		    return true;
		} else {
		    return false;
		}
    }
    
    protected function _createBNode($id = null)
    {

        if (null === $id) {
            while (true) {
                $id = self::BNODE_PREFIX . ++$this->_bnodeCounter;

                if (!isset($this->_usedBnodeIds[$id])) {
                    break;
                }
            }    
        }

        $this->_usedBnodeIds[$id] = true;
        return '_:'.$id;
    }
    
    protected function _getBaseUri()
    {
        if (null !== $this->_baseUri) {
            return $this->_baseUri;
        } else {
            return false;
        }
    }
    
    protected function _throwException($msg)
    {
        throw new Exception($msg);
    }
    
    protected function _peekStack($distanceFromTop = 0)
    {
        $count = count($this->_elementStack);
        $pos = $count-1-$distanceFromTop;
        if ($count > 0 && $pos >= 0) {
            return $this->_elementStack[$pos];
        } else {
            return null;
        }
    }
    
    private function _getXmlParserNamespacesOnly()
    {
        $xmlParser = xml_parser_create_ns(null, '');
        
        xml_parser_set_option($xmlParser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($xmlParser, XML_OPTION_SKIP_WHITE, 1);
        
        xml_set_start_namespace_decl_handler($xmlParser, array($this, '_handleNamespaceDeclaration'));
        
        return $xmlParser;
    }
    
    private function _getXmlParser()
    {
        if (null === $this->_xmlParser) {
            $this->_xmlParser = xml_parser_create_ns(null, '');
            
            // Disable case folding, for we need the uris.
            xml_parser_set_option($this->_xmlParser, XML_OPTION_CASE_FOLDING, 0);

            xml_parser_set_option($this->_xmlParser, XML_OPTION_SKIP_WHITE, 1);

            xml_set_default_handler($this->_xmlParser, array($this, '_handleDefault'));

            // Set the handler method for namespace definitions
            xml_set_start_namespace_decl_handler($this->_xmlParser, array($this, '_handleNamespaceDeclaration'));

            //$this->_setNamespaceDeclarationHandler('_handleNamespaceDeclaration');

            //xml_set_end_namespace_decl_handler($xmlParser, array(&$this, '_handleNamespaceDeclaration'));

            xml_set_character_data_handler($this->_xmlParser, array($this, '_characterData'));

            //xml_set_external_entity_ref_handler($this->_xmlParser, array($this, '_handleExternalEntityRef'));

            //xml_set_processing_instruction_handler($this->_xmlParser, array($this, '_handleProcessingInstruction'));

            //xml_set_unparsed_entity_decl_handler($this->_xmlParser, array($this, '_handleUnparsedEntityDecl'));

            xml_set_element_handler(
                $this->_xmlParser, 
                array($this, '_startElement'),
                array($this, '_endElement')
            );
        }
        
        return $this->_xmlParser;
    }
    
    protected function _handleDefault($parser, $data)
    {
        // Handles comments
        //var_dump($data);
    }
    
    protected function _handleNamespaceDeclaration($parser, $prefix, $uri)
    {
        $prefix = (string)$prefix;
        $uri = (string)$uri;
        
        if (!$this->_rdfElementParsed) {
            if ($prefix != '' && $uri != '') {
                $this->_namespaces[$uri] = $prefix;
            }
        }
    }
}

class SPRdfXmlParser_NodeElement
{
    protected $_resource = null;
    protected $_isVolatile = false;
    protected $_liCounter = 1;
    
    public function __construct($resource)
    {
        $this->_resource = $resource;
    }
    
    public function getResource()
    {
        return $this->_resource;
    }
    
    public function setIsVolatile($isVolatile)
    {
        $this->_isVolatile = $isVolatile;
    }
    
    public function isVolatile()
    {
        return $this->_isVolatile;
    }
    
    public function getNextLiCounter()
    {
        return $this->_liCounter++;
    }
}

class SPRdfXmlParser_PropertyElement
{
    protected $_uri = null;
    protected $_reificationUri = null;
    protected $_datatype = null;
    protected $_parseAsCollection = false;
    protected $_lastListResource = null;
    
    public function __construct($uri)
    {
        $this->_uri = $uri;
    }
    
    public function getUri()
    {
        return $this->_uri;
    }
    
    public function isReified()
    {
        if (null !== $this->_reificationUri) {
            return true;
        } else {
            return false;
        }
    }
    
    public function setReificationUri($reifUri)
    {
        $this->_reificationUri = $reifUri;
    }
    
    public function getReificationUri()
    {
        return $this->_reificationUri;
    }
    
    public function setDatatype($datatype)
    {
        $this->_datatype = $datatype;
    }
    
    public function getDatatype()
    {
        return $this->_datatype;
    }
    
    public function parseAsCollection()
    {
        return $this->_parseAsCollection;
    }
    
    public function setParseAsCollection($parseAsCollection)
    {
        $this->_parseAsCollection = $parseAsCollection;
    }
    
    public function getLastListResource()
    {
        return $this->_lastListResource;
    }
    
    public function setLastListResource($lastListResource)
    {
        $this->_lastListResource = $lastListResource;
    }
}

