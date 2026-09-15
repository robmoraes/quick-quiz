ROOT_DIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
comma := ,
DIST_DIR ?= $(ROOT_DIR)/deploy/dist
TAG ?= local
API_TAG ?= $(TAG)
ADS_API_TAG ?= $(TAG)
SPA_DEV_TAG ?= $(TAG)
SPA_DSLAB_TAG ?= $(TAG)
MANAGER_FPM_TAG ?= $(TAG)
MANAGER_WEB_TAG ?= $(TAG)
PLATFORM ?= linux/amd64,linux/arm64
BUILDER ?=
OUTPUT ?= oci

API_REPOSITORY ?= robmoraes/quick-quiz-api
ADS_API_REPOSITORY ?= robmoraes/quick-quiz-ads-api
SPA_DEV_REPOSITORY ?= robmoraes/quick-quiz-dev
SPA_DSLAB_REPOSITORY ?= robmoraes/quick-quiz-dslab
MANAGER_FPM_REPOSITORY ?= robmoraes/quick-quiz-manager-fpm
MANAGER_WEB_REPOSITORY ?= robmoraes/quick-quiz-manager-web

API_DOCKERFILE := $(ROOT_DIR)/deploy/docker/api/Dockerfile
ADS_API_DOCKERFILE := $(ROOT_DIR)/deploy/docker/ads-api/Dockerfile
SPA_DEV_DOCKERFILE := $(ROOT_DIR)/deploy/docker/spa-dev/Dockerfile
SPA_DSLAB_DOCKERFILE := $(ROOT_DIR)/deploy/docker/spa-dslab/Dockerfile
MANAGER_DOCKERFILE := $(ROOT_DIR)/deploy/docker/manager/Dockerfile

.PHONY: build-images api ads-api spa-dev spa-dslab manager-fpm manager-web validate-output dist-dir

build-images: api ads-api spa-dev spa-dslab manager-fpm manager-web

api: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(API_REPOSITORY),$(API_TAG)) \
		--file "$(API_DOCKERFILE)" \
		$(call output_args,api,$(API_TAG)) \
		"$(ROOT_DIR)"

ads-api: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(ADS_API_REPOSITORY),$(ADS_API_TAG)) \
		--file "$(ADS_API_DOCKERFILE)" \
		$(call output_args,ads-api,$(ADS_API_TAG)) \
		"$(ROOT_DIR)"

spa-dev: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(SPA_DEV_REPOSITORY),$(SPA_DEV_TAG)) \
		--file "$(SPA_DEV_DOCKERFILE)" \
		$(call output_args,spa-dev,$(SPA_DEV_TAG)) \
		"$(ROOT_DIR)"

spa-dslab: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(SPA_DSLAB_REPOSITORY),$(SPA_DSLAB_TAG)) \
		--file "$(SPA_DSLAB_DOCKERFILE)" \
		$(call output_args,spa-dslab,$(SPA_DSLAB_TAG)) \
		"$(ROOT_DIR)"

manager-fpm: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(MANAGER_FPM_REPOSITORY),$(MANAGER_FPM_TAG)) \
		--file "$(MANAGER_DOCKERFILE)" \
		--target fpm \
		$(call output_args,manager-fpm,$(MANAGER_FPM_TAG)) \
		"$(ROOT_DIR)"

manager-web: validate-output dist-dir
	docker buildx build $(if $(strip $(BUILDER)),--builder "$(BUILDER)") \
		--platform "$(PLATFORM)" \
		$(call image_tags,$(MANAGER_WEB_REPOSITORY),$(MANAGER_WEB_TAG)) \
		--file "$(MANAGER_DOCKERFILE)" \
		--target nginx \
		$(call output_args,manager-web,$(MANAGER_WEB_TAG)) \
		"$(ROOT_DIR)"

validate-output:
	@if [ "$(OUTPUT)" != "oci" ] && [ "$(OUTPUT)" != "push" ] && [ "$(OUTPUT)" != "load" ]; then \
		echo "Unsupported OUTPUT=$(OUTPUT). Use oci, push, or load." >&2; \
		exit 1; \
	fi
	@if [ "$(OUTPUT)" = "load" ]; then \
		case "$(PLATFORM)" in \
			*,*) echo "OUTPUT=load supports one platform. Set PLATFORM=linux/amd64 or PLATFORM=linux/arm64." >&2; exit 1 ;; \
		esac; \
	fi

dist-dir:
	@if [ "$(OUTPUT)" = "oci" ]; then mkdir -p "$(DIST_DIR)"; fi

define output_args
$(if $(filter oci,$(OUTPUT)),--output=type=oci$(comma)dest=$(DIST_DIR)/$(1)-$(2).oci.tar,$(if $(filter push,$(OUTPUT)),--push,--load))
endef

define image_tags
--tag "$(1):$(2)" $(if $(and $(filter push,$(OUTPUT)),$(filter-out latest,$(2))),--tag "$(1):latest")
endef
