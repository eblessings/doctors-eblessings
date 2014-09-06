#!/bin/bash
#Script to setup the vagrant instance for running friendica
#
#DO NOT RUN on your physical machine as this won't be of any use 
#and f.e. deletes your /var/www/ folder!

#make the vagrant directory the docroot
rm -rf /var/www/
ln -fs /vagrant /var/www

#delete .htconfig.php file if it exists to have a fresh friendica 
#installation
if [ -f /vagrant/.htconfig.php ]
  then
    rm /vagrant/.htconfig.php
fi

#change ownership of dir where sessions are stored
chown -R www-data:www-data /var/lib/php5

#create the friendica database
echo "create database friendica" | mysql -u root -proot

#create cronjob
echo "*/10 * * * * cd /vagrant; /usr/bin/php include/poller.php" >> friendicacron
crontab friendicacron
rm friendicacron

#Optional: checkout addon repositroy
#git clone https://github.com/friendica/friendica-addons.git /vagrant/addon