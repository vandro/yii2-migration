<?php

namespace bizley\migration;

use Exception;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\console\controllers\MigrateController;
use yii\db\Expression;
use yii\db\Query;
use yii\db\Schema;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * Update migration file generator.
 *
 * @author Paweł Bizley Brzozowski
 * @version 2.0
 * @license Apache 2.0
 * https://github.com/bizley/yii2-migration
 */
class Updater extends Generator
{
    /**
     * @var string the name of the table for keeping applied migration information.
     */
    public $migrationTable = '{{%migration}}';

    /**
     * @var array list of namespaces containing the migration classes.
     */
    public $migrationNamespaces = [];

    /**
     * @var string directory storing the migration classes. This can be either
     * a path alias or a directory.
     */
    public $migrationPath = '@app/migrations';

    /**
     * @var bool whether to only display changes instead of creat update migration.
     */
    public $showOnly = 0;

    private $_tableSubject;

    /**
     * Sets subject table name.
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        $this->_tableSubject = $this->tableName;
    }

    private $_classMap;

    /**
     * Sets dummy Migration class.
     * @throws InvalidParamException
     */
    protected function setDummyMigrationClass()
    {
        $this->_classMap = Yii::$classMap['yii\db\Migration'];
        Yii::$classMap['yii\db\Migration'] = Yii::getAlias('@vendor/bizley/migration/src/dummy/Migration.php');
    }

    /**
     * Restores original Migration class.
     */
    protected function restoreMigrationClass()
    {
        Yii::$classMap['yii\db\Migration'] = $this->_classMap;
    }

    /**
     * Returns the migration history.
     * This is slightly modified MigrateController::getMigrationHistory() method.
     * Migrations are fetched from newest to oldest.
     * @return array the migration history
     */
    public function fetchHistory()
    {
        if ($this->db->schema->getTableSchema($this->migrationTable, true) === null) {
            return [];
        }
        $query = (new Query)
            ->select(['version', 'apply_time'])
            ->from($this->migrationTable)
            ->orderBy(['apply_time' => SORT_DESC, 'version' => SORT_DESC]);

        if (empty($this->migrationNamespaces)) {
            $rows = $query->all($this->db);
            $history = ArrayHelper::map($rows, 'version', 'apply_time');
            unset($history[MigrateController::BASE_MIGRATION]);
            return $history;
        }

        $rows = $query->all($this->db);

        $history = [];
        foreach ($rows as $key => $row) {
            if ($row['version'] === MigrateController::BASE_MIGRATION) {
                continue;
            }
            if (preg_match('/m?(\d{6}_?\d{6})(\D.*)?$/is', $row['version'], $matches)) {
                $time = str_replace('_', '', $matches[1]);
                $row['canonicalVersion'] = $time;
            } else {
                $row['canonicalVersion'] = $row['version'];
            }
            $row['apply_time'] = (int)$row['apply_time'];
            $history[] = $row;
        }

        usort($history, function ($a, $b) {
            if ($a['apply_time'] === $b['apply_time']) {
                if (($compareResult = strcasecmp($b['canonicalVersion'], $a['canonicalVersion'])) !== 0) {
                    return $compareResult;
                }
                return strcasecmp($b['version'], $a['version']);
            }
            return ($a['apply_time'] > $b['apply_time']) ? 1 : -1;
        });

        return ArrayHelper::map($history, 'version', 'apply_time');
    }

    private $_oldSchema = [];

    /**
     * Analyses gathered changes.
     * @param array $changes
     * @return bool true if more data can be analysed or false if this must be last one
     */
    protected function analyseChanges($changes)
    {
        if (!isset($changes[$this->_tableSubject])) {
            return true;
        }
        $data = array_reverse($changes[$this->_tableSubject]);
        foreach ($data as $methods) {
            if (key($methods) === 'dropTable') {
                return false;
            }
            if (key($methods) === 'renameTable') {
                $this->_tableSubject = current($methods);
                return $this->analyseChanges($changes);
            }
            $this->_oldSchema[] = $methods;
            if (key($methods) === 'createTable') {
                return false;
            }
        }
        return true;
    }

