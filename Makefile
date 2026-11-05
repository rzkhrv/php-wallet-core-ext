# Host-side orchestration. All native compilation and PHP tests run in Docker.
# One directory per OS release, architecture, PHP ABI and Wallet Core ref.
.DEFAULT_GOAL := build

DOCKER ?= docker
WALLET_CORE_REPO ?= https://github.com/trustwallet/wallet-core.git
WALLET_CORE_REF ?= 4.8.4
OS ?= debian
OS_VERSION ?= 13
PLATFORM ?= linux/amd64
PHP_ZTS ?= 0
PHP_VERSION ?= 8.5.12
RUST_TOOLCHAIN ?= nightly-2025-12-11
BUILD_JOBS ?= 2
BUILD_ROOT ?= build
ASAN_DETECT_LEAKS ?= 1
DOCKER_BUILD_OPTIONS ?=
MATRIX_PHP_VERSIONS ?= $(PHP_VERSION)
MATRIX_WALLET_CORE_REFS ?= $(WALLET_CORE_REF)
# OS:release:architecture:ZTS; defaults to the selected single-build profile.
MATRIX_TARGETS ?= $(OS):$(OS_VERSION):$(patsubst linux/%,%,$(PLATFORM)):$(PHP_ZTS)
RUNTIME_IMAGE ?=
OCI_IMAGE ?=
# Select architectures already built and tested; never build during publish.
OCI_PLATFORMS ?= $(PLATFORM)
RUNTIME_PHP_BIN ?= php
# Rocky Linux publishes maintained base images under its project namespace.
OS_IMAGE = $(if $(filter rockylinux,$(OS)),rockylinux/rockylinux:$(OS_VERSION),$(OS):$(OS_VERSION))

# Escape slashes in branch refs so every build stays in one directory.
ABI_VARIANT = $(if $(filter 1,$(PHP_ZTS)),zts,nts)
CASE_DIR = $(BUILD_ROOT)/$(OS)$(OS_VERSION)-$(patsubst linux/%,%,$(PLATFORM))-php$(PHP_VERSION)-$(ABI_VARIANT)-wc$(subst /,%2F,$(WALLET_CORE_REF))
VALIDATE_OUTPUT = bash tools/build/check-build-output.sh "$(BUILD_ROOT)" "$(PHP_VERSION)" "$(WALLET_CORE_REF)" "$(OS)" "$(OS_VERSION)" "$(PLATFORM)" "$(PHP_ZTS)"
ARTIFACT_DIR = $(CASE_DIR)/artifacts
LOG_DIR = $(CASE_DIR)/logs

DOCKER_BUILD = $(DOCKER) buildx build \
	--file docker/Dockerfile \
	--platform="$(PLATFORM)" \
	--build-arg BASE_IMAGE="$(OS_IMAGE)" \
	--build-arg OS="$(OS)" \
	--build-arg OS_VERSION="$(OS_VERSION)" \
	--build-arg PHP_ZTS="$(PHP_ZTS)" \
	--build-arg WALLET_CORE_REPO="$(WALLET_CORE_REPO)" \
	--build-arg WALLET_CORE_REF="$(WALLET_CORE_REF)" \
	--build-arg PHP_VERSION="$(PHP_VERSION)" \
	--build-arg RUST_TOOLCHAIN="$(RUST_TOOLCHAIN)" \
	--build-arg BUILD_JOBS="$(BUILD_JOBS)" \
	--progress=plain $(DOCKER_BUILD_OPTIONS)

.PHONY: build update test test-asan matrix runtime-test publish clean clean-all help

# Preserve the previous successful .so if Docker verification fails.
build:
	@$(VALIDATE_OUTPUT)
	@mkdir -p -- "$(LOG_DIR)"
	@bash tools/build/publish-artifacts.sh "$(ARTIFACT_DIR)" "$(LOG_DIR)/build.log" \
	  $(DOCKER_BUILD) --target artifacts

update: DOCKER_BUILD_OPTIONS += --no-cache-filter upstream --build-arg WALLET_CORE_REFRESH=1
update: build

test:
	@$(VALIDATE_OUTPUT)
	@mkdir -p -- "$(LOG_DIR)"
	@bash tools/build/capture-build-log.sh "$(LOG_DIR)/test.log" \
	  $(DOCKER_BUILD) --target verified .

