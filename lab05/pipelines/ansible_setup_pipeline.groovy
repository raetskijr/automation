// --- настройка test-server: ansible на ansible-agent ---
pipeline {
    // --- где ---
    agent {
        label 'ansible-runner'
    }

    // --- опции ---
    options {
        buildDiscarder(logRotator(numToKeepStr: '10'))
        disableConcurrentBuilds()
        skipDefaultCheckout()
    }

    // --- параметры ---
    parameters {
        string(name: 'REPO_URL', defaultValue: 'https://github.com/raetskijr/automation.git', description: 'репозиторий')
        string(name: 'BRANCH', defaultValue: 'main', description: 'ветка')
    }

    // --- пути ---
    environment {
        ANSIBLE_DIR = 'lab05/ansible'
    }

    // --- этапы ---
    stages {
        stage('clone') {
            steps {
                git url: params.REPO_URL, branch: params.BRANCH
            }
        }

        stage('playbook') {
            steps {
                // инвентарь, ключ и become: ansible.cfg + group_vars, поэтому команда без флагов
                dir(env.ANSIBLE_DIR) {
                    sh 'ansible --version | head -n 1'
                    sh 'ansible-playbook setup_test_server.yml'
                }
            }
        }
    }

    // --- итог ---
    post {
        success {
            echo 'test-server настроен.'
        }
        failure {
            echo 'Ошибка при настройке test-server.'
        }
    }
}
