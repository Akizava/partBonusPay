<?php

/**
 * Функция для отловли и исправления JsData в оформлении заказа, что бы наш input в sale.order.ajax работал
 */
\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleComponentOrderJsData',
    'OnSaleComponentOrderJsDataHandler'
);

function OnSaleComponentOrderJsDataHandler(&$arResult, &$arParams)
{
    global $USER;
    if ($USER->IsAuthorized()) {
        $USER_ID = $USER->GetID();
        $dbUserAccount = CSaleUserAccount::GetList([], ["USER_ID" => $USER_ID, "CURRENCY" => "RUB",]);
        $_SESSION['USER_ACCOUNT'] = $dbUserAccount->Fetch();
        $budget = floatval($_SESSION['USER_ACCOUNT']['CURRENT_BUDGET']);
        if ($budget > 0) {
            $rsUser = CUser::GetByID($USER_ID);
            $arUser = $rsUser->Fetch();
            $_SESSION['USER_STATUS'] = $arUser['UF_STATUS'];
            $status = get_status(
                $_SESSION['USER_STATUS']
            );//Функция отвечающая за то, сколько процентов от заказа может испльзовать Юзер
            $status['UF_PERSENT'] = $status['UF_PERSENT'] ? $status['UF_PERSENT'] : 15;
            $proc_commision = intval($status['UF_PERSENT']) / 100;
            $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = 0;
            foreach ($arResult['JS_DATA']['ORDER_PROP']['properties'] as $props) {
                if ($props['CODE'] == 'WANT_SPEND') {
                    $arResult["WANT_SPEND_ID"] = $props['ID'];
                    $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = intval($props['VALUE'][0]);
                }
            }
            $orderPrice = $arResult['JS_DATA']['TOTAL']['ORDER_PRICE'];
            $comision = intval($orderPrice * $proc_commision);
            if ($comision > $budget) {
                $comision = $budget;
            }
            $want_spend = $arResult['JS_DATA']['TOTAL']["WANT_SPEND"];
            $arResult['JS_DATA']['TOTAL']['COMISION'] = $comision;


            if ($want_spend > $comision) {
                $want_spend = $comision;
                $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = $comision;
            }
            $arResult['JS_DATA']['OLD'] = $status;
            $arResult['JS_DATA']['CURRENT_BUDGET'] = intval($budget);
            $arResult['JS_DATA']['CURRENT_BUDGET_FORMATED'] = $arResult['JS_DATA']['CURRENT_BUDGET'] . " &#8381;";
            $arResult['JS_DATA']['TOTAL']['ORDER_TOTAL_LEFT_TO_PAY'] = $orderPrice - $want_spend;
            $arResult['JS_DATA']['TOTAL']['ORDER_TOTAL_LEFT_TO_PAY_FORMATED'] = $arResult['JS_DATA']['TOTAL']['ORDER_TOTAL_LEFT_TO_PAY'] . " &#8381;";
            $arResult['JS_DATA']['TOTAL']['PAYED_FROM_ACCOUNT_FORMATED'] = $want_spend . "&#8381;";
        }
    }
}

/**
 * Функция для ловли создания заказа и создание оплаченной "платежки" в формате "внутренний счет", с дополнительной
 * проверкой по ограничениям оплаты
 * Оставшуюся стоимость "размазывает" по всем товарам. Это требуется некоторомы экварингами)
 * Так же есть транзакция для Битрикс, что бы отслеживать в модуле Внутреннего счета внутри Битрикс
 */
