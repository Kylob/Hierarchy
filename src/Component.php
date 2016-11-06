<?php

namespace BootPress\Hierarchy;

use BootPress\Database\Component as Database;

class Component
{
    /** @var object A BootPress\Database\Component instance. */
    private $db;

    /** @var string The database table's id column. */
    private $id;

    /** @var string The name of the database's hierarchical table. */
    private $table;

    /** @var int Used when ``$this->traverse()``ing paths to update database. */
    private $count;

    /** @var array Used when ``$this->traverse()``ing paths to update database. */
    private $parents = array();

    /** @var array Used when ``$this->traverse()``ing paths to update database. */
    private $update = array();

    /**
     * The $db $table must have the following fields:.
     *
     * - $id => 'INTEGER PRIMARY KEY'
     * - 'parent' => 'INTEGER NOT NULL DEFAULT 0'
     * - 'level' => 'INTEGER NOT NULL DEFAULT 0'
     * - 'lft' => 'INTEGER NOT NULL DEFAULT 0'
     * - 'rgt' => 'INTEGER NOT NULL DEFAULT 0'
     *
     * All you need to worry about is the $id and 'parent'.  This class will take care of the rest.
     * 
     * @param object $db    A BootPress\Database\Component instance.
     * @param string $table The name of the database's hierarchical table.
     * @param string $id    The database table's id column.
     *
     * ```php
     * use BootPress\Database\Component as Database;
     * use BootPress\Hierarchy\Component as Hierarchy;
     *
     * $db = new Database('sqlite::memory:');
     * $db->exec(array(
     *     'CREATE TABLE category (',
     *     '    id INTEGER PRIMARY KEY,',
     *     '    name TEXT NOT NULL DEFAULT "",',
     *     '    parent INTEGER NOT NULL DEFAULT 0,',
     *     '    level INTEGER NOT NULL DEFAULT 0,',
     *     '    lft INTEGER NOT NULL DEFAULT 0,',
     *     '    rgt INTEGER NOT NULL DEFAULT 0',
     *     ')',
     * ));
     * if ($stmt = $db->insert('category', array('id', 'name', 'parent'))) {
     *     $db->insert($stmt, array(1, 'Electronics', 0));
     *     $db->insert($stmt, array(2, 'Televisions', 1));
     *     $db->insert($stmt, array(3, 'Tube', 2));
     *     $db->insert($stmt, array(4, 'LCD', 2));
     *     $db->insert($stmt, array(5, 'Plasma', 2));
     *     $db->insert($stmt, array(6, 'Portable Electronics', 1));
     *     $db->insert($stmt, array(7, 'MP3 Players', 6));
     *     $db->insert($stmt, array(8, 'Flash', 7));
     *     $db->insert($stmt, array(9, 'CD Players', 6));
     *     $db->insert($stmt, array(10, '2 Way Radios', 6));
     *     $db->insert($stmt, array(11, 'Apple in California', 1));
     *     $db->insert($stmt, array(12, 'Made in USA', 11));
     *     $db->insert($stmt, array(13, 'Assembled in China', 11));
     *     $db->insert($stmt, array(14, 'iPad', 13));
     *     $db->insert($stmt, array(15, 'iPhone', 13));
     *     $db->close($stmt);
     * }
     * $hier = new Hierarchy($db, 'category', 'id');
     * ```
     *
     * @link http://mikehillyer.com/articles/managing-hierarchical-data-in-mysql/
     */
    public function __construct(Database $db, $table, $id = 'id')
    {
        $this->db = $db;
        $this->id = $id;
        $this->table = $table;
    }

    /**
     * Refreshes the database table's 'level', 'lft', and 'rgt' columns.  This should be called any time you insert into or delete from your hierarchical table.
     * 
     * @param string $order The table's field name you would like to base the order on.  The default is to use ``$this->id``.
     *
     * ```php
     * $hier->refresh();
     * ```
     */
    public function refresh($order = null)
    {
        if (is_null($order)) {
            $order = $this->id;
        }
        $this->count = 0;
        $this->parents = array();
        $this->update = array();
        if ($stmt = $this->db->query('SELECT '.$this->id.', parent FROM '.$this->table.' ORDER BY '.$order)) {
            while (list($id, $parent) = $this->db->fetch($stmt)) {
                if (!isset($this->parents[$parent])) {
                    $this->parents[$parent] = array();
                }
                $this->parents[$parent][] = $id;
            }
            $this->db->close($stmt);
        }
        $this->traverse(0);
        unset($this->update[0]);
        ksort($this->update);
        if ($stmt = $this->db->update($this->table, $this->id, array('level', 'lft', 'rgt'))) {
            foreach ($this->update as $id => $fields) {
                $this->db->update($stmt, $id, $fields);
                unset($this->update[$id]['level']);
            }
            $this->db->close($stmt);
        }
    }

