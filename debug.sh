#!/usr/bin/env bash

# This file contains example commands that can be used for debugging.

# Create test csv.
# ./bin/evaluate create-report test.yml --format=csv --fields=name,title,branch,score,scored_points,total_points,downloads,security_advisory_coverage,starred,usage,recommended_version,is_stable,issues_total,issues_priority_critical,issues_priority_major,issues_priority_normal,issues_priority_minor,issues_category_bug,issues_category_feature,issues_category_support,issues_category_task,issues_category_plan,issues_status_rtbc,issues_status_fixed_last,releases_total,releases_last,releases_days_since,deprecation_errors,phpcs_drupal_errors,phpcs_drupal_warnings,phpcs_compat_errors,phpcs_compat_warnings,composer_validate,orca_integrated > test.csv
# awk 'FNR>1{print}' test.csv > test-no-headers.csv

# Domo interactions.
# @see https://developer.domo.com/docs/domo-apis/dataset
# @see https://developer.domo.com/docs/dataset/quickstart-4
# DOMO_CLIENT_SECRET=something. Set this in your CLI first!
# @see https://developer.domo.com/manage-clients
DOMO_CLIENT_ID=5cfe147e-ca58-4216-9094-96b00bffbd43
DOMO_SCOPE=data
DOMO_DATASET_ID=a93cb9f1-9ae3-4989-8515-0f3682f3be6a
DOMO_ACCESS_TOKEN=$(curl -u ${DOMO_CLIENT_ID}:${DOMO_CLIENT_SECRET} "https://api.domo.com/oauth/token?grant_type=client_credentials&scope=${DOMO_SCOPE}" | jq ".access_token" -r)

# Create data set. Do this only once!
# curl -v -H "Authorization:bearer ${DOMO_ACCESS_TOKEN}" -H "Content-Type: application/json" -H "Accept: application/json" -X POST "https://api.domo.com/v1/datasets" -d @domo-dataset-schema.json

# Change schema.
 curl -v -H "Authorization:bearer ${DOMO_ACCESS_TOKEN}" -H "Content-Type: application/json" -H "Accept: application/json" -X PUT "https://api.domo.com/v1/datasets/${DOMO_DATASET_ID}" -d @domo-dataset-schema.json

# Update data set.
# curl -v -H "Authorization:bearer ${DOMO_ACCESS_TOKEN}" -X PUT -H "Content-Type: text/csv" -H "Accept: application/json" "https://api.domo.com/v1/datasets/${DOMO_DATASET_ID}/data" -d '@test-no-headers.csv'
