import { defineRailway, preserve, project, service } from "railway/iac";

export const partial = "cozy-health-api";

export default defineRailway((context) => {
  if (context.projectId !== "33f9a938-49e3-42c5-83c7-633ee85760fa" || context.environmentId !== "9ffd3fd1-ae52-4cc7-903e-35b529a151ae") {
    throw new Error("The reviewed CozyHealth production environment is required.");
  }

  const api = service("cozy-health-api", {
    env: {
      "APP_MAINTENANCE_DRIVER": preserve(),
      "APP_MAINTENANCE_STORE": preserve(),
      "BACKUP_ENABLED": preserve(),
      "BACKUP_MAINTENANCE_APPROVED": preserve(),
      "BACKUP_SCHEDULED": preserve(),
      "BACKUP_STORAGE_BUCKET": preserve(),
      "DELETION_LEDGER_ACTIVATED_AT": preserve(),
      "DELETION_LEDGER_ENABLED": preserve(),
      "FIREBASE_CREDENTIALS_JSON": preserve(),
      "FIREBASE_PROJECT_ID": preserve(),
      "GOOGLE_ANDROID_CLIENT_ID": preserve(),
      "GOOGLE_CLIENT_ID": preserve(),
      "MYSQL_SSL_CA": preserve(),
      "QUIZ_ANDROID_FINGERPRINTS": preserve(),
      "QUIZ_ANDROID_PACKAGE": preserve(),
      "SENTRY_ALLOWED_HOST": preserve(),
      "SENTRY_ENABLED": preserve(),
      "SENTRY_LARAVEL_DSN": preserve(),
      "SENTRY_PROJECT_ID": preserve(),
      "SENTRY_RELEASE": preserve(),
      "APP_DEBUG": preserve(),
      "APP_ENV": preserve(),
      "APP_KEY": preserve(),
      "APP_NAME": preserve(),
      "APP_TIMEZONE": preserve(),
      "APP_URL": preserve(),
      "AVATAR_ACCESS_KEY_ID": preserve(),
      "AVATAR_BUCKET": preserve(),
      "AVATAR_ENDPOINT": preserve(),
      "AVATAR_REGION": preserve(),
      "AVATAR_SECRET_ACCESS_KEY": preserve(),
      "AVATAR_USE_PATH_STYLE_ENDPOINT": preserve(),
      "CACHE_STORE": preserve(),
      "CORS_ALLOWED_HEADERS": preserve(),
      "CORS_ALLOWED_METHODS": preserve(),
      "CORS_ALLOWED_ORIGINS": preserve(),
      "DB_CONNECTION": preserve(),
      "DB_DATABASE": preserve(),
      "DB_HOST": preserve(),
      "DB_PASSWORD": preserve(),
      "DB_PORT": preserve(),
      "DB_QUEUE_RETRY_AFTER": preserve(),
      "DB_TLS_ENFORCE": preserve(),
      "DB_USERNAME": preserve(),
      "EMERGENCY_EMAIL_DAILY_LIMIT": preserve(),
      "EMERGENCY_EMAIL_ENABLED": preserve(),
      "LOG_CHANNEL": preserve(),
      "LOG_LEVEL": preserve(),
      "MAIL_ENCRYPTION": preserve(),
      "MAIL_FROM_ADDRESS": preserve(),
      "MAIL_FROM_NAME": preserve(),
      "MAIL_HOST": preserve(),
      "MAIL_MAILER": preserve(),
      "MAIL_PASSWORD": preserve(),
      "MAIL_PORT": preserve(),
      "MAIL_USERNAME": preserve(),
      "PORT": preserve(),
      "PRIVATE_STORAGE_DRIVER": preserve(),
      "PROVIDER_PORTAL_URL": preserve(),
      "PUSH_ENABLED": preserve(),
      "PUSH_IOS_ENABLED": preserve(),
      "QUEUE_CONNECTION": preserve(),
      "RATE_LIMIT_EMAIL": preserve(),
      "RATE_LIMIT_LOGIN": preserve(),
      "RATE_LIMIT_PER_MINUTE": preserve(),
      "RUN_QUEUE_WORKER": preserve(),
      "RUN_SCHEDULER": preserve(),
      "SANCTUM_EXPIRATION": preserve(),
      "SANCTUM_STATEFUL_DOMAINS": preserve(),
      "SESSION_COOKIE": preserve(),
      "SESSION_DOMAIN": preserve(),
      "SESSION_DRIVER": preserve(),
      "SESSION_SECURE_COOKIE": preserve(),
      "STORE_BILLING_ENABLED": preserve(),
      "STUDENT_REVIEW_ENABLED": preserve(),
    },
    "source": {
      "branch": "main",
      "checkSuites": true,
      "repo": "cozy-health/cozy-health-api",
      "type": "github"
    },
    "build": {
      "buildEnvironment": "V3",
      "builder": "DOCKERFILE",
      "dockerfilePath": "Dockerfile"
    },
    "deploy": {
      "healthcheckPath": "/up",
      "ipv6EgressEnabled": false,
      "multiRegionConfig": {
        "sfo": {
          "numReplicas": 1
        }
      },
      "preDeployCommand": [
        "php artisan migrate --force"
      ],
      "runtime": "V2",
      "useLegacyStacker": false,
      "healthcheckTimeout": 30,
      "restartPolicyType": "ON_FAILURE",
      "restartPolicyMaxRetries": 3
    },
    "networking": {
      "customDomains": {
        "api.cozyhealth.org": {
          "port": 8080
        }
      },
      "serviceDomains": {
        "cozy-health-api-production-4b48.up.railway.app": {
          "port": 8080
        }
      }
    }
  });

  const worker = service("cozy-health-queue-worker", {
    env: {
      "APP_MAINTENANCE_DRIVER": preserve(),
      "APP_MAINTENANCE_STORE": preserve(),
      "BACKUP_ENABLED": preserve(),
      "BACKUP_MAINTENANCE_APPROVED": preserve(),
      "BACKUP_SCHEDULED": preserve(),
      "BACKUP_STORAGE_BUCKET": preserve(),
      "DELETION_LEDGER_ACTIVATED_AT": preserve(),
      "DELETION_LEDGER_ENABLED": preserve(),
      "FIREBASE_CREDENTIALS_JSON": preserve(),
      "FIREBASE_PROJECT_ID": preserve(),
      "GOOGLE_ANDROID_CLIENT_ID": preserve(),
      "GOOGLE_CLIENT_ID": preserve(),
      "MYSQL_SSL_CA": preserve(),
      "QUIZ_ANDROID_FINGERPRINTS": preserve(),
      "QUIZ_ANDROID_PACKAGE": preserve(),
      "SENTRY_ALLOWED_HOST": preserve(),
      "SENTRY_ENABLED": preserve(),
      "SENTRY_LARAVEL_DSN": preserve(),
      "SENTRY_PROJECT_ID": preserve(),
      "SENTRY_RELEASE": preserve(),
      "APP_DEBUG": preserve(),
      "APP_ENV": preserve(),
      "APP_KEY": preserve(),
      "APP_NAME": preserve(),
      "APP_TIMEZONE": preserve(),
      "APP_URL": preserve(),
      "AVATAR_ACCESS_KEY_ID": preserve(),
      "AVATAR_BUCKET": preserve(),
      "AVATAR_ENDPOINT": preserve(),
      "AVATAR_REGION": preserve(),
      "AVATAR_SECRET_ACCESS_KEY": preserve(),
      "AVATAR_USE_PATH_STYLE_ENDPOINT": preserve(),
      "CACHE_STORE": preserve(),
      "CORS_ALLOWED_HEADERS": preserve(),
      "CORS_ALLOWED_METHODS": preserve(),
      "CORS_ALLOWED_ORIGINS": preserve(),
      "DB_CONNECTION": preserve(),
      "DB_DATABASE": preserve(),
      "DB_HOST": preserve(),
      "DB_PASSWORD": preserve(),
      "DB_PORT": preserve(),
      "DB_QUEUE_RETRY_AFTER": preserve(),
      "DB_TLS_ENFORCE": preserve(),
      "DB_USERNAME": preserve(),
      "EMERGENCY_EMAIL_DAILY_LIMIT": preserve(),
      "EMERGENCY_EMAIL_ENABLED": preserve(),
      "LOG_CHANNEL": preserve(),
      "LOG_LEVEL": preserve(),
      "MAIL_ENCRYPTION": preserve(),
      "MAIL_FROM_ADDRESS": preserve(),
      "MAIL_FROM_NAME": preserve(),
      "MAIL_HOST": preserve(),
      "MAIL_MAILER": preserve(),
      "MAIL_PASSWORD": preserve(),
      "MAIL_PORT": preserve(),
      "MAIL_USERNAME": preserve(),
      "PRIVATE_STORAGE_DRIVER": preserve(),
      "PROVIDER_PORTAL_URL": preserve(),
      "PUSH_ENABLED": preserve(),
      "PUSH_IOS_ENABLED": preserve(),
      "QUEUE_CONNECTION": preserve(),
      "RATE_LIMIT_EMAIL": preserve(),
      "RATE_LIMIT_LOGIN": preserve(),
      "RATE_LIMIT_PER_MINUTE": preserve(),
      "RUN_QUEUE_WORKER": preserve(),
      "RUN_SCHEDULER": preserve(),
      "SANCTUM_EXPIRATION": preserve(),
      "SANCTUM_STATEFUL_DOMAINS": preserve(),
      "SESSION_COOKIE": preserve(),
      "SESSION_DOMAIN": preserve(),
      "SESSION_DRIVER": preserve(),
      "SESSION_SECURE_COOKIE": preserve(),
      "STORE_BILLING_ENABLED": preserve(),
      "STUDENT_REVIEW_ENABLED": preserve(),
    },
    "source": {
      "branch": "main",
      "checkSuites": true,
      "repo": "cozy-health/cozy-health-api",
      "rootDirectory": "/",
      "type": "github"
    },
    "build": {
      "buildEnvironment": "V3",
      "builder": "DOCKERFILE",
      "dockerfilePath": "Dockerfile"
    },
    "deploy": {
      "ipv6EgressEnabled": false,
      "multiRegionConfig": {
        "sfo": {
          "numReplicas": 1
        }
      },
      "runtime": "V2",
      "startCommand": "php artisan queue:work --queue=high,default,email --tries=3 --timeout=90",
      "useLegacyStacker": false,
      "restartPolicyType": "ON_FAILURE",
      "restartPolicyMaxRetries": 3
    }
  });

  return project("celebrated-flexibility", { resources: [api, worker] });
});