\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderBeforeSaved',
    'OnSaleOrderBeforeSavedFunction'
);
function OnSaleOrderBeforeSavedWantSpend(\Bitrix\Main\Event $event)
{
    global $USER;
    $userId = $USER->GetID();
    $budget = floatval($_SESSION['USER_ACCOUNT']['CURRENT_BUDGET']);
    if ($budget) {
        /** @var \Bitrix\Sale\Order $order * */
        $order = $event->getParameter("ENTITY");
        if ($order->getId()) {
            return;
        }
        $oldValues = $event->getParameter("VALUES");
        $paymentCollection = $order->getPaymentCollection();
        $orderBasket = $order->getBasket();
        $status['UF_PERSENT'] = 15;
        $proc_commision = intval($status['UF_PERSENT']) / 100;
        $orderPrice = $order->getPrice();
        $commission = intval($orderPrice * $proc_commision);
        $propertyCollection = $order->getPropertyCollection();
        $arPropertyCollection = $propertyCollection->getArray();
        $TransactIdProperty = false;
        $want_send_property = false;
        $want_spend = 0;
        $EMAIL = '';
        foreach ($arPropertyCollection['properties'] as $props) {
            if ($props['CODE'] == 'WANT_SPEND') {
                $want_send_property = $propertyCollection->getItemByOrderPropertyId($props['ID']);
                $want_spend = $want_send_property->getValue();
            }
            if ($props['CODE'] == 'TRANSACT_ID') {
                $TransactIdProperty = $propertyCollection->getItemByOrderPropertyId($props['ID']);
            }
        }
        if ($want_spend) {
            if ($commission > $budget) {
                $commission = $budget;
            }
            if ($want_spend > $commission) {
                $want_spend = $commission;
                $want_send_property->setValue($commission);
            }
        }
        if (!$paymentCollection->isExistsInnerPayment()) {
            if ($want_spend) {
                $resultTransact = CSaleUserTransact::Add(
                    [
                        "USER_ID" => $userId,
                        "AMOUNT" => $want_spend,
                        "CURRENCY" => "RUB",
                        "DEBIT" => "N",
                        "EMPLOYEE_ID" => 1,
                        "TRANSACT_DATE" => ConvertTimeStamp(time(), "FULL"),
                    ]
                );
                if (is_int($resultTransact)) {
                    if ($TransactIdProperty) {
                        $TransactIdProperty->setValue($resultTransact);
                    }
                    foreach ($paymentCollection as $payment) {
                        $service = \Bitrix\Sale\PaySystem\Manager::getObjectById($payment->getPaymentSystemId());
                        $r = $payment->delete();
                        if (!$r->isSuccess()) {
                            var_dump($r->getErrorMessages());
                        }
                        $newMainPayment = $paymentCollection->createItem($service);
                        $newMainPayment->setField('SUM', ($orderPrice - $want_spend));
                    }
                    $newPayment = $paymentCollection->createInnerPayment();
                    $newPayment->setField('SUM', 0);
                    $newPayment->setPaid("N");//По идее, можно было отслеживать по $resultTransact и наличию данных в $TransactIdProperty, но код писался не с нуля, поэтому оставил проверку на наличие внутренней оплаты, а тут она 0 и N, по причине, что часть эквайрингов очень ревностно относятся к оплатам и не пускают такие в оплату вообще
                    $basket_want_spend = $want_spend;
                    foreach ($orderBasket as $key => $basketItem) {
                        $price = $priceToBasketSpend = $basketItem->getPrice();
                        $basketItemQuantity = $basketItem->getQuantity();
                        $commissionBasketItem = intval(($price * $proc_commision));
                        if ($basket_want_spend != 0) {
                            if ($basket_want_spend > 0) {
                                if (($basket_want_spend - $commissionBasketItem) > 0) {
                                    $basket_want_spend -= $commissionBasketItem;
                                } else {
                                    $commissionBasketItem = $basket_want_spend;
                                    $basket_want_spend = 0;
                                }
                                $priceToBasketSpend -= ($commissionBasketItem / $basketItemQuantity);
                            } else {
                                $priceToBasketSpend += ($basket_want_spend / $basketItemQuantity);
                            }
                            $basketItem->setFields([
                                'CUSTOM_PRICE' => 'Y',
                                'PRICE' => $priceToBasketSpend,
                            ]);
                            $basketItem->save();
                        }
                    }
                }
            }
        }
        $event->addResult(
            new \Bitrix\Main\EventResult(
                \Bitrix\Main\EventResult::SUCCESS, $order
            )
        );
    }
}