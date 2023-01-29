# Labdoo Installation Guide

This document provides instructions for installing and configuring the Labdoo platform.

## Requirements

### System Requirements
- PHP 8.1 or higher
- MySQL 5.7.8 or MariaDB 10.3.7 or higher
- Apache 2.4 or Nginx
- Composer 2.x
- Git

### PHP Extensions
The following PHP extensions are required:
- curl
- gd
- mbstring
- opcache
- pdo
- pdo_mysql
- xml
- redis (for caching)

## Installation Steps

### 1. Clone the Repository

```bash
git clone [repository-url] labdoo
cd labdoo
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure the Web Server

#### Apache Configuration Example

Create a virtual host configuration:

```apache
<VirtualHost *:80>
    ServerName labdoo3.local
    DocumentRoot /path/to/labdoo/web
    
    <Directory /path/to/labdoo/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx Configuration Example

```nginx
server {
    listen 80;
    server_name labdoo3.local;
    root /path/to/labdoo/web;
    
    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }
    
    location = /robots.txt {
        allow all;
        log_not_found off;
        access_log off;
    }
    
    location ~* \.(txt|log)$ {
        deny all;
    }
    
    location ~ \..*/.*\.php$ {
        return 403;
    }
    
    location ~ ^/sites/.*/private/ {
        return 403;
    }
    
    location ~ ^/sites/[^/]+/files/.*\.php$ {
        deny all;
    }
    
    location ~* ^/.well-known/ {
        allow all;
    }
    
    location ~ (^|/)\. {
        return 403;
    }
    
    location / {
        try_files $uri /index.php?$query_string;
    }
    
    location @rewrite {
        rewrite ^ /index.php;
    }
    
    location ~ /vendor/.*\.php$ {
        deny all;
        return 404;
    }
    
    location ~ '\.php$|^/update.php' {
        fastcgi_split_path_info ^(.+?\.php)(|/.*)$;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_intercept_errors on;
        fastcgi_read_timeout 300;
    }
    
    location ~ ^/sites/.*/files/styles/ {
        try_files $uri @rewrite;
    }
    
    location ~ ^(/[a-z\-]+)?/system/files/ {
        try_files $uri /index.php?$query_string;
    }
    
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        try_files $uri @rewrite;
        expires max;
        log_not_found off;
    }
}
```

### 4. Create and Configure the Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE labdoo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'labdoo'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON labdoo.* TO 'labdoo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 5. Configure Drupal Settings

Copy the default settings file:

```bash
cp web/sites/default/default.settings.php web/sites/default/settings.php
mkdir -p web/sites/default/files
chmod 777 web/sites/default/files
```

Edit `web/sites/default/settings.php` and add your database configuration:

```php
$databases['default']['default'] = [
  'database' => 'labdoo',
  'username' => 'labdoo',
  'password' => 'your_password',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_unicode_ci',
];
```

### 6. Configure Redis (Optional but Recommended)

Add the following to your `settings.php` file:

```php
// Redis configuration
$settings['redis.connection']['interface'] = 'PhpRedis';
$settings['redis.connection']['host'] = '127.0.0.1';
$settings['redis.connection']['port'] = 6379;
$settings['cache']['default'] = 'cache.backend.redis';
$settings['cache']['bins']['bootstrap'] = 'cache.backend.chainedfast';
$settings['cache']['bins']['discovery'] = 'cache.backend.chainedfast';
$settings['cache']['bins']['config'] = 'cache.backend.chainedfast';
```

### 7. Install Drupal

Navigate to your site in a web browser (e.g., http://labdoo3.local) and follow the installation wizard, or use Drush:

```bash
cd /path/to/labdoo
./vendor/bin/drush site-install --account-name=admin --account-pass=admin_password --db-url=mysql://labdoo:your_password@localhost/labdoo
```

### 8. Import Configuration (if available)

```bash
./vendor/bin/drush config-import --source=config/sync
```

### 9. Run Database Updates

```bash
./vendor/bin/drush updatedb
```

### 10. Clear Cache

```bash
./vendor/bin/drush cache-rebuild
```

## Troubleshooting

### Common Issues

1. **Permission Issues**: Ensure that the web server has appropriate permissions to the files directory:
   ```bash
   chmod -R 755 web/sites/default/files
   chown -R www-data:www-data web/sites/default/files  # Replace www-data with your web server user
   ```

2. **Database Connection Issues**: Verify your database credentials and ensure the MySQL server is running.

3. **Missing PHP Extensions**: Check that all required PHP extensions are installed and enabled.

### Getting Help

If you encounter issues during installation, please check the Drupal documentation or seek help from the Labdoo community.

## Next Steps

After installation, you may want to:

1. Configure user roles and permissions
2. Set up content types and taxonomies
3. Configure search functionality
4. Set up multilingual features
5. Configure email notifications

Refer to the TECHNICAL.md file for more information about the project's structure and functionality.