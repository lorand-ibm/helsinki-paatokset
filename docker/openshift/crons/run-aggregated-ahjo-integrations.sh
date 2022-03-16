#!/bin/bash

while true
do
  # Sleep for 1 hour.
  sleep 3600
  echo "Aggregating data for meetings: $(date)"
  drush ahjo-proxy:aggregate meetings --dataset=latest
  echo "Aggregating data for cases: $(date)"
  drush ahjo-proxy:aggregate cases --dataset=latest
  echo "Migrating data for cases: $(date)"
  echo "Aggregating data for decisions: $(date)"
  drush ahjo-proxy:aggregate decisions --dataset=latest
  echo "Aggregating council org data: $(date)"
  drush ahjo-proxy:get-council-positionsoftrust
  echo "Aggregating data for council members: $(date)"
  drush ahjo-proxy:get-trustees positionsoftrust_council.json
  echo "Migrating data for meetings: $(date)"
  drush migrate-import ahjo_meetings:latest --update
  echo "Migrating data for cases: $(date)"
  drush migrate-import ahjo_cases:latest --update
  echo "Migrating data for decisions: $(date)"
  drush migrate-import ahjo_decisions:latest --update
  echo "Migrating data for council members: $(date)"
  drush migrate-import ahjo_trustees:council --update
  echo "Generating motions from meeting data: $(date)"
  drush ahjo-proxy:get-motions
  echo "Updating decision and motion data: $(date)"
  drush ahjo-proxy:update-decisions
  # Sleep for 23 hours.
  sleep 82800
done
