<?php

defined('TYPO3') or die();

// configure plugins

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'CartPayone',
    'Cart',
    [
        \Extcode\CartPayone\Controller\Order\PaymentController::class => 'success, cancel',
    ],
    [
        \Extcode\CartPayone\Controller\Order\PaymentController::class => 'success, cancel',
    ]
);
