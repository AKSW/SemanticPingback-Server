FROM debian:jessie

# Let the conatiner know that there is no tty
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update &&\
    apt-get install -y nginx-light php5-fpm php5-curl php5-mysql mysql-server supervisor git &&\
    rm -r /var/lib/apt/lists/*

RUN sed -i "1idaemon off;" /etc/nginx/nginx.conf
ADD nginx-default.conf /etc/nginx/sites-enabled/default

ADD init_mysql.sh /
ADD supervisor.conf /etc/supervisor/conf.d/supervisord.conf

RUN rm -r /var/www/html
RUN git clone https://github.com/AKSW/SemanticPingback-Server.git /var/www/html
ADD config.inc.php.patch /
RUN cp /var/www/html/config.inc.dist.php /var/www/html/config.inc.php
RUN cd /var/www/html/ ; patch < /config.inc.php.patch
RUN cd /var/www/html/ ; php -r "readfile('https://getcomposer.org/installer');" | php ; php composer.phar install

EXPOSE 80
CMD ["/usr/bin/supervisord"]
