# Makefile for Drupal common operations
# ⚠️ WARNING: Use tabs instead of spaces in this file.

ifneq (,$(wildcard ./.env))
	include .env
	ifneq (,$(wildcard ./.env.make))
		include .env.make
	endif

	# Default commands
	COMPOSER_COMMAND ?= composer
	DRUSH_COMMAND ?= vendor/bin/drush
	WEB_EXEC_COMMAND ?=
	NODE_EXEC_COMMAND ?=
default: help
else
	PROJECT_NAME = project
default: setup
endif

# Environment variables and configuration
ENVIRONMENT ?= dev
THEME_NAME ?= wingsuit

# Colors and styles
YELLOW := $(shell tput setaf 3)
GREEN  := $(shell tput setaf 2)
CYAN   := $(shell tput setaf 6)
RED    := $(shell tput setaf 1)
RESET  := $(shell tput sgr0)

.PHONY: help
help: ## ❓ Show available commands grouped by theme.
	@echo ""
	@echo "$(YELLOW)🚀 Available commands:$(RESET)"
	@echo ""
	@echo "$(GREEN)[ General ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "help" "❓ Show available commands."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "setup" "⚙️  Initialize local environment (copy .env and deploy dependencies)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "pull" "📥 Update code from git repository."
	@echo ""
	@echo "$(GREEN)[ Drupal ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "cr" "🧹 Clear all Drupal caches."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "cim" "📥 Import Drupal configuration."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "cex" "📤 Export Drupal configuration."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "updatedb" "🆙 Run pending database updates."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "deploy" "🚀 Run Drush deploy tasks (updb, cim, cr)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "audit" "🔍 Run site audit (site_audit)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "entity-updates" "🧩 Run entity updates (dev only)."
	@echo ""
	@echo "$(GREEN)[ Deployment ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "full-deploy" "🚢 Full deployment: git pull, dependencies and drush deploy."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "deploy-dependencies" "📦 Install PHP and theme dependencies."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "deploy-database" "🗄️  Sync database state (backup + deploy)."
	@echo ""
	@echo "$(GREEN)[ Maintenance ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "backup" "💾 Generate a database backup."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "backup-slim" "📉 Generate a slim database backup (no cache/watchdog data)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "backup-files" "📁 Generate a site files backup."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "import" "📥 Import a database backup (e.g., make import file=dump.sql[.gz])."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "fix-permissions" "🔑 Fix file and folder permissions."
	@echo ""
	@echo "$(GREEN)[ Translations ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "import-translations" "🌍 Import custom translations from custom_translations."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "update-translations" "🌏 Update module and theme translations."
	@echo ""
	@echo "$(GREEN)[ Updates ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "update-core" "🆙 Update Drupal core."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "update-contrib" "🆙 Update Drupal core and contributed modules."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "update-core-test" "🧪 Simulate core update."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "update-contrib-test" "🧪 Simulate core and contrib update."
	@echo ""
	@echo "$(GREEN)[ Theme and development ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "theme-production" "🎨 Generate theme assets for production using gulp."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "theme-watch" "👁️  Start theme watch mode using gulp."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "tests" "🧪 Run project tests."
	@echo ""
	@echo "$(GREEN)[ Migration ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-all" "🔄 Run full migration sequence (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-action" "🔄 Migrate action (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-dootrip" "🔄 Migrate dootrip (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-dootronic" "🔄 Migrate dootronic (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-edoovillage" "🔄 Migrate edoovillage (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-gallery" "🔄 Migrate gallery (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-hub" "🔄 Migrate hub (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-queue" "📥 Enqueue entities (e.g., make migrate-queue type=hub limit=100)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-page" "🔄 Migrate page (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-story" "🔄 Migrate story (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-team" "🔄 Migrate team (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-user" "🔄 Migrate user (foreground)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-all-queue" "📥 Enqueue all entities for migration."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-incremental" "🔄 Run incremental migration for all entities."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-all-incremental-bg" "🌙 Run incremental migration for all entities in background."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-*-bg" "🌙 Run migration in background with nohup and migration-[entity].log."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-*-incremental-bg" "🌙 Run incremental migration in background."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "migrate-check-integrity" "🔍 Compare N random nodes from D7 with D10."
	@echo ""
	@echo "$(GREEN)[ Deletion ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-action" "🗑️  Delete all action nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-user" "🗑️  Delete all users (except admin)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-hub" "🗑️  Delete all hub nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-edoovillage" "🗑️  Delete all edoovillage nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-dootronic" "🗑️  Delete all dootronic nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-dootrip" "🗑️  Delete all dootrip nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-gallery" "🗑️  Delete all gallery nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-page" "🗑️  Delete all basic page nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-story" "🗑️  Delete all labdoo story nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-teams" "🗑️  Delete all team related nodes."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "delete-all" "🗑️  Delete all migrated entities (except wiki)."
	@echo ""
	@echo "$(GREEN)[ Queue ]$(RESET)"
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "queue-process" "⚙️  Process the migration queue (labdoo_migrate_migration)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "queue-process-bg" "⚙️  Process the migration queue in background (labdoo_migrate_migration)."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "queue-stats" "📊 Show statistics of the migration queue."
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "entity-stats" "📊 Show statistics of entities."
	@echo ""

