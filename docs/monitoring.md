# Monitoring

Recommended production layers:

- Laravel logs through the hosting provider.
- Exception tracking such as Sentry, Bugsnag, or Flare.
- Laravel Pulse for application-level visibility.
- Queue failure alerts.
- Database backup checks.

Cloud deployments should prefer provider-native log collection over relying on long-lived local log files.
