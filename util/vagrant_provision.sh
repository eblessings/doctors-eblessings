#!/bin/bash
#Script to setup the vagrant instance for running friendica
#
#DO NOT RUN on your physical machine as this won't be of any use 
#and f.e. deletes your /var/www/ folder!
echo "Friendica configuration settings"
sudo apt-get update

# Install virtualbox guest additions
sudo apt-get install virtualbox-guest-x11

#Selfsigned cert
echo ">>> Installing *.xip.io self-signed SSL"
SSL_DIR="/etc/ssl/xip.io"
DOMAIN="*.xip.io"
PASSPHRASE="vaprobash"
SUBJ="
C=US
ST=Connecticut
O=Vaprobash
localityName=New Haven
commonName=$DOMAIN
organizationalUnitName=
emailAddress=
"
sudo mkdir -p "$SSL_DIR"
sudo openssl genrsa -out "$SSL_DIR/xip.io.key" 4096
sudo openssl req -new -subj "$(echo -n "$SUBJ" | tr "\n" "/")" -key "$SSL_DIR/xip.io.key" -out "$SSL_DIR/xip.io.csr" -passin pass:$PASSPHRASE
sudo openssl x509 -req -days 365 -in "$SSL_DIR/xip.io.csr" -signkey "$SSL_DIR/xip.io.key" -out "$SSL_DIR/xip.io.crt"


#Install apache2
echo ">>> Installing Apache2 webserver"
sudo apt-get install -y apache2
sudo a2enmod rewrite actions ssl
sudo cp /vagrant/util/vagrant_vhost.sh /usr/local/bin/vhost
sudo chmod guo+x /usr/local/bin/vhost
    sudo vhost -s 192.168.22.10.xip.io -d /var/www -p /etc/ssl/xip.io -c xip.io -a friendica-xenial.dev
    sudo a2dissite 000-default
    sudo service apache2 restart

#Install php
echo ">>> Installing PHP7"
sudo apt-get install -y php libapache2-mod-php php-cli php-mysql php-curl php-gd php-mbstring php-xml imagemagick php-imagick
sudo systemctl restart apache2


#Install mysql
echo ">>> Installing Mysql"
sudo debconf-set-selections <<< "mysql-server mysql-server/root_password password root"
sudo debconf-set-selections <<< "mysql-server mysql-server/root_password_again password root"
sudo apt-get install -qq mysql-server
# enable remote access
# setting the mysql bind-address to allow connections from everywhere
sed -i "s/bind-address.*/bind-address = 0.0.0.0/" /etc/mysql/my.cnf
# adding grant privileges to mysql root user from everywhere
# thx to http://stackoverflow.com/questions/7528967/how-to-grant-mysql-privileges-in-a-bash-script for this
MYSQL=`which mysql`
Q1="GRANT ALL ON *.* TO 'root'@'%' IDENTIFIED BY 'root' WITH GRANT OPTION;"
Q2="FLUSH PRIVILEGES;"
SQL="${Q1}${Q2}"
$MYSQL -uroot -proot -e "$SQL"
# add a separate database user for friendica
$MYSQL -uroot -proot -e "CREATE USER 'friendica'@'localhost' identified by 'friendica';"
$MYSQL -uroot -proot -e "GRANT ALL PRIVILEGES ON friendica.* TO 'friendica'@'localhost';"
$MYSQL -uroot -proot -e "FLUSH PRIVILEGES"
systemctl restart mysql



#configure rudimentary mail server (local delivery only)
#add Friendica accounts for local user accounts, use email address like vagrant@friendica.dev, read the email with 'mail'.
debconf-set-selections <<< "postfix postfix/mailname string friendica-xenial.dev"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Local Only'"
sudo apt-get install -y postfix mailutils libmailutils-dev
sudo echo -e "friendica1:	vagrant\nfriendica2:	vagrant\nfriendica3:	vagrant\nfriendica4:	vagrant\nfriendica5:	vagrant" >> /etc/aliases && sudo newaliases

#make the vagrant directory the docroot
sudo rm -rf /var/www/
sudo ln -fs /vagrant /var/www

# initial config file for friendica in vagrant
cp /vagrant/util/htconfig.vagrant.php /vagrant/.htconfig.php

# create the friendica database
echo "create database friendica DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" | mysql -u root -proot
# import test database
$MYSQL -uroot -proot friendica < /vagrant/friendica_test_data.sql

# create cronjob - activate if you have enough memory in you dev VM
echo "*/10 * * * * cd /vagrant; /usr/bin/php include/poller.php" >> friendicacron
sudo crontab friendicacron
sudo rm friendicacron

#Optional: checkout addon repositroy
#sudo git clone https://github.com/friendica/friendica-addons.git /vagrant/addon
