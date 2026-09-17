.DEFAULT_GOAL := up

.PHONY: up down artisan shell logs demo-workload demo-report demo-recovery

ARGS ?=
VARIANT ?= real

up:
	docker compose up -d --build
	@echo ""
	@echo "Ready: http://localhost:8080  (Horizon: http://localhost:8080/horizon)"

down:
	docker compose down

# Usage: make artisan ARGS="profiles:metrics"
#        make artisan ARGS="profiles:demo-workload --variant=naive"
artisan:
	docker compose exec php php artisan $(ARGS)

shell:
	docker compose exec php sh

# Usage: make logs               (all services)
#        make logs ARGS=horizon  (one service)
logs:
	docker compose logs -f $(ARGS)

# Usage: make demo-workload VARIANT=naive
demo-workload:
	docker compose exec php php artisan profiles:demo-workload --variant=$(VARIANT)

# Usage: make demo-report VARIANT=naive
demo-report:
	docker compose exec php php artisan profiles:demo-report --variant=$(VARIANT)

# Usage: make demo-recovery VARIANT=real
# Dispatches, then waits ~60s for the noisy account's rate limit to clear
# (it needs 3 failed calls, each retried after a random 5-15s delay, before
# the 4th succeeds), letting Horizon process it in the background the whole
# time, then reports the result.
demo-recovery:
	docker compose exec php php artisan profiles:demo-workload --variant=$(VARIANT)
	@echo "Waiting ~60s for the noisy account's rate limit to clear..."
	sleep 60
	docker compose exec php php artisan profiles:demo-report --variant=$(VARIANT)
