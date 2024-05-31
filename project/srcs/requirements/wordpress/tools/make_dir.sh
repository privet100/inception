#!/bin/bash
# проверить на существование директории, которые нам необходимы, если их нет, то создать
if [ ! -d "/home/${USER}/data" ]; then
        mkdir ~/data
        mkdir ~/data/mariadb
        mkdir ~/data/wordpress
fi
