// --- cd: todo-app -> test-server (ansible на ansible-agent) ---
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

    // --- этапы: один плейбук на стадию ---
    stages {
        stage('clone') {
            steps {
                git url: params.REPO_URL, branch: params.BRANCH
            }
        }

        stage('copy') {
            steps {
                dir(env.ANSIBLE_DIR) {
                    sh 'ansible-playbook deploy/copy.yml'
                }
            }
        }

        stage('configure') {
            steps {
                dir(env.ANSIBLE_DIR) {
                    sh 'ansible-playbook deploy/configure.yml'
                }
            }
        }
    }

    // --- итог ---
    post {
        success {
            echo 'todo-app выложен на test-server.'
        }
        failure {
            echo 'Ошибка при выкладке todo-app.'
        }
    }
}
