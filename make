#!/usr/bin/env bash
composer install --no-dev
./vendor/bin/box compile