.PHONY: pull
pull: ## 📥 Update code from git repository.
	@echo "$(CYAN)📥 Updating code from git...$(RESET)"
	git pull

.PHONY: cr
cr: ## 🧹 Clear all Drupal caches.
	@echo "$(CYAN)🧹 Clearing caches...$(RESET)"
	$(DRUSH_COMMAND) cr

.PHONY: cim
cim: ## 📥 Import Drupal configuration.
	@echo "$(CYAN)📥 Importing configuration...$(RESET)"
	$(DRUSH_COMMAND) config:import -y

.PHONY: cex
cex: ## 📤 Export Drupal configuration.
	@echo "$(CYAN)📤 Exporting configuration...$(RESET)"
	$(DRUSH_COMMAND) config:export -y

.PHONY: deploy
deploy: ## 🚀 Run Drush deploy tasks (updb, cim, cr).
	@echo "$(CYAN)🚀 Running drush deploy...$(RESET)"
	$(DRUSH_COMMAND) deploy

.PHONY: updatedb
updatedb: ## 🆙 Run pending database updates.
	@echo "$(CYAN)🆙 Updating database...$(RESET)"
	$(DRUSH_COMMAND) updatedb -y

.PHONY: full-deploy
full-deploy: ## 🚢 Full deployment: git pull, dependencies and drush deploy.
	@echo "$(YELLOW)🚢 Starting full deployment...$(RESET)"
	$(MAKE) pull
	$(MAKE) deploy-dependencies
	$(MAKE) deploy
	$(MAKE) import-translations
	@echo "$(GREEN)✅ Full deployment finished successfully!$(RESET)"

.PHONY: deploy-dependencies
deploy-dependencies: ## 📦 Install PHP and theme dependencies.
	@echo "$(CYAN)📦 Installing PHP dependencies...$(RESET)"
	@if [ "$(ENVIRONMENT)" = "dev" ]; then \
		$(COMPOSER_COMMAND) install --no-interaction; \
	else \
		$(COMPOSER_COMMAND) install --no-interaction --no-dev --optimize-autoloader; \
	fi
	@echo "$(CYAN)🎨 Installing theme dependencies...$(RESET)"
	$(NODE_EXEC_COMMAND) npm install --prefix "web/themes/custom/$(THEME_NAME)"
	$(MAKE) theme-production

.PHONY: deploy-database
deploy-database: ## 🗄️ Sync database state (backup + deploy).
	@echo "$(CYAN)🗄️ Syncing database...$(RESET)"
	$(MAKE) backup
	$(MAKE) deploy
	$(MAKE) update-translations
	$(MAKE) import-translations

.PHONY: backup
backup: ## 💾 Generate a database backup.
	@echo "$(CYAN)💾 Generating database backup...$(RESET)"
	mkdir -p backups
	@if $(DRUSH_COMMAND) sql-dump --gzip --result-file="../backups/$(PROJECT_NAME)_$(ENVIRONMENT)_$$(date +%Y%m%d_%H%M).sql" --extra-dump="--single-transaction=false" 2>/dev/null; then \
		echo "$(GREEN)✅ Backup generated with Drush.$(RESET)"; \
	else \
		echo "$(YELLOW)⚠️ Drush failed, trying native mysqldump...$(RESET)"; \
		BACKUP_FILE="backups/$(PROJECT_NAME)_$(ENVIRONMENT)_$$(date +%Y%m%d_%H%M).sql.gz"; \
		mysqldump -h $(DB_HOST) -P $(DB_PORT) -u $(DB_USER) -p$(DB_PASSWORD) $(DB_NAME) --single-transaction=false | gzip > $$BACKUP_FILE; \
		if [ $$? -eq 0 ]; then \
			echo "$(GREEN)✅ Backup generated successfully (native). File: $$BACKUP_FILE$(RESET)"; \
		else \
			echo "$(RED)❌ Error generating backup (native).$(RESET)"; \
			exit 1; \
		fi \
	fi

