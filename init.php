<?php

/**
 * Функция для отловли и исправления JsData в оформлении заказа, чтобы input в sale.order.ajax работал.
 * При авторизованном пользователе она вычисляет возможную сумму для списания с аккаунта.
 */
\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleComponentOrderJsData',
    'OnSaleComponentOrderJsDataHandler'
);

function OnSaleComponentOrderJsDataHandler(&$arResult, &$arParams)
{
    global $USER;
    // Проверяем, авторизован ли пользователь
    if ($USER->IsAuthorized()) {
        $USER_ID = $USER->GetID();
        // Получаем данные о балансе пользователя
        $dbUserAccount = CSaleUserAccount::GetList([], ["USER_ID" => $USER_ID, "CURRENCY" => "RUB"]);
        $_SESSION['USER_ACCOUNT'] = $dbUserAccount->Fetch();
        $budget = floatval($_SESSION['USER_ACCOUNT']['CURRENT_BUDGET']);

        // Если баланс положительный
        if ($budget > 0) {
            // Получаем информацию о пользователе
            $rsUser = CUser::GetByID($USER_ID);
            $arUser = $rsUser->Fetch();
            $_SESSION['USER_STATUS'] = $arUser['UF_STATUS'];
            // Получаем статус пользователя, чтобы узнать, сколько процентов он может потратить
            $status = get_status($_SESSION['USER_STATUS']);
            $status['UF_PERSENT'] = $status['UF_PERSENT'] ? $status['UF_PERSENT'] : 15;
            $proc_commision = intval($status['UF_PERSENT']) / 100;

            // Инициализируем нужные данные
            $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = 0;
            foreach ($arResult['JS_DATA']['ORDER_PROP']['properties'] as $props) {
                if ($props['CODE'] == 'WANT_SPEND') {
                    $arResult["WANT_SPEND_ID"] = $props['ID'];
                    $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = intval($props['VALUE'][0]);
                }
            }

            $orderPrice = $arResult['JS_DATA']['TOTAL']['ORDER_PRICE'];
            // Вычисляем возможную комиссию, которую может списать пользователь
            $comision = intval($orderPrice * $proc_commision);
            // Ограничиваем комиссию оставшимся балансом
            if ($comision > $budget) {
                $comision = $budget;
            }

            $want_spend = $arResult['JS_DATA']['TOTAL']["WANT_SPEND"];
            $arResult['JS_DATA']['TOTAL']['COMISION'] = $comision;

            // Ограничиваем сумму, которую хочет потратить пользователь, комиссией
            if ($want_spend > $comision) {
                $want_spend = $comision;
                $arResult['JS_DATA']['TOTAL']["WANT_SPEND"] = $comision;
            }

            // Заполняем оставшиеся данные
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
 * Функция для ловли создания заказа и создание оплаченной "платежки" в формате "внутренний счет".
 * Проверка по ограничениям оплаты. Остаток суммы распределяется по товарам.
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
        /** @var \Bitrix\Sale\Order $order */
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

        // Обрабатываем свойства заказа
        foreach ($arPropertyCollection['properties'] as $props) {
            if ($props['CODE'] == 'WANT_SPEND') {
                $want_send_property = $propertyCollection->getItemByOrderPropertyId($props['ID']);
                $want_spend = $want_send_property->getValue();
            }
            if ($props['CODE'] == 'TRANSACT_ID') {
                $TransactIdProperty = $propertyCollection->getItemByOrderPropertyId($props['ID']);
            }
        }

        // Проверяем, можно ли списать деньги с внутреннего счета
        if ($want_spend) {
            if ($commission > $budget) {
                $commission = $budget;
            }
            if ($want_spend > $commission) {
                $want_spend = $commission;
                $want_send_property->setValue($commission);
            }
        }

        // Если нет внутренней оплаты, создаем транзакцию
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
                    // Устанавливаем идентификатор транзакции в свойства заказа
                    if ($TransactIdProperty) {
                        $TransactIdProperty->setValue($resultTransact);
                    }

                    // Обрабатываем платежи, удаляя старые и добавляя новые
                    foreach ($paymentCollection as $payment) {
                        $service = \Bitrix\Sale\PaySystem\Manager::getObjectById($payment->getPaymentSystemId());
                        $r = $payment->delete();
                        if (!$r->isSuccess()) {
                            var_dump($r->getErrorMessages());
                        }
                        $newMainPayment = $paymentCollection->createItem($service);
                        $newMainPayment->setField('SUM', ($orderPrice - $want_spend));
                    }

                    // Создаем новый внутренний платеж
                    $newPayment = $paymentCollection->createInnerPayment();
                    $newPayment->setField('SUM', 0);
                    $newPayment->setPaid("N");

                    // Перераспределяем остаток на товары в заказе
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

        // Завершаем обработку события
        $event->addResult(
            new \Bitrix\Main\EventResult(
                \Bitrix\Main\EventResult::SUCCESS, $order
            )
        );
    }
}
