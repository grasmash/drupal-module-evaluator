#!/usr/bin/env bash

set -x

# DOMO_CLIENT_SECRET is set via Travis CI encrypted variable.
# @see https://developer.domo.com/manage-clients
if [[ -z "$DOMO_CLIENT_SECRET" ]]; then
    echo "Must provide DOMO_CLIENT_SECRET in environment" 1>&2
    exit 1
fi

DOMO_CLIENT_ID=5cfe147e-ca58-4216-9094-96b00bffbd43
DOMO_SCOPE=data
DOMO_DATASET_ID=a93cb9f1-9ae3-4989-8515-0f3682f3be6a
DOMO_UPDATE_METHOD=APPEND

# Strip header cells from CSV for Domo.
rm report-no-headers.csv
awk 'FNR>1{print}' report.csv > report-no-headers.csv
# Get access token.
DOMO_ACCESS_TOKEN=$(curl -u ${DOMO_CLIENT_ID}:${DOMO_CLIENT_SECRET} "https://api.domo.com/oauth/token?grant_type=client_credentials&scope=${DOMO_SCOPE}" | jq ".access_token" -r)
# Send to domo.
curl -v -H "Authorization:bearer ${DOMO_ACCESS_TOKEN}" -X PUT -H "Content-Type: text/csv" -H "Accept: application/json" "https://api.domo.com/v1/datasets/${DOMO_DATASET_ID}/data?updateMethod=${DOMO_UPDATE_METHOD}" --data-binary @report-no-headers.csv
