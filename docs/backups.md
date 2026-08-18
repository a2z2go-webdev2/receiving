# Backups

A backup script is not a restore plan. For production, document and test recovery:

- Database backup cadence and retention.
- Object storage backup or replication.
- Encrypted environment variable backup.
- Monthly restore test.

On Laravel Cloud, use database exports for database backups and Object Storage for uploaded files. Do not store production backups in `storage/app/private/backups`.
