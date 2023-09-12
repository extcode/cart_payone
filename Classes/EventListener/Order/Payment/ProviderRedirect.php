<?php
declare(strict_types=1);
namespace Extcode\CartPayone\EventListener\Order\Payment;

/*
 * This file is part of the package extcode/cart-payone.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Order\Item as OrderItem;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Event\Order\PaymentEvent;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class ProviderRedirect
{
    const PAYMENT_API_URL = 'https://frontend.pay1.de/frontend/v2/';

    /**
     * @var OrderItem
     */
    protected $orderItem;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var TypoScriptService
     */
    protected $typoScriptService;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var array
     */
    protected $conf = [];

    /**
     * @var array
     */
    protected $cartConf = [];

    /**
     * @var string
     */
    protected $cartFHash = '';

    /**
     * @var string
     */
    protected $cartSHash = '';

    /**
     * @var array
     */
    protected $paymentQuery = [];

    public function __construct(
        ConfigurationManager $configurationManager,
        PersistenceManager $persistenceManager,
        TypoScriptService $typoScriptService,
        UriBuilder $uriBuilder,
        CartRepository $cartRepository,
        PaymentRepository $paymentRepository
    ) {
        $this->configurationManager = $configurationManager;
        $this->persistenceManager = $persistenceManager;
        $this->typoScriptService = $typoScriptService;
        $this->uriBuilder = $uriBuilder;
        $this->cartRepository = $cartRepository;
        $this->paymentRepository = $paymentRepository;

        $this->conf = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK,
            'CartPayone'
        );

        $this->cartConf = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );
    }

    public function __invoke(PaymentEvent $event): void
    {
        $this->orderItem = $event->getOrderItem();

        $payment = $this->orderItem->getPayment();
        $provider = $payment->getProvider();

        $providerParts = explode('_', $provider);
        $provider = $providerParts[0];
        $clearingType = $providerParts[1];
        $walletType = $providerParts[2] ?? null;

        if ($provider !== 'PAYONE') {
            return;
        }

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

        $cart = new Cart();
        $cart->setOrderItem($this->orderItem);
        $cart->setCart($event->getCart());
        $cart->setPid((int)$this->cartConf['settings']['order']['pid']);

        $this->cartRepository->add($cart);
        $this->persistenceManager->persistAll();

        $this->cartFHash = $cart->getFHash();
        $this->cartSHash = $cart->getSHash();

        header('Location: ' . self::PAYMENT_API_URL . '?' . $this->getQuery());

        $event->setPropagationStopped(true);
    }

    protected function getQuery(): string
    {
        $this->getQueryFromSettings();
        $this->getQueryFromCart();
        $this->calculateQueryHash();
        $this->getQueryFromOrder();

        return http_build_query($this->paymentQuery);
    }

    protected function calculateQueryHash(): void
    {
        ksort($this->paymentQuery);

        $strToHash = '';
        foreach ($this->paymentQuery as $key => $val) {
            if ($key === 'wallettype') {
                continue;
            }
            $strToHash .= $val;
        }

        switch ($this->conf['hashAlgorithm']) {
            case 'md5':
                $this->paymentQuery['hash'] = md5(
                    $strToHash . $this->conf['key']
                );
                break;
            case 'sha2-384':
            default:
                $this->paymentQuery['hash'] = hash_hmac(
                    'sha384',
                    $strToHash,
                    $this->conf['key']
                );
        }
    }

    protected function getQueryFromSettings(): void
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

    protected function getQueryFromCart(): void
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

    protected function getQueryFromOrder(): void
    {
        $billingAddress = $this->orderItem->getBillingAddress();

        $this->paymentQuery['firstname'] = $billingAddress->getFirstName();
        $this->paymentQuery['lastname'] = $billingAddress->getLastName();
        $this->paymentQuery['email'] = $billingAddress->getEmail();
        $this->paymentQuery['company'] = $billingAddress->getCompany();
        $this->paymentQuery['zip'] = $billingAddress->getZip();
        $this->paymentQuery['street'] = $billingAddress->getStreet();
        $this->paymentQuery['city'] = $billingAddress->getCity();
        $this->paymentQuery['country'] = strtoupper($billingAddress->getCountry());
    }

    /**
     * add return URLs for Cart order controller actions to payment query
     *
     * one for payment success
     * one for payment cancel
     */
    protected function addPaymentQueryReturnUrls(): void
    {
        $this->paymentQuery['successurl'] = $this->buildReturnUrl('success', $this->cartSHash);
        $this->paymentQuery['backurl'] = $this->buildReturnUrl('cancel', $this->cartFHash);
    }

    protected function buildReturnUrl(string $action, string $hash) : string
    {
        $pid = (int)$this->cartConf['settings']['cart']['pid'];

        $arguments = [
            'tx_cartpayone_cart' => [
                'controller' => 'Order\Payment',
                'order' => $this->orderItem->getUid(),
                'action' => $action,
                'hash' => $hash
            ]
        ];

        $uriBuilder = $this->uriBuilder;

        return $uriBuilder->reset()
            ->setTargetPageUid($pid)
            ->setTargetPageType((int)$this->conf['redirectTypeNum'])
            ->setCreateAbsoluteUri(true)
            ->setArguments($arguments)
            ->build();
    }
}
