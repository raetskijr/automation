#!/bin/sh
# --- подготовка lab04: ключи агента + .env ---
set -eu
cd "$(dirname "$0")"

# --- ключи ---
mkdir -p secrets
if [ ! -f secrets/jenkins_agent_ssh_key ]; then
    ssh-keygen -f secrets/jenkins_agent_ssh_key -N "" -C "jenkins-agent@lab04"
fi

# --- .env ---
printf 'JENKINS_AGENT_SSH_PUBKEY=%s\n' "$(cat secrets/jenkins_agent_ssh_key.pub)" > .env
echo "[setup] .env готов, ключ: secrets/jenkins_agent_ssh_key"
