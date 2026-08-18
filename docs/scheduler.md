# Scheduler

Define scheduled tasks in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('report:generate')->weekly()->mondays()->at('09:00');
```

Test locally with:

```bash
php artisan schedule:run
php artisan schedule:work
```

On Laravel Cloud, enable the built-in Scheduler toggle. For tasks that should not run concurrently across replicas, use `onOneServer()` and make sure the app has a shared cache backend that supports locks.