    /**
     * Extracts migration data structures.
     * @param string $migration
     * @return array
     */
    protected function extract($migration)
    {
        require_once(Yii::getAlias($this->migrationPath . DIRECTORY_SEPARATOR . $migration . '.php'));

        $subject = new $migration;
        $subject->db = $this->db;
        $subject->up();

        return $subject->changes;
    }

    private $_structure = [];

    /**
     * Formats the gathered migrations structure.
     */
    protected function formatStructure()
    {
        $changes = array_reverse($this->_oldSchema);
        $this->_structure['columns'] = [];
        $this->_structure['fks'] = [];
        $this->_structure['pk'] = [];
        $this->_structure['uidxs'] = [];
        foreach ($changes as $change) {
            switch (key($change)) {
                case 'createTable':
                case 'addColumn':
                    foreach (current($change) as $column => $properties) {
                        $this->_structure['columns'][$column] = $properties;
                    }
                    break;
                case 'dropColumn':
                    if (isset($this->_structure['columns'][current($change)])) {
                        unset($this->_structure['columns'][current($change)]);
                    }
                    break;
                case 'renameColumn':
                    if (isset($this->_structure['columns'][key(current($change))])) {
                        $this->_structure['columns'][current(current($change))] = $this->_structure['columns'][key(current($change))];
                        unset($this->_structure['columns'][key(current($change))]);
                    }
                    break;
                case 'alterColumn':
                    if (isset($this->_structure['columns'][key(current($change))])) {
                        $this->_structure['columns'][key(current($change))] = current(current($change));
                    }
                    break;
                case 'addPrimaryKey':
                    $this->_structure['pk'] = current($change);
                    break;
                case 'dropPrimaryKey':
                    $this->_structure['pk'] = [];
                    break;
                case 'addForeignKey':
                    $this->_structure['fks'][current($change)[0]] = [current($change)[1], current($change)[2], current($change)[3], current($change)[4], current($change)[5]];
                    break;
                case 'dropForeignKey':
                    if (isset($this->_structure['fks'][current($change)])) {
                        unset($this->_structure['fks'][current($change)]);
                    }
                    break;
                case 'createIndex':
                    $this->_structure['uidxs'][key(current($change))] = current(current($change));
                    break;
                case 'dropIndex':
                    if (isset($this->_structure['uidxs'][current($change)])) {
                        unset($this->_structure['uidxs'][current($change)]);
                    }
                    break;
                case 'addCommentOnColumn':
                    if (isset($this->_structure['columns'][key(current($change))])) {
                        $this->_structure['columns'][key(current($change))]['comment'] = current(current($change));
                    }
                    break;
                case 'dropCommentFromColumn':
                    if (isset($this->_structure['columns'][current($change)])) {
                        $this->_structure['columns'][current($change)]['comment'] = null;
                    }
            }
        }
    }

