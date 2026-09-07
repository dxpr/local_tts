#!/bin/bash
set -e

cd /src
npm ci --ignore-scripts
npx eslint js/
