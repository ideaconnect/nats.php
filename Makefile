.PHONY: build-local-phan local-phan

build-local-phan:
	docker build -t local-phan -f LocalPhan .

local-phan: build-local-phan
	docker run --rm local-phan composer phan