![Screenshot from 2024-09-18 11-54-11](https://github.com/user-attachments/assets/6b9eafa6-a05d-4535-a822-82351263d4c0)

+ VM
  - папка в sgoinfre
    * на время работы перемещать в goinfre, будет быстрее работать
  - RAM 2 GB, диск VDI или VHD динамический 15 GB, CPU 1C
  - install [debian 12](https://www.debian.org)
  - software to install: ssh
  - user akostrik
  - Virtualbox -> настройки -> сеть -> дополнительно -> проброс портов (22 занят ssh хостовой машины, 443 чтобы с хостовой заходить на сайт):
    | Name    | Protocol | Host IP     | Host Port    | Guest IP    | Guest Port   |
    | ------- | -------- | ----------- | ------------ | ----------- | ------------ |
    | `ssh`   | `TCP`    | `127.0.0.1` | `4254`       | `10.0.2.15` | `22`         |
    | `https` | `TCP`    | `127.0.0.1` | `1443`       | `10.0.2.15` | `443`        |
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
  sudo nano /etc/hosts: 127.0.0.1 akostrik.42.fr 
  sudo ufw enable; sudo ufw allow ssh; sudo ufw allow https
  sudo reboot
  ssh akostrik@localhost -p 4254
  cd ~/inception/project
  make
  sudo startx (if VM is in mode terminal, without GNOME)
  ```
+ VM в браузере `https://127.0.0.1`, `https://akostrik.42.fr`
  + le certificat SSL n’a pas été signé par Trusted Authority => Accept the risk and continue
+ https://akostrik.42.fr/wp-admin/users.php: два пользователя, один админ, в его имени нет admin и тп
+ Подключение VS Code хостовой машины к VM: расширение _Remote-SSH_
+ пароли: VM root 2, VM akostrik 2, WP akostrik 2, mariadb akostrik 2 

### Относится ко всем контейнерам
+ **Makefile**                             
  - all после остановки  
  - fclean перед сохранением в облако
  - `-d` = detached mode = фоновый режим, контейнеры запущены в фоновом режиме, без привязки к терминалу
+ **docker-compose.yml**
  - сервисы автоматически подключаются к виртуальной сети
    * могут обращаться друг к другу по имени сервиса
    * если не будем обращаться к сервису с хостовой машины или из-за её пределов, то порты можно не пробрасывать
+ **.env**
  ```
  DB_NAME=wp
  DB_ROOT=2
  DB_USER=wpuser
  DB_PASS=2
  ```

### Контейнер Nginx
* веб-сервер
* фронтенд-сервер
* обрабатывает HTTP-запросы
* имеет доступ к _статическим_ файлам WordPress (index.php, style.css, PHP-скрипты, темы, плагины, загруженные изображения, ...)
  + WordPress хранит эти файлы в wp-volume
* ищет запрашиваемый файл на wp-volume, когда поступает HTTP-запрос, Nginx 
  + отдаёт его клиенту, если запрошен статический файл
    - находит image.jpg в wp-volume и возвращает его клиенту, если запрошен `/wp-content/uploads/image.jpg`
  + передаёт обработку контейнеру PHP-FPM, если запрошен PHP-скрипт, страница с динамическим содержанием
    - например `/index.php`  
+ **Dockerfile**                
  - логи в tty контейнера (т.к. не демон)
+ **nginx.conf**
  - сервер слушает на порту 443
  - поддерживает SSL/TLS-соединения
  - отвечает на запросы для доменных имён akostrik.42.fr и www.akostrik.42.fr
  - index.php если пользователь запросит директорию без указания файла
  - SSL-сертификат для HTTPS-соединений, закрытый ключ SSL используется с сертификатом
  - версии протоколов TLS 1.2 и TLS 1.3 считаются безопасными
  - SSL-сессия остается активной 10 минут
  - соединение может оставаться открытым между запросами от одного клиента в течение 70 секунд 
  - включены заголовки для отключения кэширования страниц (полезно при разработке или для динамического контента)
  - `location` правила, сервер будет обрабатывать запросы к URL или файлам
  - `location /` запросы на корневой путь
    * пытается найти файл $uri
    * если не найден, перенаправляет запрос на index.php с параметрами запроса в $args
  - `location ~ \.php$ { ... }` обработка php-файлов
    * `~` будет регулярное выражение
    * `\.php$` файлы `*.php`
    * nginx не интерпретирует PHP напрямую
    * nginx извлекает из URI имя исполняемого скрипта относительно корня сайта и автоматически присваивает его `$fastcgi_script_name`
    * `fastcgi_split_path_info ^(.+\.php)(/.+)$` разделяет путь к файлу и данные после (например /index.php/some/path) (для работы некоторых PHP-приложений)
    * например пользователь запрашивает `https://example.com/index.php` => `$fastcgi_script_name` = `/index.php`
    * nginx передаёт путь к исполняемому PHP-файлу на PHP-FPM (сервер FastCGI), доступный по wordpress:9000 (контейнер с PHP-FPM)
    * `$document_root` берётся автоматически из `root /var/www`
    * `fastcgi_param PATH_INFO $fastcgi_path_info` передаёт в FastCGI доп инфо из пути после имени PHP-файла
    * nginx передаёт в FastCGI-сервер `include fastcgi_params` стандартные параметры для работы с FastCGI (переменные окружения, пути, ...) 
    * cервер FastCGI исполняет php-код (по умолчанию index.php)
    * cервер FastCGI возвращает результат через Nginx в браузер клиента
  - `location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$`
    * правила для обработки статических файлов
    * `expires max` заголовок HTTP Expires и максимальное время кэширования для статических файлов
    * `max` задаёт очень длительное время кэширования (обычно до 10 лет)
    * браузер клиента будет кэшировать эти файлы и не будет запрашивать их у сервера при каждом обращении
    * когда такие файлы изменяются, их версионируют (например, style.css?v=2), чтобы заставить браузеры снова запросить их
    * `log_not_found off` отключает запись в лог, если сервер не находит один из этих файлов, для уменьшения объёма логов

### Контейнер wordpress
+ **Dockerfile**
  - настраивает минимальную среду для запуска WordPress на базе Alpine Linux и PHP
    * конфигурирует PHP-FPM для работы с веб-сервером
    * настраивает конфигурацию WordPress для работы с базой данных
  - wordpress работает на php
  - версия php (https://www.php.net/) соответствует установленной  
  - php-fpm для взаимодействия с nginx
  - запустить fastcgi через сокет php-fpm, fastcgi слушает на 9000 (путь /etc/php8/php-fpm.d/ зависит от версии php)   
  - конфиг fastcgi в контейнере `www.conf`   
  - CMS может скачивать темы, плагины, сохранять файлы  
  - ARG PHP_VERSION=8 DB_NAME DB_USER DB_PASS: аргументы сборки
  - `--no-cache` устанавливает пакеты без кэша, чтобы сэкономить место в образе
  - устанавливаются PHP и его компоненты (PHP-FPM для работы с FastCGI, расширения для работы с MySQL, JSON, ...)
  - `sed -i "s|;listen.group = nobody|listen.group = nobody|g" /etc/php8/php-fpm.d/www.conf`
    * смена адреса прослушивания PHP-FPM с 127.0.0.1:9000 на 9000, что важно для корректной работы с Nginx
    * редактируют конфиг PHP-FPM: устанавливаются владельцы и группа для сокета listen.owner и listen.group, чтобы процессы работали с правильными правами
    * чтобы PHP-FPM мог принимать соединения не только по IP-адресу 127.0.0.1 (локальная машина), но и через Unix-сокет или другие интерфейсы, которые настроены на взаимодействие внутри контейнера
    * Nginx и PHP-FPM находятся в разных контейнерах: когда PHP-FPM слушает только на 127.0.0.1, это означает, что он принимает запросы только от того же контейнера, а использование просто 9000 позволяет принимать запросы на всех интерфейсах, что важно для взаимодействия с другими контейнерами
    * в Docker-сетапах или на серверах может использоваться не только TCP-сокет (адрес 127.0.0.1:9000), но и Unix-сокет (например, /var/run/php-fpm.sock). Unix-сокеты часто быстрее для обмена данными между локальными процессами. Оставление только порта 9000 даёт возможность гибче настраивать способ связи.
    * внутренние сети для соединения между контейнерами. Если PHP-FPM привязан к 127.0.0.1, другие контейнеры не смогут подключиться. Оставление только порта без привязки к IP позволяет использовать Docker-сеть, что делает сервис доступным для других контейнеров.
  - sed -i "s|;listen.owner = nobody|listen.owner = nobody|g" /etc/php8/php-fpm.d/www.conf
    * раскомментирует `listen.owner = nobody` в www.conf => PHP-FPM будет запускаться от имени пользователя nobody для прослушивания сокетов
    * указать, под каким пользователем будут обрабатываться запросы, чтобы избежать проблем с правами доступа или безопасности
    * listen.owner задаёт владельца сокета или TCP-порта, на котором слушает PHP-FPM
    * Когда вы настраиваете PHP-FPM для работы через сокеты и хотите убедиться, что сокет принадлежит определённому пользователю (в данном случае nobody)
    * конфигурация серверов, где требуется чёткое разграничение прав доступа для безопасности или функциональности
  - в `/var/www` будут помещены файлы WordPress, там будет происходить работа
+ **wp-config-create.sh** 
  - экранируем \, чтобы в $table_prefix не записалась пустая строка (т.к. в bash нет такой переменной)
+ **wp-config.php**
  * инициализация и настройка сайта
  * WordPress загружает своё ядро из index.php 
  * wp-load.php вызовает wp-config.php 
  * 1. подготавливает настройки для корректной работы системы (настройки бд, секретные ключи, параметры безопасности)
  * 2. запускается, когда пользователь отправляет запрос на сайт (например, открывает страницу в браузере), WordPress начинает выполнение скриптов с файла index.php, расположенного в корневой директории сайта, WordPress получает настройки подключения к бд (хост, имя бд, имя пользователя, пароль), может подключиться к бд и продолжить обработку запроса пользователя
  * $table_prefix = префикс, который добавляется ко всем таблицам базы данных, создаваемым для сайта WordPress
    - использование стандартного `wp_` может быть уязвимым для атак SQL-инъекций
    - префикс используется в SQL-запросах WordPress для создания и доступа к таблицам базы данных
    - не может быть пустым
  * require_once ABSPATH . 'wp-settings.php';
    - включение и выполнения кода из wp-settings.php
    - один из ключевых файлов, инициализация и настройки системы, инициализация ядра WordPress: загружает необходимые файлы, настраивает глобальные переменные, настраивает среду WordPress, загружает плагины, активирует темы, ... для настройки WordPress
    - подключает wp-includes/wp-db.php, где WordPress использует DB_NAME для создания подключения
  * DB_NAME, DB_USER, DB_PASSWORD, DB_HOST
    - для установки соединения с базой данных через WordPress
    - инициализация объекта класса wpdb, который отвечает за взаимодействие с базой данных
    - используются в методах класса wpdb (db_connect(), ...) в wp-includes/wp-db.php происходит подключение к базе данных
   * `DB_NAME = ${DB_NAME}` - placeholder, должен быть заменён реальным именем бд с помощью инструментов развертывания или конфигурационных менеджеров, которые подставляют реальные значения переменных окружения при деплое
    - нужно использовать функцию для чтения переменных окружения, так как PHP сам по себе не загружает .env файлы автоматически
    - библиотека vlucas/phpdotenv, установить с помощью Composer
  * ABSPATH
    - используется внутри WordPress
    - константа, абсолютный путь к корневой директории сайта, где файлы ядра WordPress
    - помогает защитить файлы WordPress от прямого доступа через веб-сервер (если кто-то попытается напрямую открыть wp-config.php, он проверит наличие константы ABSPATH для предотвращения доступа)
+ wordpress не пробрасывает порт
  * веб-сервер nginx получает все HTTP-запросы от клиентов
  * nginx обращается к wordpress через FastCGI для выполнения php-кода
  * wordpress обрабатывает PHP-скрипты
  * внешний доступ к wordpress не нужен (то улучшгает безопасность, в чём именно?)
  * wordpress взаимодействует с nginx и mariadb через внутреннюю сеть Docker
+ зачем нужно предотвращать возможность прямого доступа к контейнеру wordpress извне?
 * для безопасности и правильного функционирования приложения
 * Уменьшение поверхности атаки: Проброс порта WordPress напрямую открывает его для внешнего мира, что увеличивает риск атак. Хакеры могут попытаться использовать уязвимости WordPress или плагинов для доступа к системе. WordPress известен своей популярностью, что делает его мишенью для атак, таких как SQL-инъекции, XSS-атаки или взлом через уязвимые плагины. Открытый доступ может позволить злоумышленникам использовать эксплойты для проникновения в систему.
  * Роль Nginx как защитного слоя: Nginx выступает в качестве обратного прокси (reverse proxy), и это помогает ограничить прямой доступ к WordPress. Nginx может быть настроен для фильтрации вредоносного трафика, ограничения доступа к определённым страницам, предоставления защиты от DDoS-атак и управления сертификатами SSL для безопасного HTTPS-соединения.
Также Nginx может оптимизировать производительность за счёт кэширования статического контента и балансировки нагрузки.
  * Изолированность и безопасность приложения:
Если доступ к контейнеру WordPress осуществляется только через внутреннюю сеть Docker, это обеспечивает изоляцию приложения и уменьшает вероятность утечек данных или вмешательства в работу сайта извне.
Docker предоставляет свою сеть для взаимодействия контейнеров, и это позволяет ограничить доступ к WordPress-трафику только из доверенных источников (например, из контейнера Nginx).
  * Защита от прямого доступа к админ-панели WordPress:
Прямой доступ к WordPress может дать возможность хакерам атаковать такие важные элементы, как админ-панель (/wp-admin). Если запросы проходят только через Nginx, можно настроить дополнительные уровни защиты (например, авторизацию на уровне сервера, защиту от брутфорса или ограничение по IP).
Без проброса порта наружу можно исключить попытки прямого взлома админ-панели с использованием перебора паролей или эксплойтов.
  * Минимизация конфигурационных рисков:
WordPress на PHP требует правильных настроек для обработки запросов. Nginx управляет всеми аспектами передачи данных к PHP через FastCGI и может контролировать сессию, работу с кешем, настройку заголовков и безопасность передачи данных.
Прямой доступ может привести к неправильной обработке запросов, что может вызвать ошибки или раскрытие данных, если PHP или WordPress неправильно настроены.
  * Избежание проблем с многопоточностью и производительностью:
WordPress может быть не оптимален для прямого обслуживания большого количества запросов. Nginx эффективно распределяет нагрузку и может управлять большим количеством клиентов благодаря своей архитектуре.
Прямой доступ к WordPress может вызвать проблемы с многопоточностью и масштабируемостью при обработке большого числа одновременных запросов.
  * улучшает безопасность приложения, защищает от атак, уменьшает риски утечек данных и позволяет использовать Nginx как мощный и безопасный прокси-сервер.
  * Это стандартная практика в веб-разработке для повышения безопасности и производительности системы.

### Конейнер mariadb
+ **Dockerfile**
  - БД из сконфигурированного на пред. слое
  - user mysql создан при установке БД  
  - переменные окружения из .env только при build  
    * другой вариант: из environment-секции внутри сервиса - будут в окружении запущенного контейнера  
    * из docker-compose ?
  - RUN mkdir /var/run/mysqld; chmod 777 /var/run/mysqld;
  - создание конфигурационного файла MariaDB { echo '[mysqld]'; echo 'skip-host-cache'; echo 'skip-name-resolve'; echo 'bind-address=0.0.0.0'; } | tee  /etc/my.cnf.d/docker.cnf;
    * skip-host-cache и skip-name-resolve помогают ускорить работу сервера, отключая кеширование DNS и разрешение имен хостов
    * bind-address=0.0.0.0 делает сервер доступным для всех IP-адресов, что позволяет подключаться к базе данных извне
  - `sed -i "s|skip-networking|skip-networking=0|g" /etc/my.cnf.d/mariadb-server.cnf`
    * настройка конфигурации MariaDB
    * sed для изменения параметра skip-networking, чтобы разрешить сетевые подключения к базе данных
  - `mysql_install_db`, которая создает основные структуры данных для MariaDB, инициализируется база данных с помощью команды
  - `USER mysql` меняет пользователя внутри контейнера на mysql, чтобы процессы базы данных запускались от имени этого пользователя (повышает безопасность)
* проброс порта 3306
  - контейнеры MariaDB и WordPress находятся в одной сети Docker и могут взаимодействовать по внутреннему порту 3306
  - WordPress подключается к БД для хранения данных (записи пользователей, посты, настройки)
  - проброс порта позволяет: с хоста или другого компьютера, через клиент БД (MySQL Workbench, phpMyAdmin), подключаться к БД, вручную управлять БД, отлаживать данные

### Инспектирование
* `docker exec -it wordpress php -m` все ли модули установились
* `docker exec -it wordpress php -v` проверим работу php
* `docker exec -it wordpress ps aux | grep 'php'` прослушаем сокет php
  + ожидаем: `1 project   0:00 {php-fpm8} php-fpm: master process (/etc/php8/php-fpm.conf` etc
* [Инспектировать](https://github.com/privet100/general-culture/blob/main/docker.md#%D0%B8%D0%BD%D1%81%D0%BF%D0%B5%D0%BA%D1%82%D0%B8%D1%80%D0%BE%D0%B2%D0%B0%D1%82%D1%8C)
*  `wget https://akostrik.42.fr --no-check-certificate`
*  `curl 'http://127.0.0.1'`
* проверка после внесения изменений:
  + `docker-compose up` убедитесь, что контейнеры запускаются корректно
  + `docker-compose ps` проверьте, что контейнеры работают и находятся в статусе running
  + `docker-compose logs` логи контейнеров
  + `docker-compose exec nginx nginx -t` убедитесь, что nginx.conf не содержит синтаксических ошибок
  + проверьте, что все ключевые функции вашего веб-приложения работают корректно. Например, если у вас есть форма на сайте, убедитесь, что она корректно отправляет данные и обрабатывает их.
  + убедитесь, что контейнеры могут взаимодействовать друг с другом (например Nginx корректно передает запросы на PHP-FPM или другой backend-сервис)
  + убедитесь, что бд доступна и взаимодействует с вашим приложением, попробуйте подключиться к базе данных из контейнера приложения

### Защита
* **убрать .env, test.sh**
* `service nginx stop; service mariadb stop; service mysql stop; docker-compose down` (!)
* add a comment using the available WordPress user
* WordPress database: 2 users, one of them being the administrator
  + the Admin username must not include admin, administrator, Admin-login, admin-123, etc
* sign in with the administrator account to access the Administration dashboard
  + from the Administration dashboard, edit a page
  + verify on the website that the page has been updated
* the database is not empty

### Теория
* explain
  + how to login into the database
  + How Docker and docker compose work
  + The difference between a Docker image used with docker compose and without docker compose
  + The benefit of Docker compared to VMs
  + The pertinence of the directory structure required for this project
  + an explanation of docker-network
    - By default Compose sets up a single network for your app. Each container for a service joins the default network and is both reachable by other containers on that network, and discoverable by them at a hostname identical to the container name. `networks` позволяет задать имя для этой сети, но и без этого будет работать.
  + Read about how daemons work and whether it’s a good idea to use them or not
* VM vs docker
  | VM                                               | Docker                                                           |
  | ------------------------------------------------ | ---------------------------------------------------------------- |
  | a lot of memory space                            | a lot less memory space                                          |
  | long time to boot up                             | quick boot up because it uses the running kernel that you using  |
  | difficult to scale up                            | super easy to scale                                              |
  | low efficiency                                   | high efficiency                                                  |
  | volumes storage cannot be shared across the VM’s | volumes storage can be shared across the host and the containers |
* PID 1
  + первый процесс, который запускается в контейнере
  + отвечает за запуск и управление процессами внутри контейнера
  + все другие процессы внутри контейнера получают свои PID от PID 1
  + если PID 1 завершится, контейнер остановится
  + обрабатывает системные сигналы (SIGTERM, ...)
    - если ваш основной процесс (например, веб-сервер) работает под PID 1, он должен корректно обрабатывать такие сигналы для правильного завершения работы
  + должен быть правильно очищать дочерние процессы, чтобы избежать зомби-процессов
  + остальные должнен быть настроены так, чтобы PID 1 был их родителем
  + CMD в Dockerfile позволит Docker назначить PID 1 вашему основному процессу
    - nginx должен быть основным процессом в контейнере
  + не используйте скрипты оболочки в качестве PID 1
    - это может привести к проблемам с управлением процессами
    - можно использовать, если tini или dumb-init служит в качестве PID 1 и корректно обрабатывает системные сигналы
  + `daemon off` для сервисов, которые по умолчанию запускаются в фоновом режиме
    - чтобы процесс оставался основным процессом с PID 1 и не запускался в фоне
  + si le service exit de facon anormale, le container doit pouvoir se restart (d'ou l'interet du PID 1)
    - `top || ps` vérifier que notre service à l'intérieur de notre container tourne bien en tant que PID 1 
  + PID 1 = systemd, mais dans un container c’est différent, il ne peux pas y avoir de systemd
* WordPress
  + PHP-приложение
  + написанно на PHP, работает на PHP
  + взаимодействует с бд
  + генерирует динамический контент для веб-страниц
  + выполняет задачи на стороне сервера (генерация HTML, работа с бд,управление контентом)
  + PHP (Hypertext Preprocessor) — серверный язык программирования
    -  используется для создания динамических веб-страниц
  + как работает
    - Nginx принимает запросы от пользователей и передают в PHP 
    - PHP-скрипты обрабаьывают запросы (отображение постов, страниц, комментариев)
    - PHP выполняет процессы FastCGI с помощью php-fpm
    - PHP взаимодействует с бд, получает/обновляет данные
    - бд хранит контент сайта (посты, страницы, настройки, ...)

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
  + t'as le choix de lancer php en daemon puis afficher du vide, ou lancer php puis afficher ses logs
  + https://sysdig.com/blog/dockerfile-best-practices/
  + https://docs.docker.com/engine/reference/commandline/run/ (fait attention au PID 1)
  + vous n'utilisez pas d'image distroless
  + est-ce que c'est Ok de faire quelque chose du genre: CMD /bin/bash /tmp/script.sh && /usr/sbin/php-fpm7.3 --nodaemonize ?
    - l'entrypoint peut bien être modifié au runtime, en cli ou via docker-compose (https://www.bmc.com/blogs/docker-cmd-vs-entrypoint) 
  + les différences entre RUN CMD ENTRYPOINT
    - CMD = définir une commande par défaut que l'on peut override
      + CMD ["executable", "params…"], par exemple: `CMD ["--help"]`
      + CMD c'est simplement une instruction qui permet de définir la commande de démarrage par défaut du container, à aucun moment durant le build la commande par défaut ne va être exécuté
    - ENTRYPOINT = définir un exécutable comme point d'entrée que l'on ne peut donc pas override, définir un process par défaut
    - faudrait que j’accède au bash du container pendant qu’il tourne et ça implique de demarrer le php-fpm et/ou le nginx soit même si je fait un CMD alors que si je fait un ENTRYPOINT je pense qu’il executera quand même et j’aurais pas à le faire enfin
  + pour le container wordpress a t on le droit d’utiliser une image de debian buster avec php-fpm ?
    - il y a une option pour ignorer le daemonize de base ???
    - pourquoi ignorer le daemonize de base ? faudrait il pas qu’il tourne pour écouter le port ?
    - Il tournera mais pas en arrière plan du coup…
    - pour moi il tourne ou ne tourne pas, mais en fait l’option daemonize n’agit que sur le foreground ou le background c’est ça ? donc l’option —nodaemonize si specifié ne fait que le mettre au premier plan
    - c'est un peu le fonctionnement de docker qui impose ce genre de truc
    - pourquoi est-ce que ce genre d'options existent
  + Tu peux avoir des trucs genre : ENTRYPOINT ["echo", "Hello"] CMD ["hehe"]
  + variables d'env, ca permet de faire docker run php --version par exemple, AKA la vraie commande mais avec juste docker run devant (si tu fais une image php) 
  + Les images officielles de nginx, mariadb, etc, sont de très bonnes inspirations
  + le flag init sur docker 
  + ['sh', 'test.sh'] vs sh /opt/test.sh ? '
  + docker compose = un simple wrapper build au dessus de docker 
  + повтор: les Shared Folders de la VM ou qu'un serveur SSH mal configuré sur la VM peuvent poser problème
  + le php-fpm dans le container wordpress doit il être démarré, c'est considéré comme un service, et c'est ce qui permet au serveur nginx de comprendre le php
  + php est censé démarrer sur /run/php/php-fpm7.3.sock mais le dossier /run/php n'existe pas
    - php-fpm c'est ce qui te permet d'executer le code php. nginx doit pouvoir passer la requete qui lui est faite a php-fpm dans le container wordpress
  +  oublier nginx de base dans vos images
  +  t c’est au run le problème car le container nginx ne connai pas fastcgi_pass wordpress:9000 en fait faudrait run (sans fastcgi_pass) ensuite le connecter au network que j’ai crée et enfin faire une modification dans la conf default pour y mettre fastcgi_pass wordpress et restart nginx et la ça fonctionne
  +  остановилась на
    
### Notes
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
