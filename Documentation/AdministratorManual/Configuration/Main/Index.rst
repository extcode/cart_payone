.. include:: ../../../Includes.txt

Main Configuration
==================

The plugin needs to know the merchant e-mail address.

.. code-block:: typoscript

   plugin.tx_cartpayone {
       sandbox = 1

       merchantId =
       subAccountId =
       portalId =
       key =
       hashAlgorithm =

       request = authorization
   }

|

.. container:: table-row

   Property
         plugin.tx_cartpayone.sandbox
   Data type
         boolean
   Description
         This configuration determines whether the extension is in live or in sandbox mode.
   Default
         The default value is chosen so that the plugin is always in sandbox mode after installation, so that payment can be tested with Payone.

.. container:: table-row

   Property
         plugin.tx_cartpayone.merchantId
   Data type
         string
   Description
         The `Merchant-ID` for your account. You can find it in your account settings.

.. container:: table-row

   Property
         plugin.tx_cartpayone.subAccountId
   Data type
         string
   Description
         The `Sub-Account-ID` for your account. You can find it in your account settings. This will used instead of plugin.tx_cartpayone.merchantId, if given.

.. container:: table-row

   Property
         plugin.tx_cartpayone.portalId
   Data type
         string
   Description
         One `Portal-Id` in your account. You can create several portals with in your account.

.. container:: table-row

   Property
         plugin.tx_cartpayone.key
   Data type
         string
   Description
         The `key` for the portal given in plugin.tx_cartpayone.portalId.

.. container:: table-row

   Property
         plugin.tx_cartpayone.hashAlgorithm
   Data type
         string
   Description
         The hash algorithm for the portal. The algorithms `sha2-384` and `md5` are implemented and possible.
         The `md5` hash algorithm should no longer be used. The selected algorithm must match the value configured in the PMI portal settings.
   Default
         sha2-384


.. container:: table-row

   Property
         plugin.tx_cartpayone.request
   Data type
         string
   Description
         This configuration determines which request type is used. Currently only `authorization` is implemented.
   Default
         authorization
