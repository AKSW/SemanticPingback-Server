<?php
/**
 * This is the main configuration file.
 *
 * @author     Philipp Frischmuth <pfrischmuth@googlemail.com>
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @version    $Id: $
 */

# Database configuration
###############################################################################
$config['db'] = mysql_connect('localhost','__user__','__password__');
mysql_select_db('__database__');

# Target configuration
###############################################################################
# Set to true if target URIs outside the namespace of the domain are allowed
$config['target_allow_external'] = false;

# Mail configuration
###############################################################################
$config['mail_send'] = true; 
$config['mail_copyToSource'] = false;
$config['mail_linkColor'] = '#07c';
$config['mail_highlightColor'] = '#07c';
$config['mail_from'] = 'Semantic Pingback Server <me@example.org>';
#$config['mail_to'] = 'me@example.org';
$config['mail_senderName'] = '';
$config['mail_subject'] = 'Semantic Pingback';
$config['mail_bye'] = '';

# Mail templates
###############################################################################
$config['mail_templates'] = array();
$config['mail_templates']['generic'] = 'generic';
#$config['mail_templates']['http://xmlns.com/foaf/0.1/knows'] = 'foaf_knows';
