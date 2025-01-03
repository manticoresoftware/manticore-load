#!/bin/bash
docker build --platform linux/amd64 --file=Dockerfile --load -t manticore-load ..
