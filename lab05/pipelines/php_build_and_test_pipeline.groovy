// --- ci: lab05/todo-app (сборка и тесты на ssh-agent) ---
pipeline {
    // --- где ---
    agent {
        label 'php-agent'
    }

    // --- опции ---
    options {
        // хранить 10 последних сборок
        buildDiscarder(logRotator(numToKeepStr: '10'))
        // одна сборка за раз
        disableConcurrentBuilds()
        // клонирование делает стадия clone
        skipDefaultCheckout()
    }

    // --- параметры ---
    parameters {
        string(name: 'REPO_URL', defaultValue: 'https://github.com/raetskijr/automation.git', description: 'репозиторий')
        string(name: 'BRANCH', defaultValue: 'main', description: 'ветка')
    }

    // --- пути ---
    environment {
        APP_DIR = 'lab05/todo-app'
    }

    // --- этапы ---
    stages {
        stage('clone') {
            steps {
                git url: params.REPO_URL, branch: params.BRANCH
            }
        }

        stage('deps') {
            steps {
                dir(env.APP_DIR) {
                    // версии в лог
                    sh 'php -v | head -n 1 && composer --version'
                    // lock не хранится -> composer подбирает версии сам
                    sh 'composer install --no-interaction --no-progress'
                }
            }
        }

        stage('test') {
            steps {
                dir(env.APP_DIR) {
                    // junit.xml -> стадия report
                    sh 'vendor/bin/phpunit --testsuite unit --colors=never --log-junit reports/junit.xml'
                }
            }
        }

        stage('report') {
            steps {
                junit "${env.APP_DIR}/reports/junit.xml"
            }
        }
    }

    // --- итог ---
    post {
        always {
            echo 'Pipeline completed.'
        }
        success {
            echo 'All stages completed successfully!'
        }
        failure {
            echo 'Errors detected in the pipeline.'
        }
    }
}