    /**
     * Delete a node and all of it's children from your hierarchical table.
     * 
     * @param int $id ``$this->id`` of the node.
     * 
     * @return bool|array False if nothing was affected, or an array of deleted ids.
     *
     * ```php
     * print_r($hier->delete(11)); // array(11, 12, 13, 14, 15)
     * $hier->refresh(); // don't forget to do this!
     * ```
     */
    public function delete($id)
    {
        if ($ids = $this->db->ids(array(
            'SELECT node.id',
            'FROM '.$this->table.' AS node, '.$this->table.' AS parent',
            'WHERE node.lft BETWEEN parent.lft AND parent.rgt AND parent.'.$this->id.' = ?',
            'ORDER BY node.lft',
        ), $id)) {
            $this->db->exec('DELETE FROM '.$this->table.' WHERE '.$this->id.' IN('.implode(', ', $ids).')');

            return $ids;
        }

        return false;
    }

    /**
     * Get the id of a given path.
     * 
     * @param string $field  ``$this->table``'s column name.
     * @param array  $values The ``$field`` values to drill down.
     * 
     * @return bool|int False if no result found, or an id.
     *
     * ```php
     * echo $hier->id('name', array('Electronics')); // 1
     * echo $hier->id('name', array('Electronics', 'Portable Electronics', 'CD Players')); // 9
     * echo $hier->id('name', array('Electronics', 'Apple in California')); // false
     * ```
     */
    public function id($field, array $values)
    {
        $from = array();
        $where = array();
        foreach (range(1, count($values)) as $level => $num) {
            $from[] = (isset($previous)) ? "{$this->table} AS t{$num} ON t{$num}.parent = t{$previous}.{$this->id}" : "{$this->table} AS t{$num}";
            $where[] = "t{$num}.level = {$level} AND t{$num}.{$field} = ?";
            $previous = $num;
        }

        return $this->db->value("SELECT t{$num}.{$this->id} \nFROM ".implode("\n  LEFT JOIN ", $from)."\nWHERE ".implode("\n  AND ", $where), $values);
    }

    /**
     * Retrieve a single path.
     *
     * @param string       $field  ``$this->table``'s column name.
     * @param string       $value  The ``$field``'s value.
     * @param string|array $column The column(s) that you want to return.  The default is the $field you specify.
     * 
     * @return array An array of id (keys) and column (values) in order.
     *
     * ```php
     * var_export($hier->path('name', 'Flash'));
     * array(
     *     1 => 'Electronics',
     *     6 => 'Portable Electronics',
     *     7 => 'MP3 Players',
     *     8 => 'Flash',
     * )
     *
     * var_export($hier->path('id', 9, array('level', 'name', 'parent')));
     * array(
     *     1 => array('level' => 0, 'name' => 'Electronics', 'parent' => 0),
     *     6 => array('level' => 1, 'name' => 'Portable Electronics', 'parent' => 1),
     *     9 => array('level' => 2, 'name' => 'CD Players', 'parent' => 6),
     * )
     * ```
     */
    public function path($field, $value, $column = null)
    {
        if (is_null($column)) {
            $column = $field;
        }
        $single = (!is_array($column)) ? $column : false;
        $path = array();
        if ($stmt = $this->db->query(array(
            'SELECT parent.'.$this->id.', '.$this->fields('parent', (array) $column),
            'FROM '.$this->table.' AS node',
            'INNER JOIN '.$this->table.' AS parent',
            'WHERE node.lft BETWEEN parent.lft AND parent.rgt AND node.'.$field.' = ?',
            'ORDER BY node.lft',
        ), $value, 'assoc')) {
            while ($row = $this->db->fetch($stmt)) {
                $path[array_shift($row)] = ($single) ? $row[$single] : $row;
            }
            $this->db->close($stmt);
        }

        return $path;
    }