.PHONY: backup-slim
backup-slim: ## 📉 Generate a slim database backup (no cache/watchdog data).
	@echo "$(CYAN)📉 Generating slim database backup...$(RESET)"
	mkdir -p backups
	@EXCLUDES_LIST="cache_*,watchdog,history,sessions,search_%,webprofiler"; \
	DRUSH_EXCLUDES=$$(echo $$EXCLUDES_LIST | sed 's/%/*/g'); \
	if $(DRUSH_COMMAND) sql-dump --gzip --structure-tables-list="$$DRUSH_EXCLUDES" --result-file="../backups/$(PROJECT_NAME)_$(ENVIRONMENT)_slim_$$(date +%Y%m%d_%H%M).sql" --extra-dump="--single-transaction=false" 2>/dev/null; then \
		echo "$(GREEN)✅ Slim backup generated with Drush.$(RESET)"; \
	else \
		echo "$(YELLOW)⚠️ Drush failed, trying native mysqldump with exclusions...$(RESET)"; \
		BACKUP_FILE="backups/$(PROJECT_NAME)_$(ENVIRONMENT)_slim_$$(date +%Y%m%d_%H%M).sql.gz"; \
		MYSQL_EXCLUDES=$$(echo $$EXCLUDES_LIST | sed 's/,/ --ignore-table=$(DB_NAME)./g' | sed 's/^/--ignore-table=$(DB_NAME)./'); \
		mysqldump -h $(DB_HOST) -P $(DB_PORT) -u $(DB_USER) -p$(DB_PASSWORD) $(DB_NAME) --single-transaction=false $$MYSQL_EXCLUDES | gzip > $$BACKUP_FILE; \
		if [ $$? -eq 0 ]; then \
			echo "$(GREEN)✅ Slim backup generated successfully (native). File: $$BACKUP_FILE$(RESET)"; \
		else \
			echo "$(RED)❌ Error generating slim backup (native).$(RESET)"; \
			exit 1; \
		fi \
	fi

.PHONY: backup-files
backup-files: ## 📁 Generate a site files backup.
	@echo "$(CYAN)📁 Generating files backup...$(RESET)"
	mkdir -p backups
	tar -zcf "backups/$(PROJECT_NAME)_$(ENVIRONMENT)_files_$$(date +%Y%m%d_%H%M).tar.gz" --exclude='css' --exclude='js' --exclude='php' --exclude='styles' -C "web/sites/default" files

.PHONY: import
import: ## 📥 Import a database backup (e.g., make import file=dump.sql[.gz]).
	@if [ -z "$(file)" ]; then \
		echo "$(RED)❌ Error: You must specify a file to import (e.g., make import file=dump.sql).$(RESET)"; \
		exit 1; \
	fi
	@if [ ! -f "$(file)" ]; then \
		echo "$(RED)❌ Error: File '$(file)' not found.$(RESET)"; \
		exit 1; \
	fi
	@echo "$(CYAN)📥 Importing database from $(file)...$(RESET)"
	@IMPORT_CMD=""; \
	if echo "$(file)" | grep -q "\.gz$$"; then \
		IMPORT_CMD="gunzip -c $(file)"; \
	else \
		IMPORT_CMD="cat $(file)"; \
	fi; \
	if $$IMPORT_CMD | $(DRUSH_COMMAND) sql-cli 2>/dev/null; then \
		echo "$(GREEN)✅ Database imported successfully with Drush!$(RESET)"; \
	else \
		echo "$(YELLOW)⚠️ Drush failed, trying native mysql...$(RESET)"; \
		$$IMPORT_CMD | mysql -h $(DB_HOST) -P $(DB_PORT) -u $(DB_USER) -p$(DB_PASSWORD) $(DB_NAME); \
		if [ $$? -eq 0 ]; then \
			echo "$(GREEN)✅ Database imported successfully (native)!$(RESET)"; \
		else \
			echo "$(RED)❌ Error importing database (native).$(RESET)"; \
			exit 1; \
		fi \
	fi

