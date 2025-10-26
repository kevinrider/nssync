# Nightscout Sync Utility (nssync)

`nssync` is a command-line utility written in PHP for synchronizing data between two Nightscout instances. It is designed to be run periodically to keep a destination (e.g. backup or follower) Nightscout site in sync with a primary (source) site.

The script syncs the last seven days of data, in increments of one day, for the following MongoDB collections:
- `entries`
- `treatments`
- `devicestatus`
- `profiles`

For regular synchronization usage, `nssync` includes special handling for long-running "Temporary Override" treatments to ensure they are correctly updated when they end, even if that occurs outside the default seven day sync window.

## Installation

1. **Install PHP:** If your OS does not have PHP 8.0 or greater already installed, the third-party [php.new](https://php.new/) script can set up an easy-to-use local installation of the latest PHP version along with composer (PHP's package manager) and environment setup.


2. **Clone the repository:**
    ```bash
    git clone <repository-url>
    cd nssync
    ```

3. **Install PHP dependencies using Composer:**
    ```bash
    composer install --no-dev
    ```
    *(Use `composer install` if you intend to run the tests).*


## Configuration

Configuration is handled through environment variables. You can set these directly in your shell, or create a `.env` file and use a library like [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) if you prefer (note: this is not included by default).

### Required Environment Variables

| Variable                            | Description                                                                 | Example                               |
| ----------------------------------- | --------------------------------------------------------------------------- | ------------------------------------- |
| `SOURCE_NIGHTSCOUT_URL`             | The full URL of the primary Nightscout instance you are syncing **from**.     | `https://my-primary-ns.herokuapp.com` |
| `SOURCE_NIGHTSCOUT_API_SECRET`      | The API secret for the source Nightscout instance.                          | `my-very-secret-api-key`              |
| `DESTINATION_NIGHTSCOUT_URL`        | The full URL of the secondary Nightscout instance you are syncing **to**.     | `https://my-backup-ns.herokuapp.com`  |
| `DESTINATION_NIGHTSCOUT_API_SECRET` | The API secret for the destination Nightscout instance.                     | `another-secret-api-key`              |

### Bash env setup example

Copy the following into .bashrc, updating as needed for your specific config:
```bash
export SOURCE_NIGHTSCOUT_URL='https://my-primary-ns.herokuapp.com'
export SOURCE_NIGHTSCOUT_API_SECRET='my-very-secret-api-key'
export DESTINATION_NIGHTSCOUT_URL='https://my-backup-ns.herokuapp.com'
export DESTINATION_NIGHTSCOUT_API_SECRET='another-secret-api-key'
```

Don't forget to apply the bash profile changes:
```bash
source ~/.bashrc # (or similar depending on your shell)
```
## Usage

The script is designed to be executed from the command line.

```bash
php nssync.php
```

If you are only making a one-time copy of a source Nightscout site, adjust the following line in `nssync.php`:
```php
$currentDate = $currentDate->modify('-1 week');
```
to whatever time frame you need using [DateTimeImmutable relative formats syntax](https://www.php.net/manual/en/datetime.formats.php). For example:

```php
$currentDate = $currentDate->modify('-3 years');
```
This would attempt to sync the previous 3 years (from today) of Nightscout data. While this script can handle long time periods, a direct MongoDB migration (`mongodump`/`mongorestore`) is likely faster and easier if you have command-line access to both servers.

### Scheduling with Cron

For automatic, periodic synchronization, it is highly recommended to schedule the script to run using a cron job. A good frequency is **every 10 to 15 minutes**. This ensures the destination site stays reasonably up-to-date without putting excessive load on either server. Syncing once every few hours or even once per day should not be an issue either.

**Example Cron Job (runs every 10 minutes):**

1.  Open your crontab for editing:
    ```bash
    crontab -e
    ```

2.  Add the following line, making sure to replace `/path/to/nssync` with the absolute path to the project directory on your system.

    ```cron
    */10 * * * * cd /path/to/nssync && /usr/bin/php nssync.php >> /your/log/dir/nssync.log 2>&1
    ```

This example does the following:
- `*/10 * * * *`: Runs the command every 10 minutes.
- `cd /path/to/nssync`: Changes to the project directory.
- `/usr/bin/php nssync.php`: Executes the sync script using the absolute path to the PHP executable.
- `>> /your/log/dir/nssync.log 2>&1`: Appends all output (both stdout and stderr) to a log file, which is useful for debugging.

### Scheduling with Watch

An alternative to cron is to use `watch` and `screen`. The following example runs `nssync` every ten minutes. This method is simpler to set up but will not persist if the server is rebooted.

```
screen
watch -n 600 php nssync.php
Ctrl-a d
```
To reattach to the screen session:
```
screen -r
```
