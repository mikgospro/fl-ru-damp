<?php
/* 
 * 
 * Данный файл является частью проекта Веб Мессенджер.
 * 
 * Все права защищены. (c) 2005-2009 ООО "ТОП".
 * Данное программное обеспечение и все сопутствующие материалы
 * предоставляются на условиях лицензии, доступной по адресу
 * http://webim.ru/license.html
 * 
 */
?>
<?php 
function smarty_block_role_admin($params, $content, &$smarty, &$repeat) {
  $o = Operator::getInstance()->GetLoggedOperator(false);
  return $o['role'] == 'admin' ? $content : '';
}
?>