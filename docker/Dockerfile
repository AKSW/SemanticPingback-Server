FROM debian:jessie

# Let the conatiner know that there is no tty
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update &&\
    apt-get install -y nginx-light php5-fpm php5-curl php5-mysql mysql-server supervisor &&\
    rm -r /var/lib/apt/lists/*

RUN sed -i "1idaemon off;" /etc/nginx/nginx.conf
ADD nginx-default.conf /etc/nginx/sites-enabled/default

VOLUME /var/www/html
EXPOSE 80

ADD init_mysql.sh /

ADD supervisor.conf /etc/supervisor/conf.d/supervisord.conf

CMD ["/usr/bin/supervisord"]
