<!--Вставить в PAY SYSTEMS BLOCK	-->
<?
if ($arResult['JS_DATA']['CURRENT_BUDGET'] > 0 && isset($arResult['JS_DATA']['CURRENT_BUDGET'])): ?>
    <?
    /** Input, что бы работал скрипт по скидке цен*/ ?>
    <label id="innerPaidSpendLabel"></label>
    <input type="number"
           data-comision="<?= $arResult['JS_DATA']['TOTAL']['COMISION'] ?>"
           value="<?= $arResult['JS_DATA']['TOTAL']['WANT_SPEND'] ?>"
           readonly
           name="ORDER_PROP_<?= $arResult["WANT_SPEND_ID"] ?>"
           id="WANT_SPEND">
<?
endif; ?>
<?
/** Вставить в конце перед скриптом BX.message */ ?>
<script>
  function changeWantNum(elem) {
    let want_spend = document.getElementById('WANT_SPEND'),
        innerPaidSpendLabel = document.getElementById('innerPaidSpendLabel');
    if (elem.checked) {
      want_spend.value = want_spend.dataset.comision;
      innerPaidSpendLabel.classList.add('btn-light-red');
      innerPaidSpendLabel.innerText = `Будет потрачено ${want_spend.value} ₽`;
    } else {
      want_spend.value = 0;
      innerPaidSpendLabel.classList.remove('btn-light-red');
      innerPaidSpendLabel.innerText = 'Потратить баллы?';
    }
  }
</script>
