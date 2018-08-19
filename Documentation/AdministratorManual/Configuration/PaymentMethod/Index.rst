.. include:: ../../../Includes.txt

Payment Method Configuration
============================

The payment method for Payone is configured like any other payment method. There are all configuration options
from Cart available.

.. code-block:: typoscript

   plugin.tx_cart {
       payments {
           ...
               options {
                   2 {
                       provider = PAYONE_CC
                       title = Payone - Credit Card
                       extra = 0.00
                       taxClassId = 1
                       status = open
                       available.from = 0.01
                   }
                   3 {
                       provider = PAYONE_SB
                       title = Payone - Online Bank Transfer
                       extra = 0.00
                       taxClassId = 1
                       status = open
                       available.from = 0.01
                   }
               }
           ...
       }
   }

|

.. container:: table-row

   Property
      plugin.tx_cart.payments....options.n.provider
   Data type
      string
   Description
      Defines that the payment provider for Payone should be used.
      This information is mandatory and ensures that the extension Cart Payone takes control and for the authorization of payment the user forwards to the Payone site.

      Possible Options are:
      * PAYONE_CC: Credit card
      * PAYONE_COD: Cash on delivery
      * PAYONE_ELV: Debit payment
      * PAYONE_FNC: Financing
      * PAYONE_REC: Invoice
      * PAYONE_SB: Online Bank Transfer
      * PAYONE_VOR: Prepayment
      * PAYONE_WLT_ALP: e-wallet Alipay
      * PAYONE_WLT_PDT: e-wallet paydirect
      * PAYONE_WLT_PPE: e-wallet PayPal

.. NOTE::

   For more information and examples on how to configure payment methods, please refer to the
   `Payment Method section <https://docs.typo3.org/typo3cms/extensions/cart/6.5.0/AdministratorManual/Configuration/PaymentMethods/Index.html>`_
   in the cart documentation.