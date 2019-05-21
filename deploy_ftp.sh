#!/bin/sh
HOST=$1
USER=$2
PASSWD=$3
FILE=$4

ftp -n $HOST <<END_SCRIPT
quote USER $USER
quote PASS $PASSWD
put $FILE
quit
END_SCRIPT
exit 0
