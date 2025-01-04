<!-- Вставить в блок PAY SYSTEMS -->
<?php
// Проверка наличия остаточного бюджета и его значение
if ($arResult['JS_DATA']['CURRENT_BUDGET'] > 0 && isset($arResult['JS_DATA']['CURRENT_BUDGET'])): ?>
    <?php
    // Вставляем input для работы с ценовой скидкой
    ?>
    <label id="innerPaidSpendLabel"></label>
    <input type="number"
           data-comision="<?= $arResult['JS_DATA']['TOTAL']['COMISION'] ?>" // Комиссия для вычислений
    value="<?= $arResult['JS_DATA']['TOTAL']['WANT_SPEND'] ?>" // Начальное значение для суммы потраченных баллов
    readonly
    name="ORDER_PROP_<?= $arResult["WANT_SPEND_ID"] ?>" // Идентификатор свойства заказа для потраченных баллов
    id="WANT_SPEND">
<?php
endif; ?>

<?php
// Вставить в конце перед скриптом BX.message
?>
<script>
  // Функция для изменения суммы, которую пользователь готов потратить
  function changeWantNum(elem) {
    let want_spend = document.getElementById('WANT_SPEND'), // Ссылка на поле с суммой
        innerPaidSpendLabel = document.getElementById('innerPaidSpendLabel'); // Ссылка на текстовую метку

    // Проверка, если чекбокс отмечен
    if (elem.checked) {
      want_spend.value = want_spend.dataset.comision; // Устанавливаем значение равным комиссии
      innerPaidSpendLabel.classList.add('btn-light-red'); // Изменяем стиль метки на красный
      innerPaidSpendLabel.innerText = `Будет потрачено ${want_spend.value} ₽`; // Отображаем потраченную сумму
    } else {
      want_spend.value = 0; // Если чекбокс снят, сбрасываем сумму
      innerPaidSpendLabel.classList.remove('btn-light-red'); // Убираем красный стиль
      innerPaidSpendLabel.innerText = 'Потратить баллы?'; // Меняем текст метки
    }
  }
</script>
