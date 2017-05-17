FROM jrottenberg/ffmpeg:3.1
# UBUNTU BUILD

MAINTAINER andy123@stanford.edu

RUN apt-get update -qq \
    && apt-get -yq --no-install-recommends install \
    apache2

RUN apt-get -yq --no-install-recommends install \
    cron \
    vim \
    supervisor \
    libapache2-mod-php \
    php-mcrypt \
    php-curl \
    && rm -r /var/lib/apt/lists/*

ADD mods/php-mods.ini /etc/php/7.0/apache2/conf.d/50-php-mod.ini

ADD mods/apache-mods.conf /etc/apache2/conf-enabled/50-apache-mods.conf

RUN rm /var/www/html/index.html
ADD html/index.php /var/www/html/index.php

#RUN echo "1"
#VOLUME ['/var/www/html']
EXPOSE 80

# A environmental variable that contains the string for user:pass (as a http base64 encoded variable)
ENV bluemix_user_pass ""

# A file that contains the string for the user:pass
ENV bluemix_user_pass_file ""

ENTRYPOINT []

#WORKDIR     /tmp/workdir
WORKDIR     /tmp

COPY mods/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
CMD ["/usr/bin/supervisord"]
