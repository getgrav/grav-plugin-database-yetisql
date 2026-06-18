# Database YetiSQL Plugin

The **Database YetiSQL** plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It adds [YetiSQL](https://github.com/yetidevworks/yetisql) — a pure-PHP, SQLite-compatible embedded SQL engine — as a connection type for the [Database](https://github.com/getgrav/grav-plugin-database) plugin.

Because YetiSQL needs no PDO extension, it is useful on hosts where `pdo_sqlite` (or any PDO driver) is unavailable. It works on both Grav 1.7 and 2.0, as long as the host runs **PHP 8.3 or newer**.

## How it works

The Database plugin fires an `onDatabaseDrivers` event. This plugin responds by registering a `yetisql` driver with everything the Database plugin needs to build and route a connection. Nothing in the Database plugin depends on YetiSQL — the engine is simply added when this plugin is installed and enabled.

## Requirements

* Grav `>= 1.7`
* The [Database](https://github.com/getgrav/grav-plugin-database) plugin `>= 1.2.0`
* PHP `>= 8.3`

## Installation

Installing through [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm):

```
bin/gpm install database-yetisql
```

## Usage

Add a `yetisql` connection to `user/config/plugins/database.yaml`:

```yaml
enabled: true
connections:
  yetisql:
    - name: default
      directory: user/data
      filename: app.ysql
```

Then use it exactly like any other Database plugin connection:

```php
$db = Grav::instance()['database']->yetisql('default');

if (!$db->tableExists('views')) {
    $db->insert('CREATE TABLE views (id VARCHAR(255), count INTEGER)');
}

$rows = $db->selectall('SELECT * FROM views');
```

The connection exposes the same `select` / `selectall` / `update` / `delete` / `insert` helpers and the safe `tableExists()` as the native PDO connections.
