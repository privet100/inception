FROM alpine:3.16
RUN	apk update && apk upgrade && apk add --no-cache nginx
CMD ["nginx", "-g", "daemon off;"]
