#!/bin/sh

sleep 3
echo "create database pingback" | /usr/bin/mysql -u root
/usr/bin/mysql -u root pingback < /var/www/html/schema.sql


