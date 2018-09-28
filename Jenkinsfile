pipeline {
    agent any
    options {
        disableConcurrentBuilds()
        timeout(time: 2, unit: 'HOURS')
    }
    stages {
        stage('Prepare') {
            steps {
                sh 'composer install --ignore-platform-reqs'
            }
        }
        stage('Build') {
            steps {
                 sh 'docker build -t gone/cloud-doctor .'
             }
        }
        stage('Push') {
            steps {
                 sh 'docker push gone/cloud-doctor'
            }
        }
    }
    post{
        always{
            cleanWs()
        }
    }
}