<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;

/**
 * Adapter for Postgres (not completed yet)
 *
 * @package ActiveRecord
 */
class PgsqlAdapter extends Connection
{
	static $QUOTE_CHARACTER = '"';
	static $DEFAULT_PORT = 5432;
	static $VERSION;

	public function supports_sequences()
	{
		return true;
	}

	public function get_sequence_name($table, $column_name)
	{
		return "{$table}_{$column_name}_seq";
	}

	public function next_sequence_value($sequence_name)
	{
		return "nextval('" . str_replace("'","\\'",$sequence_name) . "')";
	}

	public function limit($sql, $offset, $limit)
	{
		return $sql . ' LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);
	}

	public function get_version()
	{
		if (self::$VERSION === null) {
			$version_stmt = $this->query("SELECT version();");
			$version_stmt->execute();
			$version_string = $version_stmt->fetch()['version'];
			preg_match('/^PostgreSQL ([0-9\.]+)/', $version_string, $matches);
			self::$VERSION = $matches[1];
		}
		return self::$VERSION;
	}

	public function query_column_info($table)
	{

		$default_select = self::get_version() < 12 ?
			'pg_attrdef.adsrc' :
			'pg_get_expr(adbin, adrelid)';

		$sql = <<<SQL
SELECT
      a.attname AS field,
      a.attlen,
      REPLACE(pg_catalog.format_type(a.atttypid, a.atttypmod), 'character varying', 'varchar') AS type,
      a.attnotnull AS not_nullable,
      (SELECT 't'
        FROM pg_index
        WHERE c.oid = pg_index.indrelid
        AND a.attnum = ANY (pg_index.indkey)
        AND pg_index.indisprimary = 't'
      ) IS NOT NULL AS pk,
      REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE((SELECT $default_select
        FROM pg_attrdef
        WHERE c.oid = pg_attrdef.adrelid
        AND pg_attrdef.adnum=a.attnum
      ),'::[a-z_ ]+',''),'''$',''),'^''','') AS default
FROM pg_attribute a, pg_class c, pg_type t
WHERE c.relname = ?
      AND a.attnum > 0
      AND a.attrelid = c.oid
      AND a.atttypid = t.oid
ORDER BY a.attnum
SQL;
		$values = array($table);
		return $this->query($sql,$values);
	}

	public function query_for_tables()
	{
		return $this->query("SELECT tablename FROM pg_tables WHERE schemaname NOT IN('information_schema','pg_catalog')");
	}

	public function create_column(&$column)
	{
		$c = new Column();
		$c->inflected_name	= Inflector::instance()->variablize($column['field']);
		$c->name			= $column['field'];
		$c->nullable		= ($column['not_nullable'] ? false : true);
		$c->pk				= ($column['pk'] ? true : false);
		$c->auto_increment	= false;

		$is_array = false;

		if (substr($column['type'],0,9) == 'timestamp')
		{
			$c->raw_type = 'datetime';
			$c->length = 19;
		}
		elseif ($column['type'] == 'date')
		{
			$c->raw_type = 'date';
			$c->length = 10;
		}
		else
		{
			preg_match('/^([A-Za-z0-9_]+(?:\[\])?)(\(([0-9]+(,[0-9]+)?)\))?/',$column['type'],$matches);

			$raw_type = (count($matches) > 0 ? $matches[1] : $column['type']);
			$c->length = count($matches) >= 4 ? intval($matches[3]) : intval($column['attlen']);

			if ($c->length < 0)
				$c->length = null;

			if (substr($raw_type, -2) == '[]') {
				$raw_type = substr($raw_type, 0, -2);
				$is_array = true;
			}

			$c->raw_type = $raw_type;

		}

		$c->is_array = $is_array;
		$c->map_raw_type();

		if ($column['default'])
		{
			preg_match("/^nextval\('(.*)'\)$/",$column['default'],$matches);
			if (count($matches) == 2) {
				$c->sequence = $matches[1];
			} elseif ($column['type'] == 'boolean') {
				$c->default = $c->cast($column['default'] === 'true' ? true : ($column['default'] === 'false' ? false : $column['default']), $this);
			} else {
				$c->default = $c->cast($column['default'], $this);
			}
		}
		return $c;
	}

	public function set_encoding($charset)
	{
		$this->query("SET NAMES '$charset'");
	}

	public function native_database_types()
	{
		return array(
			'primary_key' => 'serial primary key',
			'string' => array('name' => 'character varying', 'length' => 255),
			'text' => array('name' => 'text'),
			'integer' => array('name' => 'integer'),
			'float' => array('name' => 'float'),
			'datetime' => array('name' => 'datetime'),
			'timestamp' => array('name' => 'timestamp'),
			'time' => array('name' => 'time'),
			'date' => array('name' => 'date'),
			'binary' => array('name' => 'binary'),
			'boolean' => array('name' => 'boolean')
		);
	}

	public function array_to_database_string(array $value)
	{
		return '{' . $this->str_putcsv($value) . '}';
	}

	public function database_string_to_array(string $value)
	{
		preg_match('/^{(.*)}$/', $value, $matches);
		if ($matches && !strlen($matches[1])) {
			return [];
		}
		return $matches ? str_getcsv($matches[1]) : [$value];
	}

	private function str_putcsv(array $input, $delimiter = ',', $enclosure = '"')
	{
		$h = fopen('php://temp', 'r+b');
		fputcsv($h, $input, $delimiter, $enclosure);
		rewind($h);
		$data = rtrim(stream_get_contents($h), "\n");
		fclose($h);
		return $data;
	}

}
