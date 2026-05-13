# nextcloud-exelearning — build and packaging.
#
# Targets mirror the conventions of gdrive-exelearning so contributors who
# arrive from that project find the same names. The eXeLearning static
# editor is optional: the viewer (preview) works without it.

# --- Shell detection (Windows compatibility) ----------------------------
# Targets shell out to bash idioms (`if`/`for`, `$$()`, `[ ... ]`, here
# pipes), so running this Makefile under native Windows cmd.exe or
# PowerShell will fail with confusing errors. Detect that situation
# early and point users at Git Bash, which is the supported shell on
# Windows. Inside Git Bash / MSYS / Cygwin / WSL MSYSTEM is set, so we
# treat those as plain Unix.
ifeq ($(OS),Windows_NT)
    ifdef MSYSTEM
        SYSTEM_OS := unix
    else ifdef CYGWIN
        SYSTEM_OS := unix
    else
        SYSTEM_OS := windows
    endif
else
    SYSTEM_OS := unix
endif

ifeq ($(SYSTEM_OS),windows)
$(info )
$(info ============================================================)
$(info  ERROR: Non-Bash shell detected (cmd or PowerShell))
$(info ============================================================)
$(info  This Makefile uses POSIX shell syntax and cannot run)
$(info  under cmd.exe or PowerShell. Use the bundled wrapper:)
$(info )
$(info    .\make.bat <target>     (cmd.exe / PowerShell))
$(info )
$(info  It re-execs `make` inside Git Bash, falling back to WSL.)
$(info  Install Git for Windows if Git Bash is missing:)
$(info    https://git-scm.com/downloads)
$(info ============================================================)
$(info )
$(error Refusing to run under a non-Bash shell. Use `.\make.bat <target>` instead.)
endif

APP_NAME := exelearning
APP_VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
BUILD_DIR := $(CURDIR)/build
ARTIFACT_DIR := $(BUILD_DIR)/artifacts
RELEASE_DIR := $(BUILD_DIR)/$(APP_NAME)
# Override on the command line to label release artefacts independently
# from appinfo/info.xml, e.g. `make package PACKAGE_VERSION=0.2.0-rc1`.
PACKAGE_VERSION ?= $(APP_VERSION)
PACKAGE_NAME := $(APP_NAME)-$(PACKAGE_VERSION).tar.gz

# --- PHP runtime selection ----------------------------------------------
# appinfo/info.xml declares PHP 8.1–8.3 as the supported range. macOS
# users frequently end up with PHP 8.4+ in their PATH (Homebrew default),
# which makes Composer / PHPUnit / php-cs-fixer print compatibility
# warnings and occasionally crash on syntax not yet supported on 8.1.
# Pick the highest in-range PHP available, in this order:
#   1. an explicit `PHP=` override on the command line
#   2. Homebrew's `php@8.3` keg
#   3. distro-style versioned binaries (php8.3, php83)
#   4. `php` in PATH, with a warning when it falls outside 8.1–8.3
PHP ?= $(shell \
	for candidate in \
		/opt/homebrew/opt/php@8.3/bin/php \
		/usr/local/opt/php@8.3/bin/php \
		/opt/homebrew/opt/php@8.2/bin/php \
		/usr/local/opt/php@8.2/bin/php \
		/opt/homebrew/opt/php@8.1/bin/php \
		/usr/local/opt/php@8.1/bin/php \
		php8.3 php83 php8.2 php82 php8.1 php81 php; do \
		if command -v $$candidate >/dev/null 2>&1; then \
			echo $$candidate; \
			exit 0; \
		fi; \
	done; \
	echo php)
COMPOSER ?= $(PHP) $(shell command -v composer 2>/dev/null || echo composer)

# --- Editor variables (optional bundle) ----------------------------------
EXELEARNING_EDITOR_REPO ?= exelearning/exelearning
# Optional pin: leave empty to track the latest GitHub release.
EXELEARNING_EDITOR_REF ?=
EXELEARNING_EDITOR_REF_TYPE ?= tag
EDITOR_SOURCE_DIR := exelearning
EDITOR_OUTPUT_DIR := $(CURDIR)/js/editor
EDITOR_REPO_URL := https://github.com/$(EXELEARNING_EDITOR_REPO)
EDITOR_API_LATEST := https://api.github.com/repos/$(EXELEARNING_EDITOR_REPO)/releases/latest

TMP_DIR := .cache
EDITOR_ZIP := $(TMP_DIR)/exelearning-static.zip
EDITOR_EXTRACT_DIR := $(TMP_DIR)/exelearning-static

# --- Docker / try-it-out variables ---------------------------------------
DOCKER_NAME ?= nc-exelearning
DOCKER_PORT ?= 8080
DOCKER_IMAGE ?= nextcloud:30
NC_ADMIN_USER ?= admin
NC_ADMIN_PASS ?= admin

.PHONY: help install build dev watch-js lint typecheck test clean \
	composer-install composer-test cs-check cs-fix php-version \
	download-editor fetch-editor-source build-editor clean-editor \
	package appstore \
	up down restart logs shell occ-status status sync

help:
	@echo "Common targets:"
	@echo "  install         - install JS and PHP dependencies"
	@echo "  build           - build the frontend bundle into js/"
	@echo "  dev             - one-shot development build"
	@echo "  watch-js        - rebuild on file changes"
	@echo "  lint            - lint frontend sources"
	@echo "  typecheck       - run vue-tsc / tsc"
	@echo "  test            - run JS and PHP tests"
	@echo "  cs-check        - php-cs-fixer dry-run (Nextcloud coding standard)"
	@echo "  cs-fix          - apply php-cs-fixer fixes"
	@echo "  php-version     - show which PHP binary Make picked up"
	@echo ""
	@echo "Try-it-out (Docker) targets:"
	@echo "  up              - build + download editor (if missing) + start Nextcloud"
	@echo "  down            - remove the running container"
	@echo "  restart         - down + up"
	@echo "  sync            - re-copy app files into a running container"
	@echo "  logs            - tail container logs"
	@echo "  shell           - open a www-data shell inside the container"
	@echo "  status          - show 'occ status' from the container"
	@echo ""
	@echo "  download-editor - download the prebuilt eXeLearning static editor"
	@echo "  build-editor    - clone and build the eXeLearning static editor"
	@echo "  clean           - remove build artifacts"
	@echo "  package         - build a distribution tarball for the app store"
	@echo "                    Usage: make package [PACKAGE_VERSION=x.y.z]"
	@echo "  appstore        - alias of 'package' (legacy name)"

# --- Dependencies --------------------------------------------------------
install: composer-install
	npm install

composer-install:
	@if command -v composer >/dev/null 2>&1; then \
		$(COMPOSER) install --no-dev --prefer-dist; \
	else \
		echo "composer not found, skipping PHP dependency install"; \
	fi

# Print which PHP binary the Makefile is using so you can confirm it
# falls within the 8.1–8.3 range declared in appinfo/info.xml.
php-version:
	@echo "PHP binary : $(PHP)"
	@$(PHP) -v | head -n1

# --- Frontend ------------------------------------------------------------
build:
	npm run build

dev:
	npm run dev

watch-js:
	npm run watch

lint:
	npm run lint

typecheck:
	npm run typecheck

# --- Tests ---------------------------------------------------------------
test: composer-test
	npm test

composer-test:
	@if [ -f vendor/bin/phpunit ]; then \
		$(PHP) vendor/bin/phpunit --configuration tests/phpunit.xml; \
	else \
		echo "phpunit not installed (run 'composer install' first), skipping"; \
	fi

# Run php-cs-fixer (Nextcloud preset) in dry-run mode — same command CI
# uses, so a green local run means a green CI lint job.
cs-check:
	@if [ -f vendor/bin/php-cs-fixer ]; then \
		$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no; \
	else \
		echo "php-cs-fixer not installed (run 'composer install' first), skipping"; \
	fi

cs-fix:
	@if [ -f vendor/bin/php-cs-fixer ]; then \
		$(PHP) vendor/bin/php-cs-fixer fix; \
	else \
		echo "php-cs-fixer not installed (run 'composer install' first), skipping"; \
	fi

# --- Editor bundle (optional) -------------------------------------------
# Download the prebuilt static editor from a GitHub release. Defaults to the
# latest tag; override with EXELEARNING_EDITOR_REF=vX.Y.Z if you need a pin.
download-editor:
	@mkdir -p "$(TMP_DIR)"
	@rm -rf "$(EDITOR_EXTRACT_DIR)" "$(EDITOR_ZIP)"
	@REF="$(EXELEARNING_EDITOR_REF)"; \
	if [ -z "$$REF" ]; then \
		REF=$$(curl -fsSL "$(EDITOR_API_LATEST)" | grep '"tag_name"' | head -n1 | sed -E 's/.*"tag_name": *"([^"]+)".*/\1/'); \
	fi; \
	if [ -z "$$REF" ]; then \
		echo "Could not resolve the latest eXeLearning release."; exit 1; \
	fi; \
	URL="$${EDITOR_ZIP_URL:-$(EDITOR_REPO_URL)/releases/download/$$REF/exelearning-static-$$REF.zip}"; \
	echo "Downloading eXeLearning editor $$REF from $$URL"; \
	curl -fL "$$URL" -o "$(EDITOR_ZIP)"; \
	unzip -q "$(EDITOR_ZIP)" -d "$(EDITOR_EXTRACT_DIR)"
	@rm -rf "$(EDITOR_OUTPUT_DIR)"
	@mkdir -p "$(EDITOR_OUTPUT_DIR)"
	@if [ "$$(find "$(EDITOR_EXTRACT_DIR)" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')" = "1" ] && \
		[ "$$(find "$(EDITOR_EXTRACT_DIR)" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')" = "1" ]; then \
		cp -R "$$(find "$(EDITOR_EXTRACT_DIR)" -mindepth 1 -maxdepth 1 -type d)"/. "$(EDITOR_OUTPUT_DIR)/"; \
	else \
		cp -R "$(EDITOR_EXTRACT_DIR)"/. "$(EDITOR_OUTPUT_DIR)/"; \
	fi
	@# Nextcloud's root .htaccess rewrites unknown extensions (incl. .json)
	@# through index.php. Disable rewriting in the editor subtree so static
	@# assets like data/bundle.json are served directly by Apache.
	@printf '<IfModule mod_rewrite.c>\n    RewriteEngine off\n</IfModule>\n' \
		> "$(EDITOR_OUTPUT_DIR)/.htaccess"

# Shallow-clone the editor source. Defaults to the latest release tag.
fetch-editor-source:
	rm -rf "$(EDITOR_SOURCE_DIR)"
	@REF="$(EXELEARNING_EDITOR_REF)"; \
	REF_TYPE="$(EXELEARNING_EDITOR_REF_TYPE)"; \
	if [ -z "$$REF" ]; then \
		REF=$$(curl -fsSL "$(EDITOR_API_LATEST)" | grep '"tag_name"' | head -n1 | sed -E 's/.*"tag_name": *"([^"]+)".*/\1/'); \
		REF_TYPE="tag"; \
	fi; \
	if [ -z "$$REF" ]; then \
		echo "Could not resolve the latest eXeLearning release."; exit 1; \
	fi; \
	echo "Fetching eXeLearning editor source $$REF (type=$$REF_TYPE)"; \
	if [ "$$REF_TYPE" = "branch" ] || [ "$$REF_TYPE" = "tag" ]; then \
		git clone --depth 1 --branch "$$REF" "$(EDITOR_REPO_URL).git" "$(EDITOR_SOURCE_DIR)"; \
	elif [ "$$REF_TYPE" = "commit" ]; then \
		git clone --depth 1 "$(EDITOR_REPO_URL).git" "$(EDITOR_SOURCE_DIR)"; \
		cd "$(EDITOR_SOURCE_DIR)" && git checkout "$$REF"; \
	else \
		echo "EXELEARNING_EDITOR_REF_TYPE must be branch, tag, or commit"; \
		exit 1; \
	fi

build-editor:
	rm -rf "$(EDITOR_OUTPUT_DIR)"
	$(MAKE) fetch-editor-source
	cd "$(EDITOR_SOURCE_DIR)" && bun install
	cd "$(EDITOR_SOURCE_DIR)" && OUTPUT_DIR="$(EDITOR_OUTPUT_DIR)" bun run build:static

clean-editor:
	rm -rf "$(EDITOR_SOURCE_DIR)" "$(EDITOR_OUTPUT_DIR)" "$(EDITOR_ZIP)" "$(EDITOR_EXTRACT_DIR)"

# --- Packaging -----------------------------------------------------------
clean:
	rm -rf $(BUILD_DIR) js/*.js js/*.js.map js/*.css js/*.css.map dist coverage

# Produce build/artifacts/$(APP_NAME)-$(PACKAGE_VERSION).tar.gz with
# `$(APP_NAME)/` as the single top-level directory, as required by the
# Nextcloud appstore. Files are filtered through .distignore (which is
# the canonical exclude list for distribution; .gitignore is only used
# for the local working copy).
package: clean build
	@if [ ! -f .distignore ]; then \
		echo "Error: .distignore is missing — cannot build a distribution package."; \
		exit 1; \
	fi
	@mkdir -p $(ARTIFACT_DIR)
	@rm -rf $(RELEASE_DIR)
	@mkdir -p $(RELEASE_DIR)
	@echo ">> staging $(APP_NAME) $(PACKAGE_VERSION) into $(RELEASE_DIR)"
	@rsync -a --delete --exclude-from=.distignore ./ $(RELEASE_DIR)/
	@echo ">> creating $(ARTIFACT_DIR)/$(PACKAGE_NAME)"
	@tar -C $(BUILD_DIR) -czf $(ARTIFACT_DIR)/$(PACKAGE_NAME) $(APP_NAME)
	@rm -rf $(RELEASE_DIR)
	@echo
	@echo "================================================================"
	@echo " Built $(ARTIFACT_DIR)/$(PACKAGE_NAME)"
	@echo "   - app id : $(APP_NAME)"
	@echo "   - version: $(PACKAGE_VERSION)"
	@echo "================================================================"

# Backwards-compatible alias for the historical target name.
appstore: package

# --- Docker quick-start --------------------------------------------------
# `make up` builds the frontend, starts a Nextcloud container with the
# auto-install variables (admin user + SQLite database) so Nextcloud
# finishes setup unattended, then copies the app files into the container
# with `docker cp`. Bind-mounting the repo into Docker-on-macOS is too
# slow because every file under node_modules/ traverses the shared
# filesystem during install; copying just the runtime files (a few MB)
# sidesteps that.
#
# Override host port with `make up DOCKER_PORT=9000`.
APP_RUNTIME_DIRS := appinfo lib js templates img src/sw

up:
	@command -v docker >/dev/null 2>&1 || { echo "docker is not installed"; exit 1; }
	@# Reinstall when node_modules is missing or older than the lockfile.
	@# Catches the case where a previous run installed against an older
	@# package.json (e.g. before cross-env was added) and the partial
	@# tree on disk no longer matches what package.json declares.
	@if [ ! -d node_modules ] || [ package-lock.json -nt node_modules ]; then \
		echo ">> npm install"; npm install; \
	fi
	@echo ">> npm run build"
	@npm run build
	@if [ ! -f js/$(APP_NAME)-main.js ]; then \
		echo "ERROR: js/$(APP_NAME)-main.js missing after build."; exit 1; \
	fi
	@# Make sure the optional eXeLearning static editor is present so the
	@# /apps/exelearning/editor route works inside the container. Skip the
	@# download when js/editor/index.html already exists; refresh the
	@# bundle explicitly with `make download-editor` (optionally pinning
	@# EXELEARNING_EDITOR_REF=vX.Y.Z) when a new upstream tag ships.
	@if [ ! -f js/editor/index.html ]; then \
		echo ">> downloading eXeLearning static editor"; \
		$(MAKE) --no-print-directory download-editor; \
	else \
		echo ">> eXeLearning editor present at js/editor/ (run 'make download-editor' to refresh)"; \
	fi
	@docker rm -f $(DOCKER_NAME) >/dev/null 2>&1 || true
	@echo ">> starting $(DOCKER_IMAGE) as $(DOCKER_NAME) on :$(DOCKER_PORT)"
	@docker run -d --name $(DOCKER_NAME) \
		-p $(DOCKER_PORT):80 \
		-e NEXTCLOUD_ADMIN_USER=$(NC_ADMIN_USER) \
		-e NEXTCLOUD_ADMIN_PASSWORD=$(NC_ADMIN_PASS) \
		-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
		-e SQLITE_DATABASE=nextcloud \
		$(DOCKER_IMAGE) >/dev/null
	@echo ">> waiting for Nextcloud to finish installing"
	@for i in $$(seq 1 120); do \
		state=$$(docker exec -u www-data $(DOCKER_NAME) php occ status 2>/dev/null | grep "installed:" | awk '{print $$3}'); \
		if [ "$$state" = "true" ]; then \
			echo "   Nextcloud installed."; break; \
		fi; \
		if ! docker ps --format '{{.Names}}' | grep -q "^$(DOCKER_NAME)$$"; then \
			echo; echo "ERROR: container stopped. Last 40 log lines:"; \
			docker logs --tail 40 $(DOCKER_NAME) 2>&1 | tail -40; exit 1; \
		fi; \
		printf "."; sleep 2; \
		if [ $$i = 120 ]; then \
			echo; echo "Timed out waiting for Nextcloud install. Last 40 log lines:"; \
			docker logs --tail 40 $(DOCKER_NAME) 2>&1 | tail -40; exit 1; \
		fi; \
	done
	@echo ">> copying app files into container"
	@docker exec $(DOCKER_NAME) mkdir -p /var/www/html/custom_apps/exelearning
	@for d in $(APP_RUNTIME_DIRS); do \
		echo "   - $$d/"; \
		parent="$$(dirname $$d)"; \
		docker exec $(DOCKER_NAME) mkdir -p "/var/www/html/custom_apps/exelearning/$$parent"; \
		docker exec $(DOCKER_NAME) rm -rf "/var/www/html/custom_apps/exelearning/$$d"; \
		docker cp "$(CURDIR)/$$d" "$(DOCKER_NAME):/var/www/html/custom_apps/exelearning/$$parent/"; \
	done
	@docker exec $(DOCKER_NAME) chown -R www-data:www-data /var/www/html/custom_apps
	@echo ">> configuring apps_paths so /custom_apps is writable"
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 0 path     --value=/var/www/html/apps         >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 0 url      --value=/apps                       >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 0 writable --value=false --type=boolean        >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 1 path     --value=/var/www/html/custom_apps   >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 1 url      --value=/custom_apps                >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ config:system:set apps_paths 1 writable --value=true  --type=boolean        >/dev/null
	@echo ">> enabling exelearning"
	@docker exec -u www-data $(DOCKER_NAME) php occ app:enable exelearning
	@# `.elpx` extension → MIME mapping. This is the only piece a
	@# Nextcloud app cannot ship by itself: the file is read from
	@# /var/www/html/config/. See README → "Custom MIME types" for the
	@# manual admin steps required on production installs.
	@echo ">> registering .elpx MIME mapping"
	@docker exec $(DOCKER_NAME) bash -c \
		'echo "{\"elpx\":[\"application/vnd.exelearning.elpx\",\"application/zip\"]}" \
		 > /var/www/html/config/mimetypemapping.json && \
		 chown www-data:www-data /var/www/html/config/mimetypemapping.json'
	@docker exec -u www-data $(DOCKER_NAME) php occ maintenance:mimetype:update-js >/dev/null
	@docker exec -u www-data $(DOCKER_NAME) php occ maintenance:mimetype:update-db --repair-filecache >/dev/null
	@# Files-list icons are served by ElpxPreviewProvider via
	@# core/preview?...&mimeFallback=true; when an .elpx package has no
	@# screenshot.png, the provider returns img/elpx-preview-fallback.png.
	@# No additional mimetypealiases.json hack is needed for that.
	@echo
	@echo "================================================================"
	@echo " Nextcloud + eXeLearning is ready."
	@echo "   URL:   http://localhost:$(DOCKER_PORT)"
	@echo "   User:  $(NC_ADMIN_USER)"
	@echo "   Pass:  $(NC_ADMIN_PASS)"
	@echo
	@echo " Upload a .elpx file in Files and click it. Stop with 'make down'."
	@echo "================================================================"

# Re-copy the app files into the running container (after editing PHP code
# or rebuilding js/). Much faster than `make restart`.
#
# Also gracefully restarts Apache so PHP opcache picks up controller-level
# attribute changes (e.g. adding `#[NoCSRFRequired]` is invisible until the
# reflection cache for that class invalidates).
sync:
	@docker ps --format '{{.Names}}' | grep -q "^$(DOCKER_NAME)$$" || { echo "$(DOCKER_NAME) not running. Use 'make up' first."; exit 1; }
	@for d in $(APP_RUNTIME_DIRS); do \
		echo ">> sync $$d/"; \
		parent="$$(dirname $$d)"; \
		docker exec $(DOCKER_NAME) rm -rf "/var/www/html/custom_apps/exelearning/$$d"; \
		docker exec $(DOCKER_NAME) mkdir -p "/var/www/html/custom_apps/exelearning/$$parent"; \
		docker cp "$(CURDIR)/$$d" "$(DOCKER_NAME):/var/www/html/custom_apps/exelearning/$$parent/"; \
	done
	@docker exec $(DOCKER_NAME) chown -R www-data:www-data /var/www/html/custom_apps
	@docker exec $(DOCKER_NAME) apachectl -k graceful 2>&1 | grep -v "ServerName" || true
	@echo "Synced."

down:
	@docker rm -f $(DOCKER_NAME) >/dev/null 2>&1 || true
	@echo "Container $(DOCKER_NAME) removed."

restart:
	@$(MAKE) down
	@$(MAKE) up

logs:
	@docker logs -f $(DOCKER_NAME)

shell:
	@docker exec -it -u www-data $(DOCKER_NAME) bash

status:
	@docker exec -u www-data $(DOCKER_NAME) php occ status