    /**
     * Find the immediate subordinates of a node ie. no grand children.
     * 
     * @param int          $id     ``$this->id`` of the node.
     * @param string|array $column The column(s) that you want to return.
     * 
     * @return array An array of id (keys) and column (values) in order.
     *
     * ```php
     * var_export($hier->children(6, 'name'));
     * array(
     *     7 => 'MP3 Players',
     *     9 => 'CD Players',
     *     10 => '2 Way Radios',
     * )
     * 
     * var_export($hier->children(6, array('level', 'name')));
     * array(
     *     7 => array('level' => 2, 'name' => 'MP3 Players'),
     *     9 => array('level' => 2, 'name' => 'CD Players'),
     *     10 => array('level' => 2, 'name' => '2 Way Radios'),
     * )
     * ```
     */
    public function children($id, $column) // The immediate subordinates of a node ie. no grand children
    {
        $single = (!is_array($column)) ? $column : false;
        $children = array();
        if ($stmt = $this->db->query('SELECT '.$this->id.', '.implode(', ', (array) $column).' FROM '.$this->table.' WHERE parent = ? ORDER BY lft', $id, 'assoc')) {
            while ($row = $this->db->fetch($stmt)) {
                $children[array_shift($row)] = ($single) ? $row[$single] : $row;
            }
            $this->db->close($stmt);
        }

        return $children;
    }

    /**
     * Find all the nodes at a given level.
     * 
     * @param int          $depth  The level you want, starting at 0.
     * @param string|array $column A single column string, or an array of columns that you want to return.
     * 
     * @return array An array of id (keys) and column (values) in order.
     *
     * ```php
     * var_export($hier->level(2, array('parent', 'name')));
     * array(
     *     3 => array('parent' => 2, 'name' => 'Tube'),
     *     4 => array('parent' => 2, 'name' => 'LCD'),
     *     5 => array('parent' => 2, 'name' => 'Plasma'),
     *     7 => array('parent' => 6, 'name' => 'MP3 Players'),
     *     9 => array('parent' => 6, 'name' => 'CD Players'),
     *     10 => array('parent' => 6, 'name' => '2 Way Radios'),
     * )
     * ```
     */
    public function level($depth, $column)
    {
        $single = (!is_array($column)) ? $column : false;
        $level = array();
        if ($stmt = $this->db->query('SELECT '.$this->id.', '.implode(', ', (array) $column).' FROM '.$this->table.' WHERE level = ? ORDER BY lft', $depth, 'assoc')) {
            while ($row = $this->db->fetch($stmt)) {
                $level[array_shift($row)] = ($single) ? $row[$single] : $row;
            }
            $this->db->close($stmt);
        }

        return $level;
    }

    /**
     * Aggregate the total records in a table for each tree node.
     * 
     * @param string $table The database table to aggregate the records from.
     * @param string $match The $table column that corresponds with ``$this->id``.
     * @param int    $id    A specific node you may be looking for.
     * 
     * @return int|array The total count(s).
     *
     * ```php
     * $db->exec(array(
     *     'CREATE TABLE products (',
     *     '    id INTEGER PRIMARY KEY,',
     *     '    category_id INTEGER NOT NULL DEFAULT 0,',
     *     '    name TEXT NOT NULL DEFAULT ""',
     *     ')',
     * ));
     *
     * if ($stmt = $db->insert('products', array('category_id', 'name'))) {
     *     $db->insert($stmt, array(3, '20" TV'));
     *     $db->insert($stmt, array(3, '36" TV'));
     *     $db->insert($stmt, array(4, 'Super-LCD 42"'));
     *     $db->insert($stmt, array(5, 'Ultra-Plasma 62"'));
     *     $db->insert($stmt, array(5, 'Value Plasma 38"'));
     *     $db->insert($stmt, array(7, 'Power-MP3 128mb'));
     *     $db->insert($stmt, array(8, 'Super-Shuffle 1gb'));
     *     $db->insert($stmt, array(9, 'Porta CD'));
     *     $db->insert($stmt, array(9, 'CD To go!'));
     *     $db->insert($stmt, array(10, 'Family Talk 360'));
     *     $db->close($stmt);
     * }
     *
     * echo $hier->counts('products', 'category_id', 2); // 5 (Televisions - category_id's 3, 4, and 5)
     *
     * var_export($hier->counts('products', 'category_id'));
     * array( // id => count
     *     1 => 10, // Electronics
     *     2 => 5, // Televisions
     *     3 => 2, // Tube
     *     4 => 1, // LCD
     *     5 => 2, // Plasma
     *     6 => 5, // Portable Electronics
     *     7 => 2, // MP3 Players
     *     8 => 1, // Flash
     *     9 => 2, // CD Players
     *     10 => 1 // 2 Way Radios
     * )
     * ```
     */
    public function counts($table, $match, $id = null)
    {
        if (!is_null($id)) {
            return (int) $this->db->value(array(
                'SELECT COUNT('.$table.'.'.$match.') AS count',
                'FROM '.$this->table.' AS node',
                'INNER JOIN '.$this->table.' AS parent',
                'INNER JOIN '.$table,
                'WHERE parent.'.$this->id.' = '.(int) $id.' AND node.lft BETWEEN parent.lft AND parent.rgt AND node.'.$this->id.' = '.$table.'.'.$match,
                'GROUP BY parent.'.$this->id,
                'ORDER BY parent.lft',
            ));
        } else {
            $counts = array();
            if ($stmt = $this->db->query(array(
                'SELECT parent.'.$this->id.', COUNT('.$table.'.'.$match.') AS count',
                'FROM '.$this->table.' AS node',
                'INNER JOIN '.$this->table.' AS parent',
                'INNER JOIN '.$table,
                'WHERE node.lft BETWEEN parent.lft AND parent.rgt AND node.'.$this->id.' = '.$table.'.'.$match,
                'GROUP BY parent.'.$this->id,
                'ORDER BY parent.lft',
            ))) {
                while (list($id, $count) = $this->db->fetch($stmt)) {
                    $counts[$id] = (int) $count;
                }
                $this->db->close($stmt);
            }

            return $counts;
        }
    }

