<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WishList\Service;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Lang;
use WishList\Model\WishList;
use WishList\Model\WishListProductQuery;
use WishList\Model\WishListQuery;
use WishList\WishList as WishListModule;

class WishListService
{
    protected $securityContext = null;
    protected $requestStack = null;
    protected $eventDispatcher = null;

    public function __construct(RequestStack $requestStack, SecurityContext $securityContext, EventDispatcherInterface $eventDispatcher)
    {
        $this->securityContext = $securityContext;
        $this->requestStack = $requestStack;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function addProduct($pseId, $quantity, $wishListId = null)
    {
        try {
            $wishList = $this->findWishListOrCreateDefault($wishListId);

            if (null === $wishList) {
                throw new \Exception(Translator::getInstance()->trans('There is no wishlist with this id for this customer', [], WishListModule::DOMAIN_NAME));
            }

            $productWishList = WishListProductQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->filterByWishListId($wishList->getId())
                ->findOneOrCreate();

            $productWishList
                ->setQuantity($quantity)
                ->save();

        } catch (\Exception $e) {
            Tlog::getInstance()->error("Error during wishlist add :".$e->getMessage());
            return false;
        }

        return $wishList->getId();
    }

    public function removeProduct($pseId, $wishListId = null)
    {
        try {

            $wishList = $this->findWishListOrCreateDefault($wishListId);

            if (null === $wishList) {
                throw new \Exception(Translator::getInstance()->trans('There is no wishlist with this id for this customer', [], WishListModule::DOMAIN_NAME));
            }

            list($customerId,$sessionId) = $this->getCurrentUserOrSession();

            $productWishList = WishListProductQuery::getExistingObject($wishList->getId(), $customerId, $sessionId, $pseId);

            if ($productWishList) {
                $productWishList->delete();
            }
        } catch (\Exception $e) {
            Tlog::getInstance()->error("Error during wishlist remove :".$e->getMessage());
            return false;
        }

        return true;
    }

    public function inWishList($pseId, $wishListId): bool
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        return null !== WishListProductQuery::getExistingObject($wishListId, $customerId, $sessionId, $pseId);
    }

    public function getWishList($wishListId)
    {
        $customer = $this->securityContext->getCustomerUser();
        $customerId = $customer?->getId();
        $sessionId = null;
        if (!$customer) {
            $sessionId = $this->requestStack->getCurrentRequest()->getSession()->getId();
        }

        return $this->getWishListObject($wishListId, $customerId, $sessionId);
    }

    public function getAllWishLists()
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $wishList = WishListQuery::create();

        if (null !== $customerId) {
            $wishList->filterByCustomerId($customerId);
        }

        if (null !== $sessionId) {
            $wishList->filterBySessionId($sessionId);
        }

