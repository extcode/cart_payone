<?php

$EM_CONF['cart_payone'] = [
    'title' => 'Cart - Payone',
    'description' => 'Shopping Cart(s) for TYPO3 - Payone Payment Provider',
    'category' => 'services',
    'author' => 'Daniel Gohlke',
    'author_email' => 'ext.cart@extco.de',
    'author_company' => 'extco.de UG (haftungsbeschränkt)',
    'shy' => '',
    'priority' => '',
    'module' => '',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'modify_tables' => '',
    'clearCacheOnLoad' => 0,
    'lockType' => '',
    'version' => '1.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-8.7.99',
            'cart' => '5.3.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
