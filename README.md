![Screenshot from 2024-09-18 11-54-11](https://github.com/user-attachments/assets/6b9eafa6-a05d-4535-a822-82351263d4c0)

+ VM
  - папка в sgoinfre
    * на время работы перемещать в goinfre, будет быстрее работать
  - RAM 6 GB, диск VDI или VHD динамический 15 GB, CPU 2C
  - install [debian 12](https://www.debian.org) (software to install: ssh, user akostrik)
  - Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов (22 занят ssh хостовой машины, 443 чтобы с хостовой заходить на сайт):
    | Name    | Protocol | Host IP     | Host Port    | Guest IP    | Guest Port   |
    | ------- | -------- | ----------- | ------------ | ----------- | ------------ |
    | `ssh`   | `TCP`    | `127.0.0.1` | `4254`       | `10.0.2.15` | `22`         |
    | `https` | `TCP`    | `127.0.0.1` | `1443`       | `10.0.2.15` | `443`        |
  - режим сети "Bridged" позволяет виртуальной машине иметь собственный IP-адрес в вашей локальной сети, доступный основной машине => VM и хостовая находится в одном сетевом пространстве
  - если используется "NAT", то настроить проброс портов
  - ```
    su
    nano /etc/ssh/sshd_config: Port 22, PasswordAuthentication yes
    /etc/init.d/ssh restart
    ```
+ на хостовой
  ```
  ssh akostrik@localhost -p 4254
  su
  apt update -y; apt install -y ufw sudo docker docker-compose make openbox xinit kitty firefox-esr wget curl libnss3-tools
  /usr/sbin/usermod -aG docker akostrik
  /usr/sbin/usermod -aG sudo akostrik
  nano /etc/sudoers: akostrik ALL=(ALL:ALL) ALL
  exit
  mkdir ~/.ssh/
  cd ~/.ssh
  ssh-keygen -t rsa
  cat ~/.ssh/id_rsa.pub - добавить ключ в git
  cd ~
  git clone https://github.com/privet100/inception inception
  sudo curl -s https://api.github.com/repos/FiloSottile/mkcert/releases/latest| grep browser_download_url  | grep linux-amd64 | cut -d '"' -f 4 | wget -qi -
  sudo mv mkcert-v*-linux-amd64 /usr/local/bin/mkcert
  chmod a+x /usr/local/bin/mkcert
  cd ~/inception/project/srcs/nginx
  mkcert akostrik.42.fr
  mv akostrik.42.fr-key.pem akostrik.42.fr.key
  mv akostrik.42.fr.pem akostrik.42.fr.crt
  sudo echo "127.0.0.1        akostrik.42.fr" >> /etc/hosts
  sudo ufw enable; sudo ufw allow ssh; sudo ufw allow https
  sudo reboot
  ssh akostrik@localhost -p 4254
  cd ~/inception/project
  make
  sudo startx (if VM is in mode terminal, without GNOME)
  ```
+ VM в браузере `https://127.0.0.1`, `https://akostrik.42.fr`
  + le certificat SSL n’a pas été signé par Trusted Authority => Accept the risk and continue
+ На хостовой машине в браузере `https://127.0.0.1:1443`
+ при установке wordpress создать первого пользователя - это будет админ (в его имени не должно быть _admin_ и тп)
  - потом в ttps://akostrik.42.fr/wp-admin/users.php добавить второго пользоваетля
+ пароли:
  + VM: root 2, akostrik 2
  + WP: admin: akostrik 2, useR: akostrik2 2
  + mariadb: akostrik 2 
+ Подключение VS Code хостовой машины к VM: установить расширение _Remote-SSH_
+ https://akostrik.42.fr/wp-admin
+ https://akostrik.42.fr/wp-login.php
### Makefile 
* all после остановки  
* fclean перед сохранением в облако
* `-d` = detached mode = фоновый режим, контейнеры запущены в фоновом режиме, без привязки к терминалу

### docker-compose.yml
* если в docker-compose не указана сеть, сервисы автоматически подключаются к виртуальной сети
  + контейнеры подключаются к Docker's default bridge network — стандартной сети Docker для изолированных контейнеров
  + контейнеры общаюся напрямую по внутренним IP-адресам и портам без необходимости явного проброса портов
  + контейнеры взаимодействуют через DNS-имена, соответствующие их именам контейнеров
    - DNS = контейнеры взаимодействуют друг с другом по доменным именам, а не по IP-адресам
    - драйвер Docker's default bridge network поддерживает автоматическое разрешение DNS для контейнеров
  + DNS-сервером = встроенный DNS-сервис Docker
    - работает с IP-адресом, который обычно связан с Docker-демоном на хосте, адрес может варьироваться в зависимости от конфигурации сети
  + `cat /etc/resolv.conf` внутри _запущенного_ контейнера - узнать IP-адрес DNS-сервера для разрешения доменных имен в контейнере
    - обычно это адрес Docker bridge-сети (например, 127.0.0.11)
* порты
  + если не будем обращаться к сервису с хостовой машины или из-за её пределов, то порты можно не пробрасывать, нет проброса порта 3306 на хост-машину
  + проброс портов к бд нужен только для доступа к сервису извне Docker (например, с хост-машины), чтобы подключаться к БД через клиент БД (MySQL Workbench, phpMyAdmin) и вручную управлять БД
  + используют стандартный порт 3306 для MariaDB
* args (build arguments)
  + передаются на этапе сборки образа с помощью Dockerfile, доступны только на этапе сборки контейнера (build), но не сохраняются в контейнере после сборки
  + применяются в docker-compose:args или Dockerfile:ARG
  + например версии зависимостей, конфигурации для сборки, ...
  + DB_NAME, DB_USER, DB_PASS на этапе сборки не обязательны, WordPress не требует их, чтобы собрать контейнер
* environment (переменные окружения)
  + переменные доступны во время работы контейнера
  + например ключи доступа, пароли, порты, ...
  + DB_NAME, DB_USER, DB_PASS нужны нужны в процессе выполнения контейнера WordPress для подключения к базе данных во время работы приложения, когда контейнер запущен, но не на этапе сборки
* - daemon 0ff = lancer nginx en premier plan pour que le container ne se stop pas


### .env
```
DB_NAME=wp
DB_ROOT=2
DB_USER=wpuser
DB_PASS=2
```
```
DOMAIN_NAME=akostrik.42.fr
DB_HOST=mariadb
DB_NAME=wp
DB_USER=akostrik
DB_PASSWORD=2
WP_ADMIN=akostrikAd
WP_ADM_PASS=2
WP_ADM_EMAIL=a@b.com
WP_USER=akostrik
WP_USER_PASS=2
WP_USER_EMAIL=c@d.com
```

### Контейнер nginx avec TLS v1.2
* nginx веб-сервер, фронтенд-сервер
* php-fpm сервер FastCGI, backend-сервис
* Dockerfile
  + логи в tty контейнера (т.к. не демон)
  + nginx должен быть основным процессом в контейнере
* **nginx.conf**:
* слушает на порту 443
  + перенаправление порта на порт компьютера
  + связать порт компьютера 443 и порт 443 контейнера
  + чтобы обратиться к серверу с компьютера (находясь за пределами контейнера)
  + на этот порт сервер ожидает поступления запросов
* поддерживает SSL/TLS-соединения
* la connexion se fera depuis akostrik.42.fr
  + отвечает на запросы для доменных имён akostrik.42.fr и www.akostrik.42.fr
* `/var/www/` le dossier où se trouvera WordPress et donc sa première page à afficher
* `index.php` quelle page afficher en premier
   index index.php index.html index.htm;
* SSL-сертификат для HTTPS-соединений, закрытый ключ SSL используется с сертификатом
* версии протоколов TLS 1.2 и TLS 1.3 считаются безопасными
* SSL-сессия остается активной 10 минут
* соединение может оставаться открытым между запросами от одного клиента в течение 70 секунд 
* включены заголовки для отключения кэширования страниц (полезно при разработке или для динамического контента)
* `location` правила, как обрабатывать запросы к URL или файлам
  + `/` запросы на корневой путь
  + `~` будет регулярное выражение
  + `location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$` запрошен статический файл
  + `\.php$` запрошен php-скрипт, страница с динамическим содержанием
* получает HTTP-запрос
* `location /`
  + пытается найти файл $uri
  + если не найден, перенаправляет запрос на index.php с параметрами запроса в $args
  + nginx renvoye n’importe quelle requête que nous ne connaissons pas sur un 404 error
  + nous essayons d’ouvrir le fichier renseigné, si c’est un échec nous renverrons 404
* `location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$` - запрошен статический файл
  + for file argument, nginx checks its existence before moving on to next argument (for uri  argument, it doesn't)
  + nginx находит его в wp-volume и возвращает клиенту
    - nginx имеет доступ к статическим файлам WordPress в wp-volume (index.php, style.css, php-скрипты, темы, плагины, загруженные изображения, ...) (это прописано в docker-compose)
    - например `/wp-content/uploads/image.jpg`
  + `expires max` заголовок HTTP Expires и максимальное время кэширования для статических файлов (до 10 лет)
  + браузер клиента будет кэшировать эти файлы, чтобы не запрашивать их у сервера при каждом обращении
    - если файлы изменяются, их версионируют (например, style.css?v=2), чтобы заставить браузеры снова запросить их
  + Nous devons d'abord demander à NGINX de renvoyer n’importe quelle requête que nous ne connaissons pas sur un 404 error.
* `location ~ \.php$ { ... }`
  + où renvoier le code php
  + `fastcgi_split_path_info ^(.+\.php)(/.+)$` разделяет путь к файлу и данные после (для работы некоторых PHP-приложений)
    - `$fastcgi_script_name` = имя исполняемого скрипта относительно корня сайта из URI 
    - например `https://example.com/index.php/some/path` => `$fastcgi_script_name` = `/index.php`
  + не интерпретирует PHP, а передаёт на php-fpm по wordpress:9000
    - путь к исполняемому php-файлу
    - `$document_root` = `/var/www` из директивы root (автоматически)
    - `$fastcgi_path_info` = доп инфо из пути после имени PHP-файла 
    - стандартные параметры для работы с php-fpm (переменные окружения, пути, ...) `include fastcgi_params` 
  + php-fpm исполняет php-код и возвращает результат nginx
  + nginx возвращает результат в браузер клиента
* `CMD ["nginx", "-g", "daemon off;"]`
  + в обычной системе nginx после старта отделяется от основного процесса и продолжает работу в фоне (демоном)
  + в контейнере nginx не отделяется в фоновый процесс, nginx = основной процесс контейнера = PID 1
  + Docker отслеживает PID 1
    - если nginx отделится, то PID 1 не будет активным процессом, за которым можно следить, и docker заершит контейнер
  
### Контейнер wordpress avec php-fpm configuré
* wordpress работает на php
* подключается к БД для хранения данных (записи пользователей, посты, настройки)
* **Dockerfile**
  + ARG: переменные доступны на этапе _сборки_ 
  + установка php и компонентов (расширения для работы с MySQL, JSON, ...)
    - `/etc/php8/php-fpm.d/` зависит от версии php   
    - версия php (https://www.php.net/) должна соответствовать установленной  
    - `--no-cache` устанавливать пакеты без кэша, экономитю место
  + настройка wp для работы с бд
    - файлы WordPress помещаются в `/var/www`, туда wp может скачивать темы, плагины, сохранять файлы  
  + настройка PHP-FPM для работы с nginx
    - `www.conf` = конфиг fastcgi   
    - запустить fastcgi через сокет php-fpm
    - fastcgi слушает на 9000
    - `sed -i "s|listen = 127.0.0.1:9000|listen = 9000|g" /etc/php8/php-fpm.d/www.conf`:  только порт без привязки к IP позволяет использовать Docker-сеть => сервис становится доступен для других контейнеров (если PHP-FPM слушает только на 127.0.0.1, он принимает запросы только от того же контейнера)
    - `sed -i "s|;listen.group = nobody|listen.group = nobody|g" /etc/php8/php-fpm.d/www.conf` `sed -i "s|;listen.owner = nobody|listen.owner = nobody|g" /etc/php8/php-fpm.d/www.conf`: владелец сокета = nobody, владелец TCP-порта на котором слушает PHP-FPM = nobody, группа = nobody, т.к. PHP-FPM запускается для прослушивания сокетов и запросы обрабатываются от пользователя nobody  
  + PID 1 = PHP-FPM, управляет обработкой PHP-кода
    - CMD ["php-fpm"] делает это
 **wp-config-create.sh** 
  + экранируем \, чтобы в $table_prefix не записалась пустая строка, т.к. в bash нет такой переменной
* **wp-config.php**
  + инициализация и настройка сайта
  + WordPress загружает своё ядро из index.php, wp-load.php вызовает wp-config.php 
  + подготавливает настройки для корректной работы системы (настройки бд, секретные ключи, параметры безопасности)
  + запускается, когда пользователь отправляет запрос на сайт (открывает страницу в браузере)
  + WordPress начинает выполнение скриптов с index.php, WordPress получает настройки подключения к бд (хост, имя бд, имя пользователя, пароль), может подключиться к бд и продолжить обработку запроса пользователя
  + $table_prefix
    - префикс
    - добавляется ко всем таблицам базы данных, создаваемым для сайта WordPress
    - используется в SQL-запросах WordPress для доступа к таблицам
    - стандартный `wp_` уязвим для SQL-инъекций
    - не может быть пустым
  + require_once ABSPATH . 'wp-settings.php' (выполнение)
    - инициализация ядра WordPress и и настройка WordPress (загружает файлы, настраивает глобальные переменные, настраивает среду WordPress, загружает плагины, активирует темы, ...) 
    - подключает wp-includes/wp-db.php, где WordPress использует DB_NAME для создания подключения
  + DB_NAME, DB_USER, DB_PASSWORD, DB_HOST
    - для соединения с бд через WordPress
    - инициализация объекта класса wpdb, который отвечает за взаимодействие с базой данных
    - в методах класса wpdb (db_connect(), ...) в wp-includes/wp-db.php происходит подключение к базе данных
    - `${DB_NAME}` заменяется реальным именем бд с помощью инструментов развертывания или конфигурационных менеджеров, они подставляют значения переменных окружения
    - но функция из библиотеки vlucas/phpdotenv может читать переменные окружения (PHP не загружает .env автоматически)
  + ABSPATH = путь к папке ядра WordPress
    - используется внутри WordPress
    - защитить файлы WordPress от прямого доступа через веб-сервер (если кто-то попытается напрямую открыть wp-config.php, он проверит наличие константы ABSPATH для предотвращения доступа)
  + `__DIR__` предопределённая php-константа, абс путь к wp-settings.php
* wordpress не пробрасывает порт, взаимодействует с nginx и mariadb через внутреннюю сеть (для безопасности и производительности)
* nginx
  + получает HTTP-запрос от клиента
  + обращается к wordpress через FastCGI для выполнения php-кода
  + wordpress обрабатывает PHP-скрипты
  + nginx = обратный прокси, защитный слой
    - контролирует сессию
    - работает с кешем
    - настраивает заголовки
    - передаёт данных к PHP через FastCGI
    - фильтрирует вредоносный трафик
    - ограничивает доступ к определённым страницам
    - имеет ограничение по IP
    - защищаает от брутфорса
    - защищает от DDoS-атак
    - управляет сертификатами SSL
    - имеет авторизацию на уровне сервера
    - кэширует статический контент для производительности (тут wordpress не всегда оптимален)
    - распределяет нагрузку (тут wordpress не всегда оптимален)
    - управляет большим количеством клиентов (тут wordpress не всегда оптимален)
  + исключаются ситуации:
    - злоумышленник использует уязвимости WordPress или плагинов для доступа к системе (SQL-инъекции, XSS-атаки, взлом через уязвимые плагины)
    - злоумышленник взламывает админ-панель с использованием перебора паролей или эксплойтов
    - за счёт неправильных настроек PHP или WordPress прямой доступ приводит к неправильной обработке запросов => ошибки или раскрытие данных
* когда вы делаете изменения страницы в WordPress через админ-панель, изменения сохраняются в бд
  + контент страниц (текст, изображения, ...) сохраняется в таблице `wp_posts`, каждая страница или пост = отдельная запись таблице
  + медиафайлы, которые вы добавляете на страницы, хранятся на сервере `/wp-content/uploads/`, но ссылки на них и метаданные также сохраняются в бд
  + настройки страниц и их шаблоны управляются через админ-панель и темы, которые хранятся в папке `/wp-content/themes/`
* je veux que ca passe par wordpress et que ca affiche sa page template, pas la page nginx que j'avais set au debut au niveau du /html
  + creer le wp-config.php qui interrompt tout le reste
  + tu peux mettre ton WordPress dans /var/www/HTML, même si y a la page template de Nginx
  + container nginx et WordPress tape sur le même volume => dans ta configuration nginx tu mettes `index.php` avant `index.html`
* WP-CLI (WordPress Command Line Interface) permet de configurer automatiquement un wp
  + официальный инструмент для управления WordPress через командную строку
  + можно автоматически настроить WordPress, создать конфигурационный файл (wp-config.php), установить плагины, темы, ...

### Контейнер mariadb
* **Dockerfile**
  + MariaDB/MySQL/docker.cnf конфиг MariaDB
  + настройка сервера базы данных
  + [mysqld] настройки применяются к серверной части MySQL/MariaDB
  + `skip-host-cache` отключает кеширование DNS (кэширование информации о хостах) для избежания проблем с DNS
  + `bind-address=0.0.0.0` сервер принимает подключения с любого IP-адреса извне контейнера
  + `skip-networking=0` сервер mariadb принимает входящие сетевые соединения к базе данных (не только через локальный Unix-сокет)
  + `mysql_install_db` создает основные структуры данных и инициализирует бд
  + `USER mysql` меняет пользователя внутри контейнера на mysql
    - процессы бд запускатися от имени этого пользователя
    - это повышает безопасность
    - user mysql создан при установке БД
  + PID 1 = основной процесс контейнера = mysqld (MariaDB server, демон mariaDB, главный процесс бд, слушает запросы и выполняет операции
    - CMD ["mysqld"] = запустить процесс MariaDB как основной
* init.sql
  + роль в инициализации базы данных
  + содержит SQL-запросы, которые автоматически выполняются при создании и запуске контейнера базы данных
    - создание таблиц, индексов, других объектов базы данных (CREATE TABLE users ...) 
    - вставкиа начальных данных в таблицы (INSERT INTO users (username, password) VALUES ('admin', 'password123'))
    - создание пользователей базы данных и назначения им прав доступа (CREATE USER my_user WITH PASSWORD 'my_password'; GRANT ALL PRIVILEGES ON DATABASE my_database TO my_user;)
    - при первом запуске контейнера Docker автоматически запускает SQL-скрипты, находящиеся в /docker-entrypoint-initdb.d/ (init.sql, ...) для инициализации базы данных
* подключиться к MariaDB, работающей в контейнере Docker, с хоста или другого контейнера
  + убедитесь, что 3306 проброшен из контейнера на хост
  + убедитесь, что у пользователя mariadb есть права на подключение с внешнего адреса
    например
    ```
    CREATE USER 'user'@'%' IDENTIFIED BY 'password';
    GRANT ALL PRIVILEGES ON *.* TO 'user'@'%';
    FLUSH PRIVILEGES;
    ```
    //`%` = пользователь может подключаться с любого ip
  + `docker exec -it mariadb bash`
  + `mariadb -u akostrik -p` или `mariadb -h 127.0.0.1 -P 3306 -u akostrik -p` подключиться с хоста
    - `mariadb -h mariadb -P 3306 -u akostrik -p` из другого контейнера
  + пароль `2`
  + `SHOW DATABASES;`
  + `USE wordpress; SHOW TABLES;`
  + `SELECT * FROM myprefix_comments;`

### Расположение файлов и папок
на VM                                                             | в контейнере                             | alias
------------------------------------------------------------------|------------------------------------------|------- 
,                                                                 | **в контейнере nginx:**                  | 
~/data/wordpress                                                  | /var/www/                                | wp-volume, root
inception/project/srcs/requirements/nginx/nginx.conf              | /etc/nginx/http.d/nginx.conf             | редактировать nginx.conf на VM без пересборки контейнера 
inception/project/srcs/requirements/nginx/akostrik.42.fr.crt      | /etc/nginx/ssl/akostrik.42.fr.crt        |
,                                                                 | **в контейнере wordpress:**              | 
~/data/wordpress                                                  | /var/www/                                | wp-volume, WORKDIR
inception/project/srcs/requirements/wordpress/wp-config-create.sh | /var/www/wp-config-create.sh             | 
,                                                                 | /etc/php8/php-fpm.d/www.conf             |
,                                                                 | /var/cache/apk/*                         |
,                                                                 | **в контейнере mariadb:**                | 
~/data/maria                                                      | /var/lib/mysql                           | db-volume, datadir, данные MySQL
~/data/maria/mysql                                                | /var/lib/mysql/mysql                     | 
~/data/maria/wordpress                                            | /var/lib/mysql/wordpress                 | 
,                                                                 | /usr                                     | basedir, установка MySQL
inception/project/srcs/requirements/wordpress/create_db.sh        |                                          | 
,                                                                 | /tmp/create_db.sql                       | 
,                                                                 | init.sql                                 |

### Инспектирование
* `docker exec -it wordpress php -m` установленные модули php
* `docker exec -it wordpress php -v` проверим работу php
* `docker exec -it wordpress ps aux | grep 'php'` прослушать сокет php
  + ожидаем: `1 project   0:00 {php-fpm8} php-fpm: master process (/etc/php8/php-fpm.conf` etc
* [Инспектировать](https://github.com/privet100/general-culture/blob/main/docker.md#%D0%B8%D0%BD%D1%81%D0%BF%D0%B5%D0%BA%D1%82%D0%B8%D1%80%D0%BE%D0%B2%D0%B0%D1%82%D1%8C)
* `wget https://akostrik.42.fr --no-check-certificate`
* проверка после внесения изменений:
  + `docker-compose ps` проверьте, что контейнеры работают и находятся в статусе running
  + `docker-compose exec nginx nginx -t` посмотреть nginx.conf
  + как работают ключевые функции 
    - форма на сайте отправляет данные и обрабатывает их
  + убедитесь, что контейнеры взаимодействуют друг с другом
    - nginx передает запросы на php-fpm
  + убедитесь, что бд доступна и взаимодействует с приложением
    - подключитест к бд из контейнера приложения
* `nslookup akostrik.42.fr` корректно ли браузер разрешает DNS-запросы
  + должен показать IP-адрес, к которому разрешается домен akostrik.42.fr
    - этот IP-адрес должен соответствовать тому, который сервер должен использовать
  + если ошибка NXDOMAIN
    - домен akostrik.42.fr должен существовать в DNS-записях на используемом DNS-сервере
    - DNS-сервер должен иметь доступ к домену akostrik.42.fr
    - DNS-сервер должен поддерживать домен akostrik.42.fr
* `ping akostrik.42.fr` доступен ли сервер
* `telnet akostrik.42.fr 443` открыт ли порт 443 на сервере
* `nc -zv akostrik.42.fr 443` открыт ли порт 443 на сервере
* `openssl s_client -connect akostrik.42.fr:443` проверить состояние SSL-сертификата
* браузер и wget должны разрешить `akostrik.42.fr` в один и тот же IP-адрес
  + хотя могут использовать разные DNS-серверы
* `dig akostrik.42.fr` как браузер разрешает DNS-запросы
  + отвечает status: NXDOMAIN (Non-Existent Domain)
    - DNS-сервер не находит запись для домена akostrik.42.fr
    - `ping akostrik.42.fr`существует ли домен akostrik.42.fr  
    - подключены ли контейнеры к одной сети Docker
    - правильно ли настроены DNS-записи для домена akostrik.42.fr 
    - добавлены ли DNS-записи в конфигурацию сервера
    - доступен ли DNS между конейнерами, работает ли DNS-разрешение между контейнерами
    - правильно ли работает встроенный Docker DNS (IP 127.0.0.11)
    - если домен akostrik.42.fr работает локально в контейнерах: akostrik.42.fr в `/etc/hosts` внутри контейнеров или на хосте
    - `cat /etc/resolv.conf` проверь, что `resolv.conf` внутри контейнера указывает на правильный DNS-сервер
    - правильно ли поднимаются контейнеры
    - позволяют ли четевые настройки контейнеров видеть друг друга (docker-compose ps, docker network ls)
    - убедитесь, что nginx направляет запросы на правильные сервисы
    - правильно ли настроены сети в Docker. В docker-compose.yml убедитесь, что контейнеры находятся в одной сети и могут разрешать имена друг друга
    - `docker network inspect srcs_default` контейнеры подключены ли к сети srcs_default
    - `docker exec -it <container_name> /bin/sh` зайти в контейнер и проверить возможность разрешения доменного имени или сетевого подключения
       * ping akostrik.42.fr
       * nslookup akostrik.42.fr
    - проверить `/etc/hosts`
    - Иногда помогает перезапустить Docker сеть `docker network rm srcs_default`, `docker-compose down
docker-compose up --build
    - в nginx правильно ли указаны домены и порты для перенаправления запросов на нужные контейнеры
    - eсли у вас настроены брандмауэры (ufw, iptables, ...), разрешены ли все необходимые порты для связи между контейнерами и DNS

### Защита
* **убрать .env, test.sh**
* просмотр с хостовой через 443 (через 80 не должно работаь)
* add a comment using the available WordPress user
* sign in with the administrator account to access the Administration dashboard
  + edit a page and verify on the website that the page has been updated
* the database is not empty
* `service nginx stop; service mariadb stop; service mysql stop; docker-compose down` (!)
* перезагрузить VM и всё проверить
* в начале проверки `docker stop $(docker ps -qa); docker rm $(docker ps -qa); docker rmi -f $(docker ps -qa); docker volume rm $(docker volume ls -q); docker network rm $(docker network ls -q) 2>/dev/null`
* how Docker and docker compose work
  + The difference between a Docker image used with docker compose and without docker compose
* explain docker-network
* the benefit of Docker compared to VMs
* how daemons work and whether it’s a good idea to use them or not
  + [демон](https://github.com/privet100/general-culture/blob/main/threads.md#%D0%B4%D0%B5%D0%BC%D0%BE%D0%BD)

### Discord
+ pour installer wp je te conseille d'utiliser la cli, tu peux tout automatiser dans ton script, ça évitera de copier ton dossier wp https://developer.wordpress.org/cli/commands/
+ tu sais pas ce qui sera disponible sur la machine qui va le lancer (à part le fait que docker sera installé)
+ on va clone ton projet et le lancer si ça fonctionne c'est bien, sinon c'est 0
+ RUN / CMD / ENTRYPOINT
  - CMD = une commande par défaut que l'on peut override
    + une instruction qui permet de définir la commande de démarrage par défaut du container, à aucun moment durant le build la commande par défaut ne va être exécuté
  - ENTRYPOINT = un exécutable comme point d'entrée que l'on ne peut donc pas override, un process par défaut
  - faudrait que j’accède au bash du container pendant qu’il tourne et ça implique de demarrer le php-fpm et/ou le nginx soit même si je fait un CMD alors que si je fait un ENTRYPOINT je pense qu’il executera quand même et j’aurais pas à le faire enfin
+ c’est au run le problème
  - le container nginx ne connai pas fastcgi_pass wordpress:9000
  - faudrait run (sans fastcgi_pass) ensuite le connecter au network que j’ai crée
  - et enfin faire une modification dans la conf default pour y mettre fastcgi_pass wordpress et restart nginx
  - et la ça fonctionne
+ c'est parce qu'on veut que le PID 1 soit directement, par exemple, "nginx", et pas une intermédiaire comme "service" ou "etc/init.d/[service]" 
  - faut pas utiliser ton container docker comme une VM
  - c'est pour pouvoir utiliser docker correctement
  - genre si tu fais du monitoring ou autre, si tu commences à utiliser des boucles infini, .. Ben le moment où tu aura un soucis je te souhaite bien du courage pour retrouver le problème
+ comment vous avez fait pr créer un user, une DB sans avoir a utiliser un service mysql start
  - `mysqld &`, puis je l’ai éteins et restart à la fin. Je me suis inspiré de l’image officielle en fait
+ остановилась: mais si tu es subscribe tu peux tjrs le faire

### Не понимаю
* поменять ARG и args на ENV и environmaent
* wp-config.php без скрипта
* create_db.sql без скрипта
* распределить действия 
  + docker-compose
    - координация контейнеров и их окружения
  + Dockefile
    - создание рабочей среды, установки зависимостей, сборки приложения
    - копирование исходного кода в контейнер
    - определение команды по умолчанию для запуска приложения
  + скрипты
    - специфические задачи, сложные для описания в Makefile или Dockerfile (сборка артефактов, настройка окружения)

### Где используется эта техника
* setting up a website with WordPress in a VPS server or a Cloud server 

### Notes
[docker](https://github.com/privet100/general-culture/blob/main/docker.md)  
[docker](https://github.com/privet100/general-culture/blob/main/wordpress.md)  
https://github.com/Forstman1/inception-42    
https://github.com/codesshaman/inception  
https://github.com/edvin3i/42_inception  
https://github.com/rbiodies/Inception   
https://github.com/SavchenkoDV/inception_School21_Ecole42  
// https://medium.com/swlh/wordpress-deployment-with-nginx-php-fpm-and-mariadb-using-docker-compose-55f59e5c1a   
[WordPress NGINX,PHP-FPM MariaDB](https://medium.com/swlh/wordpress-deployment-with-nginx-php-fpm-and-mariadb-using-docker-compose-55f59e5c1a)  
https://tuto.grademe.fr/inception/  
https://cloud.google.com/architecture/best-practices-for-building-containers  
https://www.aquasec.com/cloud-native-academy/docker-container/docker-networking/ (!)   
https://www.internetsociety.org/deploy360/tls/basics/   
[wordpress](https://make.wordpress.org/hosting/handbook/server-environment)   
https://www.php.net/manual/en/install.fpm.configuration.php  
[PHP configuration](https://www.php.net/manual/en/install.fpm.configuration.php)   
https://admin812.ru/razvertyvanie-wordpress-s-nginx-php-fpm-i-mariadb-s-pomoshhyu-docker-compose.html
