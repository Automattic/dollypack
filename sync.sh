#!/usr/bin/env bash
rsync -avz --delete --exclude='.git' --exclude='sync.sh' \
  /Users/artpi/GIT/dollypack/ \
  l7d429ea4aa135392@ssh.pressable.com:htdocs/wp-content/plugins/dollypack/
