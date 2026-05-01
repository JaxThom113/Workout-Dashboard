# Training Log Polling Setup

Run the poller from cron every 2 minutes.

## Linux cron example

```bash
*/2 * * * * /usr/bin/php /var/www/scripts/poll_training_logs.php >> /var/www/database/logs/training_log_poll.log 2>&1
```

## Windows Task Scheduler example

- Program/script: `php`
- Add arguments: `C:\path\to\Workout-Dashboard\var\www\scripts\poll_training_logs.php`
- Trigger: repeat every 2 minutes indefinitely

## What this updates

- Raw training logs cache: `database/cache/cache.json`
- Incremental sync state: `database/state/sync_state.json`
- Structured poll logs: `database/logs/training_log_poll.log`
- Workout tables in AWS DB when Gemini keys are configured

## Manual verification

1. Run: `php scripts/poll_training_logs.php`
2. Add a new workout page in Notion.
3. Run the poller again and check `new_pages` increased.
4. Open Training Log page and verify the new raw entry appears.
5. Open Dashboard page and verify DB-backed metrics reflect the new workout.
