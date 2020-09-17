<?php

namespace Extcode\CartPayone\Utility;

use Extcode\Cart\Domain\Repository\CartRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class PaymentUtility
{
    const PAYMENT_API_URL = 'https://frontend.pay1.de/frontend/v2/';

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var array
     */
    protected $conf = [];

    /**
     * @var array
     */
    protected $cartConf = [];

    /**
     * Payment Query Url
     *
     * @var string
     */
    protected $paymentQueryUrl = self::PAYMENT_API_URL;

    /**
     * Payment Query
     *
     * @var array
     */
    protected $paymentQuery = [];

    /**
     * Order Item
     *
     * @var \Extcode\Cart\Domain\Model\Order\Item
     */
    protected $orderItem = null;

    /**
     * Cart
     *
     * @var \Extcode\Cart\Domain\Model\Cart\Cart
     */
    protected $cart = null;

    /**
     * CartFHash
     *
     * @var string
     */
    protected $cartFHash = '';

    /**
     * CartSHash
     *
     * @var string
     */
    protected $cartSHash = '';

    /**
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager
     */
    public function injectPersistenceManager(
        \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager
    ) {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * Intitialize
     */
    public function __construct()
    {
        $this->objectManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Extbase\Object\ObjectManager::class
        );

        $this->configurationManager = $this->objectManager->get(
            \TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class
        );

        $this->conf = $this->configurationManager->getConfiguration(
            \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'CartPayone'
        );

        $this->cartConf = $this->configurationManager->getConfiguration(
            \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function handlePayment(array $params): array
    {
        $this->orderItem = $params['orderItem'];

        $provider = $this->orderItem->getPayment()->getProvider();
        list($provider, $clearingType, $walletType) = explode('_', $provider);

        if ($provider === 'PAYONE') {
            $params['providerUsed'] = true;

            $this->cart = $params['cart'];

            $this->paymentQuery['amount'] = round($this->orderItem->getTotalGross() * 100);
            $feUser = $this->orderItem->getFeUser();
            if ($feUser) {
                $this->paymentQuery['customerid'] = $feUser->getUid();
            }

            $this->paymentQuery['clearingtype'] = '';
            if (in_array($clearingType, ['ELV', 'CC', 'REC', 'COD', 'VOR', 'SB', 'FCN'])) {
                $this->paymentQuery['clearingtype'] = strtolower($clearingType);
            } elseif ($clearingType === 'WLT' && in_array($walletType, ['ALP', 'PDT', 'PPE'])) {
                $this->paymentQuery['clearingtype'] = strtolower($clearingType);
                $this->paymentQuery['wallettype'] = $walletType;
            }

            $cart = $this->objectManager->get(
                \Extcode\Cart\Domain\Model\Cart::class
            );
            $cart->setOrderItem($this->orderItem);
            $cart->setCart($this->cart);
            $cart->setPid($this->cartConf['settings']['order']['pid']);

            $cartRepository = $this->objectManager->get(
                \Extcode\Cart\Domain\Repository\CartRepository::class
            );
            $cartRepository->add($cart);
            $this->persistenceManager->persistAll();

            $this->cartFHash = $cart->getFHash();
            $this->cartSHash = $cart->getSHash();

            header('Location: ' . self::PAYMENT_API_URL . '?' . $this->getQuery());
        }

        return [$params];
    }

    /**
     * Builds the query for pay one
     *
     * @return string
     */
    protected function getQuery()
    {
        $this->getQueryFromSettings();
        $this->getQueryFromCart();
        $this->calculateQueryHash();
        $this->getQueryFromOrder();

        return http_build_query($this->paymentQuery);
    }

    /**
     * Calculate the hash based on arguments, it's values, and choosen algorithm.
     */
    protected function calculateQueryHash()
    {
        ksort($this->paymentQuery);
        foreach ($this->paymentQuery as $key => $val) {
            $this->paymentQuery['hash'] .= $val;
        }

        switch ($this->conf['hashAlgorithm']) {
            case 'md5':
                $this->paymentQuery['hash'] = md5(
                    $this->paymentQuery['hash'] . $this->conf['key']
                );
                break;
            case 'sha2-384':
            default:
                $this->paymentQuery['hash'] = hash_hmac(
                    'sha384',
                    $this->paymentQuery['hash'],
                    $this->conf['key']
                );
        }
    }

    /**
     * Get Query From Setting
     */
    protected function getQueryFromSettings()
    {
        $this->paymentQuery['aid'] = (int)$this->conf['merchantId'];
        if ((int)$this->conf['subAccountId']) {
            $this->paymentQuery['aid'] = (int)$this->conf['subAccountId'];
        }
        $this->paymentQuery['portalid'] = (int)$this->conf['portalId'];
        if ($this->conf['sandbox'] === '1') {
            $this->paymentQuery['mode'] = 'test';
        } else {
            $this->paymentQuery['mode'] = 'live';
        }
        $this->paymentQuery['currency'] = $this->orderItem->getCurrencyCode();
        $this->paymentQuery['request'] = $this->conf['request'];
        $this->paymentQuery['reference'] = time();

        $this->addPaymentQueryReturnUrls();
    }

    /**
     * Get Query From Cart
     */
    protected function getQueryFromCart()
    {
        $count = 0;

        if ($this->orderItem->getProducts()) {
            foreach ($this->orderItem->getProducts() as $productKey => $product) {
                ++$count;

                $this->paymentQuery['id[' . $count . ']'] = $product->getUid();
                $this->paymentQuery['it[' . $count . ']'] = 'goods';
                $this->paymentQuery['pr[' . $count . ']'] = round(($product->getGross() / $product->getCount()) * 100);
                $this->paymentQuery['no[' . $count . ']'] = $product->getCount();
                $this->paymentQuery['de[' . $count . ']'] = $product->getTitle();
            }
        }

        if ($this->orderItem->getPayment()->getGross()) {
            ++$count;
            $this->paymentQuery['id[' . $count . ']'] = $this->orderItem->getPayment()->getServiceId();
            $this->paymentQuery['it[' . $count . ']'] = 'handling';
            $this->paymentQuery['pr[' . $count . ']'] = round($this->orderItem->getPayment()->getGross() * 100);
            $this->paymentQuery['no[' . $count . ']'] = 1;
            $this->paymentQuery['de[' . $count . ']'] = $this->orderItem->getPayment()->getName();
        }

        if ($this->orderItem->getShipping()->getGross()) {
            ++$count;
            $this->paymentQuery['id[' . $count . ']'] = $this->orderItem->getShipping()->getServiceId();
            $this->paymentQuery['it[' . $count . ']'] = 'shipment';
            $this->paymentQuery['pr[' . $count . ']'] = round($this->orderItem->getShipping()->getGross() * 100);
            $this->paymentQuery['no[' . $count . ']'] = 1;
            $this->paymentQuery['de[' . $count . ']'] = $this->orderItem->getShipping()->getName();
        }
    }

    /**
     * Get Query From Order
     */
    protected function getQueryFromOrder()
    {
        $billingAddress = $this->orderItem->getBillingAddress();

        $this->paymentQuery['firstname'] = $billingAddress->getFirstName();
        $this->paymentQuery['lastname'] = $billingAddress->getLastName();
        $this->paymentQuery['email'] = $billingAddress->getEmail();
        $this->paymentQuery['company'] = $billingAddress->getCompany();
        $this->paymentQuery['zip'] = $billingAddress->getZip();

        if (empty($billingAddress->getStreetNumber())) {
            $this->paymentQuery['street'] = $billingAddress->getStreet();
        } else {
            $this->paymentQuery['street'] = $billingAddress->getStreet() . " " . $billingAddress->getStreetNumber();
        }

        $this->paymentQuery['city'] = $billingAddress->getCity();
        $this->paymentQuery['country'] = strtoupper($billingAddress->getCountry());
    }

    /**
     * add return URLs for Cart order controller actions to payment query
     *
     * one for payment success
     * one for payment cancel
     */
    protected function addPaymentQueryReturnUrls()
    {
        $this->paymentQuery['successurl'] = $this->buildReturnUrl('success', $this->cartSHash);
        $this->paymentQuery['backurl'] = $this->buildReturnUrl('cancel', $this->cartFHash);
    }

    /**
     * Builds a return URL to Cart order controller action
     *
     * @param string $action
     * @param string $hash
     * @return string
     */
    protected function buildReturnUrl(string $action, string $hash) : string
    {
        $pid = $this->cartConf['settings']['cart']['pid'];

        $arguments = [
            'tx_cartpayone_cart' => [
                'controller' => 'Order\Payment',
                'order' => $this->orderItem->getUid(),
                'action' => $action,
                'hash' => $hash
            ]
        ];

        $uriBuilder = $this->getUriBuilder();

        return $uriBuilder->reset()
            ->setTargetPageUid($pid)
            ->setTargetPageType($this->conf['redirectTypeNum'])
            ->setCreateAbsoluteUri(true)
            ->setUseCacheHash(false)
            ->setArguments($arguments)
            ->build();
    }

    /**
     * @return UriBuilder
     */
    protected function getUriBuilder(): UriBuilder
    {
        $request = $this->objectManager->get(Request::class);
        $request->setRequestURI(GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'));
        $request->setBaseURI(GeneralUtility::getIndpEnv('TYPO3_SITE_URL'));
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($request);

        return $uriBuilder;
    }
}