    /**
     * Returns values as a string.
     * @param mixed $value
     * @return string
     */
    public function displayValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($value === true) {
            return 'TRUE';
        }
        if ($value === false) {
            return 'FALSE';
        }
        return '"' . $value . '"';
    }

    private $_modifications = [];

    /**
     * Compares migration structure and database structure and gather required modifications.
     * @return bool whether modification is required or not
     */
    protected function compareStructures()
    {
        if (empty($this->_oldSchema)) {
            return true;
        }
        $this->formatStructure();
        $different = false;
        if ($this->showOnly) {
            echo "SHOWING DIFFERENCES:\n";
        }
        foreach ($this->structure['columns'] as $column => $data) {
            if (!isset($this->_structure['columns'][$column])) {
                if ($this->showOnly) {
                    echo "   - missing column '$column'\n";
                }
                $this->_modifications['addColumn'][$column] = $data;
                $different = true;
                continue;
            }
            foreach ($data as $property => $value) {
                if ($value !== null && !isset($this->_structure['columns'][$column][$property])) {
                    if ($this->showOnly) {
                        echo "   - missing '$column' column property: $property (";
                        echo 'DB: ' . $this->displayValue($value) . ")\n";
                    }
                    $this->_modifications['alterColumn'][$column][] = $data;
                    $different = true;
                    break;
                } elseif ($this->_structure['columns'][$column][$property] != $value) {
                    if ($this->showOnly) {
                        echo "   - different '$column' column property: $property (";
                        echo 'DB: ' . $this->displayValue($value) . ' <> ';
                        echo 'MIG: ' . $this->displayValue($this->_structure['columns'][$column][$property]) . ")\n";
                    }
                    $this->_modifications['alterColumn'][$column][] = $data;
                    $different = true;
                    break;
                }
            }
        }
        foreach ($this->_structure['columns'] as $column => $data) {
            if (!isset($this->structure['columns'][$column])) {
                if ($this->showOnly) {
                    echo "   - excessive column '$column'\n";
                }
                $this->_modifications['dropColumn'][] = $column;
                $different = true;
            }
        }
        foreach ($this->structure['fks'] as $fk => $data) {
            if (!isset($this->_structure['fks'][$fk])) {
                if ($this->showOnly) {
                    echo "   - missing foreign key '$fk'\n";
                }
                $this->_modifications['addForeignKey'][$fk] = $data;
                $different = true;
                continue;
            }
        }
        foreach ($this->_structure['fks'] as $fk => $data) {
            if (!isset($this->structure['fks'][$fk])) {
                if ($this->showOnly) {
                    echo "   - excessive foreign key '$fk'\n";
                }
                $this->_modifications['dropForeignKey'][] = $fk;
                $different = true;
            }
        }
        foreach ($this->structure['uidxs'] as $uidx => $data) {
            if (!isset($this->_structure['uidxs'][$uidx])) {
                if ($this->showOnly) {
                    echo "   - missing unique index '$uidx'\n";
                }
                $this->_modifications['createIndex'][$uidx] = $data;
                $different = true;
                continue;
            }
        }
        foreach ($this->_structure['uidxs'] as $uidx => $data) {
            if (!isset($this->structure['uidxs'][$uidx])) {
                if ($this->showOnly) {
                    echo "   - excessive unique index '$uidx'\n";
                }
                $this->_modifications['dropIndex'][] = $uidx;
                $different = true;
            }
        }
        return $different;
    }

    /**
     * Checks if new updating migration is required.
     * @return bool
     * @throws InvalidParamException
     */
    public function isUpdateRequired()
    {
        $history = $this->fetchHistory();
        if (!empty($history)) {
            $this->setDummyMigrationClass();
            foreach ($history as $migration => $time) {
                if (!$this->analyseChanges($this->extract($migration))) {
                    break;
                }
            }
            $this->restoreMigrationClass();
            return $this->compareStructures();
        }
        return true;
    }

    /**
     * Returns column definition based on data array.
     * @param array $column
     * @return string
     */
    public function renderColumnStructure($column)
    {
        $definition = '$this';
        $checkNotNull = true;
        $checkUnsigned = true;
        $schema = $this->db->schema;
        $size = $this->renderSizeStructure($column);
        if ($this->generalSchema) {
            $size = '';
        }
        switch ($column['type']) {
            case Schema::TYPE_UPK:
                if ($this->generalSchema) {
                    $checkUnsigned = false;
                    $definition .= '->unsigned()';
                }
                // no break
            case Schema::TYPE_PK:
                if ($this->generalSchema) {
                    if ($schema::className() !== 'yii\db\mssql\Schema') {
                        $checkNotNull = false;
                    }
                }
                $definition .= '->primaryKey(' . $size . ')';
                break;
            case Schema::TYPE_UBIGPK:
                if ($this->generalSchema) {
                    $checkUnsigned = false;
                    $definition .= '->unsigned()';
                }
                // no break
            case Schema::TYPE_BIGPK:
                if ($this->generalSchema) {
                    if ($schema::className() !== 'yii\db\mssql\Schema') {
                        $checkNotNull = false;
                    }
                }
                $definition .= '->bigPrimaryKey(' . $size . ')';
                break;
            case Schema::TYPE_CHAR:
                $definition .= '->char(' . $size . ')';
                break;
            case Schema::TYPE_STRING:
                $definition .= '->string(' . $size . ')';
                break;
            case Schema::TYPE_TEXT:
                $definition .= '->text(' . $size . ')';
                break;
            case Schema::TYPE_SMALLINT:
                $definition .= '->smallInteger(' . $size . ')';
                break;
            case Schema::TYPE_INTEGER:
                if ($this->generalSchema && array_key_exists('append', $column)) {
                    $append = $this->checkPrimaryKeyString($column['append']);
                    if ($append) {
                        $definition .= '->primaryKey()';
                        $column['append'] = !is_string($append) || $append == ' ' ? null : $append;
                    }
                } else {
                    $definition .= '->integer(' . $size . ')';
                }
                break;
            case Schema::TYPE_BIGINT:
                if ($this->generalSchema && array_key_exists('append', $column)) {
                    $append = $this->checkPrimaryKeyString($column['append']);
                    if ($append) {
                        $definition .= '->bigPrimaryKey()';
                        $column['append'] = !is_string($append) || $append == ' ' ? null : $append;
                    }
                } else {
                    $definition .= '->bigInteger(' . $size . ')';
                }
                break;
            case Schema::TYPE_FLOAT:
                $definition .= '->float(' . $size . ')';
                break;
            case Schema::TYPE_DOUBLE:
                $definition .= '->double(' . $size . ')';
                break;
            case Schema::TYPE_DECIMAL:
                $definition .= '->decimal(' . $size . ')';
                break;
            case Schema::TYPE_DATETIME:
                $definition .= '->dateTime(' . $size . ')';
                break;
            case Schema::TYPE_TIMESTAMP:
                $definition .= '->timestamp(' . $size . ')';
                break;
            case Schema::TYPE_TIME:
                $definition .= '->time(' . $size . ')';
                break;
            case Schema::TYPE_DATE:
                $definition .= '->date()';
                break;
            case Schema::TYPE_BINARY:
                $definition .= '->binary(' . $size . ')';
                break;
            case Schema::TYPE_BOOLEAN:
                $definition .= '->boolean()';
                break;
            case Schema::TYPE_MONEY:
                $definition .= '->money(' . $size . ')';
        }
        if ($checkUnsigned && array_key_exists('isUnsigned', $column) && $column['isUnsigned']) {
            $definition .= '->unsigned()';
        }
        if ($checkNotNull && array_key_exists('isNotNull', $column) && $column['isNotNull']) {
            $definition .= '->notNull()';
        }
        if (array_key_exists('default', $column) && $column['default'] !== null) {
            if ($column['default'] instanceof Expression) {
                $definition .= '->defaultExpression(\'' . $column['default']->expression . '\')';
            } else {
                $definition .= '->defaultValue(\'' . str_replace("'", "\'", $column['default']) . '\')';
            }
        }
        if (array_key_exists('comment', $column) && $column['comment']) {
            $definition .= '->comment(\'' . str_replace("'", "\'", $column['comment']) . '\')';
        }
        if (array_key_exists('append', $column) && $column['append']) {
            $definition .= '->append(\'' . $column['append'] . '\')';
        }

        return $definition;
    }

    /**
     * Checks for primary key string based column properties and used schema.
     * @param string $append
     * @return string|bool
     */
    public function checkPrimaryKeyString($append)
    {
        $schema = $this->db->schema;
        $primaryKey = false;
        switch ($schema::className()) {
            case 'yii\db\mssql\Schema':
                if (stripos($append, 'IDENTITY') !== false && stripos($append, 'PRIMARY KEY') !== false) {
                    $append = str_replace(['PRIMARY KEY', 'IDENTITY'], '', strtoupper($append));
                    $primaryKey = true;
                }
                break;
            case 'yii\db\oci\Schema':
            case 'yii\db\pgsql\Schema':
                if (stripos($append, 'PRIMARY KEY') !== false) {
                    $append = str_replace('PRIMARY KEY', '', strtoupper($append));
                    $primaryKey = true;
                }
                break;
            case 'yii\db\sqlite\Schema':
                if (stripos($append, 'PRIMARY KEY') !== false) {
                    $append = str_replace(['PRIMARY KEY', 'AUTOINCREMENT'], '', strtoupper($append));
                    $primaryKey = true;
                }
                break;
            case 'yii\db\cubrid\Schema':
            case 'yii\db\mysql\Schema':
            default:
                if (stripos($append, 'PRIMARY KEY') !== false) {
                    $append = str_replace(['PRIMARY KEY', 'AUTO_INCREMENT'], '', strtoupper($append));
                    $primaryKey = true;
                }
        }
        if ($primaryKey) {
            $append = preg_replace('/\s+/', ' ', $append);
            return $append ?: true;
        }
        return null;
    }

    /**
     * Returns size value from its structure.
     * @param array $column
     * @return mixed
     */
    public function renderSizeStructure($column)
    {
        return $column['length'] ?: null;
    }

    /**
     * Prepares updates definitions.
     * @return array
     */
    public function prepareUpdates()
    {
        $updates = [];
        /* @var $data array */
        foreach ($this->_modifications as $method => $data) {
            switch ($method) {
                case 'dropColumn':
                    foreach ($data as $column) {
                        $updates[] = [$method, implode(', ', [
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                            '\'' . $column . '\'',
                        ])];
                    }
                    break;
                case 'addColumn':
                    foreach ($data as $column => $type) {
                        $updates[] = [$method, implode(', ', [
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                            '\'' . $column . '\'',
                            $this->renderColumnStructure($type),
                        ])];
                    }
                    break;
                case 'alterColumn':
                    /* @var $typesList array */
                    foreach ($data as $column => $typesList) {
                        foreach ($typesList as $type) {
                            $updates[] = [$method, implode(', ', [
                                '\'' . $this->generateTableName($this->tableName) . '\'',
                                '\'' . $column . '\'',
                                $this->renderColumnStructure($type),
                            ])];
                        }
                    }
                    break;
                case 'addForeignKey':
                    foreach ($data as $fk => $params) {
                        $tmp = [
                            '\'' . $fk . '\'',
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                            is_array($params[0]) ? '[' . implode(', ', $params[0]) . ']' : '\'' . $params[0] . '\'',
                            '\'' . $this->generateTableName($params[1]) . '\'',
                            is_array($params[2]) ? '[' . implode(', ', $params[2]) . ']' : '\'' . $params[2] . '\'',
                        ];
                        if ($params[3] !== null || $params[4] !== null) {
                            $tmp[] = $params[3] !== null ? '\'' . $params[3] . '\'' : 'null';
                        }
                        if ($params[4] !== null) {
                            $tmp[] = '\'' . $params[4] . '\'';
                        }
                        $updates[] = [$method, implode(', ', $tmp)];
                    }
                    break;
                case 'dropForeignKey':
                    foreach ($data as $fk) {
                        $updates[] = [$method, implode(', ', [
                            '\'' . $fk . '\'',
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                        ])];
                    }
                    break;
                case 'createIndex':
                    foreach ($data as $uidx => $columns) {
                        $updates[] = [$method, implode(', ', [
                            '\'' . $uidx . '\'',
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                            count($columns) === 1 ? '\'' . $columns[0] . '\'' : '[' . implode(', ', $columns) . ']',
                            'true',
                        ])];
                    }
                    break;
                case 'dropIndex':
                    foreach ($data as $uidx) {
                        $updates[] = [$method, implode(', ', [
                            '\'' . $uidx . '\'',
                            '\'' . $this->generateTableName($this->tableName) . '\'',
                        ])];
                    }
            }
        }
        return $updates;
    }

    /**
     * Generates migration content or echoes exception message.
     * @return string
     * @throws InvalidParamException
     */
    public function generateMigration()
    {
        if (empty($this->_modifications)) {
            return parent::generateMigration();
        }
        $params = [
            'className' => $this->className,
            'methods' => $this->prepareUpdates(),
            'namespace' => !empty($this->namespace) ? FileHelper::normalizePath($this->namespace, '\\') : null
        ];
        return $this->view->renderFile(Yii::getAlias($this->templateFileUpdate), $params);
    }
}
