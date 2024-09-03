name = inception

all:
	mkdir -p ~/data
	mkdir -p ~/data/mariadb
	mkdir -p ~/data/wordpress
	@docker-compose -f docker-compose.yml --env-file .env up -d

down:
	@docker-compose -f docker-compose.yml --env-file .env down

re: fclean all

clean: down
	@docker system prune -a
	@sudo rm -rf ~/data/wordpress/*
	@sudo rm -rf ~/data/mariadb/*

fclean:
	@docker container ls -aq | xargs --no-run-if-empty docker container rm -f
	@docker system prune --all --force --volumes
	@docker network prune --force
	@docker volume prune --force
	@sudo rm -rf ~/data/wordpress/*
	@sudo rm -rf ~/data/mariadb/*

.PHONY	: all build down re clean fclean
