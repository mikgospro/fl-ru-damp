<?php if ( !defined('IS_SITE_ADMIN') ) { header('Location: /404.php'); exit; }
	if (!hasPermissions('birthday'))
		{exit;}
	$action = trim($_GET['action']);

	$msettings = new settings();
	
	switch ($action){
		case "delete":
			$id = trim($_GET['id']);
			if ($id) $error = birthday08::Delete($id);
		break;
		case "status":
			$msettings->SetVariable("birthday08", "status", $_GET['value']);
	}
	
	$users = birthday08::GetAll();
?>
<script src="/scripts/warning.js" type="text/javascript"></script>

<?
    	if ($msettings->GetVariable("birthday08", "status") == "open")
    	{

?>
		<B>������ �����������: �������</B> &nbsp;&nbsp;&nbsp; &raquo; <A href="./?action=status&value=close" class="blue">�������</A>

<?
    	}
    	else
    	{
?>

		<B>������ �����������: �������</B> &nbsp;&nbsp;&nbsp; &raquo; <A href="./?action=status&value=open" class="blue">�������</A>

<?
    	}

?>

<br><br><br>

<strong>������������</strong><br><br>

<table width="100%" border="0" cellspacing="2" cellpadding="2">
<?
$i = 0;

 if ($users) foreach($users as $ikey=>$user){ $i++; ?>
<tr class="qpr">
	<td>
		<table width="100%" cellspacing="0" cellpadding="0" border="0">
		<tr valign="top" class="n_qpr">
			<td>
			<?=$i?> <a href="/users/<?=$user['login']?>"><?=". ".$user['name']." ".$user['surname']." [".$user['login']."]"?></a> 
			<a href="mailto:<?=$user['email']?>"><?=$user['email']?></a> 
			<? if ($user['type'] == 1) print("���-������"); if ($user['type'] == 2) print("������������"); if ($user['type'] == 3) print("������") ?>
			<a href="/siteadmin/bill/?login=<?=$user['login']?>">����</a>
			<br>
			 ��� �������: <?=($user['message']  ? $user['message'] : "������ �� �������")?><br><br>
			
			</td>
		</tr>
		</table>
	</td>
</tr>
<? } ?>
</table>