# A full Docker/ASan trace is saved alongside the release and test logs.
test-asan:
	@$(VALIDATE_OUTPUT)
	@if [ "$(OS)" != debian ]; then \
	  echo 'ERROR: test-asan is currently supported only for Debian (other OS targets are release-only)' >&2; \
	  exit 2; \
	fi
	@mkdir -p -- "$(LOG_DIR)"
	@WALLET_CORE_REF="$(WALLET_CORE_REF)" ASAN_DETECT_LEAKS="$(ASAN_DETECT_LEAKS)" \
	  bash tools/asan/capture-asan-trace.sh "$(LOG_DIR)/asan.log" \
	  $(DOCKER_BUILD) --build-arg ASAN_DETECT_LEAKS="$(ASAN_DETECT_LEAKS)" \
	  --target asan_verified .

# One matrix interface: profiles × PHP versions × Wallet Core revisions.
matrix:
	@MATRIX_TARGETS="$(MATRIX_TARGETS)" \
	  MATRIX_PHP_VERSIONS="$(MATRIX_PHP_VERSIONS)" \
	  MATRIX_WALLET_CORE_REFS="$(MATRIX_WALLET_CORE_REFS)" \
	  BUILD_ROOT="$(BUILD_ROOT)" \
	  RUST_TOOLCHAIN="$(RUST_TOOLCHAIN)" BUILD_JOBS="$(BUILD_JOBS)" \
	  python3 tools/matrix/matrix.py

# Explicit opt-in against a *different* application PHP image. The source
# artifact must already exist; a runtime failure never overwrites it.
runtime-test:
	@$(VALIDATE_OUTPUT)
	@test -n "$(RUNTIME_IMAGE)" || { echo 'ERROR: set RUNTIME_IMAGE to a PHP CLI image' >&2; exit 2; }
	@mkdir -p -- "$(LOG_DIR)"
	@bash tools/build/capture-build-log.sh "$(LOG_DIR)/runtime.log" \
	  python3 tools/matrix/runtime-test.py --docker "$(DOCKER)" \
	  --image "$(RUNTIME_IMAGE)" --php "$(RUNTIME_PHP_BIN)" --artifacts "$(ARTIFACT_DIR)"

# Publish *existing verified* artifacts. Single-platform builds need only one
# local artifact; specify OCI_PLATFORMS for a multi-platform release.
publish:
	@python3 tools/oci/publish.py \
	  --docker "$(DOCKER)" --image "$(OCI_IMAGE)" \
	  --build-root "$(BUILD_ROOT)" \
	  --os "$(OS)" --os-version "$(OS_VERSION)" \
	  --php "$(PHP_VERSION)" --zts "$(PHP_ZTS)" \
	  --core "$(WALLET_CORE_REF)" --platforms "$(OCI_PLATFORMS)"

# Remove only the requested version pair; other versions remain available.
clean:
	@$(VALIDATE_OUTPUT)
	rm -rf -- "$(CASE_DIR)"

clean-all:
	@$(VALIDATE_OUTPUT)
	rm -rf -- "$(BUILD_ROOT)"

help:
	@echo 'make OS=alpine OS_VERSION=3.24 PHP_VERSION=8.4.27 PHP_ZTS=0 PLATFORM=linux/amd64'
	@echo 'make OS=debian OS_VERSION=13 PHP_VERSION=8.4.27 PHP_ZTS=1 PLATFORM=linux/arm64'
	@echo 'make OS=ubuntu OS_VERSION=24.04; make OS=fedora OS_VERSION=44'
	@echo 'make OS=rockylinux OS_VERSION=9; make OS=almalinux OS_VERSION=9'
	@echo 'make test / make test-asan / make matrix / make runtime-test / make publish'
	@echo 'Output: build/<os><release>-<arch>-php<version>-<nts|zts>-wc<ref>/'
	@echo 'Report: build/matrix.json (last matrix run)'
	@echo 'make publish OCI_IMAGE=ghcr.io/owner/walletcore-php OS=alpine OS_VERSION=3.24 PHP_VERSION=8.4.27 PHP_ZTS=0 WALLET_CORE_REF=4.8.4'
	@echo 'make matrix MATRIX_TARGETS="debian:13:arm64:0 alpine:3.24:arm64:0" MATRIX_PHP_VERSIONS=8.4.27 MATRIX_WALLET_CORE_REFS=4.8.4'
	@echo 'make runtime-test RUNTIME_IMAGE=php:8.4-cli-trixie PHP_VERSION=8.4.27 OS=debian OS_VERSION=13'
	@echo 'test-asan currently supports Debian only'
