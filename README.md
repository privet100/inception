![Screenshot from 2024-05-31 21-42-58](https://github.com/privet100/inception/assets/22834202/1cc5a6b3-0b96-43fe-8c03-c92e7ef5c222)

### VM
+ папка VM в sgoinfre, оперативка от 512 мб, диск VDI или VHD, динамический, 8 гб
+ [debian](https://www.debian.org/ "скачать debian")
  - software to install: ssh
+ `/etc/sudoers`: добавляем `akostrik ALL=(ALL:ALL) ALL`
+ `/etc/hosts`: 127.0.0.1 localhost akostrik.42.fr
+ ssh
  - `/etc/ssh/sshd_config`: Port 22, PermitRootLogin yes, PasswordAuthentication yes  
  - Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов (на школьном маке 22 занят ssh хостовой машины):
    | Name    | Protocol | Host IP     | Host Port    | Guest IP    | Guest Port   |
    | ------- | -------- | ----------- | ------------ | ----------- | ------------ |
    | `ssh`   | `TCP`    | `127.0.0.1` | `4249`       | `10.0.2.15` | `22`         |
    | `http`  | `TCP`    | `127.0.0.1` | `8080`       | `10.0.2.15` | `80`         |
    | `http`  | `TCP`    | `127.0.0.1` | `443`        | `10.0.2.15` | `443`        |
  - `sudo ufw enable` 
  - `sudo ufw allow 22; sudo ufw allow 80; sudo ufw allow 443`
  - `/etc/init.d/ssh restart`
  - `ssh root@localhost -p 4249` на хостовой
+ ```
  #!/bin/bash
  apt update
  apt install -y ufw docker docker-compose make openbox xinit kitty firefox-esr
  adduser akostrik
  usermod -aG docker akostrik
  usermod -aG sudo akostrik
  mkdir -p ./srcs
  mkdir -p ./srcs/requirements/nginx
  mkdir -p ./srcs/requirements/nginx/conf
  mkdir -p ./srcs/requirements/nginx/tools
  mkdir -p ./srcs/requirements/mariadb
  mkdir -p ./srcs/requirements/mariadb/conf
  mkdir -p ./srcs/requirements/mariadb/tools
  mkdir -p ./srcs/requirements/wordpress
  mkdir -p ./srcs/requirements/wordpress/conf
  touch ./srcs/requirements/mariadb/conf/create_db.sh
  touch ./srcs/requirements/mariadb/Dockerfile
  touch ./srcs/docker-compose.yml
  touch ./srcs/requirements/nginx/conf/nginx.conf
  touch ./srcs/requirements/nginx/Dockerfile
  touch ./srcs/requirements/wordpress/conf/wp-config-create.sh
  touch ./srcs/requirements/wordpress/Dockerfile
  touch ./srcs/requirements/mariadb/.dockerignore
  touch ./srcs/requirements/nginx/.dockerignore
  touch ./srcs/requirements/wordpress/.dockerignore
  su
  apt update -y; apt install -y wget curl libnss3-tools
  curl -s https://api.github.com/repos/FiloSottile/mkcert/releases/latest| grep browser_download_url  | grep linux-amd64 | cut -d '"' -f 4 | wget -qi -
  mv mkcert-v*-linux-amd64 /usr/local/bin/mkcert
  chmod a+x /usr/local/bin/mkcert
  cd ./srcs/requirements/nginx/tools
  mkcert akostrik.42.fr
  mv akostrik.42.fr-key.pem akostrik.42.fr.key;
  mv akostrik.42.fr.pem akostrik.42.fr.crt
  ```
+ le certificat SSL n’a pas été signé par Trusted Authority => une alerte "ce site tente de vous voler des informations"
+ пароли: VM root 2, VM akostrik 2, mariadb akostrik 2 
+ Makefile:                             
  - Sets up the app  
  - all = после остановки  
  - fclean перед сохранением в облако
+ srcs/docker-compose.yml:                
  - calls dockerfiles

### srcs/requirements/nginx/Dockerfile                
Builds a Docker image  
https://www.alpinelinux.org  
для отладки запускаем nginx напрямую (не демон), логи в tty контейнера   
```
FROM alpine:3.19 
RUN apk update && apk upgrade && apk add --no-cache nginx
EXPOSE 443
CMD ["nginx", "-g", "daemon off;"]
```

### srcs/requirements/nginx/tools/akostrik.42.fr

### srcs/requirements/nginx/tools/akostrik.42.fr

### srcs/requirements/mariadb/conf/create_db.sh
```
#!bin/sh
cat << EOF > /tmp/create_db.sql
USE mysql;
FLUSH PRIVILEGES;
DELETE FROM     mysql.user WHERE User='';
DROP DATABASE test;
DELETE FROM mysql.db WHERE Db='test';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT}';
CREATE DATABASE ${DB_NAME} CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER '${DB_USER}'@'%' IDENTIFIED by '${DB_PASS}';
GRANT ALL PRIVILEGES ON wordpress.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
EOF
# run init.sql 
/usr/bin/mysqld --user=mysql --bootstrap < /tmp/create_db.sql  # выполняем код
rm -f /tmp/create_db.sql
```

### srcs/requirements/mariadb/Dockerfile
тут из .env только при build  
не используем тут: арг из environment-секции внутри сервиса - в окружении запущенного контейнера  
ещё можно переменнын оеркжегия из docker-compose ?  
БД из сконфигурированного на пред. слое, user mysql создан при установке БД  
```
FROM alpine:3.19
ARG DB_NAME DB_USER DB_PASS
RUN apk update && apk add --no-cache mariadb mariadb-client
RUN mkdir /var/run/mysqld; chmod 777 /var/run/mysqld; \
  { echo '[mysqld]'; echo 'skip-host-cache'; echo 'skip-name-resolve'; echo 'bind-address=0.0.0.0'; } | tee  /etc/my.cnf.d/docker.cnf; \
  sed -i "s|skip-networking|skip-networking=0|g" /etc/my.cnf.d/mariadb-server.cnf
RUN mysql_install_db --user=mysql --datadir=/var/lib/mysql
EXPOSE 3306
COPY requirements/mariadb/conf/create_db.sh .
RUN sh create_db.sh && rm create_db.sh
USER mysql                                                
#? COPY tools/db.sh .
#? ENTRYPOINT  ["sh", "db.sh"]
CMD ["/usr/bin/mysqld", "--skip-log-error"]               
```

### srcs/requirements/wordpress/conf/wp-config-create.sh 
Соединит с контейнером БД  
экранируем \, чтобы в $table_prefix не записалась пустая строка (т.к. в bash нет такой переменной)  
```
#!bin/sh
if [ ! -f "/var/www/wp-config.php" ]; then
cat << EOF > /var/www/wp-config.php
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', 'mariadb' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define('FS_METHOD','direct');
\$table_prefix = 'wp_';
define( 'WP_DEBUG', false );
if ( ! defined( 'ABSPATH' ) ) {
define( 'ABSPATH', __DIR__ . '/' );}
require_once ABSPATH . 'wp-settings.php';
EOF
fi
```

### srcs/requirements/wordpress/Dockerfile
wordpress работает на php, версия php (https://www.php.net/) соответствует установленной  
php-fpm для взаимодействия с nginx, запустить fastcgi через сокет php-fpm, fastcgi слушает на 9000 (путь /etc/php8/php-fpm.d/ зависит от версии php)   
конфиг fastcgi в контейнере `www.conf`   
CMS может скачивать темы, плагины, сохранять файлы  
```
FROM alpine:3.16
ARG PHP_VERSION=8 \
    DB_NAME \
    DB_USER \
    DB_PASS
RUN apk update && apk upgrade && apk add --no-cache \
    php${PHP_VERSION} \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-mysqli \
    php${PHP_VERSION}-json \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-dom \
    php${PHP_VERSION}-exif \
    php${PHP_VERSION}-fileinfo \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-redis \
    wget \
    unzip && \
    sed -i "s|listen = 127.0.0.1:9000|listen = 9000|g" \
      /etc/php8/php-fpm.d/www.conf && \
    sed -i "s|;listen.owner = nobody|listen.owner = nobody|g" \
      /etc/php8/php-fpm.d/www.conf && \
    sed -i "s|;listen.group = nobody|listen.group = nobody|g" \
      /etc/php8/php-fpm.d/www.conf && \
    rm -f /var/cache/apk/*
WORKDIR /var/www
RUN wget https://wordpress.org/latest.zip && \
    unzip latest.zip && \
    cp -rf wordpress/* . && \
    rm -rf wordpress latest.zip
COPY ./requirements/wordpress/conf/wp-config-create.sh .
RUN sh wp-config-create.sh && rm wp-config-create.sh && \
    chmod -R 0777 wp-content/
CMD ["/usr/sbin/php-fpm8", "-F"]
```

### srcs/requirements/nginx/.dockerignore
### srcs/requirements/mariadb/.dockerignore
### srcs/requirements/wordpress/.dockerignore
три файла `.git`

### Проверка
`docker-compose up -d --build` запускаем конфигурацию  
`https://127.0.0.1`  
`https://akostrik.42.fr`  
`docker exec -it wordpress php -m` проверим, все ли модули установились  
`docker exec -it wordpress php -v` проверим работу php  
`docker exec -it wordpress ps aux | grep 'php'` прослушаем сокет php, ожидаем:  
```
1 project   0:00 {php-fpm8} php-fpm: master process (/etc/php8/php-fpm.conf
...
10 nobody    0:00 {php-fpm8} php-fpm: pool www
```
`service nginx stop`  
`service mariadb stop`  
`service mysql stop`  
`docker-compose down`

## VM vs docker
| VM                                               | Docker                                                           |
| ------------------------------------------------ | ---------------------------------------------------------------- |
| a lot of memory space                            | a lot less memory space                                          |
| long time to boot up                             | quick boot up because it uses the running kernel that you using  |
| difficult to scale up                            | super easy to scale                                              |
| low efficiency                                   | high efficiency                                                  |
| volumes storage cannot be shared across the VM’s | volumes storage can be shared across the host and the containers |

## Notes 
* open `https://akostrik.42.fr`
* TLS **v1.2/v1.3** certificate
* your volumes will be available in `/home/akostrik/data` folder of the host machine using Docker
* docker-network is used by checking the docker-compose.yml
  + 'docker network ls' to verify the network 
* 'docker volume ls', 'docker volume inspect wordpress'
  + the result contains '/home/akostrik/data/'
* add a comment using the available WordPress user
* WordPress database: 2 users, one of them being the administrator
  + the Admin username must not include 'admin' 'Admin' admin administrator Admin-login admin-123, etc
* sign in with the administrator account to access the Administration dashboard
  + from the Administration dashboard, edit a page
  + verify on the website that the page has been updated
* 'docker volume inspect mariadb'
  + the result contains '/home/akostrik/data/'
* the database is not empty
* run `docker stop $(docker ps -qa); docker rm $(docker ps -qa); docker rmi -f $(docker images -qa); docker volume rm $(docker volume ls -q); docker network rm $(docker network ls -q) 2>/dev/null` **!**
* reboot the VM and launch compose again
  + everything is functional
  + both WordPress and MariaDB are configured
  + the changes you made previously to the WordPress website should still be here
* explain
  + how to login into the database
  + How Docker and docker compose work
  + The difference between a Docker image used with docker compose and without docker compose
  + The benefit of Docker compared to VMs
  + The pertinence of the directory structure required for this project
  + an explanation of docker-network
  + Read about how daemons work and whether it’s a good idea to use them or not
* **убрать .env, test.sh**
* discord
  + ca sera a ton container nginx de passer les requetes a php-fpm pour executer le php
  + je comprend pas l'utilité de devoir link ce volume au containeur nginx
    - Le but c'est de vous simplifier votre config
  + pour installer wp je te conseille d'utiliser la cli, tu peux tout automatiser dans ton script, ça évitera de copier ton dossier wp... https://developer.wordpress.org/cli/commands/
  + automatiser le plus possible via tes containers
  + tu sais pas ce qui sera disponible sur la machine qui va le lancer (à part le fait que docker sera installé)
  + Ton env sera vierge par rapport à docker
  + on va clone ton projet et le lancer si ça fonctionne c'est bien, sinon c'est 0
  + https://nginx.org/en/docs/http/configuring_https_servers.html openssl pour la gen du certif
  + TLSv1.{2,3}
  + pas d'entry point avec une boucle infini genre typiquement les scripts qui utilisent tall -f and co
  + si le service exit de facon anormale, le container doit pouvoir se restart (**d'ou l'interet du PID 1**)
    - informes toi sur le PID 1 et tout ce qui en découle
    - un moyen de vérifier que notre service à l'intérieur de notre container tourne bien en tant que PID 1 ? `top || ps`
  + t'as le choix de lancer php en daemon puis afficher du vide, ou lancer php puis afficher ses logs
  + docker-compose --env-file
  + est-ce que c'est Ok de faire quelque chose du genre: CMD /bin/bash /tmp/script.sh && /usr/sbin/php-fpm7.3 --nodaemonize ?
    - Tu connais ENTRYPOINT ?
    - c'est quoi la différence entre ENTRYPOINT et CMD ?
    - https://www.bmc.com/blogs/docker-cmd-vs-entrypoint/ (y'a un truc faux ou pas à jour, contrairement à ce qui est dit l'entrypoint peut bien être modifié au runtime, en cli ou via docker-compose) 
    - surtout https://sysdig.com/blog/dockerfile-best-practices/ même si vous n'utilisez pas d'image distroless
    - https://docs.docker.com/engine/reference/commandline/run/ (fait attention au PID 1)
    - Sinon pour les commands infini je pense surtout aux tail -f /dev/random and co ça va de soit
  + CMD = définir une commande par défaut que l'on peut override
    - lorsque tu utilises CMD utilise plutôt CMD ["executable", "params…"] pareil pour les COPY etc c'est plus propre et lisible ! 
    - CMD en tant que paramètre par défaut, par exemple: `CMD ["--help"], ENTRYPOINT ["ping"]`
  + ENTRYPOINT = définir un exécutable comme point d'entrée que l'on ne peut donc pas override
    - on peux utiliser ENTRYPOINT afin de définir un process par défaut
  + si je run mon image sans lui donner d'argument c'est ping --help qui va se lancer
  + si je run mon image en lui donnant google.fr, c'est ping google.fr qui va se lancer
  + Tu peux même avoir des trucs genre : ENTRYPOINT ["echo", "Hello"]CMD ["hehe"]
  + faire un script en entrypoint qui récupère éventuellement les arguments que je pourrais donner avec un docker run, dans lequel je vais pouvoir faire ce dont j'ai besoin au runtime et qui finirait par exemple par un  exec /usr/sbin/php-fpm7.3 --nodaemonize afin de "remplacer" mon script par php-fpm (qui conserverait donc bien le PID 1 et qui pourrais donc catch comme il faut les signaux)
    - est-ce que tu vas vraiment gagner quelque chose a pouvoir passer des arguments au scrip
    - pour les parametres de ce que j'ai pu voir la pratique repandue c'est plus avec variables d'env
    -  ca permet de faire docker run php --version par exemple, AKA la vraie commande mais avec juste docker run devant (si tu fais une image php) 
  + Le principe de docker c'est pas d'avoir 50 services pour tout faire mais un seul qui fait une chose. Comme une fonction en C tu peux faire un programme avec uniquement un main ou faire des fonctions. Ben docker c'est pareil. Tu utilises docker-compose qui permet d'avoir la possibilité de link simplement tes services donc utilise ça.
  + Tu as pas mal d'image distroless and co. Ici je ne demande pas ça.
  + le PID 1 sur un systeme c’est systemd si je ne m’abuse par contre dans un container c’est différent il ne peux pas y avoir de systemd je crois
    - Na mais je ne te demande pas ça à toi spécifiquement (no stress) juste que si tu as un doute sur un truc dans le sujet faut pas hésiter à chercher c'est tout
  + le PID 1 sur un systeme c’est systemd, dans un container c’est différent, il ne peux pas y avoir de systemd
  + voir systemctl sur nginx m'a fait du mal
    - systemctl start nginx dans un container n’est pas possible
    - possible techniquement mais c'est pas dingue
  + Les images officielles de nginx, mariadb, etc, sont en effet de très bonnes inspirations
  + Tu connais les différences entre RUN CMD ENTRYPOINT ?
  + tu connais le flag init sur docker ?
  + 'fin faut pas regarder des images docker si tu sais pas définir ce que je viens de demander. Faut manger de la doc avant tout. ça te parle ['sh', 'test.sh'] vs sh /opt/test.sh ? '
  + faut voir docker compose comme un simple wrapper build au dessus de docker 
  + повтор: les Shared Folders de la VM ou qu'un serveur SSH mal configuré sur la VM peuvent poser problème
  + повтор: **Est ce qu'il faut avoir accès à login.42.fr sur la machine physique Ou uniquement virtuel?**
  + остановилась: docker-compose il va juste simplifier tes commandes docker pour tout mettre en place comme tu veux.
(fait attention à l'ordre des services que tu vas deploy ça peut poser des problèmes)

[docker](https://github.com/privet100/general-culture/blob/main/docker.md)  
https://github.com/Forstman1/inception-42    
https://github.com/codesshaman/inception  
https://github.com/rbiodies/Inception   
https://github.com/SavchenkoDV/inception_School21_Ecole42  
[WordPress NGINX,PHP-FPM MariaDB](https://www.php.net/manual/en/install.fpm.configuration.php)  
[WordPress NGINX,PHP-FPM MariaDB](https://medium.com/swlh/wordpress-deployment-with-nginx-php-fpm-and-mariadb-using-docker-compose-55f59e5c1a)  
https://tuto.grademe.fr/inception/  
https://cloud.google.com/architecture/best-practices-for-building-containers  
https://www.aquasec.com/cloud-native-academy/docker-container/docker-networking/ (!)   
https://www.internetsociety.org/deploy360/tls/basics/   
[wordpress](https://make.wordpress.org/hosting/handbook/server-environment)   
https://www.php.net/manual/en/install.fpm.configuration.php  
[PHP configuration](https://www.php.net/manual/en/install.fpm.configuration.php)   
https://admin812.ru/razvertyvanie-wordpress-s-nginx-php-fpm-i-mariadb-s-pomoshhyu-docker-compose.html
