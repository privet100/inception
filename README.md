![Screenshot from 2024-05-31 21-42-58](https://github.com/privet100/inception/assets/22834202/1cc5a6b3-0b96-43fe-8c03-c92e7ef5c222)

+ VM
  - папка в sgoinfre
    * на время работы перемещать в goinfre, будет быстрее работать
  - RAM 2 GB
  - диск VDI или VHD динамический 15 GB
  - CPU 1C
+ install [debian 12](https://www.debian.org)
  - software to install: ssh
  - user akostrik
+ ssh
  - Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов (22 занят ssh хостовой машины, 443 чтобы с хостовой заходить на сайт):
    | Name    | Protocol | Host IP     | Host Port    | Guest IP    | Guest Port   |
    | ------- | -------- | ----------- | ------------ | ----------- | ------------ |
    | `ssh`   | `TCP`    | `127.0.0.1` | `4252`       | `10.0.2.15` | `22`         |
    | `https` | `TCP`    | `127.0.0.1` | `1443`       | `10.0.2.15` | `443`        |
  - ```
    su
    nano /etc/ssh/sshd_config : Port 22, PasswordAuthentication yes
    /etc/init.d/ssh restart
    ```
  - `ssh akostrik@localhost -p 4252` на хостовой
+ ```
  su
  apt update -y; apt install -y ufw sudo docker docker-compose make openbox xinit kitty firefox-esr wget curl libnss3-tools
  /usr/sbin/usermod -aG docker akostrik
  /usr/sbin/usermod -aG sudo akostrik
  nano /etc/hosts: 127.0.0.1 akostrik.42.fr 
  nano /etc/sudoers: akostrik ALL=(ALL:ALL) ALL
  exit
  sudo ufw enable; sudo ufw allow ssh; sudo ufw allow http; sudo ufw allow https
  mkdir ~/.ssh/
  cd ~/.ssh
  ssh-keygen -t rsa
  cat ~/.ssh/id_rsa.pub - добавить ключ в git
  cd ~
  git clone https://github.com/privet100/inception inception
  sudo curl -s https://api.github.com/repos/FiloSottile/mkcert/releases/latest| grep browser_download_url  | grep linux-amd64 | cut -d '"' -f 4 | wget -qi -
  sudo mv mkcert-v*-linux-amd64 /usr/local/bin/mkcert
  chmod a+x /usr/local/bin/mkcert
  cd ~/inception/project/srcs/requirements/nginx
  mkcert akostrik.42.fr
  mv akostrik.42.fr-key.pem akostrik.42.fr.key
  mv akostrik.42.fr.pem akostrik.42.fr.crt
  sudo shutdown now (или reboot?)
  cd ~/inception/project
  make
  ```
+ пароли: VM root 2, VM akostrik 2, mariadb akostrik 2 

### Проверка
* `docker exec -it wordpress php -m` все ли модули установились
* `docker exec -it wordpress php -v` проверим работу php
* `docker exec -it wordpress ps aux | grep 'php'` прослушаем сокет php
  + ожидаем: `1 project   0:00 {php-fpm8} php-fpm: master process (/etc/php8/php-fpm.conf` etc
* [Инспектировать](https://github.com/privet100/general-culture/blob/main/docker.md#%D0%B8%D0%BD%D1%81%D0%BF%D0%B5%D0%BA%D1%82%D0%B8%D1%80%D0%BE%D0%B2%D0%B0%D1%82%D1%8C)
*  `wget https://akostrik.42.fr --no-check-certificate`
*  `curl 'http://127.0.0.1'`
*  telnet ?
* `sudo start x`, на VM в браузере `https://127.0.0.1`, `https://akostrik.42.fr`
* `service nginx stop; service mariadb stop; service mysql stop; docker-compose down` (!)
* add a comment using the available WordPress user
* WordPress database: 2 users, one of them being the administrator
  + the Admin username must not include admin, administrator, Admin-login, admin-123, etc
* sign in with the administrator account to access the Administration dashboard
  + from the Administration dashboard, edit a page
  + verify on the website that the page has been updated
* the database is not empty
* le certificat SSL n’a pas été signé par Trusted Authority => une alerte

### Пояснения к файлам
+ Makefile                             
  - all после остановки  
  - fclean перед сохранением в облако
+ srcs/.env
  ```
  DB_NAME=wp
  DB_ROOT=2
  DB_USER=wpuser
  DB_PASS=2
  ```
+ ./srcs/requirements/nginx/Dockerfile                
  - https://www.alpinelinux.org  
  - для отладки запускаем nginx напрямую (не демон), логи в tty контейнера   
+ ./srcs/requirements/mariadb/Dockerfile
  - БД из сконфигурированного на пред. слое
  - user mysql создан при установке БД  
  - переменные окружения из .env только при build  
    * другой вариант: из environment-секции внутри сервиса - будут в окружении запущенного контейнера  
    * из docker-compose ?  
+ ./srcs/requirements/wordpress/conf/wp-config-create.sh 
  - Соединит с контейнером БД  
  - экранируем \, чтобы в $table_prefix не записалась пустая строка (т.к. в bash нет такой переменной)  
+ ./srcs/requirements/wordpress/Dockerfile
  - wordpress работает на php
  - версия php (https://www.php.net/) соответствует установленной  
  - php-fpm для взаимодействия с nginx
  - запустить fastcgi через сокет php-fpm, fastcgi слушает на 9000 (путь /etc/php8/php-fpm.d/ зависит от версии php)   
  - конфиг fastcgi в контейнере `www.conf`   
  - CMS может скачивать темы, плагины, сохранять файлы  

### VM vs docker
| VM                                               | Docker                                                           |
| ------------------------------------------------ | ---------------------------------------------------------------- |
| a lot of memory space                            | a lot less memory space                                          |
| long time to boot up                             | quick boot up because it uses the running kernel that you using  |
| difficult to scale up                            | super easy to scale                                              |
| low efficiency                                   | high efficiency                                                  |
| volumes storage cannot be shared across the VM’s | volumes storage can be shared across the host and the containers |
* explain
  + how to login into the database
  + How Docker and docker compose work
  + The difference between a Docker image used with docker compose and without docker compose
  + The benefit of Docker compared to VMs
  + The pertinence of the directory structure required for this project
  + an explanation of docker-network
  + Read about how daemons work and whether it’s a good idea to use them or not

### WP-CLI
* the command line interface for WordPress
* allows to interact with your WordPress site from the command line
* is used for automating tasks, debugging problems, installing/removing plugins along side with themes, managing users and roles, exporting/importing data, run databses queries, ...
* can save time that will take you to installing a pluging/theme manually, moderate users and their roles, deploy a new WordPress website to a production server, ...
* helps you react with your WordPress website.

### Discord
  + container nginx passe les requetes a php-fpm pour executer le php
  + link ce volume au containeur nginx => simplifier votre config
  + pour installer wp je te conseille d'utiliser la cli, tu peux tout automatiser dans ton script, ça évitera de copier ton dossier wp... https://developer.wordpress.org/cli/commands/
  + automatiser le plus possible via tes containers
  + tu sais pas ce qui sera disponible sur la machine qui va le lancer (à part le fait que docker sera installé)
  + on va clone ton projet et le lancer si ça fonctionne c'est bien, sinon c'est 0
  + pas d'entry point avec une boucle infini genre typiquement les scripts qui utilisent tall -f and co
  + si le service exit de facon anormale, le container doit pouvoir se restart (**d'ou l'interet du PID 1**)
    - vérifier que notre service à l'intérieur de notre container tourne bien en tant que PID 1 ? `top || ps`
  + t'as le choix de lancer php en daemon puis afficher du vide, ou lancer php puis afficher ses logs
  + docker-compose --env-file
  + est-ce que c'est Ok de faire quelque chose du genre: CMD /bin/bash /tmp/script.sh && /usr/sbin/php-fpm7.3 --nodaemonize ?
    - l'entrypoint peut bien être modifié au runtime, en cli ou via docker-compose (https://www.bmc.com/blogs/docker-cmd-vs-entrypoint) 
    - surtout https://sysdig.com/blog/dockerfile-best-practices/ même si vous n'utilisez pas d'image distroless
    - https://docs.docker.com/engine/reference/commandline/run/ (fait attention au PID 1)
    - Sinon pour les commands infini je pense surtout aux tail -f /dev/random and co
  + les différences entre RUN CMD ENTRYPOINT
  + CMD = définir une commande par défaut que l'on peut override
    - lorsque tu utilises CMD utilise plutôt CMD ["executable", "params…"] pareil pour les COPY etc c'est plus propre et lisible
    - par exemple: `CMD ["--help"], ENTRYPOINT ["ping"]`
  + ENTRYPOINT = définir un exécutable comme point d'entrée que l'on ne peut donc pas override
    - on peux utiliser ENTRYPOINT afin de définir un process par défaut
  + si je run mon image sans lui donner d'argument c'est ping --help qui va se lancer
  + si je run mon image en lui donnant google.fr, c'est ping google.fr qui va se lancer
  + Tu peux avoir des trucs genre : ENTRYPOINT ["echo", "Hello"] CMD ["hehe"]
  + faire un script en entrypoint qui récupère éventuellement les arguments que je pourrais donner avec un docker run, dans lequel je vais pouvoir faire ce dont j'ai besoin au runtime et qui finirait par exemple par un  exec /usr/sbin/php-fpm7.3 --nodaemonize afin de "remplacer" mon script par php-fpm (qui conserverait donc bien le PID 1 et qui pourrais donc catch comme il faut les signaux)
    - est-ce que tu vas gagner quelque chose a pouvoir passer des arguments au scrip
    - variables d'env, ca permet de faire docker run php --version par exemple, AKA la vraie commande mais avec juste docker run devant (si tu fais une image php) 
  + Le principe de docker c'est pas d'avoir 50 services pour tout faire mais un seul qui fait une chose
  + docker-compose permet d'avoir la possibilité de link simplement tes services
  + Tu as pas mal d'image distroless and co. Ici je ne demande pas ça.
  + le PID 1 c’est systemd
    - dans un container c’est différent il ne peux pas y avoir de systemd je crois
    - si tu as un doute sur un truc dans le sujet faut pas hésiter à chercher c'est tout
  + voir systemctl sur nginx m'a fait du mal
    - systemctl start nginx dans un container n’est pas possible
  + Les images officielles de nginx, mariadb, etc, sont de très bonnes inspirations
  + le flag init sur docker 
  + ['sh', 'test.sh'] vs sh /opt/test.sh ? '
  + docker compose = un simple wrapper build au dessus de docker 
  + повтор: les Shared Folders de la VM ou qu'un serveur SSH mal configuré sur la VM peuvent poser problème
  + le php-fpm dans le container wordpress doit il être démarré, c'est considéré comme un service, et c'est ce qui permet au serveur nginx de comprendre le php
  + php est censé démarrer sur /run/php/php-fpm7.3.sock mais le dossier /run/php n'existe pas
    - php-fpm c'est ce qui te permet d'executer le code php. nginx doit pouvoir passer la requete qui lui est faite a php-fpm dans le container wordpress
  + CMD ou ENTRYPOINT
    - faudrait que j’accède au bash du container pendant qu’il tourne et ça implique de demarrer le php-fpm et/ou le nginx soit même si je fait un CMD alors que si je fait un ENTRYPOINT je pense qu’il executera quand même et j’aurais pas à le faire enfin
    - CMD c'est simplement une instruction qui permet de définir la commande de démarrage par défaut du container, à aucun moment durant le build la commande par défaut ne va être exécuté
  + je n’utilise pas docker-compose
    - j’ai crée un network et je crée mes images et enfin mes containers ce qui m’étonne c’est que nginx reste en running mais pas mon container wordpress dans lequel j’ai installé php-fpm
  + pour le container wordpress a t on le droit d’utiliser une image de debian buster avec php-fpm ?
    - il y a une option pour ignorer le daemonize de base ???
    - pourquoi ignorer le daemonize de base ? faudrait il pas qu’il tourne pour écouter le port ?
    - Il tournera mais pas en arrière plan du coup…
    - pour moi il tourne ou ne tourne pas, mais en fait l’option daemonize n’agit que sur le foreground ou le background c’est ça ? donc l’option —nodaemonize si specifié ne fait que le mettre au premier plan
    - c'est un peu le fonctionnement de docker qui impose ce genre de truc
    - pourquoi est-ce que ce genre d'options existent
  + остановилась на Apres sinon, un second conteneur nginx, mais bon

### Notes
* **убрать .env, test.sh**

[docker](https://github.com/privet100/general-culture/blob/main/docker.md)  
https://github.com/Forstman1/inception-42    
https://github.com/codesshaman/inception  
https://github.com/edvin3i/42_inception  
https://github.com/rbiodies/Inception   
https://github.com/SavchenkoDV/inception_School21_Ecole42  
[WordPress NGINX,PHP-FPM MariaDB](https://medium.com/swlh/wordpress-deployment-with-nginx-php-fpm-and-mariadb-using-docker-compose-55f59e5c1a)  
https://tuto.grademe.fr/inception/  
https://cloud.google.com/architecture/best-practices-for-building-containers  
https://www.aquasec.com/cloud-native-academy/docker-container/docker-networking/ (!)   
https://www.internetsociety.org/deploy360/tls/basics/   
[wordpress](https://make.wordpress.org/hosting/handbook/server-environment)   
https://www.php.net/manual/en/install.fpm.configuration.php  
[PHP configuration](https://www.php.net/manual/en/install.fpm.configuration.php)   
https://admin812.ru/razvertyvanie-wordpress-s-nginx-php-fpm-i-mariadb-s-pomoshhyu-docker-compose.html
