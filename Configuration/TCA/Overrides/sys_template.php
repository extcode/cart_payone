<?php
defined('TYPO3') or die();

call_user_func(function () {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
        'cart_payone',
        'Configuration/TypoScript',
        'Shopping Cart - Payone'
    );
});
