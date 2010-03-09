
CREATE TABLE IF NOT EXISTS `sp_pingbacks` (
  `id` tinyint(3) unsigned NOT NULL auto_increment,
  `s` varchar(255) character set ascii collate ascii_bin NOT NULL,
  `p` varchar(255) character set ascii collate ascii_bin NOT NULL,
  `o` varchar(255) character set ascii collate ascii_bin NOT NULL,
  PRIMARY KEY  (`id`)
) ;
