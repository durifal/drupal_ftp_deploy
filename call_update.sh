#!/bin/bash
URL=$1
while : ; do
        responseCode=$(curl -sL -w "%{http_code}" -I "$URL" -o /dev/null)
        echo "Curl exited with response code $responseCode"
        if [ $responseCode != 206 ]; then
                break
        fi
done
echo "Update ended"
