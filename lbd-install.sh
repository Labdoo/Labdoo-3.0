#!/bin/bash

# Ensure system is up to date
sudo apt update
sudo apt -y upgrade # && sudo systemctl reboot

# Install MySql
sudo apt install -y mariadb-server mariadb-client


