# Backups

To enable the backups, first, you have to connect to the dilios server. the server must have its backup settings activated from the project settings. Put this in your local.settings.inc to enable backups using the command 'drush sbc'

```php
$settings['dilios_enable_backups'] = TRUE;
$settings['dilios_env'] = 'YOUR_ENV';
$settings['dilios_bucket_name'] = 'YOUR_BUCKET_NAME';
```

Make sure there is a cron job which execute drush sbc daily.
