.PHONY: setup up down test lint logs shell
setup:
	docker compose up --build -d --wait
up:
	docker compose up -d --wait
down:
	docker compose down
test:
	docker compose exec app php artisan test
lint:
	docker compose exec app vendor/bin/pint --test
logs:
	docker compose logs -f --tail=100
shell:
	docker compose exec app sh
