![Screenshot from 2024-05-31 21-42-58](https://github.com/privet100/inception/assets/22834202/1cc5a6b3-0b96-43fe-8c03-c92e7ef5c222)

## виртуальная машина
* Создать витртуальную машину (папку в goinfre, оперативной памяти от 512 МБ если на ПК 4-8 ГБ, до 4096 МБ если на ПК от 16 и выше, формат VDI или VHD, динамический формат и 8 гигабайт под диск) 
* устанавливаем [debian](https://www.debian.org/ "скачать debian")
* `apt update; apt install -y ufw docker docker-compose make openbox xinit kitty firefox-esr`
* Пользователь
  + `adduser akostrik`
  + `usermod -aG docker akostrik` добавим в группу docker 
  + `usermod -aG sudo akostrik`
  + в `/etc/sudoers` добавляем `akostrik ALL=(ALL:ALL) ALL` возможность sudo
  + `groups akostrik` проверим
* Порты
  + Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов:  
![Screenshot from 2024-05-31 21-47-48](https://github.com/privet100/inception/assets/22834202/70b3e159-365a-4f65-83e1-60d70d042cae)
  + `ufw enable`
  + `ufw allow 42` 42 для ssh, 443 для сайта (и 80, если будетм тестировать с http) 
* ssh
  + `/etc/ssh/sshd_config`:      
    `Port 42                    # меняем на 42, на школьном маке 22-й занят ssh хостовой машины`  
    `PermitRootLogin yes`   
    `PubkeyAuthentication`  
    `PasswordAuthentication yes # подтверждаем вход по паролю`  
  + `service ssh restart` 
  + `ssh root@localhost -p 4243` на хостовой
* в `/etc/hosts` добавляем `akostrik.42.fr`
* создать папки, можно скриптом ./make_dirs.sh
* установка mkcert и сертификат
  + `apt update -y` 
  + `apt install -y wget curl libnss3-tools` утиллиты, которые помогут нам загрузить mkcert
  + `curl -s https://api.github.com/repos/FiloSottile/mkcert/releases/latest| grep browser_download_url  | grep linux-amd64 | cut -d '"' -f 4 | wget -qi -` загружаем бинарник mkcert
  + `mv mkcert-v*-linux-amd64 mkcert` переименовываем 
  + `chmod a+x mkcert`
  + `mv mkcert /usr/local/bin/` перемещаем в рабочую директорию
  + `cd ~/project/srcs/requirements/tools/`
  + `mkcert akostrik.42.fr`
  + `mv akostrik.42.fr-key.pem akostrik.42.fr.key` чтобы nginx правильно читал
  + `mv akostrik.42.fr.pem akostrik.42.fr.crt`
* настройку можно автоматизировать скриптом https://github.com/tblaase/inception/blob/main/inception_prep.sh
  + а также через Makefile, Dockerfile, docker-compose.yml? 
* пароли: VM root 2, VM akostrik 2, mariadb akostrik 2 

```
root/
├── srcs/
│   ├── requirements/
│   │   ├── nginx/
│   │   │   ├── conf/nginx.conf  
│   │   │   ├── Dockerfile                # builds a Docker image
│   │   │   └── tools/                    # папка для ключей
│   │   ├── mariadb/
│   │   │   ├── conf/create_db.sh         # скрипт, создающий БД   
│   │   │   ├── Dockerfile                # builds a Docker image
│   │   │   └── tools/
│   │   └── wordpress/
│   │       ├── conf/wp-config-create.sh  # конфиг соединит нас с контейнером БД    
│   │       ├── Dockerfile                # builds a Docker image
│   │       └── tools/
│   ├── .env
│   └── docker-compose.yml                # calls dockerfiles
└── Makefile                              # sets up the app, calls docker-compose.yml
```

## контейнер Nginx
Nginx Dockerfile:  
```
FROM alpine:3.19                                          # смотрим версию https://www.alpinelinux.org/ (нельзя alpine:latest) 
RUN apk update && apk upgrade && apk add --no-cache nginx # установить софт, --no-cache nginx = не сохраняя исходники в кэше
EXPOSE 443
CMD ["nginx", "-g", "daemon off;"]                        # для отладки запускаем nginx напрямую (не демон) => логи напрямую в tty контейнера
```

nginx.conf:  
```
server {
  listen              443 ssl;                           # 443 поддерживает только https-протокол
  server_name         akostrik.42.fr www.akostrik.42.fr;
  root                /var/www/;
  index               index.php index.html;              # временно html
  ssl_certificate     /etc/nginx/ssl/akostrik.42.fr.crt;
  ssl_certificate_key /etc/nginx/ssl/akostrik.42.fr.key;
  ssl_protocols       TLSv1.2 TLSv1.3;
  ssl_session_timeout 10m;
  keepalive_timeout   70;
  location / {
    try_files         $uri /index.php?$args /index.html;
    add_header        Last-Modified $date_gmt;
    add_header        Cache-Control 'no-store, no-cache';
    if_modified_since off;
    expires           off;
    etag              off;
  }
}
```

Nginx docker-compose.yml:  
```
version: '3'
services:
  nginx:
    build:
      context: .
      dockerfile: requirements/nginx/Dockerfile
    container_name: nginx  
    ports:
      - "443:443"                                     # только ssl
    volumes:                                          # your volumes will be available in the /home/akostrk/data folder of the host machine using Docker
      - ./requirements/nginx/conf/:/etc/nginx/http.d/ # конфиг, ключи
      - ./requirements/nginx/tools:/etc/nginx/ssl/
      - /home/${USER}/ex3/public/html:/var/www/       # монтируем /var/www из старой конфигурации для пробного запуска nginx (потом удалим иы будем брать файлы из каталога wordpress)
    restart: always                                   # Your containers have to restart in case of a crash ПРОВЕРИТЬ
```
`docker-compose up -d` запускаем конфигурацию  
`https://127.0.0.1`  
`https://akostrik.42.fr`  
`docker-compose down` выключить конфигурацию   

## Контейнер Mariadb
create_db.sh:   
```
#!bin/sh
cat << EOF > /tmp/create_db.sql                               # создание базы
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

Mariadb Dockerfile:
```
FROM alpine:3.16
ARG DB_NAME DB_USER DB_PASS                                   # передача переменных окружения из .env в образ: аргументы используются при только сборке образа (build)
                                                              # второй способ: через environment-секцию внутри сервиса, будут в окружении запущенного контейнера 
RUN apk update && apk add --no-cache mariadb mariadb-client   # устанавливаем mariadb и mariadb-client
RUN mkdir /var/run/mysqld; \
    chmod 777 /var/run/mysqld; \
    { echo '[mysqld]'; \
      echo 'skip-host-cache'; \
      echo 'skip-name-resolve'; \
      echo 'bind-address=0.0.0.0'; \
    } | tee  /etc/my.cnf.d/docker.cnf; \                      # отправляет результат вывода echo в файл
    sed -i "s|skip-networking|skip-networking=0|g" /etc/my.cnf.d/mariadb-server.cnf # заменяет строки в файлах по значению
RUN mysql_install_db --user=mysql --datadir=/var/lib/mysql    # создаём БД из того, что мы сконфигурировали на предыдущем слое
EXPOSE 3306
COPY requirements/mariadb/conf/create_db.sh .
RUN sh create_db.sh && rm create_db.sh
USER mysql                                                    # переключаемся на пользователя mysql, созданного при установке БД
#? COPY tools/db.sh .
#? ENTRYPOINT  ["sh", "db.sh"]
CMD ["/usr/bin/mysqld", "--skip-log-error"]                   # под этим пользователем запускаем БД
```

Mariadb docker-compose.yml:    
```
version: '3'
services:
  nginx:
    build:
      context: .
      dockerfile: requirements/nginx/Dockerfile
    container_name: nginx
    ports:
      - "443:443"
    volumes:
      - ./requirements/nginx/conf/:/etc/nginx/http.d/
      - ./requirements/nginx/tools:/etc/nginx/ssl/
      - /home/${USER}/ex3/public/html:/var/www/
    restart: always
  mariadb:
    build:
      context: .
      dockerfile: requirements/mariadb/Dockerfile
      args:
        DB_NAME: ${DB_NAME}
        DB_USER: ${DB_USER}
        DB_PASS: ${DB_PASS}
        DB_ROOT: ${DB_ROOT}
    container_name: mariadb
    ports:
      - "3306:3306"
    volumes:
      - db-volume:/var/lib/mysql  # примонтировать раздел, чтобы состояние базы не сбрасывалось после каждого перезапуска контейнеров
    restart: always
```
Mariadb Проверка:  
`docker exec -it mariadb mysql -u root`  
`MariaDB [(none)]>` `show databases;` должна показать:  
```
+--------------------+
| Database           |
+--------------------+
| information_schema |
| mysql              |
| performance_schema |
| sys                |
| wordpress          |   # созданная нами база wordpress
+--------------------+
```

## Контейнер wordpress
* `www.conf`:  
  + подсунуть в контейнер правильный конфиг fastcgi (`www.conf`)   
  + запустить в контейнере fastcgi через сокет php-fpm   
* in your WordPress database, there must be two users, one of them being the administrator. The administrator’s username can’t contain admin/Admin or administrator/Administrator (e.g., admin, administrator, Administrator, admin-123, and so forth).
wordpress Dockerfile:  
```
FROM alpine:3.16
ARG PHP_VERSION=8 DB_NAME DB_USER DB_PASS # актуальная версию php https://www.php.net/ , три аргумента из .env
                                          # ARG с параметрами задаёт переменную окружения с переданным параметром
                                          # ARG без параметров берёт параметр из такой же переменной в docker-compose  
RUN apk update && apk upgrade && apk add --no-cache \
    php${PHP_VERSION} \             # php, на нём работает wordpress
    php${PHP_VERSION}-fpm \         # php-fpm для взаимодействия с nginx 
    php${PHP_VERSION}-mysqli \      # php-mysqli для взаимодействия с mariadb
    php${PHP_VERSION}-json \        # все обязательные модули, опустив модули кэширования и дополнительные
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-dom \
    php${PHP_VERSION}-exif \
    php${PHP_VERSION}-fileinfo \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    wget \                          # для скачивания wordpress
    unzip                           # для разархивирования wordpress
    sed -i "s|listen = 127.0.0.1:9000|listen = 9000|g"         /etc/php8/php-fpm.d/www.conf \  # fastcgi слушает соединения по 9000 (путь /etc/php8/php-fpm.d/ зависит от версии php)
    sed -i "s|;listen.owner = nobody |listen.owner = nobody|g" /etc/php8/php-fpm.d/www.conf \
    sed -i "s|;listen.group = nobody |listen.group = nobody|g" /etc/php8/php-fpm.d/www.conf \
    && rm -f /var/cache/apk/*      # очищаем кэш установленных модулей
WORKDIR /var/www                   # рабочий путь
RUN wget https://wordpress.org/latest.zip && \ # скачать wordpress и разархивировать в /var/www
    unzip latest.zip && \
    cp -rf wordpress/* . && \
    rm -rf wordpress latest.zip
COPY ./requirements/wordpress/conf/wp-config-create.sh . # конфигурационный файл
RUN sh wp-config-create.sh && rm wp-config-create.sh && chmod -R 0777 wp-content/ # всем права на wp-conten, чтобы CMS могла скачивать темы, плагины, сохранять файлы
CMD ["/usr/sbin/php-fpm8", "-F"]  # CMD запускает php-fpm (версия должна соответствовать установленной!)  
```

Wordpresse Makefile:    
`srcs/requirements/wordpress/tools./make_dir.sh` создать директории и файлы   
`chmod +x requirements/wordpress/tools/make_dir.sh`  
`requirements/wordpress/tools/make_dir.sh`    
`ls ~/data/` должны увидеть папки wordpress и mariadb  

wp-config-create.sh:    
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
\$table_prefix = 'wp_';   # чтобы в $table_prefix не записалась пустая строка (так как в bash у нас нет такой переменной), экранируем строку обратным слэшем
define( 'WP_DEBUG', false );
if ( ! defined( 'ABSPATH' ) ) {
define( 'ABSPATH', __DIR__ . '/' );}
require_once ABSPATH . 'wp-settings.php';
EOF
fi
```

requirements/nginx/conf/**nginx.conf** (чтобы nginx обрабатывал только php-файлы):  
```
server {
    listen      443 ssl;
    server_name  akostrik.42.fr www.akostrik.42.fr;
    root    /var/www/;
    index index.php;
    ssl_certificate     /etc/nginx/ssl/akostrik.42.fr.crt;
    ssl_certificate_key /etc/nginx/ssl/akostrik.42.fr.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_timeout 10m;
    keepalive_timeout 70;
    location / {
        try_files $uri /index.php?$args;
        add_header Last-Modified $date_gmt;
        add_header Cache-Control 'no-store, no-cache';
        if_modified_since off;
        expires off;
        etag off;
    }
    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass wordpress:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
}
```

### docker-compose.yml    
```
version: '3'
services:
  nginx:
    build:
      context: .
      dockerfile: requirements/nginx/Dockerfile
    container_name: nginx
    depends_on:  # NEW wordpress не запустится, пока контейнер с базой данных не соберётся
      - wordpress
    ports:
      - "443:443"
    networks:    # the network line 
      - inception # все контейнеры в docker-compose или конфигурации которых находятся в одной папке, автоматически объединяются в сеть, но чтобы сеть была доступна по имени, вдобавок к дефолтной собственную сеть
    volumes:
      - ./requirements/nginx/conf/:/etc/nginx/http.d/
      - ./requirements/nginx/tools:/etc/nginx/ssl/
      - wp-volume:/var/www/
    restart: always
  mariadb:
    build:
      context: .
      dockerfile: requirements/mariadb/Dockerfile
      args:
        DB_NAME: ${DB_NAME}  # передадим в контейнер "секреты", хранимые в .env
        DB_USER: ${DB_USER}
        DB_PASS: ${DB_PASS}
        DB_ROOT: ${DB_ROOT}
    container_name: mariadb
    ports:
      - "3306:3306"
    networks:
      - inception
    volumes:
      - db-volume:/var/lib/mysql
    restart: always
  wordpress:
    build:
      context: .
      dockerfile: requirements/wordpress/Dockerfile
      args:
        DB_NAME: ${DB_NAME}
        DB_USER: ${DB_USER}
        DB_PASS: ${DB_PASS}
    container_name: wordpress
    depends_on:
      - mariadb
    restart: always
    networks:
      - inception
    volumes:
      - wp-volume:/var/www/
volumes:
  wp-volume: # общий раздел nginx и wordpress для обмена данными. Можно примонтировать туда и туда одну и ту же папку, но для удобства создадим раздел
    driver_opts:
      o: bind
      type: none
      device: /home/${USER}/data/wordpress
  db-volume:                                    # раздел для хранения базы данных в /home/<username>/data
    driver_opts:
      o: bind
      type: none
      device: /home/${USER}/data/mariadb
networks:
    inception:
        driver: bridge
```

### Проверка
`cd ~/root/srcs`   
`docker-compose up -d --build`   
`docker exec -it wordpress ps aux | grep 'php'` прослушаем сокет php, ожидаем:  
```
    1 root      0:00 {php-fpm8} php-fpm: master process (/etc/php8/php-fpm.conf
    9 nobody    0:00 {php-fpm8} php-fpm: pool www
   10 nobody    0:00 {php-fpm8} php-fpm: pool www
```
`docker exec -it wordpress php -v` проверим работу php  
`docker exec -it wordpress php -m` проверим, все ли модули установились  

## Настройка wordpress
`https://127.0.0.1` в браузере хостовой машины  
Вбиваем нужные нам логин, пароль, имя сайта (akostrik, 2)  
"Установить Wordpress"   
Сообщение об успешной установке и предложением залогиниться   
Стартовая страницу чистого wordpress

## Makefile
`make fclean` перед сохранением в облако   
`make build` развёртывание проекта  
`make down` остановка  
`make` запуск после остановки  

At 42's computer:  
* to stop these services running by default (?):  
`service nginx stop`  
`service mariadb stop`  
`service mysql stop`  

## Notes 
* configure akostrik.42.fr point to your local IP address
* open `https://akostrik.42.fr`
* NGINX is accessed by port 443 only
* you shouldn't be able to access `http://login.42.fr`, no access ia http (port 80)
* TLS **v1.2/v1.3** certificate
* your volumes will be available in `/home/login/data` folder of the host machine using Docker
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
* explain how to login into the database
* the database is not empty
* run `docker stop $(docker ps -qa); docker rm $(docker ps -qa); docker rmi -f $(docker images -qa); docker volume rm $(docker volume ls -q); docker network rm $(docker network ls -q) 2>/dev/null` **!**
* reboot the VM and launch compose again
  + everything is functional
  + both WordPress and MariaDB are configured
  + the changes you made previously to the WordPress website should still be here
* explain
  + How Docker and docker compose work
  + The difference between a Docker image used with docker compose and without docker compose
  + The benefit of Docker compared to VMs
  + The pertinence of the directory structure required for this project
  + an explanation of docker-network
* **убрать .env, test.sh**
* discord
  + остановидлсь на Ton env sera vierge par rapport à docker.
  + Ca sera a ton container nginx de passer les requetes a php-fpm pour executer le php
  + Ok mais je comprend pas l'utilité de devoir link ce volume au containeur nginx
    - Le but c'est de vous simplifier votre config
  + pour installer wp je te conseille d'utiliser la cli, tu peux tout automatiser dans ton script, ça évitera de copier ton dossier wp... https://developer.wordpress.org/cli/commands/
  + Il faut automatiser le plus possible via tes containers
  + Tu sais pas ce qui sera disponible sur la machine qui va le lancer (à part le fait que docker sera installé)
  + Du moment que tu ne te retrouves pas à faire du tail -f and co c'est déjà très bien crois moi
  + Ton env sera vierge par rapport à docker.
* On the mac Apache service is installed by default
  + delete Apache from the computer to avoid any problem with nginx

https://github.com/privet100/general-culture/blob/main/docker.md  
https://www.internetsociety.org/deploy360/tls/basics/  
https://admin812.ru/razvertyvanie-wordpress-s-nginx-php-fpm-i-mariadb-s-pomoshhyu-docker-compose.html
[WordPress Deployment with NGINX, PHP-FPM and MariaDB using Docker Compose](https://www.php.net/manual/en/install.fpm.configuration.php)  
[wordpress](https://make.wordpress.org/hosting/handbook/server-environment)   
https://medium.com/swlh/wordpress-deployment-with-nginx-php-fpm-and-mariadb-using-docker-compose-55f59e5c1a  
https://www.php.net/manual/en/install.fpm.configuration.php  
[PHP configuration](https://www.php.net/manual/en/install.fpm.configuration.php)  
https://github.com/codesshaman/inception  
https://github.com/SavchenkoDV/inception_School21_Ecole42  
https://www.aquasec.com/cloud-native-academy/docker-container/docker-networking/ (!)  
