# use BootPress\Hierarchy\Component as Hierarchy;

[![Packagist][badge-version]][link-packagist]
[![License MIT][badge-license]](LICENSE.md)
[![HHVM Tested][badge-hhvm]][link-travis]
[![PHP 7 Supported][badge-php]][link-travis]
[![Build Status][badge-travis]][link-travis]
[![Code Climate][badge-code-climate]][link-code-climate]
[![Test Coverage][badge-coverage]][link-coverage]

The BootPress Hierarchy Component bridges the gap between Adjacency lists, and the [Nested Set Model](http://mikehillyer.com/articles/managing-hierarchical-data-in-mysql/) so that you can have both the simplicity of Adjacency lists, with the power and efficiency of Nested sets.

## Installation

Add the following to your ``composer.json`` file.

``` bash
{
    "require": {
        "bootpress/hierarchy": "^1.0"
    }
}
```

## Example Usage

``` php
<?php

use BootPress\Database\Component as Database;
use BootPress\Hierarchy\Component as Hierarchy;

$db = new Database('sqlite::memory:');

$db->exec(array(
    'CREATE TABLE category (',
    '    id INTEGER PRIMARY KEY,',
    '    name TEXT NOT NULL DEFAULT "",',
    '    parent INTEGER NOT NULL DEFAULT 0,',
    '    level INTEGER NOT NULL DEFAULT 0,',
    '    lft INTEGER NOT NULL DEFAULT 0,',
    '    rgt INTEGER NOT NULL DEFAULT 0',
    ')',
));

if ($stmt = $db->insert('category', array('id', 'name', 'parent'))) {
    $db->insert($stmt, array(1, 'Electronics', 0));
    $db->insert($stmt, array(2, 'Televisions', 1));
    $db->insert($stmt, array(3, 'Tube', 2));
    $db->insert($stmt, array(4, 'LCD', 2));
    $db->insert($stmt, array(5, 'Plasma', 2));
    $db->insert($stmt, array(6, 'Portable Electronics', 1));
    $db->insert($stmt, array(7, 'MP3 Players', 6));
    $db->insert($stmt, array(8, 'Flash', 7));
    $db->insert($stmt, array(9, 'CD Players', 6));
    $db->insert($stmt, array(10, '2 Way Radios', 6));
    $db->insert($stmt, array(11, 'Apple in California', 1));
    $db->insert($stmt, array(12, 'Made in USA', 11));
    $db->insert($stmt, array(13, 'Assembled in China', 11));
    $db->insert($stmt, array(14, 'iPad', 13));
    $db->insert($stmt, array(15, 'iPhone', 13));
    $db->close($stmt);
}

$hier = new Hierarchy($db, 'category', 'id');
```

The $db table must have the 'parent', 'level', 'lft', and 'rgt' fields for everything to work.  All you need to worry about is the 'id' and 'parent'.  This class will take care of the rest.  When you get things all set up, and whenever you make any changes:

```php
$hier->refresh();
```

That will create and update the nested sets info from your 'id' and 'parent' fields.  To delete a node and all of it's children you can:

```php
print_r($hier->delete(11)); // array(11, 12, 13, 14, 15)
$hier->refresh(); // don't forget to do this!
```

You just removed "Apple in California", and everything associated with them.

```php
// Get the id of a given path
echo $hier->id('name', array('Electronics', 'Portable Electronics', 'CD Players')); // 9

// Retrieve a single path
print_r($hier->path('name', 'Flash'));
/*
array(
    1 => 'Electronics',
    6 => 'Portable Electronics',
    7 => 'MP3 Players',
    8 => 'Flash',
)
*/

// Aggregate the total records in a table for each tree node
$db->exec(array(
    'CREATE TABLE products (',
    '    id INTEGER PRIMARY KEY,',
    '    category_id INTEGER NOT NULL DEFAULT 0,',
    '    name TEXT NOT NULL DEFAULT ""',
    ')',
));

if ($stmt = $db->insert('products', array('category_id', 'name'))) {
    $db->insert($stmt, array(3, '20" TV'));
    $db->insert($stmt, array(3, '36" TV'));
    $db->insert($stmt, array(4, 'Super-LCD 42"'));
    $db->insert($stmt, array(5, 'Ultra-Plasma 62"'));
    $db->insert($stmt, array(5, 'Value Plasma 38"'));
    $db->insert($stmt, array(7, 'Power-MP3 128mb'));
    $db->insert($stmt, array(8, 'Super-Shuffle 1gb'));
    $db->insert($stmt, array(9, 'Porta CD'));
    $db->insert($stmt, array(9, 'CD To go!'));
    $db->insert($stmt, array(10, 'Family Talk 360'));
    $db->close($stmt);
}

print_r($hier->counts('products', 'category_id'));
/*
array( // id => count
    1 => 10, // Electronics
    2 => 5, // Televisions
    3 => 2, // Tube
    4 => 1, // LCD
    5 => 2, // Plasma
    6 => 5, // Portable Electronics
    7 => 2, // MP3 Players
    8 => 1, // Flash
    9 => 2, // CD Players
    10 => 1 // 2 Way Radios
)
*/

// Retrieve a tree
$tree = $hier->tree('name', 'id', 6);
print_r($tree);
/*
array(
    6 => array('name' => 'Portable Electronics', 'parent' => 1, 'depth' => 0),
    7 => array('name' => 'MP3 Players', 'parent' => 6, 'depth' => 1),
    8 => array('name' => 'Flash', 'parent' => 7, 'depth' => 2),
    9 => array('name' => 'CD Players', 'parent' => 6, 'depth' => 1),
    10 => array('name' => '2 Way Radios', 'parent' => 6, 'depth' => 1),
)
*/

// Flatten it
$nest = $hier->nestify($tree);
var_export($hier->flatten($nest));
array(
    array(6, 7, 8),
    array(6, 9),
    array(6, 10),
)
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[badge-version]: https://img.shields.io/packagist/v/bootpress/hierarchy.svg?style=flat-square&label=Packagist
[badge-license]: https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square
[badge-hhvm]: https://img.shields.io/badge/HHVM-Tested-8892bf.svg?style=flat-square
[badge-php]: https://img.shields.io/badge/PHP%207-Supported-8892bf.svg?style=flat-square
[badge-travis]: https://img.shields.io/travis/Kylob/Hierarchy/master.svg?style=flat-square
[badge-code-climate]: https://img.shields.io/codeclimate/github/Kylob/Hierarchy.svg?style=flat-square
[badge-coverage]: https://img.shields.io/codeclimate/coverage/github/Kylob/Hierarchy.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/bootpress/hierarchy
[link-travis]: https://travis-ci.org/Kylob/Hierarchy
[link-code-climate]: https://codeclimate.com/github/Kylob/Hierarchy
[link-coverage]: https://codeclimate.com/github/Kylob/Hierarchy/coverage