.PHONY: fix-permissions
fix-permissions: ## 🔑 Fix file and folder permissions.
	@echo "$(CYAN)🔑 Fixing permissions...$(RESET)"
	find . -type d -exec chmod 755 {} \;
	find . -type f -exec chmod 644 {} \;
	find web/sites/default/files/ -type d -exec chmod 775 {} \;
	find web/sites/default/files/ -type f -exec chmod 664 {} \;
	find config/ -type d -exec chmod 775 {} \;
	find config/ -type f -exec chmod 664 {} \;
	chmod +x vendor/bin/*
	@echo "$(YELLOW)Note: Manually execute 'chown -R $(USER):[SERVER_USER] .' for ownership.$(RESET)"

.PHONY: setup
setup: ## ⚙️ Initialize local environment (copy .env and deploy dependencies).
ifeq (,$(wildcard ./.env))
	@echo "$(CYAN)⚙️ Generating .env file...$(RESET)"
	cp .env.example .env
else
	@echo "$(YELLOW)⚠️ .env file already exists, it is not overwritten.$(RESET)"
endif
	$(MAKE) deploy-dependencies
	@echo "$(GREEN)✅ Environment initialized. Complete the data in .env and import the DB.$(RESET)"

.PHONY: import-translations
import-translations: ## 🌍 Import custom translations from custom_translations.
	@echo "$(CYAN)🌍 Importing custom translations...$(RESET)"
	@for entry in custom_translations/* ; do	\
		filename=$${entry##*/}; \
		lang=$${filename%.*}; \
		echo "Importing $${lang}...";	\
		$(DRUSH_COMMAND) locale-import $${lang} ../$${entry} --type=customized --override=all;	\
	done

.PHONY: update-translations
update-translations: ## 🌏 Update module and theme translations.
	@echo "$(CYAN)🌏 Updating translations...$(RESET)"
	$(DRUSH_COMMAND) locale-check
	$(DRUSH_COMMAND) locale-update

.PHONY: update-core
update-core: ## 🆙 Update Drupal core.
	@echo "$(CYAN)🆙 Updating Drupal core...$(RESET)"
	$(COMPOSER_COMMAND) update "drupal/core-*" --with-all-dependencies
	$(MAKE) updatedb
	$(MAKE) cex

