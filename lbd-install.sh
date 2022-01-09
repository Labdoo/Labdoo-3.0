#!/bin/bash

set -x

LBD_PSWD="grassroots"
LBD_DOMAIN="www.labdoo-dev.org"

# Ensure system is up to date
sudo apt update
# sudo apt -y upgrade && sudo systemctl reboot

# Install MySql
sudo apt install -y mariadb-server mariadb-client

# Secure your database server by setting root password, 
# disabling root remote logins and removing test databases that we don’t need.
#mysql -u root <<EOF
#SET PASSWORD FOR root@localhost = PASSWORD('${LBD_PSWD}');
#FLUSH PRIVILEGES;
#EOF

# Note: the following assumes that the root password for mysql is disabled. 
# If not, run first this command to ensure it is:
#    mysqladmin -u root -pTHE_CURRENT_PASSWORD password ''

mysqladmin -u root -p${LBD_PSWD} password ''

sed -e 's/\s*\([\+0-9a-zA-Z]*\).*/\1/' << EOF | mysql_secure_installation
      # current root password (emtpy after installation)
    y # Set root password?
    ${LBD_PSWD} # new root password
    ${LBD_PSWD} # new root password
    y # Remove anonymous users?
    y # Disallow root login remotely?
    y # Remove test database and access to it?
    y # Reload privilege tables now?
EOF

# Allow normal users to login as root user with a password
mysql -u root -p${LBD_PSWD}<<EOF
UPDATE mysql.user SET plugin = 'mysql_native_password' WHERE User = 'root';
FLUSH PRIVILEGES;
EOF

# Create the Drupal DB
#mysql -u root -p${LBD_PSWD}<<EOF
#CREATE DATABASE labdoo_db;
#GRANT ALL PRIVILEGES ON labdoo_db.* TO 'labdoo_db'@'localhost' IDENTIFIED BY 'grassroots';
#FLUSH PRIVILEGES;
#EOF

# Install PHP
sudo apt install -y php php-{cli,fpm,json,common,mysql,zip,gd,intl,mbstring,curl,xml,pear,tidy,soap,bcmath,xmlrpc}

# Install Apache
sudo apt install -y apache2 libapache2-mod-php

# Set PHP memory limit
sed -i "s/memory_limit = .*/memory_limit = 256M/" /etc/php/*/apache2/php.ini

# Install Composer
mkdir -p ./composer
cd ./composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
cd ../

# Install Drupal9
mv ./drupal9 ./drupal9.$(date +%s) &>/dev/null
mkdir -p ./drupal9

composer create-project --no-interaction drupal/recommended-project ./drupal9
cd ./drupal9

# Require Drush so we can start using it
composer require --no-interaction drush/drush:^10

# Install the base Drupal site
./vendor/bin/drush site-install standard --yes --db-url='mysql://root:grassroots@localhost:3306/lbd_db' --account-name=admin --account-pass=grassroots --site-name=${LBD_DOMAIN} --site-mail=jordi.ros@labdoo.org

# Adjust development settings
chmod +w web/sites/default
mkdir web/sites/default/settings
cp web/sites/default/settings.php web/sites/default/settings/settings.shared.php
chmod 644 web/sites/default/settings/settings.shared.php
chmod +w web/sites/default/settings.php

cat <<'EOF' > web/sites/default/settings.php 
<?php
include __DIR__ . '/settings/settings.shared.php';
EOF

cat <<EOF >> web/sites/default/settings/settings.shared.php

\$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127.0.0.1$',
  '^${LBD_DOMAIN}$',
];
EOF

cp web/sites/example.settings.local.php web/sites/default/settings/settings.local.php
cd ../

# Create link from Apache path to Drupal installation
sudo ln -nsf $(pwd)/drupal9/web/ /var/www/lbd

sudo sed -i "s/DocumentRoot .*/DocumentRoot \/var\/www\/lbd/" /etc/apache2/sites-available/000-default.conf
sudo sed -i "s/DocumentRoot .*/DocumentRoot \/var\/www\/lbd/" /etc/apache2/sites-available/default-ssl.conf 

# Set AllowOverride to All for our Apache path
sudo sed -i '/Directory \/var\/www/{n;n;s/.*/\tAllowOverride All/}' /etc/apache2/apache2.conf

# Have Apache user own the Drupal tree
sudo chown -R www-data:www-data ./drupal9

# Add a development host entry to the hosts file if it's not there already
grep -q ${LBD_DOMAIN} /etc/hosts || sudo sed -i -e "\$a127.0.0.1       ${LBD_DOMAIN}" /etc/hosts

# Restart the services
sudo /etc/init.d/apache2 restart
sudo /etc/init.d/php7.4-fpm restart
sudo /etc/init.d/mysql restart