    /**
     * Retrieve a full tree, or any parts thereof.
     * 
     * @param string|array $column The column(s) that you want to return.
     * @param string       $field  ``$this->table``'s column name, or the $having depth below if not specifying a $value.
     * @param string       $value  The ``$field``'s value.  The depths will be relative to this now.
     * @param string       $having The desired depth of the nodes eg. 'depth > 1'
     *
     * @return array An array of id (keys) and column (values) including 'parent' and 'depth' info.
     *
     * ```php
     * var_export($hier->tree('name'));
     * array(
     *     1 => array('name' => 'Electronics', 'parent' => 0, 'depth' => 0),
     *     2 => array('name' => 'Televisions', 'parent' => 1, 'depth' => 1),
     *     3 => array('name' => 'Tube', 'parent' => 2, 'depth' => 2),
     *     4 => array('name' => 'LCD', 'parent' => 2, 'depth' => 2),
     *     5 => array('name' => 'Plasma', 'parent' => 2, 'depth' => 2),
     *     6 => array('name' => 'Portable Electronics', 'parent' => 1, 'depth' => 1),
     *     7 => array('name' => 'MP3 Players', 'parent' => 6, 'depth' => 2),
     *     8 => array('name' => 'Flash', 'parent' => 7, 'depth' => 3),
     *     9 => array('name' => 'CD Players', 'parent' => 6, 'depth' => 2),
     *     10 => array('name' => '2 Way Radios', 'parent' => 6, 'depth' => 2),
     * )
     * 
     * var_export($hier->tree('name', 'id', 6));
     * array(
     *     6 => array('name' => 'Portable Electronics', 'parent' => 1, 'depth' => 0),
     *     7 => array('name' => 'MP3 Players', 'parent' => 6, 'depth' => 1),
     *     8 => array('name' => 'Flash', 'parent' => 7, 'depth' => 2),
     *     9 => array('name' => 'CD Players', 'parent' => 6, 'depth' => 1),
     *     10 => array('name' => '2 Way Radios', 'parent' => 6, 'depth' => 1),
     * )
     * 
     * var_export($hier->tree('name', 'depth > 2',));
     * array(
     *     8 => array('name' => 'Flash', 'parent' => 7, 'depth' => 3),
     * )
     * ```
     */
    public function tree($column, $field = null, $value = null, $having = null)
    {
        $tree = array();
        if (func_num_args() >= 3) {
            $depth = (is_string($having) && strpos($having, 'depth') !== false) ? ' HAVING '.$having : null;
            $stmt = $this->db->query(array(
                'SELECT node.'.$this->id.', '.$this->fields('node', (array) $column).', node.parent, (COUNT(parent.'.$this->id.') - (sub_tree.sub_depth + 1)) AS depth',
                'FROM '.$this->table.' AS node',
                'INNER JOIN '.$this->table.' AS parent',
                'INNER JOIN '.$this->table.' AS sub_parent',
                'INNER JOIN (',
                '    SELECT node.'.$this->id.', (COUNT(parent.'.$this->id.') - 1) AS sub_depth',
                '    FROM '.$this->table.' AS node',
                '    INNER JOIN '.$this->table.' AS parent',
                '    WHERE node.lft BETWEEN parent.lft AND parent.rgt',
                '    AND node.'.$field.' = ?',
                '    GROUP BY node.'.$this->id,
                '    ORDER BY node.lft',
                ') AS sub_tree',
                'WHERE node.lft BETWEEN parent.lft AND parent.rgt',
                '    AND node.lft BETWEEN sub_parent.lft AND sub_parent.rgt',
                '    AND sub_parent.'.$this->id.' = sub_tree.'.$this->id,
                'GROUP BY node.'.$this->id.$depth,
                'ORDER BY node.lft',
            ), $value, 'assoc');
        } else {
            $depth = (is_string($field) && strpos($field, 'depth') !== false) ? ' HAVING '.$field : null;
            $stmt = $this->db->query(array(
                'SELECT node.'.$this->id.', '.$this->fields('node', (array) $column).', node.parent, (COUNT(parent.'.$this->id.') - 1) AS depth',
                'FROM '.$this->table.' AS node',
                'INNER JOIN '.$this->table.' AS parent',
                'WHERE node.lft BETWEEN parent.lft AND parent.rgt',
                'GROUP BY node.'.$this->id.$depth,
                'ORDER BY node.lft',
            ), '', 'assoc');
        }
        while ($row = $this->db->fetch($stmt)) {
            $tree[array_shift($row)] = $row;
        }
        $this->db->close($stmt);

        return $tree;
    }

