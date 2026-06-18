<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\DatabaseYetiSQL\YetiSQL;

/**
 * Registers the pure-PHP YetiSQL engine as a connection type for the Database
 * plugin. The Database plugin fires `onDatabaseDrivers`; we respond by handing
 * it everything it needs to build and route a `yetisql` connection.
 *
 * This plugin requires PHP 8.3+ (YetiSQL uses PHP 8 syntax), but the Database
 * plugin itself stays compatible with PHP 7.x / Grav 1.7 — the engine simply
 * isn't available unless this plugin is installed on a supported host.
 */
class DatabaseYetisqlPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
            ],
            'onDatabaseDrivers' => ['onDatabaseDrivers', 0],
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload (registers YetiSQL).
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * [onDatabaseDrivers] Register the yetisql connection type with the
     * Database plugin.
     */
    public function onDatabaseDrivers(): void
    {
        if (!isset($this->grav['database'])) {
            return;
        }

        $this->grav['database']->registerDriver('yetisql', [
            'label' => 'YetiSQL (pure PHP)',
            'class' => YetiSQL::class,
            'dsn' => static function (array $connection): string {
                return 'yetisql:'
                    . ($connection['directory'] ?? '')
                    . '/'
                    . ($connection['filename'] ?? '');
            },
            // Connection-config fields, ready for the Database plugin to expose
            // in its admin form. Mirrors the built-in SQLite fieldset.
            'blueprint' => [
                'type' => 'fieldset',
                'title' => 'PLUGIN_DATABASE_YETISQL.DRIVER_YETISQL',
                'collapsed' => true,
                'collapsible' => true,
                'fields' => [
                    'connections.yetisql' => [
                        'type' => 'list',
                        'fields' => [
                            '.name' => [
                                'type' => 'text',
                                'label' => 'PLUGIN_DATABASE.CONNECTION_NAME',
                                'validate' => ['required' => true],
                            ],
                            '.directory' => [
                                'type' => 'text',
                                'label' => 'PLUGIN_DATABASE.SQLITE_DIRECTORY',
                                'validate' => ['required' => true],
                            ],
                            '.filename' => [
                                'type' => 'text',
                                'label' => 'PLUGIN_DATABASE.SQLITE_FILENAME',
                                'validate' => ['required' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
