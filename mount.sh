#!/bin/bash

sshfs -o ro,allow_other,default_permissions,ServerAliveInterval=15,ServerAliveCountMax=3 ubuntu@172.31.19.100:/var/chroot/lbd/var/www/lbd/sites/default/files/ /mnt/drupal-files-source
