pipeline {

    agent {
        docker {
            image 'php:8.2-cli'
            args '-u root'
        }
    }

    environment {
        REPO = "RonDevHub/Mini-Badges"
        CONTEXT = "jenkins/badge-test"
        BADGE_FILE = "output.svg"
    }

    stages {

        stage('Build Trigger Check') {
            steps {
                echo "🚀 Pipeline durch Push ausgelöst"
            }
        }

        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Badge Test') {
            steps {
                script {
                    def commit = sh(script: "git rev-parse HEAD", returnStdout: true).trim()
                    echo "🔑 Commit: ${commit}"

                    withCredentials([string(credentialsId: 'forgejo-token', variable: 'GITEA_TOKEN')]) {

                        sh """
                        curl -X POST "https://commitcloud.net/api/v1/repos/${REPO}/statuses/${commit}" \
                            -H "Content-Type: application/json" \
                            -H "Authorization: Bearer $GITEA_TOKEN" \
                            -d '{ "state": "pending", "description": "Badge Test running", "context": "${CONTEXT}" }'
                        """

                        sh "php badge.php type=static left=Hallo right=Welt style=flat > ${BADGE_FILE}"

                        def status = fileExists(BADGE_FILE) ? "success" : "failure"
                        echo "📦 Badge File Status: ${status}"

                        sh """
                        curl -X POST "https://commitcloud.net/api/v1/repos/${REPO}/statuses/${commit}" \
                            -H "Content-Type: application/json" \
                            -H "Authorization: Bearer $GITEA_TOKEN" \
                            -d '{ "state": "${status}", "description": "Badge Test Result", "context": "${CONTEXT}" }'
                        """

                        if (status == "failure") {
                            error("❌ Badge Test fehlgeschlagen")
                        }
                    }
                }
            }
        }
    }

    post {

        always {
            archiveArtifacts artifacts: 'output.svg', allowEmptyArchive: true
        }

        success {
            withCredentials([string(credentialsId: 'matrix-token', variable: 'MATRIX_TOKEN')]) {
                sh """
curl -XPOST "https://matrix.s3cr.net/_matrix/client/v3/rooms/!fPQlDFSrZpnlfnEfYH:matrix.s3cr.net/send/m.room.message?access_token=$MATRIX_TOKEN" \
 -H 'Content-Type: application/json' \
 -d '{ "msgtype": "m.text", "body": "✅ Jenkins Pipeline erfolgreich abgeschlossen!" }'
"""
            }
        }

        failure {
            withCredentials([string(credentialsId: 'matrix-token', variable: 'MATRIX_TOKEN')]) {
                sh """
curl -XPOST "https://matrix.s3cr.net/_matrix/client/v3/rooms/!fPQlDFSrZpnlfnEfYH:matrix.s3cr.net/send/m.room.message?access_token=$MATRIX_TOKEN" \
 -H 'Content-Type: application/json' \
 -d '{ "msgtype": "m.text", "body": "❌ Jenkins Pipeline fehlgeschlagen — Logs prüfen!" }'
"""
            }
        }
    }
}
