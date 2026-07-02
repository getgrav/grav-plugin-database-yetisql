# v1.0.1
## 07/02/2026

1. [](#improved)
    * Updated the bundled YetiSQL engine to 1.0.1, which more than halves query benchmark times.
1. [](#bugfix)
    * Fixed a query bug where a table alias in a correlated subquery could return the full-table count instead of the per-value count.

# v1.0.0
## 06/18/2026

1. [](#new)
    * Initial release
    * Registers the pure-PHP [YetiSQL](https://github.com/yetidevworks/yetisql) engine as a connection type for the Database plugin via its `onDatabaseDrivers` event
