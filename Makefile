# Makefile for Drupal common operations
# ⚠️ WARNING: Use tabs instead of spaces in this file.

ifneq (,$(wildcard ./.env))
	include .env
	ifneq (,$(wildcard ./.env.make))
		include .env.make
	endif

	# Default commands
	COMPOSER_COMMAND ?= ddev composer
	DRUSH_COMMAND ?= ddev drush
	WEB_EXEC_COMMAND ?= ddev exec
	NODE_EXEC_COMMAND ?= ddev exec
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
	@printf "  $(CYAN)%-25s$(RESET) %s\n" "backup-files" "📁 Generate a site files backup."
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
	$(DRUSH_COMMAND) sql-dump --gzip --skip-tables-key=common --result-file="../backups/$(PROJECT_NAME)_$(ENVIRONMENT)_$$(date +%Y%m%d_%H%M).sql" --extra-dump="--single-transaction=false"

.PHONY: backup-files
backup-files: ## 📁 Generate a site files backup.
	@echo "$(CYAN)📁 Generating files backup...$(RESET)"
	mkdir -p backups
	tar -zcf "backups/$(PROJECT_NAME)_$(ENVIRONMENT)_files_$$(date +%Y%m%d_%H%M).tar.gz" --exclude='css' --exclude='js' --exclude='php' --exclude='styles' -C "web/sites/default" files

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

.PHONY: confirm
confirm: ## ❓ Ask for confirmation to continue.
	@echo -n "$(RED)❓ Are you sure? [y/N] $(RESET)" && read ans && [ $${ans:-N} = y ]