        return $wishList->find();
    }

    public function clearWishList($wishListId)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $query =  WishListProductQuery::create()
            ->useWishListQuery()
            ->filterById($wishListId)
            ->endUse();

        if (null !== $customerId) {
            $query
                ->useWishListQuery()
                ->filterByCustomerId($customerId)
                ->endUse();
        }

        if (null !== $sessionId) {
            $query
                ->useWishListQuery()
                ->filterBySessionId($sessionId)
                ->endUse();
        }

        $query->find()->delete();

        return true;
    }

    public function findWishListOrCreateDefault($wishListId = null)
    {
        if ($wishListId) {
            return $this->getWishList($wishListId);
        }
        $defaultWishList = $this->findCurrentDefaultWishList();
        if (null !== $defaultWishList) {
            return $defaultWishList;
        }
        return $this->createUpdateWishList("Default");
    }

    public function setWishListToDefault($wishListId)
    {
        $newDefaultWishList = $this->getWishList($wishListId);
        if (null === $newDefaultWishList) {
            throw new \Exception(Translator::getInstance()->trans('There is no wishlist with this id for this customer', [], WishListModule::DOMAIN_NAME));
        }

        list($customerId,$sessionId) = $this->getCurrentUserOrSession();
        $wishList = WishListQuery::create();
        if (null !== $customerId) {
            $wishList->filterByCustomerId($customerId);
        }
        if (null !== $sessionId) {
            $wishList->filterBySessionId($sessionId);
        }
        $wishList->update(["Default" => 0]);

        $newDefaultWishList->setDefault(1)->save();
    }
    public function createUpdateWishList($title, $products = null, $wishListId = null)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $rewrittenUrl = null;
        if (null === $wishList = $this->getWishListObject($wishListId, $customerId, $sessionId)) {
            $wishList = new WishList();
            $defaultWishList = $this->findCurrentDefaultWishList();
            if (null !== $customerId) {
                $wishList->setCustomerId($customerId);
            }

            if (null !== $sessionId) {
                $wishList->setSessionId($sessionId);
            }
            $hash = bin2hex(random_bytes(20));

            $wishList->setCode($hash);
            if (null === $defaultWishList) {
                $wishList->setDefault(1);
            }
            $rewrittenUrl = $hash;
        }

        if (null !== $title) {
            $wishList->setTitle($title);
        }

        $wishList->save();

        if (null !== $rewrittenUrl) {
            $currentLang = $this->requestStack->getCurrentRequest()->getSession()->get('thelia.current.lang');
            $wishList
                ->setRewrittenUrl($currentLang->getLocale(), $rewrittenUrl)
                ->save();
        }



        if (null !== $products) {
            foreach ($products as $product) {
                $this->addProduct($product['productSaleElementId'], $product['quantity'], $wishList->getId());
            }
        }

        return $wishList;
    }

    public function deleteWishList($wishListId)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        if (null !== $wishList = $this->getWishListObject($wishListId, $customerId, $sessionId)) {
            $wishList->delete();
        }
    }

    public function duplicateWishList($wishListId, $title)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();
        /** @var Lang $currentLang */
        $currentLang = $this->requestStack->getCurrentRequest()->getSession()->get('thelia.current.lang');

        $wishList = $this->getWishListObject($wishListId, $customerId, $sessionId);

        $code = bin2hex(random_bytes(20));

        $newWishList = (new WishList())
            ->setTitle($title)
            ->setCustomerId($customerId)
            ->setSessionId($sessionId)
            ->setCode($code)
        ;

        $newWishList->save();

        $newWishList
            ->setRewrittenUrl($currentLang->getLocale(), $code)
            ->save();

        foreach ($wishList->getWishListProducts() as $wishListProduct) {
            $this->addProduct($wishListProduct->getProductSaleElementsId(), $wishListProduct->getQuantity(), $newWishList->getId());
        }

        return $newWishList;
    }

    public function sessionToUser($sessionId)
    {
        $customer = $this->securityContext->getCustomerUser();
        $wishLists = WishListQuery::create()->filterBySessionId($sessionId)->find();

        foreach ($wishLists as $wishList) {
            $wishList
                ->setCustomerId($customer->getId())
                ->setSessionId(null)
                ->save();
        }
    }

    public function addWishListToCart($wishListId)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $wishList = $this->getWishListObject($wishListId, $customerId, $sessionId);

        if (null !== $wishList){
            $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart($this->eventDispatcher);

            foreach ($wishList->getWishListProducts() as $wishListProduct) {
                $event = new CartEvent($cart);
                $event
                    ->setProduct($wishListProduct->getProductSaleElements()->getProductId())
                    ->setProductSaleElementsId($wishListProduct->getProductSaleElementsId())
                    ->setQuantity($wishListProduct->getQuantity())
                    ->setAppend(true)
                    ->setNewness(true)
                ;

                $this->eventDispatcher->dispatch($event, TheliaEvents::CART_ADDITEM);
            }
        }
    }

    public function cloneWishList($wishListId)
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $wishList = WishListQuery::create()->findPk($wishListId);

        /** @var Lang $currentLang */
        $currentLang = $this->requestStack->getCurrentRequest()->getSession()->get('thelia.current.lang');

        $code = bin2hex(random_bytes(20));

        $newWishList = (new WishList())
            ->setTitle($wishList->getTitle())
            ->setCustomerId($customerId)
            ->setSessionId($sessionId)
            ->setCode($code)
        ;

        $newWishList->save();

        $newWishList
            ->setRewrittenUrl($currentLang->getLocale(), $code)
            ->save();

        foreach ($wishList->getWishListProducts() as $wishListProduct) {
            $this->addProduct($wishListProduct->getProductSaleElementsId(), $wishListProduct->getQuantity(), $newWishList->getId());
        }

        return $newWishList;
    }

    protected function getWishListObject($wishListId, $customerId, $sessionId)
    {
        $wishList = WishListQuery::create()
            ->filterById($wishListId);

        if (null !== $customerId) {
            $wishList->filterByCustomerId($customerId);
        }

        if (null !== $sessionId) {
            $wishList->filterBySessionId($sessionId);
        }

        return $wishList->findOne();
    }

    protected function findCurrentDefaultWishList()
    {
        list($customerId,$sessionId) = $this->getCurrentUserOrSession();

        $wishList = WishListQuery::create()
            ->filterByDefault(1);

        if (null !== $customerId) {
            $wishList->filterByCustomerId($customerId);
        }

        if (null !== $sessionId) {
            $wishList->filterBySessionId($sessionId);
        }

        return $wishList->findOne();
    }

    protected function getCurrentUserOrSession()
    {
        $customer = $this->securityContext->getCustomerUser();
        $customerId = null !== $customer ? $customer->getId() : null;
        $sessionId = null;
        if (!$customer) {
            $sessionId = $this->requestStack->getCurrentRequest()->getSession()->getId();
        }
        return [$customerId,$sessionId];
    }
}
