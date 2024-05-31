FROM   alpine:3.20.0                                         # см. https://www.alpinelinux.org/ (нельзя alpine:latest) 
RUN    apk update && apk upgrade && apk add --no-cache nginx # установить софт, --no-cache nginx = не сохраняя исходники в кэше
EXPOSE 443
CMD   ["nginx", "-g", "daemon off;"]                         # для отладки запускаем nginx напрямую (не демон) => логи напрямую в tty контейнера
