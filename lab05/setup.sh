#!/bin/sh
# --- подготовка lab05: три пары ключей + .env ---
set -eu
cd "$(dirname "$0")"

# --- ключи ---
# имя файла -> комментарий ключа
#   jenkins_agent_ssh_key  jenkins -> ssh-agent
#   jenkins_to_ansible     jenkins -> ansible-agent
#   ansible_to_test        ansible-agent -> test-server
mkdir -p secrets
make_key() {
    if [ ! -f "secrets/$1" ]; then
        ssh-keygen -q -t ed25519 -f "secrets/$1" -N "" -C "$2"
        echo "[setup] создан ключ: secrets/$1"
    fi
}
make_key jenkins_agent_ssh_key "jenkins-agent@lab05"
make_key jenkins_to_ansible    "jenkins@ansible-agent"
make_key ansible_to_test       "ansible@test-server"

# --- .env: только публичный ключ для ssh-agent ---
printf 'JENKINS_AGENT_SSH_PUBKEY=%s\n' "$(cat secrets/jenkins_agent_ssh_key.pub)" > .env
echo "[setup] .env готов"