.PHONY: update-contrib
update-contrib: ## 🆙 Update Drupal core and contributed modules.
	@echo "$(CYAN)🆙 Updating core and contrib...$(RESET)"
	$(COMPOSER_COMMAND) update drupal/* --with-dependencies
	$(MAKE) updatedb
	$(MAKE) cex

.PHONY: update-core-test
update-core-test: ## 🧪 Simulate core update.
	@echo "$(CYAN)🧪 Simulating core update...$(RESET)"
	$(COMPOSER_COMMAND) update "drupal/core-*" --with-all-dependencies --dry-run

.PHONY: update-contrib-test
update-contrib-test: ## 🧪 Simulate core and contrib update.
	@echo "$(CYAN)🧪 Simulating core and contrib update...$(RESET)"
	$(COMPOSER_COMMAND) update drupal/* --with-dependencies --dry-run

.PHONY: tests
tests: ## 🧪 Run project tests.
	@echo "$(CYAN)🧪 Running tests...$(RESET)"
	$(WEB_EXEC_COMMAND) php vendor/bin/phpunit

.PHONY: audit
audit: ## 🔍 Run site audit (site_audit).
	@echo "$(CYAN)🔍 Running audit...$(RESET)"
	$(DRUSH_COMMAND) pm:enable site_audit
	$(DRUSH_COMMAND) site_audit:all --bootstrap --detail > report.html
	$(DRUSH_COMMAND) pm:uninstall site_audit

.PHONY: entity-updates
entity-updates: ## 🧩 Run entity updates (dev only).
	@echo "$(CYAN)🧩 Updating entities...$(RESET)"
	$(DRUSH_COMMAND) pm:enable devel_entity_updates
	$(DRUSH_COMMAND) devel-entity-updates
	$(DRUSH_COMMAND) pm:uninstall devel_entity_updates

.PHONY: theme-production
theme-production: ## 🎨 Generate theme assets for production using gulp.
	@echo "$(CYAN)🎨 Generating assets for production (gulp)...$(RESET)"
	$(DRUSH_COMMAND) cr
	$(NODE_EXEC_COMMAND) "cd web/themes/custom/$(THEME_NAME) && gulp build"

.PHONY: theme-watch
theme-watch: ## 👁️  Start theme watch mode using gulp.
	@echo "$(CYAN)👁️  Watching theme changes (gulp)...$(RESET)"
	$(DRUSH_COMMAND) cr
	$(NODE_EXEC_COMMAND) "cd web/themes/custom/$(THEME_NAME) && gulp"

.PHONY: migrate-all
migrate-all: ## 🔄 Run full migration sequence (foreground).
	@echo "$(CYAN)🔄 Running full migration sequence...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush entity:delete node --bundle action
	vendor/bin/drush entity:delete user
	vendor/bin/drush labdoo-sync user
	vendor/bin/drush entity:delete node --bundle=hub
	vendor/bin/drush labdoo-sync hub
	vendor/bin/drush entity:delete node --bundle=edoovillage
	vendor/bin/drush labdoo-sync edoovillage
	vendor/bin/drush entity:delete node --bundle=dootronic
	vendor/bin/drush labdoo-sync laptop
	vendor/bin/drush entity:delete node --bundle=dootrip
	vendor/bin/drush labdoo-sync dootrip
	vendor/bin/drush entity:delete node --bundle=team_comment
	vendor/bin/drush entity:delete node --bundle=team_post
	vendor/bin/drush entity:delete node --bundle=team
	vendor/bin/drush sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"
	vendor/bin/drush labdoo-sync-teams
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-all-bg
migrate-all-bg: ## 🌙 Run full migration sequence in background (nohup + log).
	@echo "$(CYAN)🌙 Running full migration sequence in background (migration-all.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush entity:delete node --bundle action && vendor/bin/drush entity:delete user && vendor/bin/drush labdoo-sync user && vendor/bin/drush entity:delete node --bundle=hub && vendor/bin/drush labdoo-sync hub && vendor/bin/drush entity:delete node --bundle=edoovillage && vendor/bin/drush labdoo-sync edoovillage && vendor/bin/drush entity:delete node --bundle=dootronic && vendor/bin/drush labdoo-sync laptop && vendor/bin/drush entity:delete node --bundle=dootrip && vendor/bin/drush labdoo-sync dootrip && vendor/bin/drush entity:delete node --bundle=team_comment && vendor/bin/drush entity:delete node --bundle=team_post && vendor/bin/drush entity:delete node --bundle=team && vendor/bin/drush sql-query \"DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'\" && vendor/bin/drush labdoo-sync-teams && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-all.log 2>&1 &

.PHONY: migrate-all-resume-bg
migrate-all-resume-bg: ## 🌙 Resume full migration in background without deleting existing entities.
	@echo "$(CYAN)🌙 Resuming full migration in background (migration-all-resume.log) without deletions...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync user && vendor/bin/drush labdoo-sync hub && vendor/bin/drush labdoo-sync edoovillage && vendor/bin/drush labdoo-sync laptop && vendor/bin/drush labdoo-sync dootrip && vendor/bin/drush labdoo-sync-teams && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-all-resume.log 2>&1 &

.PHONY: migrate-action
migrate-action: ## 🔄 Migrate action (foreground).
	@echo "$(CYAN)🔄 Running action migration...$(RESET)"
	vendor/bin/drush labdoo-sync action

.PHONY: migrate-action-bg
migrate-action-bg: ## 🌙 Migrate action in background (nohup + log).
	@echo "$(CYAN)🌙 Running action migration in background (migration-action.log)...$(RESET)"
	nohup vendor/bin/drush labdoo-sync action > migration-action.log 2>&1 &

.PHONY: migrate-dootrip
migrate-dootrip: ## 🔄 Migrate dootrip (foreground).
	@echo "$(CYAN)🔄 Running dootrip migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync dootrip
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-dootrip-bg
migrate-dootrip-bg: ## 🌙 Migrate dootrip in background (nohup + log).
	@echo "$(CYAN)🌙 Running dootrip migration in background (migration-dootrip.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync dootrip && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-dootrip.log 2>&1 &

.PHONY: migrate-dootronic
migrate-dootronic: ## 🔄 Migrate dootronic (foreground).
	@echo "$(CYAN)🔄 Running dootronic migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync laptop
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-dootronic-bg
migrate-dootronic-bg: ## 🌙 Migrate dootronic in background (nohup + log).
	@echo "$(CYAN)🌙 Running dootronic migration in background (migration-dootronic.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync laptop && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-dootronic.log 2>&1 &

.PHONY: migrate-edoovillage
migrate-edoovillage: ## 🔄 Migrate edoovillage (foreground).
	@echo "$(CYAN)🔄 Running edoovillage migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush entity:delete node --bundle=edoovillage
	vendor/bin/drush labdoo-sync edoovillage
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-edoovillage-bg
migrate-edoovillage-bg: ## 🌙 Migrate edoovillage in background (nohup + log).
	@echo "$(CYAN)🌙 Running edoovillage migration in background (migration-edoovillage.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush entity:delete node --bundle=edoovillage && vendor/bin/drush labdoo-sync edoovillage && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-edoovillage.log 2>&1 &

.PHONY: migrate-gallery
migrate-gallery: ## 🔄 Migrate gallery (foreground).
	@echo "$(CYAN)🔄 Running gallery migration...$(RESET)"
	vendor/bin/drush entity:delete node --bundle=gallery
	vendor/bin/drush labdoo-sync-galleries

.PHONY: migrate-gallery-bg
migrate-gallery-bg: ## 🌙 Migrate gallery in background (nohup + log).
	@echo "$(CYAN)🌙 Running gallery migration in background (migration-gallery.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush entity:delete node --bundle=gallery && vendor/bin/drush labdoo-sync-galleries" > migration-gallery.log 2>&1 &

.PHONY: migrate-hub
migrate-hub: ## 🔄 Migrate hub (foreground).
	@echo "$(CYAN)🔄 Running hub migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync hub
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-hub-bg
migrate-hub-bg: ## 🌙 Migrate hub in background (nohup + log).
	@echo "$(CYAN)🌙 Running hub migration in background (migration-hub.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync hub && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-hub.log 2>&1 &

.PHONY: migrate-queue
migrate-queue: ## 📥 Enqueue entities for migration (e.g., make migrate-queue type=hub limit=100).
	@if [ -z "$(type)" ]; then \
		echo "$(RED)❌ Error: You must specify a type (e.g., make migrate-queue type=hub limit=100).$(RESET)"; \
		exit 1; \
	fi
	@echo "$(CYAN)📥 Enqueueing $(type) nodes...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync $(type) --queue --limit=$(or $(limit),-1)
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-page
migrate-page: ## 🔄 Migrate page (foreground).
	@echo "$(CYAN)🔄 Running page migration...$(RESET)"
	vendor/bin/drush entity:delete node --bundle=basic_page
	vendor/bin/drush labdoo-sync-basic-pages

.PHONY: migrate-page-bg
migrate-page-bg: ## 🌙 Migrate page in background (nohup + log).
	@echo "$(CYAN)🌙 Running page migration in background (migration-page.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush entity:delete node --bundle=basic_page && vendor/bin/drush labdoo-sync-basic-pages" > migration-page.log 2>&1 &

.PHONY: migrate-story
migrate-story: ## 🔄 Migrate story (foreground).
	@echo "$(CYAN)🔄 Running story migration...$(RESET)"
	vendor/bin/drush entity:delete node --bundle=labdoo_story
	vendor/bin/drush labdoo-sync-stories

.PHONY: migrate-story-bg
migrate-story-bg: ## 🌙 Migrate story in background (nohup + log).
	@echo "$(CYAN)🌙 Running story migration in background (migration-story.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush entity:delete node --bundle=labdoo_story && vendor/bin/drush labdoo-sync-stories" > migration-story.log 2>&1 &

.PHONY: migrate-team
migrate-team: ## 🔄 Migrate team (foreground).
	@echo "$(CYAN)🔄 Running team migration...$(RESET)"
	vendor/bin/drush entity:delete node --bundle=team_comment
	vendor/bin/drush entity:delete node --bundle=team_post
	vendor/bin/drush entity:delete node --bundle=team
	vendor/bin/drush sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"
	vendor/bin/drush labdoo-sync-teams

.PHONY: migrate-team-bg
migrate-team-bg: ## 🌙 Migrate team in background (nohup + log).
	@echo "$(CYAN)🌙 Running team migration in background (migration-team.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush entity:delete node --bundle=team_comment && vendor/bin/drush entity:delete node --bundle=team_post && vendor/bin/drush entity:delete node --bundle=team && vendor/bin/drush sql-query \"DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'\" && vendor/bin/drush labdoo-sync-teams" > migration-team.log 2>&1 &

.PHONY: migrate-user
migrate-user: ## 🔄 Migrate user (foreground).
	@echo "$(CYAN)🔄 Running user migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync user
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-all-queue
migrate-all-queue: ## 📥 Enqueue all entities for migration.
	@echo "$(CYAN)📥 Enqueueing all entities for migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync user --queue
	vendor/bin/drush labdoo-sync hub --queue
	vendor/bin/drush labdoo-sync edoovillage --queue
	vendor/bin/drush labdoo-sync laptop --queue
	vendor/bin/drush labdoo-sync dootrip --queue
	vendor/bin/drush labdoo-sync-teams --queue
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-user-bg
migrate-user-bg: ## 🌙 Migrate user in background (nohup + log).
	@echo "$(CYAN)🌙 Running user migration in background (migration-user.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync user && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-user.log 2>&1 &

.PHONY: migrate-incremental
migrate-incremental: ## 🔄 Run incremental migration for all entities.
	@echo "$(CYAN)🔄 Running incremental migration...$(RESET)"
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
	vendor/bin/drush labdoo-sync user --incremental
	vendor/bin/drush labdoo-sync hub --incremental
	vendor/bin/drush labdoo-sync edoovillage --incremental
	vendor/bin/drush labdoo-sync laptop --incremental
	vendor/bin/drush labdoo-sync dootrip --incremental
	vendor/bin/drush labdoo-sync-teams --incremental
	vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: migrate-all-incremental-bg
migrate-all-incremental-bg: ## 🌙 Run incremental migration for all entities in background.
	@echo "$(CYAN)🌙 Running incremental migration for all entities in background (migration-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync user --incremental && vendor/bin/drush labdoo-sync hub --incremental && vendor/bin/drush labdoo-sync edoovillage --incremental && vendor/bin/drush labdoo-sync laptop --incremental && vendor/bin/drush labdoo-sync dootrip --incremental && vendor/bin/drush labdoo-sync-teams --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-incremental.log 2>&1 &

.PHONY: migrate-user-incremental-bg
migrate-user-incremental-bg: ## 🌙 Run incremental user migration in background.
	@echo "$(CYAN)🌙 Running incremental user migration in background (migration-user-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync user --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-user-incremental.log 2>&1 &

.PHONY: migrate-hub-incremental-bg
migrate-hub-incremental-bg: ## 🌙 Run incremental hub migration in background.
	@echo "$(CYAN)🌙 Running incremental hub migration in background (migration-hub-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync hub --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-hub-incremental.log 2>&1 &

.PHONY: migrate-edoovillage-incremental-bg
migrate-edoovillage-incremental-bg: ## 🌙 Run incremental edoovillage migration in background.
	@echo "$(CYAN)🌙 Running incremental edoovillage migration in background (migration-edoovillage-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync edoovillage --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-edoovillage-incremental.log 2>&1 &

.PHONY: migrate-dootronic-incremental-bg
migrate-dootronic-incremental-bg: ## 🌙 Run incremental dootronic migration in background.
	@echo "$(CYAN)🌙 Running incremental dootronic migration in background (migration-dootronic-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync laptop --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-dootronic-incremental.log 2>&1 &

.PHONY: migrate-dootrip-incremental-bg
migrate-dootrip-incremental-bg: ## 🌙 Run incremental dootrip migration in background.
	@echo "$(CYAN)🌙 Running incremental dootrip migration in background (migration-dootrip-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y && vendor/bin/drush labdoo-sync dootrip --incremental && vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y" > migration-dootrip-incremental.log 2>&1 &

.PHONY: migrate-team-incremental-bg
migrate-team-incremental-bg: ## 🌙 Run incremental team migration in background.
	@echo "$(CYAN)🌙 Running incremental team migration in background (migration-team-incremental.log)...$(RESET)"
	nohup sh -c "vendor/bin/drush labdoo-sync-teams --incremental" > migration-team-incremental.log 2>&1 &

.PHONY: migrate-check-integrity
migrate-check-integrity: ## 🔍 Compare N random nodes from D7 with D10 (e.g., make migrate-check-integrity type=story limit=10).
	@if [ -z "$(type)" ]; then \
		echo "$(RED)❌ Error: You must specify a content type (e.g., make migrate-check-integrity type=story).$(RESET)"; \
		exit 1; \
	fi
	@echo "$(CYAN)🔍 Checking integrity for $(type) nodes...$(RESET)"
	$(DRUSH_COMMAND) labdoo:migrate-check-integrity $(type) --limit=$(or $(limit),5)

.PHONY: queue-process
queue-process: ## ⚙️ Process all migration queues.
	@echo "$(CYAN)⚙️ Processing migration queues...$(RESET)"
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_action
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_user
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_hub
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_edoovillage
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_laptop
	$(DRUSH_COMMAND) queue:run labdoo_migrate_migration_dootrip

.PHONY: queue-process-bg
queue-process-bg: ## ⚙️ Process all migration queues in background.
	@echo "$(CYAN)⚙️ Processing migration queues in background...$(RESET)"
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_action > queue-action.log 2>&1 &
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_user > queue-user.log 2>&1 &
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_hub > queue-hub.log 2>&1 &
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_edoovillage > queue-edoovillage.log 2>&1 &
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_laptop > queue-laptop.log 2>&1 &
	nohup $(DRUSH_COMMAND) queue:run labdoo_migrate_migration_dootrip > queue-dootrip.log 2>&1 &
	@echo "$(GREEN)✅ Queues are being processed in background. Check queue-*.log for details.$(RESET)"

.PHONY: queue-stats
queue-stats: ## 📊 Show statistics of the migration queues.
	@echo "$(CYAN)📊 Migration queues statistics:$(RESET)"
	$(DRUSH_COMMAND) queue:list | grep labdoo_migrate_migration
	@echo ""

.PHONY: entity-stats
entity-stats: ## 📊 Show statistics of entities.
	@echo "$(CYAN)📊 Entity statistics:$(RESET)"
	$(DRUSH_COMMAND) labdoo:entity-stats
	@echo ""

.PHONY: delete-action
delete-action: confirm ## 🗑️ Delete all action nodes.
	@$(MAKE) delete-action-no-confirm

.PHONY: delete-action-no-confirm
delete-action-no-confirm:
	@echo "$(CYAN)🗑️ Deleting action nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle action

.PHONY: delete-user
delete-user: confirm ## 🗑️ Delete all users (except admin).
	@$(MAKE) delete-user-no-confirm

.PHONY: delete-user-no-confirm
delete-user-no-confirm:
	@echo "$(CYAN)🗑️ Deleting users...$(RESET)"
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 1 -y
	$(DRUSH_COMMAND) entity:delete user
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: delete-hub
delete-hub: confirm ## 🗑️ Delete all hub nodes.
	@$(MAKE) delete-hub-no-confirm

.PHONY: delete-hub-no-confirm
delete-hub-no-confirm:
	@echo "$(CYAN)🗑️ Deleting hub nodes...$(RESET)"
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 1 -y
	$(DRUSH_COMMAND) entity:delete node --bundle=hub
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: delete-edoovillage
delete-edoovillage: confirm ## 🗑️ Delete all edoovillage nodes.
	@$(MAKE) delete-edoovillage-no-confirm

.PHONY: delete-edoovillage-no-confirm
delete-edoovillage-no-confirm:
	@echo "$(CYAN)🗑️ Deleting edoovillage nodes...$(RESET)"
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 1 -y
	$(DRUSH_COMMAND) entity:delete node --bundle=edoovillage
	$(DRUSH_COMMAND) cset geocoder.settings geocoder_presave_disabled 0 -y

.PHONY: delete-dootronic
delete-dootronic: confirm ## 🗑️ Delete all dootronic nodes.
	@$(MAKE) delete-dootronic-no-confirm

.PHONY: delete-dootronic-no-confirm
delete-dootronic-no-confirm:
	@echo "$(CYAN)🗑️ Deleting dootronic nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=dootronic

.PHONY: delete-dootrip
delete-dootrip: confirm ## 🗑️ Delete all dootrip nodes.
	@$(MAKE) delete-dootrip-no-confirm

.PHONY: delete-dootrip-no-confirm
delete-dootrip-no-confirm:
	@echo "$(CYAN)🗑️ Deleting dootrip nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=dootrip

.PHONY: delete-gallery
delete-gallery: confirm ## 🗑️ Delete all gallery nodes.
	@$(MAKE) delete-gallery-no-confirm

.PHONY: delete-gallery-no-confirm
delete-gallery-no-confirm:
	@echo "$(CYAN)🗑️ Deleting gallery nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=gallery

.PHONY: delete-page
delete-page: confirm ## 🗑️ Delete all basic page nodes.
	@$(MAKE) delete-page-no-confirm

.PHONY: delete-page-no-confirm
delete-page-no-confirm:
	@echo "$(CYAN)🗑️ Deleting basic page nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=basic_page

.PHONY: delete-story
delete-story: confirm ## 🗑️ Delete all labdoo story nodes.
	@$(MAKE) delete-story-no-confirm

.PHONY: delete-story-no-confirm
delete-story-no-confirm:
	@echo "$(CYAN)🗑️ Deleting story nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=labdoo_story

.PHONY: delete-teams
delete-teams: confirm ## 🗑️ Delete all team related nodes.
	@$(MAKE) delete-teams-no-confirm

.PHONY: delete-teams-no-confirm
delete-teams-no-confirm:
	@echo "$(CYAN)🗑️ Deleting team nodes...$(RESET)"
	$(DRUSH_COMMAND) entity:delete node --bundle=team_comment
	$(DRUSH_COMMAND) entity:delete node --bundle=team_post
	$(DRUSH_COMMAND) entity:delete node --bundle=team
	$(DRUSH_COMMAND) sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"

.PHONY: delete-all
delete-all: confirm ## 🗑️ Delete all migrated entities.
	@echo "$(YELLOW)⚠️ Deleting all migrated entities...$(RESET)"
	$(MAKE) delete-action-no-confirm
	$(MAKE) delete-user-no-confirm
	$(MAKE) delete-hub-no-confirm
	$(MAKE) delete-edoovillage-no-confirm
	$(MAKE) delete-dootronic-no-confirm
	$(MAKE) delete-dootrip-no-confirm
	$(MAKE) delete-gallery-no-confirm
	$(MAKE) delete-story-no-confirm
	$(MAKE) delete-teams-no-confirm

.PHONY: confirm
confirm: ## ❓ Ask for confirmation to continue.
	@echo -n "$(RED)❓ Are you sure? [y/N] $(RESET)" && read ans && [ $${ans:-N} = y ]
