<?php

# Database configuration
$config['db'] = mysql_connect('localhost','ow','ow');
mysql_select_db('test');

# Target configuration
$config['target_allow_external'] = true; // Must be true, if used as a service for external target URIs

# Mail configuration
$config['mail_send'] = true; 
$config['mail_copyToSource'] = true;
$config['mail_linkColor'] = '#07c';
$config['mail_highlightColor'] = '#07c';
$config['mail_from'] = 'Semantic Pingback Server <frischmuth@informatik.uni-leipzig.de>';
#$config['mail_to'] = 'me@example.org';
$config['mail_senderName'] = 'Agile Knowledge Engineering and Semantic Web (AKSW)';
$config['mail_subject'] = 'Semantic Pingback';
$config['mail_bye'] = 'Yours, AKSW';

# Mail templates
$config['mail_templates'] = array();
$config['mail_templates']['generic'] = 'generic';
#$config['mail_templates']['http://xmlns.com/foaf/0.1/knows'] = 'foaf_knows';