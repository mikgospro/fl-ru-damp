<?php
/**
 * ����������� �������������
 * */
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

require_once '../classes/stdf.php';
require_once '../classes/memBuff.php';
require_once '../classes/smtp.php';


/**
 * ����� ������������ �� ���� �������������� ��������
 * 
 */
//$sender = 'jb_admin';
$sender = 'admin';

$sql = "
SELECT f.uid, f.email, f.login, f.uname, f.usurname, f.subscr FROM employer f
INNER JOIN (
SELECT uid FROM employer WHERE substring(subscr from 8 for 1)::integer = 1 AND is_banned = B'0'
EXCEPT
SELECT emp_id FROM sbr
ORDER BY uid ASC) as u ON u.uid = f.uid";

//$sql = "SELECT uid, email, login, uname, usurname FROM users WHERE email in ('jb@x13-net.ru', 'jb.x13@mail.ru', 'stoiss@yandex.ru')"; 


$pHost = str_replace(array("http://", "https://"), "", $GLOBALS['host']);
if ( defined('HTTP_PREFIX') ) {
    $pHttp = str_replace("://", "", HTTP_PREFIX); // ������� � ������ ���� ����������� ��������� HTTPS �� �������� (��� ����� � ��)
} else {
    $pHttp = 'http';
}
$eHost = $GLOBALS['host'];

$pMessage = "
������������!

� ��� ��������� ��������������� �� �{$pHttp}:/{������ ��� �����}/{$pHost}/sbr/?utm_source=newsletter4&utm_medium=email&utm_campaign=video_free<i>�</i>. � ������� �{$pHttp}:/{������}/{$pHost}/help/?c=41&utm_source=newsletter4&utm_medium=email&utm_campaign=video_free<i>�</i> ��������� �����������, � ������� ������ � �������� ������������ �������� ������� �������������� ����� ������.

��� ������� �����������? ��� ������ ������? ��� ���������� �� ������ ����� ��������������? ��� ��������� �������� � ��������� ������� ��� �����? ������ �� ��� � ������ ������ ������� �� ������ ����� � ����� ������� ������ �����.

�� ������� ���, ��� �������� ���������!

�� ���� ����������� �������� �� ������ ���������� � ���� {$pHttp}:/{������ ���������}/{$pHost}/help/?all<i>.</i>
�� ������ ��������� ����������� ��{$pHttp}:/{�������� &laquo;�����������/��������&raquo;}/{$pHost}/users/%USER_LOGIN%/setup/mailer/<span> ������</span> ��������.

�������� ������,
������� {$pHttp}:/{Free-lance.ru}/{$pHost}/";


$eSubject = "Free-lance.ru: ��������������� �� ������� ��� �����";

$eMessage = "<p>������������!</p>
<p>� ��� ��������� ��������������� �� �<a href='{$eHost}/sbr/?utm_source=newsletter4&utm_medium=rassylka&utm_campaign=rating_freelancer' target='_blank'>������ ��� �����</a>�. � ������� �<a href='{$eHost}/help/?c=41&utm_source=newsletter4&utm_medium=email&utm_campaign=video_free' target='_blank'>������</a>� ��������� �����������, � ������� ������ � �������� ������������ �������� ������� �������������� ����� ������.</p>
<p>��� ������� �����������? ��� ������ ������? ��� ���������� �� ������ ����� ��������������? ��� ��������� �������� � ��������� ������� ��� �����? ������ �� ��� � ������ ������ ������� �� ������ ����� � ����� ������� ������ �����.</p>
<p>�� ������� ���, ��� �������� ���������!</p>
<p>�� ���� ����������� �������� �� ������ ���������� � ���� <a href=\"{$eHost}/help/?all\" target=\"_blank\">������ ���������</a>.</p>
<p>�� ������ ��������� ����������� �� <a href=\"{$eHost}/users/%USER_LOGIN%/setup/mailer/\" target=\"_blank\">�������� ������������/��������</a> ������ ��������.</p>
<br/>
�������� ������!<br/>
������� <a href='{$eHost}/' target='_blank'>Free-lance.ru</a>";

// ----------------------------------------------------------------------------------------------------------------
// -- �������� ----------------------------------------------------------------------------------------------------
// ----------------------------------------------------------------------------------------------------------------
$DB = new DB('plproxy');
$master = new DB('master');
$cnt = 0;


$sender = $master->row("SELECT * FROM users WHERE login = ?", $sender);
if (empty($sender)) {
    die("Unknown Sender\n");
}

echo "Send personal messages\n";

// �������������� ��������
$msgid = $DB->val("SELECT masssend(?, ?, '{}', '')", $sender['uid'], $pMessage);
if (!$msgid) die('Failed!');

// ��������, �� �������� ��������� � ������-�� �������
$i = 0;
while ($users = $master->col("{$sql} LIMIT 30000 OFFSET ?", $i)) {
    $DB->query("SELECT masssend_bind(?, {$sender['uid']}, ?a)", $msgid, $users);
    $i = $i + 30000;
}
// �������� �������� � �����
$DB->query("SELECT masssend_commit(?, ?)", $msgid, $sender['uid']); 
echo "Send email messages\n";

$mail = new smtp;
$mail->subject = $eSubject;  // ��������� ������
$mail->message = $eMessage; // ����� ������
$mail->recipient = ''; // �������� '����������' ��������� ������
$spamid = $mail->send('text/html');
if (!$spamid) die('Failed!');
// � ����� ������� �������� �������, �� ��� ������ �� ����������!
// �������� ��� ����� �������� ������ ����������� � ������-���� �������
$i = 0;
$mail->recipient = array();
$res = $master->query($sql);
while ($row = pg_fetch_assoc($res)) {
    $mail->recipient[] = array(
        'email' => $row['email'],
        'extra' => array('first_name' => $row['uname'], 'last_name' => $row['usurname'], 'USER_LOGIN' => $row['login'])
    );
    if (++$i >= 30000) {
        $mail->bind($spamid);
        $mail->recipient = array();
        $i = 0;
    }
    $cnt++;
}
if ($i) {
    $mail->bind($spamid);
    $mail->recipient = array();
}

echo "OK. Total: {$cnt} users\n";