#!/bin/bash

php artisan serve &

ngrok http 8000 > ngrok.log &

sleep 5

NGROK_URL=$(grep -o "https://[0-9a-zA-Z\-]*\.ngrok-free\.app" ngrok.log | head -n1)

php artisan telegram:webhook:set "$NGROK_URL"

php artisan telegram:webhook &

wait
