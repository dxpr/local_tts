#!/bin/sh
set -e

cd /src
npm ci --ignore-scripts
npx stylelint "css/**/*.css"