    /**
     * Create a multi-dimensional array using the first value of each tree array.
     * 
     * @param array $tree As retrieved from ``$this->tree()``.
     * 
     * @return array
     *
     * ```php
     * $tree = $hier->tree('name', 'id', 6);
     * var_export($hier->lister($tree));
     * array(
     *     'Portable Electronics' => array(
     *         'MP3 Players' => array(
     *             'Flash',
     *         ),
     *         'CD Players',
     *         '2 Way Radios',
     *     ),
     * )
     * ```
     */
    public function lister(array $tree, array $nest = null)
    {
        if (is_null($nest)) {
            return $this->lister($tree, $this->nestify($tree));
        }
        $list = array();
        foreach ($nest as $id => $values) {
            if (!empty($values)) {
                $list[array_shift($tree[$id])] = $this->lister($tree, $values);
            } else {
                $list[] = array_shift($tree[$id]);
            }
        }

        return $list;
    }

    /**
     * Create a multi-dimensional array using ``$this->id``'s id of each tree array.
     * 
     * @param array $tree As retrieved from ``$this->tree()``.
     * 
     * @return array
     *
     * ```php
     * $tree = $hier->tree('name', 'id', 6);
     * var_export($hier->nestify($tree));
     * array(
     *     6 => array(
     *         7 => array(
     *             8 => array(),
     *         ),
     *         9 => array(),
     *         10 => array(),
     *     ),
     * )
     * ```
     */
    public function nestify(array $tree)
    {
        $nested = array();
        $children = array();
        foreach ($tree as $id => $fields) {
            if ($fields['depth'] == 0) {
                $nested[$id] = array(); // $fields;
                $children[$fields['depth'] + 1] = $id;
            } else {
                $parent = &$nested;
                for ($i = 1; $i <= $fields['depth']; ++$i) {
                    if (isset($children[$i])) {
                        $parent = &$parent[$children[$i]];
                    }
                }
                $parent[$id] = array(); // $fields;
                $children[$fields['depth'] + 1] = $id;
            }
        }

        return $nested;
    }

    /**
     * Flatten a nested tree.
     * 
     * @param array $nest As retrieved from ``$this->nestify()``.
     * 
     * @return array
     *
     * ```php
     * $tree = $hier->tree('name', 'id', 6);
     * $nest = $hier->nestify($tree);
     * var_export($hier->flatten($nest));
     * array(
     *     array(6, 7, 8),
     *     array(6, 9),
     *     array(6, 10),
     * )
     * ```
     */
    public function flatten(array $nest, array $related = array())
    {
        $children = array();
        foreach ($nest as $id => $values) {
            $parents = $related;
            $parents[] = $id;
            if (!empty($values)) {
                foreach ($this->flatten($values, $parents) as $nest) {
                    $children[] = $nest;
                }
            } else {
                $children[] = $parents;
            }
        }

        return $children;
    }

    /**
     * Used to refresh the database.
     */
    private function traverse($id, $level = -1)
    {
        $lft = $this->count;
        ++$this->count;
        if (isset($this->parents[$id])) {
            foreach ($this->parents[$id] as $child) {
                $this->traverse($child, $level + 1);
            }
            unset($this->parents[$id]);
        }
        $rgt = $this->count;
        ++$this->count;
        $this->update[$id] = array('level' => $level, 'lft' => $lft, 'rgt' => $rgt);
    }

    /**
     * Add column names to SQL queries.
     */
    private function fields($prefix, array $fields)
    {
        return $prefix.'.'.implode(', '.$prefix.'.', $fields);
    }
}
