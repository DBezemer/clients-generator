#!/bin/bash
source ../configurations/system.ini

output_path=$WEB_DIR/content/clientlibs

rm -fr $output_path/$@
rm -fr ../cache/api_v3/*
rm -fr ../cache/generator/*

php generate.php "$@"

rm -fr ../admin_console/lib/Kaltura/Client
rm -fr ../batch/client/*
rm -fr ../tests/lib/*

rsync -avC $output_path/php5ZendClientAdminConsole/ ../admin_console/lib
rsync -avC $output_path/batchClient/ ../batch/client
rsync -avC  $output_path/testsClient/* ../tests/lib

rm -fr ../cache/batch/*
