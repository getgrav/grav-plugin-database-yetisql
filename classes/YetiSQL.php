<?php
namespace Grav\Plugin\DatabaseYetiSQL;

use Grav\Plugin\Database\StatementHelpers;

/**
 * Wraps the pure-PHP YetiSQL PDO-shaped engine and mixes in the Database
 * plugin's shared StatementHelpers, so a yetisql connection gains the same
 * select/selectall/update/delete/insert sugar and safe tableExists() as the
 * native PDO connections.
 */
class YetiSQL extends \YetiDevWorks\YetiSQL\PDO
{
    use StatementHelpers;
}
