![Screenshot from 2024-05-31 21-42-58](https://github.com/privet100/inception/assets/22834202/1cc5a6b3-0b96-43fe-8c03-c92e7ef5c222)

## виртуальная машина
* Создать витртуальную машину (папку в goinfre, оперативной памяти от 512 МБ если на ПК 4-8 ГБ, до 4096 МБ если на ПК от 16 и выше, формат VDI или VHD, динамический формат и 8 гигабайт под диск) 
* устанавливаем [debian](https://www.debian.org/ "скачать debian")
* `apt update`
* `apt install -y ufw docker docker-compose make openbox xinit kitty firefox-esr`
* user
  + `adduser akostrik`
  + `usermod -aG docker akostrik` добавим в группу docker 
  + `usermod -aG sudo akostrik`
  + в `/etc/sudoers` добавляем `akostrik ALL=(ALL:ALL) ALL` возможность sudo
  + `groups akostrik` проверим
* Порты
  + Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов:  
![Screenshot from 2024-05-31 21-47-48](https://github.com/privet100/inception/assets/22834202/70b3e159-365a-4f65-83e1-60d70d042cae)
  + `ufw enable`
  + `ufw allow 4242` ssh 
  + `ufw allow 80` если будетм тестировать http 
  + `ufw allow 443` https = port SSL
  + ouverir comme port d’écoute
* le certificat SSL n’a pas été signé par Trusted Authority
  + le navigateur affiche un message d’alerte indiquant que ce site tente surement de vous voler des informations sensibles
  + ne pouvons rien y faire quand il s’agit d’un projet en local et encore moins avec un certificat généré par OpenSSL
* `/etc/hosts`  afin qu’il pointe vers votre adresse IP locale
  + localhost = 127.0.0.1
  + akostrik.42.fr = 127.0.0.1
  + un fichier très visé par les hackers, il permettrait de rediriger google.fr -> un faux google
  + **modifier cet IP dans le fichier de conf de NGINX dans la case server_name**
  +  modifier cet IP dans la génération du certificat SSL, mais bon, celui-ci n’est pas authentifié
* ssh
  + **/etc/ssh/sshd_config**:      
    `Port 4242                  # на школьном маке 22-й занят ssh хостовой машины`  
    `PermitRootLogin yes`   
    `PubkeyAuthentication`  
    `PasswordAuthentication yes # подтверждаем вход по паролю`  
  + `service ssh restart`
  + `service sshd restart`
  + `service ssh status`
  + `ssh root@localhost -p 4242` на хостовой
* ` ./make_dirs.sh` создать папки
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
  + Ca sera a ton container nginx de passer les requetes a php-fpm pour executer le php
  + Ok mais je comprend pas l'utilité de devoir link ce volume au containeur nginx
    - Le but c'est de vous simplifier votre config
  + pour installer wp je te conseille d'utiliser la cli, tu peux tout automatiser dans ton script, ça évitera de copier ton dossier wp... https://developer.wordpress.org/cli/commands/
  + Il faut automatiser le plus possible via tes containers
  + Tu sais pas ce qui sera disponible sur la machine qui va le lancer (à part le fait que docker sera installé)
  + Du moment que tu ne te retrouves pas à faire du tail -f and co c'est déjà très bien crois moi
  + Ton env sera vierge par rapport à docker
  + Le reste tu fais ce que tu veux on va clone ton projet et le lancer si ça fonctionne c'est bien sinon c'est 0
  + https://nginx.org/en/docs/http/configuring_https_servers.html openssl pour la gen du certif
  + tu dois forcer TLSv1.{2,3}
  + je veux pas avoir d'entry point avec une boucle infini genre typiquement les scripts qui utilisent tall -f and co
  + si le service exit de facon anormale, le container doit pouvoir se restart (d'ou l'interet du PID 1)
  + t'as le choix de lancer php en daemon puis afficher du vide, ou lancer php puis afficher ses logs, à toi de trouver comment faire ça proprement
  + Informes toi justement sur le PID 1 et tout ce qui en découle
  + un moyen de vérifier que notre service à l'intérieur de notre container tourne bien en tant que PID 1 ? `top || ps`
  + on peux faire docker-compose --env-file
  + quand je lance mes containers (avec debian:buster), il n'ya pas de repertoire var/www/ dedans... mais si je me souviens bien quand j'ai fait ft_server, var/www + var/www/html ont été crée automatiquement je pense 🤔\
    - /var/www/ tu veux dire ? Au hasard tu as surement mal config un truc. Va dans ton image au pire et regarde ce qu'il se passe.
  + est-ce que c'est Ok de faire quelque chose du genre: CMD /bin/bash /tmp/script.sh && /usr/sbin/php-fpm7.3 --nodaemonize ? Ou bien alors c'est considéré comme étant une commande faisant tourner une boucle inf?
    - Tu connais ENTRYPOINT ?
    - Et surtout pour toi c'est quoi la différence entre ENTRYPOINT et CMD ?
    - 2 links pour comprendre puisque ça peut être tricky
    - https://www.bmc.com/blogs/docker-cmd-vs-entrypoint/ (y'a un truc faux ou pas à jour, contrairement à ce qui est dit l'entrypoint peut bien être modifié au runtime, en cli ou via docker-compose) 
    - surtout https://sysdig.com/blog/dockerfile-best-practices/ même si vous n'utilisez pas d'image distroless
    - https://docs.docker.com/engine/reference/commandline/run/ (fait attention au PID 1)
    - Sinon pour les commands infini je pense surtout aux tail -f /dev/random and co ça va de soit.
    - Dans un premier temps tu es dans la bonne direction
  + CMD permet de définir une commande par défaut que l'on peut override tandis que ENTRYPOINT permet de définir un exécutable comme point d'entrée que l'on ne peut donc pas override
    - D'accord et donc dans ce cas à quel moment tu penses il est bien d'utiliser CMD ou ENTRYPOINT ou les deux ?
    - lorsque tu utilises CMD utilise plutôt CMD ["executable", "params…"] pareil pour les COPY etc c'est plus propre et lisible ! 
  + on peux utiliser ENTRYPOINT afin de définir un process par défaut
  + CMD  en tant que paramètre par défaut, par exemple: `CMD ["--help"], ENTRYPOINT ["ping"]`
  + si je run mon image sans lui donner d'argument c'est ping --help qui va se lancer tandis que si je run mon image en lui donnant par exemple google.fr, c'est ping google.fr qui va se lancer.
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
  + остановилась: Faut manger de la doc avant tout
* On the mac Apache service is installed by default
  + delete Apache from the computer to avoid any problem with nginx

https://tuto.grademe.fr/inception/  
https://github.com/privet100/general-culture/blob/main/docker.md  
https://github.com/rbiodies/Inception?tab=readme-ov-file  
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
https://cloud.google.com/architecture/best-practices-for-building-containers  

