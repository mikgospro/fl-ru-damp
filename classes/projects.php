<?
/**
 * ���������� ���� ��� ������ � �������� 
 */
require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/memBuff.php");
require_once $_SERVER["DOCUMENT_ROOT"]."/classes/projects_offers.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/user_content.php';

/**
 * ���� ����� ������������ �������.
 *
 * @var array
 */
require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/project_exrates.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/projects_filter.php");

$GLOBALS['project_exRates'] = project_exrates::GetAll();
if(!$GLOBALS['exch'])
    $GLOBALS['exch'] = array(1=>'FM', 'USD','Euro','���');

$GLOBALS['rates'] = array
(
0 => $project_exRates[12],
1 => $project_exRates[13],
2 => $project_exRates[14]
);


if(!defined('FTS_PROJECTS')) {
    define('FTS_PROJECTS', true);
}


/**
 * ����� ��� ������ � ���������
 *
 */
class projects 
{
    
    /**
     * ������ �������
     *
     */
    const PAGE_SIZE = 30;
    
    /**
     * ���������� �������� ��������� �� �������� ��� RSS
     */
    const PAGE_SIZE_RSS = 60;
    
    /**
     * ������������ ���������� �������� �������� �������
     * 
     */
    const LIMIT_DESCR = 5000;
    
    //��� ������� - ������������ ������
    const KIND_PERSONAL = 9;
    // ��� ������� - ��������
    const KIND_VACANCY = 4;
    // ��� ������� - �������
    const KIND_CONTEST = 2;
    // ��� ������ - ����� �������
    const KIND_CONTEST7 = 7;
    // ��� ������� - ������
    const KIND_PROJECT = 1;
    
    // ��������� "���������"
    const STATE_PUBLIC = 0;
    // ��������� "������ ��������� � ��������"
    const STATE_MOVED_TO_VACANCY = 1;

    const VIEWS_COUNT_KEY = 'project_view_count_%d';
    
    /**
     * ������ �������
     * 
     * @var integer
     */
    public $page_size = self::PAGE_SIZE;



    public static function initData($data)
    {
        $calledClass = get_called_class();
        $project = new $calledClass();
        $project->_project = $data;
        return $project;
    }

    /**
     * ���� �������� �������
     * 
     * @return type
     */
    public function getCreateDate()
    {
        return $this->_project['create_date'];
    }

    
    /**
     * ��������������� ���� �������� �������
     * 
     * @return type
     */
    public function getCreateDateEx()
    {
        if (isset($this->_project['create_date_ex'])) {
            return $this->_project['create_date_ex'];
        }
        
        $this->_project['create_date_ex'] = strtotimeEx($this->getCreateDate());
        return $this->_project['create_date_ex'];
    }

    /**
     * �������� �� ��� ���������
     * @return boolean   �������� ��� ���
     */
    public function isVacancy() 
    {
        return $this->_project['kind'] == self::KIND_VACANCY;
    }

    
    /**
     * ��� ������?
     * 
     * @return boolean
     */
    public function isProject()
    {
        return $this->_project['kind'] == self::KIND_PROJECT;
    }

    /**
    * ���������� ������������� ���� �������
    *
    * @return string $ident
    */
    public function getKindIdent() 
    {
        $ident = 'contest';

        if ($this->isProject()) {
            $ident = 'project';
        } 
        else if ($this->isVacancy()) {
            $ident = 'vacancy';
        }
        
        return $ident;
    }
    
    /**
     * ����������� �������� ����� ���
     * 
     * @return boolean
     */
    public function isPreferSbr()
    {
        return $this->_project['prefer_sbr'] == 't';
    }

    

    /**
    * � ����� ��������� ��������� ������
    *
    * @param int $state - self::STATE_MOVED_TO_VACANCY, ...
    */
    public function inState($state)
    {
        return $this->_project['state'] == $state;
    }

    
    /**
     * ������ ������������ � ��������
     * 
     * @return boolean
     */
    public function isStateMovedToVacancy()
    {
        return $this->_project['state'] == self::STATE_MOVED_TO_VACANCY;
    }

    

    /**
    * �������� �������� �� ������������ ���������� �������
    *
    * @param int $uid ������������ ������������
    */
    public function isOwner($uid)
    {
        return $this->_project['user_id'] == $uid;
    }

    /**
    * ���������� ���� �������
    */
    public function setKind($kind)
    {
        global $DB;
        $result = FALSE;

        if (isset($this->_project['id'])) {
            $result = $DB->update('projects', array('kind' => $kind), "id = ?", $this->_project['id']);
        }

        return $result;
    }

    
    /**
     * ����������� � ��������
     * 
     * @global type $DB
     * @return type
     */
    public function movedToVacancy()
    {
        global $DB;
        $result = FALSE;        
        
        if (isset($this->_project['id'])) {
            $result = $DB->update('projects', array(
                'kind' => self::KIND_VACANCY, 
                'state' => self::STATE_MOVED_TO_VACANCY,
                'moved_vacancy' => "NOW()"
            ), "kind = 1 AND id = ?", $this->_project['id']);
            
            if ($result) {
                require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/autoresponse.php";
                $autoresponse = new autoresponse();
                $autoresponse->increaseByProject($this->_project['id']);
            }
        }
        
        return $result;
    }

    
    /**
     * �������� ��� ���������� ������������ ��������
     * ��� ���������� ������������
     * 
     * @global type $DB
     * @param array $user
     * @param int $project_id
     */
    public function publishedMovedToVacancy(Array $user, $project_id = 0)
    {
        global $DB;
        
        $emp_id = intval($user['uid']);
        
        $project_where = $project_id > 0 ? $DB->parse(" AND id = ?i", $project_id) : "";
        
        $list = $DB->rows("
            SELECT id, name 
            FROM projects 
            WHERE 
                payed = 0 AND  
                user_id = ?i AND 
                kind = ?i AND 
                state = ?i
                {$project_where}
        ", $emp_id, self::KIND_VACANCY, self::STATE_MOVED_TO_VACANCY);
        
        $ids = array();
        if (count($list)) {

            //require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/smail.php");
            //$smail = new smail();
            
            foreach($list as $el){
                $ids[] = $el['id'];
                
                /*
                if (!$project_id) {
                    $tproject = $el;
                    $tproject['email'] = $user['email'];
                    $smail->sendMovedToVacancySuccessPayed($tproject);
                }*/
            }
            
            if (count($ids)) {
                $DB->update('projects', array(
                    'state' => self::STATE_PUBLIC,
                    'moved_vacancy_pro' => true
                ),'user_id = ? AND id IN(?l)', $emp_id, $ids);
            }
        }
    }

    

    /**
     * ���� ������ ��������� ������������� ������� 
     * ��� ����� ����������� � ��������
     * 
     * @return type
     */
    public function isAllowMovedToVacancy()
    {
        return $this->isProject() && 
               @$this->_project['exec_id'] == 0 && //��� �����������
               //@$this->_project['is_pro'] != 't' && //�������� �� ���
               @$this->_project['payed'] == 0 && //������ �� ����������
               @$this->_project['urgent'] != 't' && //�� ������� ������ ������� ������
               @$this->_project['hide'] != 't' && //�� ������� ������ ������� ������
               @$this->_project['logo_id'] == NULL && //�� ������� ������ ID ����� ��������
               @$this->_project['top_from'] == NULL && 
               @$this->_project['top_to'] == NULL;
    }



    /**
     * �������� ������� ��� ����?
     * 
     * @return type
     */
    public function isOwnerPro()
    {
        return @$this->_project['is_pro'] == 't';
    }

    

    /**
     * �������� ���������� � ���� �� ��������?
     * 
     * @return type
     */
    public function isNotPayedVacancy()
    {
        return $this->isVacancy() && 
               $this->inState(self::STATE_MOVED_TO_VACANCY) && 
               @$this->_project['payed'] == 0;
    }

    
    
    public function isClosed()
    {
        return @$this->_project['closed'] == 't';
    }
    
    
    
    /**
     * �������� �� ����������� ����������� � ������������ �������-��������
     * 
     * @return type
     */
    public function isAllowShowOffers()
    {
        $is_adm = hasPermissions('projects');
        return !$this->isNotPayedVacancy() || $is_adm;
    }


    /**
     * ���������� ������ �������
     *
     * @param int $set ������ �������
     */
    function setPageSize($set) {
        $this->page_size = $set;    
    }

    /**
     * �������� ��� ������ "� ����" ��� ����������� ����� �������� ����� ���
     * 
     * @param    array    $prj    ������ �������
     * @return   boolean          tru - �����, false - ��
     */
    function isProjectOfficePostedAfterNewSBR($prj) {
        $releaseDate = '2012-09-10';
        $ret = false;
        if($prj['kind']==4 && dateFormat("Y-m-d", $prj['post_date'])<$releaseDate) {
            $ret = true;
        }
        return $ret;
    }

    /**
     * ���������� ������ ����������� ��� ���������� ��� ������
     *
     * @param     integer    $id    ID �������
     * @return    array             ������ ��� ���
     */
    public function getInfoForFriendlyURL($id) {
        global $DB;
        $sql = "SELECT id, name FROM projects WHERE id=?i";
        return $DB->cache(1800)->row($sql, $id);
    }

    /**
     * ���������� ������ ������������� �� �������
     *
     * @param  array $specs
     * @return int
     */
    public static function getFirstSpec($specs)
    {
        if (count($specs)) {
            $spec = $specs[0];
            if ($spec['category_id'] || $spec['subcategory_id']) {
                return $spec['subcategory_id'] ? $spec['subcategory_id'] : $spec['category_id'];
            }
        }
        
        return 0;
    }
      
    /**
     * ���������� ������ �� ��������������� �������
     *
     * @param  int $id
     * @param  string $in_clue ����������� ������ � �������������
     * @param  string $group_clue ����������� ��������� �������������
     * @param  bool   $lnk        ��������� �������� ������������� ������� ��� ��� @see professions::GetProfNameWP()
     * @return string
     */
      public static function getSpecsStr($id, $in_clue='/', $group_clue = '&nbsp;&nbsp;', $lnk=false){
        if(!$sp = new_projects::getSpecs($id)) return '������';
        return self::_getSpecsStr( $sp, $in_clue, $group_clue, $lnk );
      }
    
    /**
     * ���������� ������ �� ��������������� �������
     * 
     * @param  array $sp ������ �� ���������������
     * @param  string $in_clue ����������� ������ � �������������
     * @param  string $group_clue ����������� ��������� �������������
     * @param  bool $lnk ��������� �������� ������������� ������� ��� ��� @see professions::GetProfNameWP()
     * @return string
     */
    public static function _getSpecsStr( $sp, $in_clue = '/', $group_clue = '&nbsp;&nbsp;', $lnk = false ) {
        if ( empty($sp) ) return '������';
        
        $parts = array();
        
        foreach ( $sp as $item ) {
            $name = '';
            
            if ( $item['subcategory_id'] ) $name = professions::GetProfNameWP($item['subcategory_id'], $in_clue, "��� �������", $lnk);
            elseif ( $item['category_id'] ) $name = professions::GetGroupNameWP($item['category_id']);
            else continue;
            
            $parts[] = $name;
        }
        
        return implode($group_clue, $parts);
    }

    
    /**
     * ������ ������������� ����� �����������
     * 
     * @param type $sp
     * @param type $link
     * @param type $glue
     */
    public static function getGroupLinks($sp, $link = false, $glue = ', ')
    {
        if (empty($sp)) {
            return false;
        }
        
        $ids = array();
        foreach ($sp as $item) {
            
            if (!$item['category_id']) {
                continue;
            }
            
            $ids[] = $item['category_id'];
        }
        
        
        return professions::getGroupLinks($ids, $link, $glue);
    }

    

    /**
     * ���������� �������� ������������� �������
     * 
     * @param integer $id �������
     * @return array
     */
    public static function getPrimarySpec($id){
        global $DB;
        $sql = "SELECT * FROM project_to_spec WHERE project_id = ?i ORDER BY id DESC LIMIT 1";
        $ret = $DB->row($sql, $id);
        return $ret ? $ret : array('category_id' => 0, 'subcategory_id' => 0);
    }

    /**
     * ���������� ������ ��������� ��� ������������� �������
     * @param integer $pid - �������� �������
     * @param boolean $first_only - ��� ��� ������ ������ ������� (��� ������ ����)
     * @return array
     */
    public static function getProjectCategories($pid, $first_only = false){
        if($first_only) return self::getPrimarySpec ($pid);
        global $DB;
        $sql = "SELECT DISTINCT a.*, pg.name AS category_name, pf.name AS subcategory_name, pf.name_case as name_case FROM
            project_to_spec a
            INNER JOIN prof_group pg ON (a.category_id = pg.id)
            LEFT JOIN professions pf ON (a.subcategory_id = pf.id)
            WHERE a.project_id = ?i
            ORDER BY a.id DESC";
        return $DB->rows($sql, $pid);
    }

    /**
     * ������� ��������� � ���� HTML
     * @param array $categories
     * @return string
     */
    public static function printCategories($categories){
        if(!empty($categories)){
            $out = '';
                    foreach ($categories as $value) {
                        $out .= '<a href="">'.$value['category_name'].'</a>';
                        if($value['subcategory_id']){
                            $out .= '&nbsp;/&nbsp;<a href="">'.$value['subcategory_name'].'</a>';
                        }
                        $out .= '&nbsp;&nbsp;&nbsp;&nbsp;';
                    }
            }
            return $out;
    }

    /**
    * ���������� ������ �������� � ������� ������������� ����������� ������� ������� �������� ����� ����
    *
    * @return array     ������ ��������
    */
    function GetAlertsPrjTopDays() {
        global $DB;
        $sql = "SELECT id, user_id, kind, name, top_to, 
                       EXTRACT(DAY FROM top_to) as date_d,
                       EXTRACT(MONTH FROM top_to) as date_m, 
                       EXTRACT(YEAR FROM top_to) as date_y, 
                       EXTRACT(MONTH FROM top_to) as date_m,
                       to_char(top_to, 'HH24:MI') as date_t 
                FROM projects 
                WHERE top_to-top_from>'1 day'::interval AND top_to>(NOW()+'1 day') AND top_to<=NOW()+('1 day 1 hour') AND closed=false;";
        return $DB->rows($sql);
    }



    /**
     * ���������� ���������� �� ������� �������� ������������
     *
     * @param integer $fid				UID ������������
     * @param boolean $closed			���������� �� �������, ������ � ����������
	 * @param boolean $is_owner         �������� ������� ����� ��������?
	 * @param boolean $is_moder         �������� ������� ���������?
     * @return array					���� �� ��������
     */
    function GetCurPrjs($fid, $closed = '', $is_owner = false, $is_moder = false, $kind = null, $trash = null, $page = 0){
        global $DB;

        if($page!=0) {
            $limit = $this->page_size;
            if((int)$page < 1) $page = 1;
            $offset = ($page-1)*$limit;
            $limit_str = "LIMIT {$limit} OFFSET {$offset}";
        }

        $base_th = ($kind == 2)? 5 : 3;
        $closed=($closed=='true' ? " AND (p.closed=true OR COALESCE(p.end_date, 'infinity') < now() OR pb.project_id IS NOT NULL) " : ($closed=='false' ? " AND (p.closed=false AND COALESCE(p.end_date, 'infinity') > now() AND pb.project_id IS NULL) " : "" ));
		if ($is_moder) {
            $sel   = ", pb.reason AS blocked_reason, blocked_time, admins.login AS admin_login, admins.uname AS admin_name, admins.usurname AS admin_uname";
            $join  = "LEFT JOIN users AS admins ON pb.admin = admins.uid";
		} else if ($is_owner) {
            $sel   = ", pb.reason AS blocked_reason, blocked_time";
		} else {
            $where = "AND pb.project_id IS NULL AND NOT(p.payed = 0 AND p.kind = ".self::KIND_VACANCY." AND p.state = ".self::STATE_MOVED_TO_VACANCY.")";
		}
		      
        switch($kind) {
            case '1':
                $where_kind = " AND p.kind=1 ";
                break;
            case '2':
                $where_kind = " AND (p.kind=2 OR p.kind=7) ";
                break;
            case '3':
                $where_kind = " AND p.kind=4 ";
                break;
            default:

                $uid = get_uid(FALSE);
                
                $where_kind = ($uid == $fid || $is_moder)?'':(($uid <= 0)?' AND (p.kind <> 9)':' AND (p.kind <> 9 OR p.exec_id = ?i OR po.user_id = ?i)');
                if(!empty($where_kind)) $where_kind = $DB->parse($where_kind,$uid,$uid);

                break;
        }
        
        $where .= $trash ? ' AND p.trash = TRUE ' : ' AND (p.trash IS NULL OR p.trash = FALSE) ';
        
        $sql = "SELECT 
                    p.*, 
                    p.exec_id as p_exec_id, 
                    ex.uid as exec_id,
                    ex.login as exec_login, 
                    ex.uname as exec_name, 
                    ex.usurname as exec_surname, 
                    rating_get(ex.rating, ex.is_pro, ex.is_verify, ex.is_profi) as exec_rating, 
                    ex.role as exec_role, 
                    ex.is_profi as exec_is_profi, 
                    ex.is_pro as exec_is_pro, 
                    ex.is_pro_test as exec_is_pro_test, 
                    ex.is_team as exec_is_team,

                    f.fname as logo_name, 
                    f.path as logo_path,
                    bt.thread_id, 
                    bt.messages_cnt-1 as comm_count, 
                    now() as now,
                    
                    s.id as sbr_id, 
                    s.status as sbr_status, 
                    s.is_draft as sbr_is_draft, 
                    s.reserved_id as sbr_reserved_id, 
                    s.emp_id as sbr_emp_id, 
                    s.frl_id as sbr_frl_id,
                    
                    pb.project_id::boolean as is_blocked, 
                    end_date, 
                    win_date, 
                    
                    po.id as offer_id, 
                    (p.closed=true OR COALESCE(p.end_date, 'infinity') < now() OR pb.project_id IS NOT NULL) as ico_closed,

                    (uc.paid_advices_cnt + uc.ops_emp_plus + uc.ops_frl_plus + uc.sbr_opi_plus + uc.tu_orders_plus + uc.projects_fb_plus) AS total_opi_plus,
                    (uc.ops_emp_null + uc.ops_frl_null + uc.sbr_opi_null) AS total_opi_null,
                    (uc.ops_emp_minus + uc.ops_frl_minus + uc.sbr_opi_minus + uc.tu_orders_minus + uc.projects_fb_minus) AS total_opi_minus,

            (SELECT 
              SUM(CASE WHEN p.kind = 7
                THEN CASE WHEN projects_offers.po_emp_read = false
                    THEN projects_offers.emp_new_msg_count + 1
                    ELSE projects_offers.emp_new_msg_count
                    END
                ELSE projects_offers.emp_new_msg_count
                END) 
              FROM projects_offers 
              WHERE p.id=projects_offers.project_id)
             AS new_messages_cnt,

             CASE WHEN p.kind = 7 THEN
                (SELECT ( (emp_new_msg_count > 0)::boolean OR (po_emp_read = false)::boolean )::boolean FROM projects_contest_offers WHERE p.id=projects_contest_offers.project_id ORDER BY projects_contest_offers.po_emp_read DESC LIMIT 1) IS TRUE
             ELSE 
             CASE
               WHEN p.post_date >= '2008-07-17' THEN (SELECT (emp_new_msg_count = 0)::boolean FROM projects_offers WHERE p.id=projects_offers.project_id ORDER BY projects_offers.emp_new_msg_count DESC LIMIT 1) IS FALSE
               WHEN p.post_date >= '2007-01-01' THEN COALESCE(messages_cnt-1,0) > COALESCE((SELECT COALESCE(NULLIF(status,-100),30000) FROM blogs_themes_watch WHERE theme_id = bt.thread_id AND user_id = '$fid'),0)
               ELSE false
              END 
             END AS is_new_offers,
             --���� ��� ������������ ������ �� �������� ������������� ���������
             CASE WHEN p.kind = 9 THEN 
                (
                    SELECT COALESCE(fu.uname,'') || ' ' || COALESCE(fu.usurname,'') || ' [' || fu.login || ']' 
                    FROM projects_offers AS po2
                    INNER JOIN freelancer AS fu ON fu.uid = po2.user_id
                    WHERE po2.project_id = p.id
                    LIMIT 1
                )
             ELSE '' END AS personal_fullname
             {$sel}
            FROM projects p
          LEFT JOIN sbr s ON s.project_id = p.id AND s.is_draft = false AND s.status <> 500 AND s.status <> 600 
          LEFT JOIN freelancer ex ON (ex.uid = COALESCE(s.frl_id, p.exec_id) AND ex.is_banned <> 1::bit)
          LEFT JOIN users_counters uc ON (uc.user_id = ex.uid)
          LEFT JOIN blogs_themes_old bt ON p.id = bt.id_gr AND base IN(3,5)
          LEFT JOIN file_projects f ON f.id = p.logo_id
          LEFT JOIN projects_blocked pb ON pb.project_id = p.id
          LEFT JOIN projects_offers po ON po.project_id = p.id AND po.user_id = p.exec_id
          {$join}
          WHERE p.user_id = '$fid' {$closed} {$where} {$where_kind}
           ORDER BY post_date DESC, p.id DESC {$limit_str}
        ";
		$ret = $DB->rows($sql);
		// ����� �������� � ����������� �������������. ���� Postres ����� ������ 8.4, ��� ��� ���������� ����� ���������� ����� array_agg
		$ids = '';
		$idx = array();
		for ($i=0,$c=count($ret); $i<$c; $i++) {
            if ($ret[$i]['kind'] == 7 && !$ret[$i]['sbr_id']) {
				$ret[$i]['exec_id'] = array();
				$idx[ $ret[$i]['id'] ] = &$ret[$i];
				$ids .= ','.$ret[$i]['id'];
			}
		}
		if ($ids) {
			$sql = "
                SELECT pco.project_id, f.uid, f.login, f.uname, f.usurname, f.photo
				FROM projects_contest_offers AS pco
                JOIN freelancer f ON pco.user_id = f.uid
				WHERE project_id IN (".substr($ids, 1).") AND position IS NOT NULL AND position > 0 
				ORDER BY project_id, position
			";
            $res = $DB->rows($sql);
            if($res) {
    			foreach ($res as $row) {
    				$idx[ $row['project_id'] ]['exec_id'][] = $row;
    			}
            }
		}

        if($ret && !hasPermissions('projects')) {
    		$ids2 = '';
    		$idx2 = array();
    		for ($i=0,$c=count($ret); $i<$c; $i++) {
                if($ret[$i]['kind']==7) {
    				$idx2[ $ret[$i]['id'] ] = &$ret[$i];
    				$ids2 .= ','.$ret[$i]['id'];
                }
   			}
            if($ids2) {
                $sql = "
                    SELECT project_id, COUNT(id) as no_deleted_count 
                    FROM projects_contest_offers
                    WHERE project_id IN (".substr($ids2, 1).") AND is_deleted = false AND user_id != ".intval(get_uid(false))."
                    GROUP BY project_id
                ";
                $res = $DB->rows($sql);
                if($res) {
                    foreach($res as $row) {
                        $idx2[ $row['project_id'] ]['offers_count'] =  $row['no_deleted_count'];
                    }
                }
            }
		}

        return $ret;
    }
    /**
     * ����� ���� �� ������� �� ��������
     *
     * @param integer $id          �� �������
     * @param string  $fieldname   �������� ����
     * @return string �������� ����
     */
    function GetField($id, $fieldname) {
        global $DB;
        $sql = "SELECT $fieldname FROM projects WHERE id=?i";
        $ret = $DB->val($sql, $id);
        return $ret;
    }

    /**
    * ��������� ����� �� ������������ ������ �� ������
    *
    * @param    integer $project_id     ID �������
    * @param    integer $user_id        ID ������������
    * @return   boolean                 true - ������ ���� �� ������ �� ������������, false - ������ ���
    */
    function IsHaveComplain($project_id, $user_id, &$complain = null) {
        global $DB;
        if($complain != null) {
            $sql = "SELECT * FROM projects_complains WHERE project_id=?i AND user_id=?i";
            $complain = $DB->rows($sql, $project_id, $user_id); 
            return (bool) empty($complain);
        } else {
            $sql = "SELECT COUNT(id) FROM projects_complains WHERE project_id=?i AND user_id=?i";
            return (bool) $DB->val($sql, $project_id, $user_id);
        }
    }
    
    /**
     * ���������, ���� �� ��������� ������ �� ������ ����� �������������
     * @global type $DB
     * @param type $project_id
     * @return type
     */
    public function isComplainSent($project_id) 
    {
        global $DB;
        $sql = "SELECT id FROM projects_complains WHERE project_id=?i AND exported = TRUE";
        return (bool)$DB->val($sql, $project_id);
    }
    
    /**
     * ��������� ����� �� ������������ ������ �� ������ ������������� ����
     * 
     * @global type $DB
     * @param type $project_id
     * @param type $user_id
     * @param type $type
     * @return type
     */
    function IsHaveComplainType($project_id, $user_id, $type) {
        global $DB;
        $sql = "SELECT COUNT(id) FROM projects_complains WHERE project_id=?i AND user_id=?i AND type = ?i";
        return (bool) $DB->val($sql, $project_id, $user_id, $type);
    }
    
    public static function updateComplainCounters($update, $project_id, $where = '') {
        global $DB;
        return $DB->update("projects_complains_counter", $update, "project_id = ? {$where}", $project_id);
    }

    /**
     * ��������� ������ �� ������
     *
     * @param    integer $project_id     ID �������
     * @param    integer $user_id        ID ������������
     * @param    integer     $type           ��� ������
     * @param    string      $msg            ����� ������
     * @param    string      $files      ����������� ���������
     * @param    boolean     $exported   ����������� �� ��������� ������� ������ ������� FL
     * @return   string ��������� ������
     */
    function AddComplain($project_id, $user_id, $type, $msg, $files, $exported = false) {
        global $DB;
        $error = '';
        $msg = change_q_new(stripslashes($msg),true,true);
        $sql = "INSERT INTO projects_complains(project_id,user_id,type,msg,files,exported) VALUES(?i,?i,?i,?,?,?)";
        $DB->query($sql, $project_id, $user_id, $type, $msg, $files, $exported);
        
        if ( !$DB->error ) {
            $oMemBuf = new memBuff();
            $oMemBuf->delete( 'complain_projects_count' );
        }
        
        $error = $DB->error;
        return $error;
    }

    /**
     * �������� ������ ������� �� ��������������� (����������� <-> �� �����������)
     *
     * @param integer $fid			uid ������������
     * @param integer $prj_id		id �������
     * @param boolean $st           ������ �������
     * @return string				��������� �� ������
     */
    function SwitchStatusPrj($fid, $prj_id, $st = null){
        global $DB;
        $status = projects::GetStatusPrj($prj_id);
        if($status[1] == 7) {
            $error = "������� � ���������� ������� ���������.";
            return ($error);
        }
        if($st === null) {
            $sql = "UPDATE projects SET closed = NOT closed::bool WHERE (user_id = ?i AND id = ?i AND (end_date IS NULL OR end_date > NOW())) RETURNING kind";
        } else {
            $sql = "UPDATE projects SET closed = " . ( $st === true ? "true" : "false") . " WHERE (user_id = ?i AND id = ?i AND (end_date IS NULL OR end_date > NOW())) RETURNING kind";
        }
        $kind = $DB->val($sql, $fid, $prj_id);
        if(!$kind) {
            $error = '������ �� ������.';
        }
        
        if ( !$DB->error ) {
            if ($status[0] == 't') {
                //������ ����������� ��������, ���������� �� ���������
                $this->addModeration($prj_id);
            } else {
                $this->cancelModeration($prj_id);
            }
            
            $oMemBuf = new memBuff();
            $oMemBuf->delete( 'complain_projects_count' );
        }
        
        return ($error);
    }

    /**
     * ���������� ������ ������� (����������� | �� �����������)
     *
     * @param integer $prj_id		id �������
     * @return string				������ ������� (t - �� �����������, f - �����������)
     */
    function GetStatusPrj($prj_id){
        global $DB;
    	$sql = "SELECT closed, kind FROM projects WHERE id = '$prj_id'";
    	$ret = $DB->query($sql, $prj_id);
        $status = pg_fetch_row($ret);
        return $status;
    }
   

	/**
	 * ����� ������������ ������
	 * 
	 * @param integer $fid    �� ������ �������
	 * @param inetegr $prj_id �� �������
	 * @param integer $force  �������� �������
	 * @return array ������ �������
	 */
    function GetPrj($fid, $prj_id, $force = 0){
        global $DB;
        $sql = "SELECT projects.id, projects.exec_id, projects.status, login, pro_only, uname, usurname, projects.name, cost, anon_id,
         link, currency, descr, kind, projects.payed, projects_blocked.project_id::boolean as is_blocked,
         post_date, no_risk, attach, closed, blogs_themes.thread_id, messages_cnt-1 as comm_count, pro_only, top_to as payed_to, now() as now,
         (projects.closed=true OR COALESCE(projects.end_date, 'infinity') < now() OR projects_blocked.project_id IS NOT NULL) as ico_closed
                FROM projects 
                LEFT JOIN blogs_themes_old as blogs_themes ON projects.id = blogs_themes.id_gr AND blogs_themes.base IN (3,5)
		LEFT JOIN projects_blocked ON projects_blocked.project_id = projects.id
                LEFT JOIN users ON uid = projects.user_id
                WHERE user_id='$fid' AND projects.id = ?i";
        if ($force)
        $sql = "SELECT projects.id, projects.exec_id, projects.status, user_id, name, anonymous.mail as email, anonymous.phone, anonymous.icq, cost, projects.payed, no_risk, kind, 
            currency, descr, post_date, attach, link, anon_id, link, pro_only, closed 
            FROM projects
		LEFT JOIN anonymous ON anonymous.id = anon_id
		 WHERE (projects.id = ?i)";

        $ret = $DB->row($sql, $prj_id);
        return $ret;
    }
	
	/**
	 * ������ ���������� ���������� �� ������� � ������� � ������������.
	 * @param   integer   $fid    id �������
	 * @return  array             ������ � �������
	 */
	function GetProject($fid) {
        global $DB;
		$sql = "
			SELECT
				projects.*, employer.login AS emp_login, employer.uname AS emp_name, employer.usurname AS emp_uname
			FROM
				projects
			JOIN
				employer ON employer.uid = projects.user_id
			WHERE
				projects.id = ?i";

		return $DB->row($sql, intval($fid));
	}
	
    
        
    /**
     * �������� ������ ������� �� ID ����������� � ����
     * 
     * @global type $DB
     * @param type $offer_id
     * @return type
     */
    function getProjectByOfferId($offer_id, $emp_id)
    {
        global $DB;
        
        $sql = "
            SELECT
                p.*,
                po.user_id AS frl_id
            FROM projects AS p
            LEFT JOIN projects_blocked AS pb ON pb.project_id = p.id
            INNER JOIN projects_offers AS po ON po.project_id = p.id
            INNER JOIN freelancer AS f ON f.uid = po.user_id
            WHERE 
                pb.id IS NULL AND 
                f.is_banned = B'0' AND 
                f.self_deleted = FALSE AND 
                po.id = ?i AND
                p.user_id = ?i
        ";
        
        return $DB->row($sql, intval($offer_id), intval($emp_id));
    }






    /**
      * �������� �� ���������� ������ � �������� � ������� ���������
      * 
      * @global object $DB
      * @param int $project_id
      * @return array
      */   
     function getProjectWithFeedback($project_id)
     {
         global $DB;
         
         $sql = "
             SELECT
                p.*,
                e.uid, 
                e.login, 
                e.uname, 
                e.usurname, 
                e.email,
                --COALESCE(efb.id,0) AS emp_feedback_id,
                COALESCE(ffb.id,0) AS frl_feedback_id
             FROM projects AS p
             LEFT JOIN projects_blocked AS pb ON pb.project_id = p.id 
             INNER JOIN employer AS e ON e.uid = p.user_id
             -- ���� ��� ������������� � ���� � ������ ����
             -- LEFT JOIN projects_feedbacks AS efb ON (efb.project_id = p.id AND efb.user_id = p.user_id AND efb.is_emp = TRUE AND efb.deleted = FALSE)
             LEFT JOIN projects_feedbacks AS ffb ON (ffb.project_id = p.id AND ffb.user_id = p.exec_id AND ffb.is_emp = FALSE AND ffb.deleted = FALSE)
             WHERE 
                pb.id IS NULL AND 
                e.is_banned = B'0' AND 
                e.self_deleted = FALSE AND 
                p.id = ?i
        ";
         
        return $DB->row($sql, intval($project_id));
     }







     /**
      * ������� ������������� ������� ������� ���������� 
      * � ������ ���� ������ � ��������� ��������� ������� 
      * 
      * @global type $DB
      * @param string $datefrom
      * @param string $dateto
      * @param int $page
      * @param int $offset
      * @return array
      */   
     function getEmpPrjFeedback($datefrom, $dateto = null, $page = 1, $offset = 1000)
     {
        global $DB;
        
        $where = ($dateto)?"AND p.create_date < '{$dateto}'":"";
        
        $from = $offset;
        $to = ($page-1)*$offset;
        
        $sql = "
            SELECT 
                e.uid,
                e.email,
                e.uname,
                e.usurname,
                e.login
            FROM employer AS e
            LEFT JOIN projects AS p ON p.user_id = e.uid
            LEFT JOIN projects_spam_is_send AS ps ON (ps.user_id = e.uid AND ps.type = 2)
            WHERE
                ps.user_id IS NULL
                AND p.create_date >= '{$datefrom}' $where 
                AND p.kind = 1 
                AND e.anti_uid > 0 
            GROUP BY e.uid
            ORDER BY e.uid            
            LIMIT {$from} OFFSET {$to}";
            
        $res = $DB->query($sql);          
        $ret = pg_fetch_all($res); 
        
        return $ret;       
     }








    /**
     * ������� ����������� �� ������� �������� � ������� ��� �����������
     * �� ������������ �������� ������� � ����������� ������� �����������.
     * 
     * @global type $DB
     * @param string $datefrom
     * @param string $dateto
     * @param int $page
     * @param int $offset
     * @return array
     */
    function getFrlExec($datefrom, $dateto = null, $page = 1, $offset = 1000)
    {
        global $DB;
        
        $where = ($dateto)?"AND p.create_date < '{$dateto}'":"";
        
        $from = $offset;
        $to = ($page-1)*$offset;
        
        $sql = "
          SELECT 
            array_agg(p.id||'||'||p.name) AS projects_list,
            f.uid,
            f.email,
            f.uname,
            f.usurname,
            f.login
          FROM freelancer AS f
          LEFT JOIN projects AS p ON p.exec_id = f.uid
          LEFT JOIN projects_spam_is_send AS ps ON (ps.user_id = f.uid AND ps.type = 1) 
          WHERE
            ps.user_id IS NULL
            AND p.create_date >= '{$datefrom}' $where 
            AND p.exec_id > 0 AND p.kind = 1
          GROUP BY f.uid
          ORDER BY f.uid
          LIMIT {$from} OFFSET {$to}";
        
          
       $res = $DB->query($sql);          
       $ret = pg_fetch_all($res); 
        
       return $ret;
    }


    
    
    /*
    function getPrjByUser($user_id)
    {
       global $DB;
        
       $sql = "
         SELECT
            p.id,
            p.name
         FROM projects AS p
         
    ";
       
       $res = $DB->query($sql);          
       $ret = pg_fetch_all($res); 
        
       return $ret;
    }
    */






    /**
     * ������� ����������� ������ ���� ��� ���������� � ������� 
     * �� ��������� ���������� ������� � ����������� �������������.
     * 
     * @global type $DB
     * @param string $datefrom
     * @param string $dateto
     * @param int $page
     * @param int $offset
     * @return array
     */
    function getFrlOffer($datefrom, $dateto = null, $page = 1, $offset = 1000)
    {
        global $DB;
        
        $where = ($dateto)?"AND p.create_date < '{$dateto}'":"";
        
        $from = $offset;
        $to = ($page-1)*$offset;
        
        $sql = "
          SELECT 
            --array_agg(p.id||'||'||p.name) AS projects_list,
            f.uid,
            f.email,
            f.uname,
            f.usurname,
            f.login
          FROM freelancer AS f
          INNER JOIN projects_offers AS po ON po.user_id = f.uid
          INNER JOIN projects AS p ON po.project_id = p.id
          LEFT JOIN projects_spam_is_send AS ps ON (ps.user_id = f.uid AND ps.type = 0)  
          WHERE
            ps.user_id IS NULL
            AND p.create_date >= '{$datefrom}' $where 
            --AND p.exec_id > 0 
            AND p.kind = 1 
            AND p.exec_id <> f.uid
          GROUP BY f.uid
          ORDER BY f.uid
          LIMIT {$from} OFFSET {$to}";
          
       $res = $DB->query($sql);          
       $ret = pg_fetch_all($res); 
        
       return $ret;
    }





    /**
	 * ����� ����� ������� �� ���������� ����
	 *
	 * @param string  $error       ���� ���������� ������ ���� ����
     * @param boolean $get_specs   ����� �� �������� ������ ������������� ��� ��������
     * @param integer $limit       ������������ �-�� �������� ��� ����������
	 * @return array               ������ �������
	 */        
    function GetNewProjectsPreviousDay(&$error, $get_specs = false, $limit = 0, $order_by_cost = false) 
    {
        global $DB;

        $sql =
        "SELECT 
            p.create_date,
            p.end_date,
            p.cost,
            p.priceby,
            p.currency,
            p.kind, 
            p.name, 
            p.descr, 
            p.id, 
            p.post_date, 
            p.pro_only, 
            p.verify_only, 
            p.videolnk,
            p.urgent, 
            e.login,
            pb.project_id::boolean AS is_blocked,
            p.closed,
            p.state
         FROM projects p
         INNER JOIN employer e ON e.uid = p.user_id AND e.is_banned = '0'
         LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
         WHERE 
            p.post_date >= DATE_TRUNC('day', now() - interval '24 hours')
            AND p.post_date < DATE_TRUNC('day', now())";

        if($order_by_cost){
            $sql .= 'ORDER BY p.cost DESC';
        }else{
            $sql .= 'ORDER BY p.kind, p.post_date DESC';
        }
        
        // ������������ �-�� �������� ��� ������
        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        if( ($prjs = $DB->rows($sql)) && $get_specs ) {
            foreach($prjs as &$prj) {
                $prj['specs'] = new_projects::getSpecs($prj['id']);
            }
        }
        $error = $DB->error;
        return $prjs;
    }    
        

	/**
	 * ����� ����� �������
	 *
	 * @param string  $error       ���� ���������� ������ ���� ����
     * @param boolean $get_specs   ����� �� �������� ������ ������������� ��� ��������
     * @param integer $timeOffset  ���������� ������ �������� ���� �� ������� �������� �������. 0 - ������
     * @param integer $limit ������������ �-�� �������� ��� ����������
	 * @return array ������ �������
	 */
    function GetNewProjects(&$error, $get_specs = false, $timeOffset = 0, $limit = 0) {
        global $DB;
        if ( $timeOffset ) {
            $lOffset = $DB->parse("- interval '?i seconds'", $timeOffset);
            $rOffset = $DB->parse("AND p.post_date <= (now() - interval '?i seconds')", $timeOffset);
        }
        $sql =
        "SELECT p.kind, p.name, p.descr, p.id, p.post_date, p.pro_only, p.verify_only, p.videolnk, p.urgent, e.login
           FROM projects p
         INNER JOIN
           employer e
             ON e.uid = p.user_id
            AND e.is_banned = '0'
          LEFT JOIN projects_blocked pb ON pb.project_id = p.id
          WHERE pb.project_id IS NULL
            /*AND p.moderator_status > 0  */
            AND p.post_date > DATE_TRUNC('hour', now() - interval '24 hours' {$lOffset})
            {$rOffset}
            AND p.closed = false 
            AND p.kind <> 9 
          ORDER BY p.kind, p.post_date DESC";

        // ������������ �-�� �������� ��� ������
        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        if( ($prjs = $DB->rows($sql)) && $get_specs ) {
            foreach($prjs as &$prj) {
                $prj['specs'] = new_projects::getSpecs($prj['id']);
            }
        }
        $error = $DB->error;
        return $prjs;
    }
    
    
    
    
    
    /**
     * ������� ������� �������/��������/��������
     * 
     * @param type $project_id
     * @param type $kind
     * @param type $specs
     * @param type $limit
     * @return type
     */
    public function getSimilarProjects($project_id, $kind, $specs = null, $limit = 20)
    {
        $filter = array(
            'not_project_ids' => array($project_id),
            'active' => 't'
        );
        
        if ($specs && !empty($specs)) {
            foreach ($specs as $spec) {
                $filter['categories'][0][$spec['category_id']] = 0;
            }
        }

        $this->page_size = $limit;
        $num_prjs = 'nenado';
        $prjs = $this->getProjects($num_prjs, $kind, 1, false, $filter, true, false, null, false, null, true);
        
        require_once(ABS_PATH . '/classes/HTML/projects_lenta.php');
        $htmlPrj = new HTMLProjects();
        $htmlPrj->template = "/projects/tpl.lenta.new.php";
        $htmlPrj->hide_paginator = true;
        $htmlPrj->hide_rss = true;
        $prj_content = $htmlPrj->ShowProjects($num_prjs, $prjs, $kind, 1, $filter, false);
        return $prj_content;
    }
    





    /**
	 * ����� ��������, ��� ������ �� ������� �������� (�� �������� �� ������������� � ��)
	 *
	 * @param integer $prj_kind   ������
	 * @param integer $page       ��������
	 * @param integer $from_cache �� ���� ��� ���
	 * @param integer $filter     ������
     * @param inetger $is_ajax    ���� ������� ������� ����� ajax @see JS seo_print();
	 * @return string HTML-code
	 */
    function SearchDB($prj_kind, $page = 1, $from_cache = 0, $filter = NULL, $is_ajax = false, $new = false)
    {
        $pro_last = $_SESSION['pro_last'];
        $edit_mode = hasPermissions('projects');
        $uid = $_SESSION['uid'];
        $page = intval($page);
        if ($page == 0) $page = 1;

        if (!$out_HTML){
            
            $prjs = $this->getProjects($num_prjs, $prj_kind, $page, true, $filter, true, $is_ajax); // ����������� new_projects::getProjects().

            //������� ���������� ������� ��������
            $_SESSION['top_payed'] = 0;
            $_SESSION['hidetopprjlenta_more'] = 0;
            if ($prjs) foreach($prjs as $p) {
                if ($p['top_payed'] > 1970) {
                    $_SESSION['top_payed']++;
                }
                if ( strtotime($p['top_to']) >= time() && !$p['strong_top'] && isset($_COOKIE['hidetopprjlenta']) && $_COOKIE['hidetopprjlenta']==1 && $_COOKIE['hidetopprjlenta_time']<strtotime($p['create_date'])) { $_SESSION['hidetopprjlenta_more'] = 1; }
            }

            if (!$_SESSION['top_payed']) {unset($_SESSION['top_payed']);}

            require_once(ABS_PATH.'/classes/HTML/projects_lenta.php');
            $htmlPrj = new HTMLProjects();
            if($new) $htmlPrj->template = "/projects/tpl.lenta.new.php";
            $out_HTML = $htmlPrj->ShowProjects($num_prjs, $prjs, $prj_kind, $page, $filter, $is_ajax);
        }
        return $out_HTML;
    }

	/**
	 * ������� ����������� �������� (������ �������)
	 *
	 * @param integer $uid     �� ������������
	 * @param integer $blog_id �� �����
	 */
    function DeleteWinner ($uid, $blog_id) {
        global $DB;
        $sql = "SELECT id_gr FROM blogs_msgs LEFT JOIN blogs_themes_old as blogs_themes ON blogs_msgs.thread_id=blogs_themes.thread_id LEFT JOIN blogs_msgs t ON (t.thread_id=blogs_msgs.thread_id AND t.reply_to IS NULL) WHERE t.fromuser_id=?i AND blogs_msgs.id=?i";
        $proj_id = $DB->val($sql, $uid, $blog_id);
        if ($proj_id) {
            $sql = "DELETE FROM winner WHERE blog_id=?i AND proj_id=?i";
            $DB->query($sql, $blog_id, $proj_id);
        }

    }
    
	/**
	 * ��������� ���������� �������� (������ �������)
	 *
	 * @param integer $uid     �� ������������
	 * @param integer $blog_id �� �����
	 */
    function SetWinner($uid, $blog_id){
        global $DB;
        $sql = "SELECT id_gr FROM blogs_msgs LEFT JOIN blogs_themes_old as blogs_themes ON blogs_msgs.thread_id=blogs_themes.thread_id LEFT JOIN blogs_msgs t ON (t.thread_id=blogs_msgs.thread_id AND t.reply_to IS NULL) WHERE t.fromuser_id=?i AND blogs_msgs.id=?i";
        $proj_id = $DB->val($sql, $uid, $blog_id);
        if ($proj_id) {
            $sql = "INSERT INTO winner(blog_id, proj_id) VALUES(?i, ?i)";
            $DB->query($sql, $blog_id, $proj_id);
        }
    }
    
	/**
	 * ��������� ���� �� ���������� � �������� 
	 *
	 * @param integer $prj_id �� �������
	 * @return integer ���� ������ 0 ���, ����� ����� ����������� (1,2,3)
	 */
    function CheckWinner($prj_id){
        global $DB;
        $prj_id = (int) $prj_id;
        $sql = "SELECT projects.post_date, exec_id, kind, position FROM projects LEFT JOIN projects_contest_offers pco ON projects.id = pco.project_id AND position = 1 WHERE projects.id=?i";
        $res = $DB->query($sql, $prj_id);
        $out = 0;
        $post_date = 0;

        if (pg_numrows($res)) {
            list($post_date, $exec_id, $kind, $position) = pg_fetch_row($res);
            if (is_new_prj($post_date)) {

                $sql = "SELECT blog_id FROM winner WHERE proj_id=?i";
                $out = intval($DB->val($sql, $prj_id));
            } else if ($kind == 7) {
				$out = (int) $position;
			} else {
                $out = ($exec_id > 0) ? 1 : 0;
            }
        }
        return $out;
    }
    
	/**
	 * ���������� ��������� � �������, � ������ ����� � ������ �� ����� ������ ������ ��������� ������� �����
	 *
	 * @param integer $bill_id �� ��������
	 * @param integer $uid     �� ������������
	 * @return string ���� � ������ �� ����� ������ ������ ��������� ������� �����
	 */
    function GetOrderInfo($bill_id, $uid){
        global $DB;
        $sql = "SELECT * FROM projects WHERE billing_id=?i AND user_id=?i";
        $row = $DB->row($sql, $bill_id, $uid);
        if ($row) {
            $out = "� ".date("d.m.Y | H:i",strtotimeEx($row['top_from']))." �� ".date("d.m.Y | H:i",strtotimeEx($row['top_to']));
        }
        return $out;
    }
    
	/**
	 * ��������� ������ �� ������� �� ����������� ����� ��������� ������ - "������ ��� ���"
	 *
	 * @param integer $thread �� ������
	 * @return string ������ �� ���� pro_only
	 */
    function CheckProOnly($thread){
        global $DB;
        $sql = "SELECT pro_only FROM blogs_themes_old as blogs_themes LEFT JOIN projects ON id_gr=projects.id WHERE thread_id = ?i";
        $out = $DB->val($sql, $thread);
        return $out;
    }
    
	/**
	 * ������� �������� ��� �������� ��������
	 *
	 * @param integer $uid  �� ������������
	 * @param integer $opid �� ��������
 	 * @return integer
	 */
    function DelByOpid($uid, $opid)
    {
//        $sql = "SELECT id FROM projects WHERE billing_id = '$opid'";
//        @todo: ����� �������� ��������� � ��
//        list($prj_id) = pg_fetch_row($res);
//        if($prj_id)
//            $this->DeletePublicProject($prj_id, $uid, true);
        return 0;
    }
    
	/**
	 * ����� ��� �������� �� �������
	 * 
	 * @param integer $prj_id �� �������
	 * @return array
	 */
    function getAllAttach($prj_id)
    {
        global $DB;
        $ret = NULL;
        $sql = 
        "SELECT pa.*, f.fname as name, f.path, f.size, f.virus
           FROM project_attach pa
         INNER JOIN
           file_projects f
             ON f.id = pa.file_id
          WHERE pa.project_id = ?i";
        $res = $DB->rows($sql, $prj_id);
        if($res) {
            foreach ($res as $row) {
                $row['ftype'] = CFile::getext($row['name']);
                $ret[$row['id']] = $row;
            }
        }
        return $ret;
    }

	/**
	 * ������� ������
	 *
	 * @param integer $id      �� �������
	 * @param integer $user_id �� ������
	 * @param integer $force   ������, �������� �� �������� ������
	 * @return boolean ���������� true ���� �������� ������ �������, ����� false
	 */
    function DeletePublicProject($id, $user_id, $force=false ) {
        global $DB;

        if (!$force) {
            $uid=$this->GetField($id,'user_id');
            if ($uid!=$user_id) { return false; }
        }

        $attach = $this->getAllAttach($id);

        $sql ="DELETE FROM projects WHERE id=?i ".($force ? '' : "AND user_id='{$uid}'" )." RETURNING kind";
        if(($res = $DB->query($sql, $id)) && pg_num_rows($res)) {
            if($attach) {
                $cfile = new CFile();
                foreach($attach as $a)
                    $cfile->Delete(0, $a['path'], $a['name']);
            }
        }
        return true;
    }
    
    
    /**
     * ��������� ��������� ����� ���������� �������� �� 24 ���� ��� ����� �������������
     * 
     * @param int $uid ID ������������
     * @param int $limit ����� �� ��������
     * @return int|bool ����� ����� ����� �������� ���������� ��� FALSE ���� ��� ��� ������
     */
    function isProjectsLimit($uid = null, $limit = 2){
        global $DB;

        if(!$uid && isset($this->_uid) && $this->_uid > 0) 
        {
            $uid = $this->_uid;
        }
        
        $sql = "
            SELECT 
                p.create_date  
            FROM projects AS p 
            INNER JOIN employer AS e ON e.uid = p.user_id 
            -- LEFT JOIN projects_blocked AS pb ON pb.project_id = p.id
            WHERE 
                -- pb.project_id IS NULL AND 
                e.is_pro = FALSE AND 
                p.kind = 1 AND 
                p.create_date > NOW() - interval '24 hours' AND 
                p.user_id = ?i 
            ORDER BY
                p.post_date
            LIMIT ?i
        ";

        $rows = $DB->rows($sql,$uid,$limit);
        return (count($rows) < $limit)?FALSE:strtotime('+ 24 hours',strtotime($rows[0]['create_date']));
    }






    /**
     * @deprecated
	 * ����� ���������� ��������, �������������� ������� � ������������ ����
	 *
	 * @param string  $post_date ����
	 * @param integer $kind      ��� ������� (1 - �������, 2 - ��������, 4 - � ����, 7 - ����� ��������)
	 * @param integer $payed     ������� �������� ������� � ��������� ��������� �������
	 * @return integer �� �������
	 */
    function CountProject ($post_date, $kind, $payed=0, $project_id = null) {
        global $DB;
        if($kind == 2 || $kind == 7) {
            $skind = "AND kind IN('2', '7')";
        } elseif ($kind) {
            $skind = "AND kind = '{$kind}'";
        } else {
            $skind = "";
        }
        $strong_top = '';
        $project_id = (int) $project_id;
        if ( $project_id ) {
            $strong_top = $DB->val("SELECT strong_top FROM projects WHERE id = $project_id");
            if ( $strong_top ) {
                $strong_top = " AND ( strong_top = 1 ) ";
                if ( !$payed ) {
                    $strong_top .= " AND payed = 0";/*��� ��� ������ �� ���� ������� �� ������� ����������� ��������� ��� ������ ������� ������������� � ��� ������-�� ���� �����������, ������������ ����� ��� ������*/
				}
            } else {
                $strong_top = '';
            }
        }
        $sql="SELECT COUNT(projects.id) FROM projects WHERE projects.closed=false {$skind} AND ((projects.post_date >= '".$post_date."') ".($payed ? "AND" : "OR")."  (   (top_from < now() AND top_to > now())  )) {$strong_top}";
        //print $sql;
        $count = intval($DB->val($sql));
        /*��� ��� ������ �� ���� ������� �� ������� ����������� ��������� ��� ������ ������� ������������� � ��� ������-�� ���� �����������, ������������ ����� ��� ������*/
        if ( $strong_top && $payed) {
            $sql="SELECT COUNT(projects.id) FROM projects WHERE projects.closed=false {$skind} {$strong_top} AND payed = 0";
            $strong_count = intval($DB->val($sql));
            $count += $strong_count;
        }
        return $count;
    }
    
    /**
     * ��������� ��������� ������� �� ����� �������, ���� �� �������
     * @param $post_date - ���� ���������� �������
     * @param $kind - ��� �������
     * @param $top_from - ���� ������ �����������
     * @param $top_to - ���� ��������� �����������
     * @param $strong_top - �������������������
     */
    function CountProjectNew ($post_date, $kind = null, $top_from = null, $top_to = null, $strong_top = null) {
        global $DB;
        
        if ($kind == 2 || $kind == 7) {
            $skind = "AND kind IN('2', '7')";
        } elseif ($kind) {
            $skind = "AND kind = '{$kind}'";
        } else {
            $skind = "";
        }
        
        $join = "LEFT JOIN projects_blocked AS pb ON pb.project_id = projects.id";
        $join_where = "AND pb.project_id IS NULL";
        
        $mainWhere = "projects.closed = false " . $skind . $join_where;
       
        // ���������� �������� � ����� ������ ����� ����� ������:
        // �������� ������� ���� ������ ������� � ����������� �� ����������� ���� ����������
        // ��������� ������������ �� �������� ���� �����������
        // ��� ��������� ������� �� �������� ���� ����������

        // ��������� ��� ������������ ������, ��� �������-������������, ��� �������
        if ($strong_top) { // ���� �������-������������, �� ��������� ������� ����� �������-������������
            $sql = "
                SELECT COUNT(projects.id)
                FROM projects
                {$join}
                WHERE $mainWhere
                    AND strong_top = 1
                    AND projects.post_date <= '" . $post_date . "'";
        } elseif (!$strong_top && strtotime($top_to) > time()) { // ���� ������ ������ ������������, ������� ������� ����� ��������, �������� ��� ����� ���� ��� �������-������������
            $sql = "
                SELECT COUNT(projects.id)
                FROM projects
                {$join}
                WHERE $mainWhere
                    AND (
                        strong_top = 1
                        OR (
                            now() BETWEEN top_from AND top_to
                            AND top_from >= '" . $top_from . "'
                        )
                    )";
        } else { // ���� ������� ������
            $sql = "
                SELECT COUNT(projects.id)
                FROM projects
                {$join}
                WHERE $mainWhere
                    AND (
                        strong_top = 1
                        OR now() BETWEEN top_from AND top_to
                        OR post_date >= '" . $post_date . "'
                    )";
        }
        $count = intval($DB->val($sql));
        
        
        return $count;
    }
    
    
    /**
     * ��������� ��������� ������� � ����� �� ��������������� ������� �� ��� ID
     * @param type $project_id
     */    
    function CountProjectByID ($project_id) {
        global $DB;
        if (!$project_id) {
            return false;
        }
        
        $prj = $DB->row("SELECT kind, top_from, top_to, strong_top, post_date FROM projects WHERE id = ?i", (int)$project_id);
        if (!$prj) {
            return false;
        }
        
        return projects::CountProjectNew($prj['post_date'], $prj['kind'], $prj['top_from'], $prj['top_to'], $prj['strong_top']);

    }
    
    
    /**
     * ���������� ���� ��������
     *
     * @param integer $uid          �� ������������
     * @param boolean $inc_blocked  ���� ������ ���������� � �������� true, �� �� ����� ��� ������� ��� ����������, ����� �� ����� ��������������� �������
     * @param integer $kind         ��� ������� (0 - ���, 1 - �������, 2 - ��������, 3 - � ����)
     * @param integer $folder       ID �����
     * @param integer $filter       ������ �������
     * @return array                ���-�� ��������: kind_all - ������� ���� �����, kind_prj - �������(�������), kind_contest - ��������, kind_office - �������(� ����), open - �������� ��������(� ������ �������), closed - ��������(� ������ �������), all - �������� � ��������(� ������ �������)
     */
    function CountMyProjects($uid, $inc_blocked=true, $only_open = false, $kind=0, $folder=-1, $filter=null) {
        global $DB;
        $filterSql = new_projects::createFilterSql($filter);

        $closed = "(closed = true OR COALESCE(end_date, 'infinity') < now() OR blocked.project_id IS NOT NULL)";
        $not_closed = "(closed = false AND COALESCE(end_date, 'infinity') > now() AND blocked.project_id IS NULL)";
        switch($kind) {
            case '1':
                $where_kind = ' AND kind=1 ';
                break;
            case '2': 
                $where_kind = ' AND (kind=2 OR kind=7) ';
                break;
            case '3':
                $where_kind = ' AND kind=4 ';
                break;
            default:
                
                //$where_kind = (!$inc_blocked)?' AND kind <> 9':'';
                
                $_uid = get_uid(FALSE);
                
                $where_kind = ($inc_blocked)?'':(($_uid <= 0)?' AND (p.kind <> 9)':' AND (p.kind <> 9 OR p.exec_id = ?i)');
                if(!empty($where_kind)) $where_kind = $DB->parse($where_kind,$_uid);
                
                
                break;
        }
        
        $nFolderId    = intval($folder);
        $where_folder = ( $nFolderId >= 0 ) ? ' AND p.folder_id = ' . $nFolderId : '';

        if ($inc_blocked) {
            $sql = "select(select count(p.id) from projects as p 
                left join projects_blocked as blocked on blocked.project_id = p.id 
                WHERE (p.trash IS NULL OR p.trash = FALSE) 
                AND user_id='".$uid."' {$where_kind} $where_folder {$filterSql} AND {$not_closed}) as open";
            if(!$only_open) {
                $sql .= ",
                (
                    select count(p.id) from projects as p 
                    left join projects_blocked as blocked on blocked.project_id = p.id 
                    where (p.trash IS NULL OR p.trash = FALSE) AND 
                    user_id='".$uid."' {$where_kind} $where_folder {$filterSql} AND {$closed}
                ) as closed, 
                (
                    select count(p.id)  from projects as p 
                    left join projects_blocked as blocked on blocked.project_id = p.id 
                    where (p.trash IS NULL OR p.trash = FALSE) AND 
                    user_id='".$uid."' {$where_kind} $where_folder {$filterSql}
                ) as all;
                ";
            }
		} else {
            
            $_where = " AND NOT(p.payed = 0 AND p.kind = ".self::KIND_VACANCY." AND p.state = ".self::STATE_MOVED_TO_VACANCY.")";
            
            $sql = "select(select count(p.id) from projects as p 
                left join projects_blocked as blocked on blocked.project_id = p.id 
                where (p.trash IS NULL OR p.trash = FALSE) 
                AND user_id='".$uid."' {$where_kind} $where_folder {$filterSql} AND {$not_closed} 
                AND blocked.project_id IS NULL {$_where}) as open";
            if(!$only_open) {
                $sql .= ",
                (
                    select count(p.id) from projects as p 
                    left join projects_blocked as blocked on blocked.project_id = p.id 
                    where (p.trash IS NULL OR p.trash = FALSE) 
                    AND user_id='".$uid."' {$where_kind} $where_folder {$filterSql} AND {$closed} 
                    AND blocked.project_id IS NULL {$_where}
                ) as closed, 
                (
                    select count(p.id) from projects as p 
                    left join projects_blocked as blocked on blocked.project_id = p.id 
                    where (p.trash IS NULL OR p.trash = FALSE) 
                    AND user_id='".$uid."' {$where_kind} $where_folder {$filterSql} AND blocked.project_id IS NULL {$_where}
                ) as all;
                ";
            }
		}
        
        $ret = $DB->row($sql);
        if ($inc_blocked) {
            $sql = "SELECT
                        COUNT(id) as kind_all,
                        SUM(CASE WHEN kind=1 THEN 1 ELSE 0 END) as kind_prj,
                        SUM(CASE WHEN (kind=2 OR kind=7) THEN 1 ELSE 0 END) as kind_contest,
                        SUM(CASE WHEN kind=4 THEN 1 ELSE 0 END) as kind_office
                    FROM projects
                    WHERE (projects.trash IS NULL OR projects.trash = FALSE) AND projects.user_id=?i";
        } else {
            $sql = "SELECT
                        COUNT(projects.id) as kind_all,
                        SUM(CASE WHEN kind=1 THEN 1 ELSE 0 END) as kind_prj,
                        SUM(CASE WHEN (kind=2 OR kind=7) THEN 1 ELSE 0 END) as kind_contest,
                        SUM(CASE WHEN kind=4 THEN 1 ELSE 0 END) as kind_office
                    FROM projects
                    LEFT JOIN projects_blocked as blocked ON blocked.project_id = projects.id
                    WHERE 
                        (projects.trash IS NULL OR projects.trash = FALSE)
                        AND projects.user_id=?i AND blocked.project_id IS NULL 
                        AND NOT(projects.payed = 0 AND projects.kind = ".self::KIND_VACANCY." AND projects.state = ".self::STATE_MOVED_TO_VACANCY.")";
        }
        $ret1 = $DB->row($sql, $uid);
        
        $sql = "SELECT COUNT(id) as trash 
            FROM projects
            WHERE trash = TRUE AND projects.user_id=?i";
        $ret2 = $DB->row($sql, $uid);
        
        $ret = array_merge($ret, $ret1, $ret2);
        
        return $ret;
    }
    
    /**
     * ������� ��� ������� ������������� ������������
     *
     * @param integer $uid �� ������������
     */
    function CloseAllUserPrj($uid) {
        $sql="UPDATE projects SET closed=true WHERE user_id='".$uid."' ";
    }
    
	/**
	 * ����� ��� ������ �� �������
	 *
	 * @param integer $prj_id �� �������
	 * @return array
	 */
    function GetPrjCust($prj_id)
    {
        global $DB;
        if (hasPermissions('projects')) {
            $sel  = ", admins.login as admin_login, admins.uname as admin_name, admins.usurname as admin_uname";
            $join = "LEFT JOIN users AS admins ON admins.uid = b.admin";
        }

        if (isset($_SESSION['role']) && substr($_SESSION['role'], 0, 1) === '0') {
            $favorites_query = 'LEFT JOIN projects_favorites pf ON pf.pid = p.id AND pf.uid = ' . intval($_SESSION['uid']);
            $favorites_field = ', pf.pid as in_favorites';
        } else {
            $favorites_query = '';
            $favorites_field = '';
        }

        $sql = "SELECT p.*, city.city_name, country.country_name,
            (COALESCE(p.payed,0)<>0) as ico_payed, p.top_from as payed_from, p.top_to as payed_to, -- ��� �������������
            u.uid, u.login, u.uname, u.usurname, u.email, u.photo, u.photosm, u.is_pro, u.warn, u.role, u.is_banned, u.ban_where, u.is_team, u.reg_date, u.modified_time, u.is_verify, u.self_deleted, u.photo_modified_time, 
            b.project_id::boolean AS is_blocked, b.admin as blocked_admin, b.reason as blocked_reason, b.blocked_time,
            blogs_themes.thread_id, link,  NULL  AS category, messages_cnt-1 as comm_count, offers_count, now() as now,
            s.id as sbr_id, s.emp_id as sbr_emp_id, s.frl_id as sbr_frl_id, sbr_meta.completed_cnt, s.status as sbr_status,
            (p.closed=true OR COALESCE(p.end_date, 'infinity') < now() OR b.project_id IS NOT NULL) as ico_closed,
            NULL as category_name $sel $favorites_field
        FROM projects AS p 
        LEFT JOIN blogs_themes_old as blogs_themes ON p.id = blogs_themes.id_gr
        LEFT JOIN projects_blocked AS b ON b.project_id = p.id
        $favorites_query
        LEFT JOIN city ON city.id = p.city
        LEFT JOIN country ON country.id = p.country
        $join
        LEFT JOIN users AS u ON u.uid = p.user_id
        LEFT JOIN sbr s ON s.project_id = p.id AND s.is_draft = false
        LEFT JOIN sbr_meta ON sbr_meta.user_id = p.user_id
                WHERE (p.id = ?i)";

        return $DB->row($sql, $prj_id);
    }

    /**
     * ��������� ��� ������� ������ � ��������� ��� ������������
     *
     * @param integer $uid    ������������� ������������
     * @param integer $pid    ������������� �������
     * @return integer        ��������� - ������� ��������� (1) ��� ������� (2)
     */
    function changePrjFavState($uid, $pid)
    {
        global $DB;

        $table = 'projects_favorites';
        $ret = $DB->row("SELECT 1 FROM {$table} WHERE pid = ?i AND uid = ?i LIMIT 1", $pid, $uid);
        if (is_array($ret) && count($ret) > 0) {
            $sql = "DELETE FROM {$table} WHERE pid = ?i AND uid = ?i";
            $ret = 2;
        } else {
            $sql = "INSERT INTO {$table} VALUES (?i, ?i)";
            $ret = 1;
        }
        $DB->query($sql, $pid, $uid);

        return $ret;
    }

	/**
	 * ����� ��� ������ �� ������� �� �������
	 *
	 * @param integer $prj_id �� �������
	 * @return array
	 */
    function GetPrjHistory($prj_id) {
        global $DB;
        $sql = "SELECT p.*, city.city_name, country.country_name 
                FROM projects_history AS p 
                LEFT JOIN city ON city.id = p.city
                LEFT JOIN country ON country.id = p.country
                WHERE p.id=?i";
        $project = $DB->row($sql, $prj_id);
        if($project) {
            $project['spec_txt'] = '';
            if($project['specs']) {
                $sp = array();
                $spec_t = preg_split("/,/", $project['specs']);
                foreach($spec_t as $spec_t_item) {
                    $spec_t_i = preg_split("/\|/", $spec_t_item);
                    array_push($sp, array('category_id'=>$spec_t_i[0], 'subcategory_id'=>$spec_t_i[1]));
                }
                $parts = array();
                foreach ($sp as $item){
                    $name = '';
                    if($item['subcategory_id']) $name = professions::GetProfNameWP($item['subcategory_id'], '&nbsp;/&nbsp;');
                    elseif($item['category_id']) $name = professions::GetGroupName($item['category_id']);
                    else continue;
                    $parts[] = $name;
                }
                $project['spec_txt'] = implode('&nbsp;&nbsp;', $parts);
            }
            if($project['files']) {
                $files = preg_split("/,/", $project['files']);
                $project['attach'] = array();
                $month = date('Ym');
                foreach($files as $file) {
                    array_push($project['attach'], array('name'=>$file, 'path'=>'projects/upload/' . $month));
                }
            }
        }
        return $project;
    }
    
    
    /**
     * �������, ���� �� ������, ������������ ���� �������.
     * @param array $prj   ������ �������� �������.
     * @return boolean
     */
    function checkShowTop(&$prj) {
        if(isset($prj['show_top'])) {
            $show_top = $prj['show_top'];
        }
        else if(strtotime($prj['top_to']) > time()) {
            $tops = new_projects::getTopProjects(false, 1);
            $show_top = (strtotime($tops[0]['top_from']) > strtotime($prj['top_from']));
        }
        return ($prj['show_top'] = $show_top);
    }
    
  /**
   * ������������� ����������� �� ������ ������
   *
   * @param integer $prj_id			id �������
   * @param integer $user_id		uid ����������
   * @param integer $emp_id			uid ������������
   * @return string					��������� �� ������
   */
    function SetExecutor($prj_id, $user_id, $emp_id)
    {
        global $DB;
        $prj_id = intval($prj_id);
        $user_id = intval($user_id);
        $sql = "UPDATE projects SET exec_id=?i, exec_date=NOW() WHERE id=?i AND user_id = ?i";
        if(!$DB->query($sql, $user_id, $prj_id, $emp_id))
            $error = '������.';
        
        $mem = new memBuff();
        $mem->delete('prjEventsCnt' . $user_id);
        $mem->delete('prjEventsCntWst' . $user_id);
        
        $this->cancelModeration($prj_id);
        
        if (!isset($error)) {
            require_once(ABS_PATH . '/classes/messages.php');
            messages::setIsAllowed($emp_id, $user_id);
        }
        
        return $error;
    }

   /**
   * ������� ����������� � ������� �������
   *
   * @todo ����� � ����� ����������� �����������.
   * @param integer $prj_id			id �������
   * @param integer $emp_id			uid ������������
   * @return string					��������� �� ������
   */
    function ClearExecutor($prj_id, $emp_id, $force = false)
    {
        global $DB;
        if ($force){
            $sql = "UPDATE projects SET exec_id = NULL WHERE id=?i";
            $DB->query($sql, $prj_id);
            
            $this->addModeration($prj_id);
            
            return "";
        }
        
        $prj_id = intval($prj_id);
        $sql = "UPDATE projects SET exec_id = NULL WHERE id=?i AND user_id = ?i";
        if(!$DB->query($sql, $prj_id, $emp_id))
            $error = '������.';
        
        if (!$error) {
            $this->addModeration($prj_id);
        }
        
        return $error;
    }

    /**
     * ��� ������ �� ������� ���������� �����������
     * 
     * @param type $uid
     */
    static function SetReadAll( $uid ) {
        global $DB;
        $sql  = "UPDATE projects_offers_dialogue pod SET emp_read = TRUE FROM projects p
                 INNER JOIN projects_offers po ON po.project_id = p.id AND po.emp_new_msg_count <> 0
                 WHERE p.user_id = ?i AND pod.po_id = po.id AND pod.emp_read = FALSE;";
        $sql .= 'UPDATE projects_offers po SET po_emp_read = ?b FROM projects p WHERE p.user_id = ?i AND po.project_id = p.id AND po_emp_read = false;';
        $sql .= 'UPDATE projects_contest_offers po SET po_emp_read = ?b, emp_new_msg_count = 0 FROM projects p WHERE p.kind = 7 AND p.user_id = ?i AND po.project_id = p.id AND ( po_emp_read = false OR emp_new_msg_count > 0 )';
        $mem = new memBuff();
        $mem->delete("prjMsgsCnt{$uid}");
        return $DB->query($sql, $uid, true, $uid, true, $uid);
    }
    /**
     * �������� ������ ��� ����������/������������ ��� ����������� �����
     *
     * @param array $prj   ������
     * @param integer $uid   id �����
     * @param boolean $status �������/���������
     *
     * @return boolean   �������?
     */
    function SetRead($prj, $uid, $status = true) {
        global $DB;
        
        $res = $this->incrementViews($prj['id']);
        
        if (!$uid) {
            return $res;
        }
        
        // � ������� �������� projects_watch ������������ ������ ��� ������. � ��������� -- ��� ����.
        if ($prj['user_id'] == $uid || $prj['kind'] == 7) {
            $sql = 'UPDATE projects_watch SET status = ?b, last_view = now() WHERE prj_id = ?i AND user_id = ?i';
            $res = $DB->query($sql, $status, $prj['id'], $uid);
            if ($res && !pg_affected_rows($res)) {
                $sql = 'INSERT INTO projects_watch (prj_id, user_id, status) VALUES (?i, ?i, ?b)';
                $res = $DB->query($sql, $prj['id'], $uid, $status);
            }
        }
        
        if($res) {
            if ($prj['user_id'] == $uid) {
                $sql = 'UPDATE projects_offers SET po_emp_read = ?b WHERE project_id = ?i AND po_emp_read <> ?b';
                $res = $DB->query($sql, $status, $prj['id'], $status);
            } else {
                $sql = 'UPDATE projects_offers SET po_frl_read = ?b WHERE project_id = ?i AND user_id = ?i AND po_frl_read <> ?b';
                $res = $DB->query($sql, $status, $prj['id'], $uid, $status);
            }
        }
        
        $mem = new memBuff();
        $mem->delete('prjEventsCnt' . $uid);
        $mem->delete('prjEventsCntWst' . $uid);
        
        return !!$res;
    }
    
    /**
     * ����������� ������� ����������
     * @todo ��������������, ������ ������� �������� ���������� ����� ��� ����������
     * @param int $project_id
     */
    function incrementViews($project_id)
    {
        $dbstat = new DB('stat');
        $dbstat->query('SELECT view_project(?i)', $project_id);
        
        $memBuff = new memBuff();
        $memBuff->delete(md5(self::getViewsCountSql($project_id)));
        
        // ���������� � ��� �����, �.�. � �������� ���������� 
        // ������������ ��������� �� ������, � ����� ����� �� ����������
        $this->getProjectWatch($project_id);

        return true;
    }

    /**
     * ���������� ���������� �� ������� �� ������� projects_stat (�� stat)
     * 
     * @param integer $prj_id   id �������.
     * @param integer $cache   ����� ����� ���� ����������. ������ 0 -- �� ����������.
     * @return array ������.
     */
    function getProjectWatch($prj_id, $cache = 180) 
    {
        $db = new DB('stat');
        if($cache >= 0) {
           $db->cache($cache);
        }
        return $db->row(self::getViewsCountSql($prj_id));
    }
    
    /**
     * ���������� ���������� ���������� ������� �� ����
     * 
     * @param integer $prj_id   id �������.
     * @return array ������.
     */
    function getProjectViews($prj_id) 
    {
        $data = self::getProjectWatch($prj_id);
        
        return is_array($data) && isset($data['view_cnt']) ? $data['view_cnt'] : 0;
    }
    
    /**
     * ���������� ������ �� ��������� ���������� ����������
     * @param type $project_id
     */
    public static function getViewsCountSql($project_id)
    {
        global $DB;
        return $DB->parse('SELECT * FROM projects_stat WHERE project_id = ?i', $project_id);
    }

    /**
     * ��������� XML ��� Rambler.Job
     * ������ �������� ����� ��������� ������ � �������� �������� (�������, ����� �� �������� ������������).
     * ��������� ������ ����.
     * ��������� ������������� � ������������ ��������.
     *
     * @param array $projects ������ ��������.
     */
    function makeRamblerJobXML($projects) {
        $xml  = '<document-list>';
        $xml .= '<date>' . date() . '</date>';
        $xml .= '<site-url>www.free-lance.ru</site-url>';
        $xml .= '<documents>';

        foreach ($projects as $key => $project) {
            $xml .= '<vacancy>';
            $xml .= '<url>'.HTTP_PREFIX.'www.fl.ru/projects/' . $project['id'] . '</url>';
            $xml .= '<offer-date>' . date($project['add_date']) . '</offer-date>';
            $xml .= '<valid-till>' . date($project['add_date'] + 60 * 60 * 24 * 7) . '</valid-till>';
            $xml .= '<offer>';
            $xml .= '<title>' . $project['name'] . '</title>';
            $xml .= '<description>' . $project['descr']  . '</description>';
            $xml .= '<occupation>' . GetKind($project['kind']) . '</occupation>';
            $xml .= '</offer>';
            if (isset($project['compname']) && !is_empty($project['compname'])) {
                $xml .= '<company>';
                if (isset($project['cityname']) && !is_empty($project['cityname'])) {
                    $xml .= '<city>' . $project['cityname'] . '</city>';
                }
                $xml .= '<name>' . $project['compname'] . '</name>';
                $xml .= '</company>';
            }
            $xml .= '<salary>';
            $xml .= '<currency>' . GetCur($project['cunnercy']) . '</currency>';
            $xml .= '<min>' . $project['cost'] . '</min>';
            $xml .= '<max>' . $project['cost'] . '</max>';
            $xml .= '<salary>';
            /*if ($project['prepay'] == 't') {
                $xml .= '<additional>';
                $xml .= '<prepay>����������</prepay>';
                $xml .= '</additional>';
            }*/
            $xml .= '</vacancy>';
        }
        $xml .= '</documents>';
        $xml .= '</document-list>';
    }

	/**
	 * ����� ���� �������� ����������
	 *
	 * @param integer $fid    �� ����������
	 * @param integer $folder ��� ������� � ����(0 - ��� �������, 1 - �� ���������, 2 - ��������, 3 - �����������, 4 - ��������, 5 - �������, 6 - ����������)
	 * @param integer $offset ������� � �������
	 * @param string  $limit  ����� �������
	 * @param integer $count  ���������� ���������� ������
	 * @return array ������
	 */
    function GetFrlMenuProjects($fid, $folder, $offset = 0, $limit = 'ALL', &$count = -1)
    {
        global $DB;
		$ret = NULL;
        $where = "po.user_id = {$fid} ";
        $c[1] = "COALESCE(p.exec_id,0)<>{$fid}  AND (pco.position = 0 OR pco.position IS NULL) AND NOT(po.refused OR po.selected OR po.frl_refused)"; // unknown
        $c[2] = "COALESCE(p.exec_id,0)<>{$fid} AND po.selected"; // selected
        $c[3] = "COALESCE(p.exec_id,0)={$fid} OR (pco.position > 0 AND pco.position IS NOT NULL)"; // executor
        $c[4] = "po.refused"; // refused
        $c[6] = "po.frl_refused"; // freelancer self refused
		//$c[6] = "p.kind = 7 AND p.end_date < NOW() AND pco.position = 0 AND (SELECT COUNT(*) FROM projects_contest_offers AS pcoi WHERE pcoi.project_id = po.project_id AND pcoi.position = 1) > 0"; // contest end

        if($folder != 5/* && $folder != 6*/) {
            if($folder)
              $where .= 'AND ('.$c[$folder].')';
            $where .= ' AND po.is_waste = false ';
        }
        else {
            $where .= 'AND po.is_waste = true ';
        }

        $colSql = "
          p.*, f.fname as logo_name, f.path as logo_path, NULL as category_name,
          (COALESCE(p.payed,0)<>0) as ico_payed, -- ��� �������������
          e.login, e.uname, e.usurname, e.is_pro, e.email, e.is_team,
          po.msg_count, po.frl_new_msg_count, po.emp_new_msg_count, po.id as offer_id, po.last_emp_activity,
          s.id as sbr_id, p.end_date, p.win_date, pco.position, ce.project_id::bool as contest_end,
          (p.closed=true OR COALESCE(p.end_date, 'infinity') < now() OR pb.project_id IS NOT NULL) as ico_closed,
          CASE 
            WHEN ({$c[1]}) THEN 1
            WHEN ({$c[2]}) THEN 2
            WHEN ({$c[3]}) THEN 3
            WHEN ({$c[4]}) THEN 4 
            WHEN ({$c[6]}) THEN 6 ELSE 0 END as folder
        ";
        
        $joinSql = "
          INNER JOIN
            projects p
              ON p.id = po.project_id
          INNER JOIN
            employer e
              ON e.uid = p.user_id
             AND e.is_banned = '0'
          LEFT JOIN
            projects_contest_offers pco
              ON po.id = pco.id
          LEFT JOIN
            projects_contest_offers ce
              ON ce.project_id = po.project_id
             AND ce.position = 1
          LEFT JOIN
            file_projects f
              ON f.id = p.logo_id
          LEFT JOIN
            projects_blocked pb
              ON pb.project_id = p.id
          LEFT JOIN
            sbr s
              ON s.project_id = p.id
             AND s.emp_id = p.user_id
             AND s.frl_id = {$fid}
             AND s.is_draft = false
        ";
        
        $limit_i = $limit == 'ALL' ? $limit : $limit+$offset;

        $sql = "
          SELECT *
            FROM (
              (SELECT {$colSql}
                 FROM ONLY projects_offers po
               {$joinSql}
                WHERE {$where} AND pb.project_id IS NULL
                ORDER BY po.last_emp_activity DESC
                LIMIT {$limit_i})
              UNION ALL
              (SELECT {$colSql}
                 FROM projects_contest_offers po
               {$joinSql}
                WHERE {$where} AND pb.project_id IS NULL
                ORDER BY po.last_emp_activity DESC
                LIMIT {$limit_i})
            ) as po
           ORDER BY po.last_emp_activity DESC
           LIMIT {$limit} OFFSET {$offset}
        ";
        
        $ret = $DB->rows($sql);

        if($ret) {
            if($count != -1) {
                $sql = 
                "SELECT COUNT(p.id)
                   FROM projects_offers po
                 INNER JOIN
                   projects p
                     ON p.id = po.project_id
                 INNER JOIN
                   employer e
                     ON e.uid = p.user_id
                    AND e.is_banned = '0'
                 LEFT JOIN
                   projects_blocked pb
                     ON pb.project_id = p.id
				 LEFT JOIN
				   projects_contest_offers pco 
				     ON po.id = pco.id
                  WHERE {$where} AND pb.project_id IS NULL";

                $count = $DB->cache(600)->val($sql);
            }
        }
        foreach ($ret as &$row){
            $row['categories'] = self::getProjectCategories($row['id']);
        }
        return $ret;
    }
    
    /**
     * ��������� ������
     *
     * @param integer $project_id  id �������
     * @param string  $reason      �������
     * @param string  $reason_id   id �������, ���� ��� ������� �� ������
     * @param integer $uid         uid �������������� (���� 0, ������������ $_SESSION['uid'])
     * @param boolean $from_stream true - ���������� �� ������, false - �� �����
     * @return int                ID ����������
     */
    function Blocked( $project_id, $reason, $reason_id = null, $uid=0, $from_stream = false ) {
        global $DB;
        require_once $_SERVER['DOCUMENT_ROOT'].'/classes/messages.php';
        if (!$uid && !($uid = $_SESSION['uid'])) return '������������ ����';
        
        if ( !$from_stream ) {
            $this->cancelModeration($project_id);
            $DB->query( 'UPDATE projects SET moderator_status = ?i WHERE id = ?i', $uid, $project_id );
        }
        
        $sql = "INSERT INTO projects_blocked (project_id, \"admin\", reason, reason_id, blocked_time) VALUES(?i, ?i, ?, ?, NOW()) RETURNING id";
        $sId = $DB->val($sql, $project_id, $uid, $reason, $reason_id);

        if(!$from_stream) {
            messages::SendBlockedProject($project_id, $reason);
        }
        
        require_once $_SERVER['DOCUMENT_ROOT'].'/classes/projects_offers_answers.php';
        $off = new projects_offers_answers();
        $off->ReturnAnswers($project_id);
        
        return $sId;
    }
        
    /**
     * ������������ ������
     *
     * @param integer $project_id  id �������
     * @return string ��������� �� ������
     */
    function UnBlocked($project_id) {
        global $DB;
        require_once $_SERVER['DOCUMENT_ROOT'].'/classes/messages.php';
        
        $sql = "DELETE FROM projects_blocked WHERE project_id = ?i";
        $DB->query($sql, $project_id);
        
        if (!$DB->error) {
            messages::SendUnBlockedProject($project_id);
        }
        return $DB->error;
    }
    
    /**
     * �� ������������. ������ admin_log::getAdminReasons( 9 )
     * ���������� ������ ���������������� ������ ��� ���������� �������.
     * 
     * @return array ������ ������ (ID � ��������) ��� ������ ������.
     */
    function getBlockedReasons() {
        global $DB;
        $sql = 'SELECT id, name FROM projects_blocked_reasons ORDER BY id';
        return $DB->rows( $sql );
    }
    
    /**
     * �� ������������. ������ admin_log::getAdminReasonText
     * ���������� ����� ���������� ������� ���������� �������.
     *
     * @param  integer $reasonId ID ������� ���������� �������.
     * @return string ����� ������� ���������� �������.
     */
    function getBlockedReasonText( $reasonId ) {
        global $DB;
        $sql = 'SELECT reason FROM projects_blocked_reasons WHERE id = ?';
        return $DB->val( $sql, $reasonId );
    }
    
    /**
     * �� ������������. ������ admin_log::getAdminReason
     * ���������� ��� ������ ���������� ������� ���������� �������.
     *
     * @param  integer $reasonId ID ������� ���������� �������.
     * @return arrary
     */
    function getBlockedReasonFull( $reasonId ) {
        global $DB;
        $sql = 'SELECT id, name, reason FROM projects_blocked_reasons WHERE id = ?';
        return $DB->row( $sql, $reasonId );
    }
    
    /**
     * �� ������������. ������ admin_log::addAdminReason( 9, $reason_name, $reason_text)
     * �������� ������� ���������� �������.
     *
     * @param  string $name ������� �������� ������� ���������� �������
     * @param  string $reason ������ ����� ������� ���������� �������
     * @return bool true ��� ������ � false ��� �������
     */
    function addBlockedReason( $name = '', $reason = '' ) {
        global $DB;
        $data = compact( 'name', 'reason' );
        
        $DB->insert( 'projects_blocked_reasons', $data );
        
        return (!$DB->error ? true : false);
    }
    
    /**
     * �� ������������. ������ admin_log::updateAdminReason
     * �������� ������� ���������� �������.
     *
     * @param  int $id ID ������� ���������� �������
     * @param  string $name ������� �������� ������� ���������� �������
     * @param  string $reason ������ ����� ������� ���������� �������
     * @return bool true ��� ������ � false ��� �������
     */
    function updateBlockedReason( $id = 0, $name = '', $reason = '' ) {
        global $DB;
        $data = compact( 'name', 'reason' );
        
        $DB->update( 'projects_blocked_reasons', $data, 'id = ?', $id );
        
        return (!$DB->error ? true : false);
    }
    
    /**
     * �� ������������. ������ admin_log::deleteAdminReason
     * ������� ������� ���������� �������.
     *
     * @param  int $id ID ������� ���������� �������
     * @return bool true ��� ������ � false ��� �������
     */
    function deleteBlockedReason( $id = 0 ) {
        global $DB;
        $DB->query( 'DELETE FROM projects_blocked_reasons WHERE id = ?', $id );
        
        return (!$DB->error ? true : false);
    }

    /**
     * ���������� ��������������� �������
     *
     * @param integer  $nums          ���������� ���-�� ��������������� ��������
     * @param string   $error		  ���������� ��������� �� ������
     * @param integer  $page          ����� ��������
     * @param string   $sort          ��� ����������
     * @param string   $search        ������ ��� ������
     * @param integer  $admin         uid ����������, ��������������� ������� �������� ����� ��������
     * @return array				  [[������ � ���������]]
     */
    function GetBlockedPrjs(&$nums, &$error, $page=1, $sort='', $search='', $admin=0) {
        global $DB;
        $limit = $GLOBALS['prjspp'];
        $offset = $limit*($page-1);
        $limit_into = false;
        $count_cahce = false;
        // ����������
        if ($search) {
            switch ($sort) {
                case 'btime':
                    $order = "ORDER BY blocked_time DESC";
                break;
                case 'login':
                    $order = "ORDER BY login";
                break;
                default:
                    $order = "ORDER BY relevant DESC";
                break;
            }
        } else {
            switch ($sort) {
                case 'btime':
                    $order = "ORDER BY projects_blocked.blocked_time DESC";
                    $limit_into = true;
                break;
                case 'login':
                    $order = "ORDER BY login";
                break;
                default:
                    $order = "ORDER BY projects_blocked.project_id";
                    $limit_into = true;
                break;
            }
        }
        $sql = "
            SELECT
                projects.*, NULL AS category_name,
                users.login AS login, users.uname AS uname, users.usurname AS usurname, users.is_pro, users.is_team,
                projects_blocked.project_id, projects_blocked.reason AS blocked_reason, projects_blocked.blocked_time,
                admins.login AS admin_login, admins.uname AS admin_uname, admins.usurname AS admin_usurname
            FROM
            " . ($limit_into? "(SELECT * FROM projects_blocked ".($admin? "WHERE projects_blocked.admin = '$admin'": "")." $order LIMIT $limit OFFSET $offset) AS projects_blocked": "projects_blocked") . "
            JOIN
                projects ON projects_blocked.project_id = projects.id
            LEFT JOIN
                users ON users.uid = projects.user_id
            LEFT JOIN
                users AS admins ON projects_blocked.admin = admins.uid
        " . (($admin && !$limit_into)? "WHERE projects_blocked.admin = '$admin'": "");
        if ($search) {
            $w = preg_split("/\\s/", $search);
            for ($i=0; $i<count($w); $i++) {
                $s .= "(
                    CASE
                    WHEN
                        (LOWER(login) = LOWER('{$w[$i]}') OR LOWER(uname) = LOWER('{$w[$i]}') OR LOWER(usurname) = LOWER('{$w[$i]}') OR LOWER(name) = LOWER('{$w[$i]}')) THEN 2
                    WHEN
                        (LOWER(login) LIKE LOWER('%{$w[$i]}%') OR LOWER(uname) LIKE LOWER('%{$w[$i]}%') OR LOWER(usurname) LIKE LOWER('%{$w[$i]}%') OR LOWER(name) LIKE LOWER('%{$w[$i]}%')) THEN 1
                    ELSE 0
                    END
                ) + ";
                $t .= "(LOWER(login) LIKE LOWER('%{$w[$i]}%') OR LOWER(uname) LIKE LOWER('%{$w[$i]}%') OR LOWER(usurname) LIKE LOWER('%{$w[$i]}%') OR LOWER(name) LIKE LOWER('%{$w[$i]}%')) OR ";
            }
            $s = substr($s, 0, strlen($s) - 3);
            $t = substr($t, 0, strlen($t) - 4);
            $sql  = "SELECT s.*, ($s) AS relevant FROM ($sql) AS s WHERE $t";
            $csql = "
                SELECT COUNT(*) 
                FROM (
                    SELECT users.login, users.uname, users.usurname, projects.name
                    FROM projects_blocked 
                    JOIN projects ON projects.id = projects_blocked.project_id 
                    LEFT JOIN users ON projects.user_id = users.uid
                    " . ($admin? "WHERE projects_blocked.admin = '$admin'": "") . "
                ) AS s
                WHERE $t
            ";
            $nums = $DB->val($csql);
        } else {
            $csql = "SELECT COUNT(1) FROM projects_blocked ".($admin? " WHERE projects_blocked.admin = '$admin'": "");
            $nums = $DB->cache(180)->val($csql);
        }
        $ret = $DB->rows("$sql $order" . ($limit_into? "": " LIMIT $limit OFFSET $offset"));
        foreach ($ret as &$row){
            $row['categories'] = self::getProjectCategories($row['id']);
        }
        return $ret;
    }

    /** 
    * ���������� ������ ����� �� ������
    *
    * @param    integet $prj_id     ������������� �������
    * @return   array               ������ � ��������
    */
    function GetPrjComplains($prj_id) {
        global $DB;
        $prj_id = (int)$prj_id;
        $sql = "SELECT * FROM projects_complains WHERE project_id=?i ORDER BY date DESC";
        return $DB->rows($sql, $prj_id);
    }

    /**
    * ������� ������ �� ������
    *
    * @param  int $complain_id ������������� ������
    * @param  bool $bMemDel ���� ������ �������
    * @return null
    */
    function DeleteComplain( $complain_id, $bMemDel = true ) {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/users.php");
        global $DB;
        $sql = "SELECT p.user_id as emp_id, c.files FROM projects_complains c LEFT JOIN projects p ON p.id=c.project_id WHERE c.id=?i";
        $row = $DB->row($sql, $complain_id);
        $user_id = $row['emp_id'];
        $emp = new users();
        $emp->GetUser($emp->GetField($user_id,$ee,'login')); 
        $login = $emp->login;

        $files_str = $row['files'];
        if($files_str) {
            $files = preg_split("/,/",$files_str);
            if($files) {
                $f = new CFile();
                foreach($files as $file) {
                    $f->Delete(0, "users/".substr($login,0,2)."/".$login."/upload/", $file);
                }
            }
        }
        $complain_id = (int)$complain_id;
        $sql = "DELETE FROM projects_complains WHERE id=?i";
        $DB->query($sql, $complain_id);
        
        if ( $bMemDel && !$DB->error ) {
            $oMemBuf = new memBuff();
            $oMemBuf->delete( 'complain_projects_count' );
        }
    }
    
    /**
    * �������� ������ ������ �� ������
    *
    * @param  int $complain_id ������������� ������
    * @param  bool $status ���� �������
    * @param  bool $bMemDel ���� ������ �������
    * @return null
    */
    protected function SetComplainStatus( $complain_id, $status , $bMemDel = true ) {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/users.php");
        global $DB;
        $sql = "SELECT p.user_id as emp_id, c.files FROM projects_complains c LEFT JOIN projects p ON p.id=c.project_id WHERE c.id=?i";
        $row = $DB->row($sql, $complain_id);
        $user_id = $row['emp_id'];
        $emp = new users();
        $emp->GetUser($emp->GetField($user_id,$ee,'login')); 
        $login = $emp->login;

        $files_str = $row['files'];
        if($files_str) {
            $files = preg_split("/,/",$files_str);
            if($files) {
                $f = new CFile();
                foreach($files as $file) {
                    $f->Delete(0, "users/".substr($login,0,2)."/".$login."/upload/", $file);
                }
            }
        }
        
        $complain_id = (int)$complain_id;
        $sql = "
        	update projects_complains 
        	set is_satisfied = ?b, admin_user_id = ?i, processed_at = now()
        	WHERE id=?i
        	 and is_satisfied is null
        ";
        $DB->query($sql, $status, $_SESSION['uid'], $complain_id);
        //echo $sql; exit;
        if ( $bMemDel && !$DB->error ) {
            $oMemBuf = new memBuff();
            $oMemBuf->delete( 'complain_projects_count' );
        }
    }
    
    /**
    * ������� ��� ������ �� ������
    *
    * @param    $prj_id    ������������� �������
    * @deprecated
    * @return   null
    */
    function DeleteComplains($prj_id) {
        global $DB;
        $sql = "SELECT id FROM projects_complains WHERE project_id=?i";
        $rows = $DB->rows($sql, $prj_id);
        if($rows) {
            foreach($rows as $row) {
                self::DeleteComplain( $row['id'], false );
            }
            
            if ( !$DB->error ) {
                $oMemBuf = new memBuff();
                
                if ( ($nCount = $oMemBuf->get('complain_projects_count')) !== false ) {
                    $nCount = $nCount - 1;
                    $oMemBuf->set( 'complain_projects_count', $nCount, 3600 );
                }
                else {
                    $oMemBuf->delete( 'complain_projects_count' );
                }
            }
        }
    }
    
    /**
    * �������� ��� ����� ������ 
    *
    * @param  int $prj_id ������������� �������
    * @return array
    */
    protected function getNewComplains($prj_id) {
    	global $DB;
    	
        $sql = "
        	SELECT C.id 
        	FROM projects_complains C
        	join projects_complains_types T on C.type = T.id
        	WHERE C.project_id=?i
        	 and C.is_satisfied is NULL
        	 and T.moder = true
        ";
        
        return $DB->rows($sql, $prj_id);
    }

    /**
    * ������������� ����� ������ �� ������
    *
    * @param    $prj_id    ������������� �������
    * @return   null
    */
    public function SatisfyComplains($prj_id) {
        $rows = $this->getNewComplains($prj_id);
    	
        if($rows) {
            foreach($rows as $row) {
                $this->SetComplainStatus( $row['id'], true, false );
            }
            
            if ( !$DB->error ) {
                $oMemBuf = new memBuff();
                
                if ( ($nCount = $oMemBuf->get('complain_projects_count')) !== false ) {
                    $nCount = $nCount - 1;
                    $oMemBuf->set( 'complain_projects_count', $nCount, 3600 );
                }
                else {
                    $oMemBuf->delete( 'complain_projects_count' );
                }
            }
        }
    }

    /**
    * �� ������������� ����� ������ �� ������
    *
    * @param    $prj_id    ������������� �������
    * @return   null
    */
    public function NotSatisfyComplains($prj_id) {
        $rows = $this->getNewComplains($prj_id);
    	
        if($rows) {
            foreach($rows as $row) {
                $this->SetComplainStatus( $row['id'], false, false );
            }
            
            if ( !$DB->error ) {
                $oMemBuf = new memBuff();
                
                if ( ($nCount = $oMemBuf->get('complain_projects_count')) !== false ) {
                    $nCount = $nCount - 1;
                    $oMemBuf->set( 'complain_projects_count', $nCount, 3600 );
                }
                else {
                    $oMemBuf->delete( 'complain_projects_count' );
                }
            }
        }
    }

    /**
     * ���������� ������� � ��������
     *
     * @param integer  $nums          ���������� ���-�� �������� � ��������
     * @param string   $error		  ���������� ��������� �� ������
     * @param integer  $page          ����� ��������
     * @param string   $sort          ��� ����������
     * @param string   $search        ������ ��� ������
     * @param integer  $admin         uid ����������, ��������������� ������� �������� ����� �������� - ������������
     * @param integer  $limit         ���������� ��������� �� ��������
     * @param string   $group         ������, � ������� ��������� -> (new, approved, refused)
     * @return array				  [[������ � ���������]]
     */
    function GetComplainPrjs( &$nums, &$error, $page=1, $sort='', $search='', $admin=0, $limit = 20, $group = 'new' ) {
        global $DB;
        $limit  = intval($limit);
        $offset = $limit*($page-1);
        $limit_into = false;
        $count_cahce = false;
        
        // ����������
        $order = ( $sort == 'login' ? ' login ' : ($search ? ' relevant DESC ' : ' pc.date DESC ') );
        
        $sSelect = ''; // �����
        $sWhere  = ' WHERE closed = false ';
        
        if ($search) {
            $w = preg_split("/\\s/", $search);
            
            for ($i=0; $i<count($w); $i++) {
                $s .= "(
                    CASE
                    WHEN
                        (LOWER(login) = LOWER('{$w[$i]}') OR LOWER(uname) = LOWER('{$w[$i]}') OR LOWER(usurname) = LOWER('{$w[$i]}') OR LOWER(s.name) = LOWER('{$w[$i]}')) THEN 2
                    WHEN
                        (LOWER(login) LIKE LOWER('%{$w[$i]}%') OR LOWER(uname) LIKE LOWER('%{$w[$i]}%') OR LOWER(usurname) LIKE LOWER('%{$w[$i]}%') OR LOWER(s.name) LIKE LOWER('%{$w[$i]}%')) THEN 1
                    ELSE 0
                    END
                ) + ";
                $t .= "(LOWER(login) LIKE LOWER('%{$w[$i]}%') OR LOWER(uname) LIKE LOWER('%{$w[$i]}%') OR LOWER(usurname) LIKE LOWER('%{$w[$i]}%') OR LOWER(pi.name) LIKE LOWER('%{$w[$i]}%')) OR ";
            }
            
            $s = substr($s, 0, strlen($s) - 3);
            $t = substr($t, 0, strlen($t) - 4);
            
            $sSelect .= " ($s) AS relevant ";
            $sWhere  .= " AND $t";
        } else {
            $count_cache = true;
        }
        
        $group_qry = '';
        switch($group) {
        	case 'new':
        		$group_qry = ' AND is_satisfied IS NULL ';
        		break;
        	case 'approved':
        		$group_qry = ' AND is_satisfied = true ';
        		break;
        	case 'refused':
        		$group_qry = ' AND is_satisfied = false ';
        		break;
        }
        
        $sCountQuery = 'SELECT COUNT(pci.pid) AS cnt FROM ( 
                SELECT pi.id AS pid 
                FROM projects_complains pc 
                INNER JOIN projects pi ON pi.id = pc.project_id 
                '. ( $search ? 'INNER JOIN employer e ON e.uid = pi.user_id ' : '' ) . '
                INNER JOIN projects_complains_types pct ON pc.type = pct.id ' .
                $sWhere . ' AND pct.moder = TRUE AND pc.exported != TRUE
                '.$group_qry.'
                GROUP BY pi.id 
            ) AS pci';
        
        $sQuery = 'SELECT p.*, pc.id AS c_id, pc.msg, pc.date, pc.files AS c_files, pc.type, 
                NULL AS category_name, e.login, e.uname, e.usurname, e.is_pro, e.is_team, 
                c.complain_cnt, u.login AS c_login, u.uname AS c_uname, u.usurname AS c_usurname, 
                pc.admin_user_id, pc.is_satisfied, pc.processed_at, a.login as admin_login, a.uname as admin_uname, a.usurname as admin_usurname
            FROM ( 
                SELECT MIN(pci.id) AS min_id 
                FROM projects_complains pci 
                INNER JOIN projects pi ON pci.project_id = pi.id 
                '. ( $search ? 'INNER JOIN employer ei ON ei.uid = pi.user_id '  : '' ) . '
                INNER JOIN projects_complains_types pct ON pci.type = pct.id ' .
                $sWhere . ' AND pct.moder = TRUE AND pci.exported != TRUE
                '.$group_qry.'
                GROUP BY pci.project_id 
            ) AS pco 
            INNER JOIN projects_complains pc ON pc.id = pco.min_id 
            LEFT JOIN users a ON a.uid = pc.admin_user_id 
            INNER JOIN projects p ON pc.project_id = p.id 
            LEFT JOIN employer e ON e.uid = p.user_id 
            LEFT JOIN users u ON u.uid = pc.user_id 
            LEFT JOIN ( 
                -- ���������� ����� �� ������ 
                SELECT MIN(ppc.id) AS min_cnt_id, COUNT(ppc.id) AS complain_cnt 
                FROM projects_complains pcc 
                INNER JOIN projects ppc ON ppc.id = pcc.project_id 
                INNER JOIN projects_complains_types pctt ON pcc.type = pctt.id
                WHERE pctt.moder = TRUE AND pcc.exported != TRUE
                '.$group_qry.'
                GROUP BY ppc.id 
            ) AS c ON c.min_cnt_id = p.id';
        
        $sQuery = ( $sSelect ? "SELECT s.*, $sSelect FROM ($sQuery) AS s " : $sQuery ) 
            . ' ORDER BY ' . $order . " LIMIT $limit OFFSET $offset";
        
        //echo "<pre>$sQuery</pre>"; exit;
        //echo "<br><pre>$sCountQuery</pre>";
        
        $nums = $GLOBALS['DB']->val( $sCountQuery );
            
        $ret = $GLOBALS['DB']->rows( $sQuery );
        
        foreach($ret as &$row){
            $row['categories'] = self::getProjectCategories($row['id']);
        }
        
        return $ret;
    }
    
    /**
     * ���������� ������ ����� �� ������.
     * 
     * @param  int $nPrjId ������������� �������
     * @param  string $group ������, � ������� ��������� -> (new, approved, refused)
     * @return array
     */
    function getProjectComplaints( $nPrjId = 0, $group = 'new' ) {
        $group_qry = '';
        switch($group) {
        	case 'new':
        		$group_qry = ' AND is_satisfied IS NULL ';
        		break;
        	case 'approved':
        		$group_qry = ' AND is_satisfied = true ';
        		break;
        	case 'refused':
        		$group_qry = ' AND is_satisfied = false ';
        		break;
        }
        
        $sQuery = 'SELECT o.*, u.uname, u.usurname, u.login, e.login AS e_login, a.login as admin_login, a.uname as admin_uname, a.usurname as admin_usurname
            FROM projects_complains o 
            LEFT JOIN users a ON a.uid = o.admin_user_id 
            INNER JOIN projects p ON o.project_id = p.id 
            INNER JOIN employer e ON e.uid = p.user_id 
            INNER JOIN users u ON u.uid = o.user_id
            INNER JOIN projects_complains_types pct ON pct.id = o.type
            WHERE o.project_id = ? 
            '.$group_qry.'
            AND pct.moder = TRUE
            ORDER BY o.id';
        
        return $GLOBALS['DB']->rows( $sQuery, $nPrjId );
    }
    
    /**
     * ���������� ���������� ����� ����� �� �������
     * 
     * @param  string $group ������, � ������� ��������� -> (new, approved, refused)
     * @return int
     */
    function GetComplainPrjsCount($group = 'new') {
        $group_qry = '';
        switch($group) {
        	case 'new':
        		$group_qry = ' AND is_satisfied IS NULL ';
        		break;
        	case 'approved':
        		$group_qry = ' AND is_satisfied = true ';
        		break;
        	case 'refused':
        		$group_qry = ' AND is_satisfied = false ';
        		break;
        }
        $oMemBuf = new memBuff();
        
        $nCount  = $oMemBuf->get('complain_projects_count');
        
        if ( $nCount === false ) {
            $sCountQuery = 'SELECT COUNT(pci.pid) AS cnt FROM ( 
                SELECT p.id AS pid 
                FROM projects_complains pc 
                JOIN projects p ON p.id = pc.project_id 
                INNER JOIN projects_complains_types pct ON pct.id = pc.type
                WHERE p.closed = false
                AND pct.moder = TRUE
                AND pc.exported = FALSE
            	'.$group_qry.'
                GROUP BY p.id 
            ) AS pci';
            
            $nCount = $GLOBALS['DB']->val( $sCountQuery );
            
            if ( !$GLOBALS['DB']->error ) {
            	$oMemBuf->set( 'complain_projects_count', $nCount, 3600 );
            }
        }
        
        return $nCount;
    }


    /**
     * ���������� ���-�� ��������������� ��������
     *
     * @return integer
     */    
    function NumsBlockedProjects() {
        global $DB;
        return $DB->cache(180)->val('SELECT COUNT(1) FROM projects_blocked');
    }
    
    /**@todo: ����� �� ��� �� �����������
     * 
     * ����� ������� ��� ��� 
     * 
     * @global type $DB
     * @param type $uid �� ������������ ��� ���������� ��� �������������
     * @return boolean 
     */
    public function getProjectPromo($uid) {
        global $DB;
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/professions.php");
        
        $user_specs = professions::GetProfessionsByUser2($uid, true);
        if($user_specs) {
            $category   = implode(", ", $user_specs['prof_group']);
            $subcategory = implode(", ", $user_specs['prof']);
        }
        
        $sql = "SELECT 
                p.id, p.post_date as create_date, p.cost, p.priceby, p.currency, p.name AS project_name, NULL AS cat_name, NULL AS subcat_name,
                p.kind, p.end_date, p.budget_type,
                co.country_name, ci.city_name, p.descr, e.uname as e_name, e.usurname as e_surname, e.login as e_login   
            FROM projects p
            INNER JOIN employer e ON e.uid = p.user_id 
            LEFT JOIN country co ON co.id = p.country 
            LEFT JOIN city ci ON ci.id = p.city 
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
            WHERE 
                p.pro_only = true 
                AND pb.project_id IS NULL
                AND p.cost > 0
                AND budget_type = 3
                AND p.closed = false
                AND p.kind <> 9 
                AND e.is_banned < 1::bit
                AND p.post_date > DATE_TRUNC('hour', now() - interval '2 day')
                ". (!empty($user_specs) ? (" AND EXISTS (SELECT 1 from project_to_spec WHERE project_id = p.id AND (subcategory_id IN ({$subcategory}) OR ( category_id IN ({$category}) AND subcategory_id = 0 )))") : "")."
            ORDER BY RANDOM() LIMIT 20";
                
        $result = $DB->cache(300)->rows($sql);
        
        if($result) {
            if(count($result) > 3) {
                //�������� ����������� ������ 3 ������� �� �������
                $rnd = array_rand($result, 3);
                return array($result[$rnd[0]], $result[$rnd[1]], $result[$rnd[2]]);
            } else {
                return $result;
            }
        } else {
            return false;
        }
    }
    
    /**
     * ���������, ������������ �� ������
     *
     * @param integer $prj_id �� �������
     * @return boolean
     */ 
    function CheckBlocked($prj_id) {
        global $DB;
        $sql = "SELECT COUNT(*) FROM projects_blocked WHERE project_id = ?i";
        return $DB->val($sql, $prj_id);
    }


   /**
    * ����� ������� � ���������� ������������� ��� �������� �����������
    *
    * ����� ��������� ���� �������, ���������� ������������� ��������� /classes/pgq/mail_cons.php �� �������.
    * ���� ��� �����������, �� �������� ������.
    * @see pmail::ProjectsExecSelected()
    * @see PGQMailSimpleConsumer::finish_batch()
    *
    * @param string|array  $ids  �������������� ��������
    * @param resource      $connect ���������� � �� (���������� � PgQ) ��� NULL -- ������� �����.
    * @return array|mixed  
    */
   function GetExecProjects($ids, $connect=NULL) {
       global $DB;
       if(!$ids) return NULL;
       if(is_array($ids))
           $ids = implode(',', array_unique($ids));

       $sql = "SELECT 
         p.kind, p.name as project_name, p.id as project_id,
         f.usurname, f.uname, f.login, f.email, f.subscr, f.is_banned
           FROM projects p
         INNER JOIN
           freelancer f
             ON f.uid = p.exec_id
          WHERE p.id IN ({$ids})";

       return $DB->rows($sql);
   }

   /**
    * ����� ������� ��� �������� ����������� �� �������
    *
    * ����� ��������� ���� �������, ���������� ������������� ��������� /classes/pgq/mail_cons.php �� �������.
    * ���� ��� �����������, �� �������� ������.
    * @see pmail::ProjectPosted()
    * @see PGQMailSimpleConsumer::finish_batch()
    *
    * @param string|array  $ids  �������������� ��������
    * @param resource      $connect ���������� � �� (���������� � PgQ) ��� NULL -- ������� �����.
    * @return array|mixed
    */
   function getProjects4Sending($ids, $connect=NULL) {
        global $DB;
       if(!$ids) return NULL;
       if(is_array($ids))
           $ids = implode(',', array_unique($ids));

        $sql = "SELECT
                    p.kind, p.name, p.id, p.prefer_sbr,
                    e.usurname, e.uname, e.login, e.email, e.subscr
                FROM projects p
                INNER JOIN employer e
                    ON e.uid = p.user_id
                    AND e.is_banned = '0'
                WHERE p.id IN ({$ids})";

       return $DB->rows($sql);
   }


	/**
	 * ���������� ������������ �������, ������� �������� ������ �����
	 * 
	 * @return array  ������ � �������
	 */
   function getRemindTopProjects() {
		return $GLOBALS['DB']->rows("

			SELECT
				p.*, u.uid, u.login, u.uname, u.usurname, u.email
			FROM
				projects p
			INNER JOIN
				employer u ON p.user_id = u.uid
			LEFT JOIN
				projects_blocked pb ON p.id = pb.project_id
			WHERE
				date_trunc('hour', top_to) = date_trunc('hour', CURRENT_TIMESTAMP + interval '1 day') AND pb.project_id IS NULL
		");
	}



    /**
     * �������� ���������� � ������� �������
     *
     * @param   integer $prj_id         ID �������
     * @param   float   $prj_cost       ���������
     * @param   integer $prj_currency   ������
     * @param   integer $prj_costby     ��� ���������(�� ���/������/�����)
     * @param   boolean $prj_agreement  true - ��������� �� ��������������
     * @return  array                   ���������� � ������� �������
     */
    function updateBudget($prj_id, $prj_cost, $prj_currency, $prj_costby, $prj_agreement) {
        global $DB;
        if($prj_agreement) {
            $prj_cost = 0;
            $prj_currency = 0;
            $prj_costby = 0;
            $prj_budget_type = 0;
        } else {
            $prj_cost = floatval($prj_cost);
            $prj_currency = intval($prj_currency);
            $prj_costby = intval($prj_costby);
            $prj_budget_type = 0;

            require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/professions.php");
            $categories = professions::GetAllGroupsLite();
            $professions = professions::GetAllProfessions();
            array_group($professions, 'groupid');
            $professions[0] = array();
            $professions_cost = array();
            $professions_cost['prj'] = array();
            $professions_cost['hour'] = array();
            $professions_cost['prj']['min'] = array();
            $professions_cost['prj']['avg'] = array();
            $professions_cost['prj']['max'] = array();
            $professions_cost['hour']['min'] = array();
            $professions_cost['hour']['avg'] = array();
            $professions_cost['hour']['max'] = array();
            foreach($categories as $cat) {
                $professions_cost['prj']['min'][$cat['id']] = array();
                $professions_cost['prj']['avg'][$cat['id']] = array();
                $professions_cost['prj']['max'][$cat['id']] = array();
                $professions_cost['hour']['min'][$cat['id']] = array();
                $professions_cost['hour']['avg'][$cat['id']] = array();
                $professions_cost['hour']['max'][$cat['id']] = array();
                $ncount_prj = 0;
                $ncount_hour = 0;  
                $nsum_min_prj = 0;
                $nsum_max_prj = 0;
                $nsum_avg_prj = 0;
                $nsum_min_hour = 0;
                $nsum_max_hour = 0;
                $nsum_avg_hour = 0;
                if(!is_array($professions[$cat['id']])) continue;
                foreach($professions[$cat['id']] as $subcat) {
                    $professions_cost['hour']['min'][$cat['id']][$subcat['id']] = $subcat['min_cost_hour'];
                    $professions_cost['hour']['avg'][$cat['id']][$subcat['id']] = $subcat['avg_cost_hour'];
                    $professions_cost['hour']['max'][$cat['id']][$subcat['id']] = $subcat['max_cost_hour'];
                    $professions_cost['prj']['min'][$cat['id']][$subcat['id']] = $subcat['min_cost_prj'];
                    $professions_cost['prj']['avg'][$cat['id']][$subcat['id']] = $subcat['avg_cost_prj'];
                    $professions_cost['prj']['max'][$cat['id']][$subcat['id']] = $subcat['max_cost_prj'];
                    $nsum_min_prj = $nsum_min_prj + $subcat['min_cost_prj'];
                    $nsum_max_prj = $nsum_max_prj + $subcat['max_cost_prj'];
                    $nsum_avg_prj = $nsum_avg_prj + $subcat['avg_cost_prj'];
                    $nsum_min_hour = $nsum_min_hour + $subcat['min_cost_hour'];
                    $nsum_max_hour = $nsum_max_hour + $subcat['max_cost_hour'];
                    $nsum_avg_hour = $nsum_avg_hour + $subcat['avg_cost_hour'];
                    if($subcat['avg_cost_prj']!=0) $ncount_prj++;
                    if($subcat['avg_cost_hour']!=0) $ncount_hour++;
                }
                if($ncount_prj==0) $ncount_prj = 1;
                if($ncount_hour==0) $ncount_hour = 1;
                $professions_cost['prj']['min'][$cat['id']][0] = round(($nsum_min_prj/$ncount_prj),0);
                $professions_cost['prj']['avg'][$cat['id']][0] = round(($nsum_avg_prj/$ncount_prj),0);
                $professions_cost['prj']['max'][$cat['id']][0] = round(($nsum_max_prj/$ncount_prj),0);
                $professions_cost['hour']['min'][$cat['id']][0] = round(($nsum_min_hour/$ncount_hour),0);
                $professions_cost['hour']['avg'][$cat['id']][0] = round(($nsum_avg_hour/$ncount_hour),0);
                $professions_cost['hour']['max'][$cat['id']][0] = round(($nsum_max_hour/$ncount_hour),0);
            }

            switch($prj_costby) {
               case '1':
                   $itype = 'hour';
                   $ctype = 1;
                   break;
               case '2':
                   $itype = 'hour';
                   $ctype = 8;
                   break;
               case '3':
                   $itype = 'hour';
                   $ctype = 22*8;
                   break;
               case '4':
                   $itype = 'prj';
                   $ctype = 1;
                   break;
            }       
            $sql = "SELECT * FROM project_to_spec WHERE project_id=?i";
            $prj_cats = $DB->rows($sql, $prj_id);
            $count = 1;
            $sum_min = 0;
            $sum_avg = 0;
            $sum_max = 0;
            foreach($prj_cats as $prj_cat) {
                $sum_min = $sum_min + $professions_cost[$itype]['min'][$prj_cat['category_id']][$prj_cat['subcategory_id']];
                $sum_avg = $sum_avg + $professions_cost[$itype]['avg'][$prj_cat['category_id']][$prj_cat['subcategory_id']];
                $sum_max = $sum_max + $professions_cost[$itype]['max'][$prj_cat['category_id']][$prj_cat['subcategory_id']];
                $sum_min = $sum_min / $count;
                $sum_avg = $sum_avg / $count;
                $sum_max = $sum_max / $count;
                $count++;
            }
            $s_min = $sum_min*$ctype;
            $s_avg = $sum_avg*$ctype;
            $s_max = $sum_max*$ctype;

            require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/project_exrates.php");
            $oprj_exrates = new project_exrates();
            $prj_exrates = $oprj_exrates->GetAll();
            switch($prj_currency) {
                case '2':
                    $prj_cost_fm = $prj_cost*$prj_exrates['41'];
                    break;
                case '0':
                    $prj_cost_fm = $prj_cost*$prj_exrates['31'];
                    break;
                case '1':
                    $prj_cost_fm = $prj_cost*$prj_exrates['21'];
                    break;
                default:
                    $prj_cost_fm = $prj_cost*1;
                    break;
            }

            if($prj_cost_fm<=$s_min) {
                $prj_budget_type = 1;
            }
            if(($prj_cost_fm>$s_min && $prj_cost_fm<=$s_avg) || ($prj_cost_fm>=$s_avg && $prj_cost_fm<$s_max)) {
                $prj_budget_type = 2;
            }
            if($prj_cost_fm>=$s_max) {
                $prj_budget_type = 3;
            }
            
        }

        $projectData = self::GetProject($prj_id);
        if (is_array($projectData) && $projectData['kind'] == 7) {
            $prj_costby = 0;
        }

        $sql = "UPDATE projects 
                SET cost = ?,
                    currency = ?i,
                    priceby = ?i,
                    budget_type = ?i
                WHERE id = ?i";
        $DB->query($sql, $prj_cost, $prj_currency, $prj_costby, $prj_budget_type, $prj_id);
        return array('cost'=>$prj_cost, 'currency'=>$prj_currency, 'costby'=>$prj_costby, 'budget_type'=>$prj_budget_type);
    }
    
    /**
     *  ����� ������� ������� ������� � ����� �����
     * @return inetger �� ������� 
     */
    public function getFirstProjectsList() {
        $mbuff = new memBuff();
        $project_id = $mbuff->get('IDfirstProjectList');
        return $project_id;
    }
    
    /**
     * ���������� �� ������� ������� � �����
     * @param integer $project_id  �� �������
     * @return boolean 
     */
    public function setFirstProjectsList($project_id) {
        $mbuff = new memBuff();
        $timeLife = (int)(3600*24*7);
        $set = $mbuff->set('IDfirstProjectList', $project_id, $timeLife);
        return $set;
    }
    
    /**
     * ��������� rss ��� bicotender.ru 
     * @param $date datetime � Y-m-d H:i:s
     */
    static public function bicotenderGenerateRss($date) {
       if (!preg_match("#^[0-9]{4}\-[0-9]{2}\-[0-9]{2}\s[0-9]{2}:[0-9]{2}:[0-9]{2}$#", $date, $m)) {
           $date = date("Y-m-d 00:00:00");
       }
       global $DB;
       $cache_expire = 900;
       $sql =
        "SELECT p.kind, p.name, p.descr, p.id, p.post_date, p.end_date, p.cost, p.currency, p.edit_date, p.exec_id,
                e.login, e.uname, e.usurname, e.compname, e.phone_1 AS phis_phone, e.second_email AS phis_email, e.site,
                fin.form_type, fin._1_inn, fin._2_inn, fin._2_address_fct, fin._1_address, fin._2_phone AS jur_phone, fin._2_email AS jur_email, fin._2_bossname AS boss, fin._2_full_name,
                country.country_name, city.city_name, groups.name AS category, professions.name AS prof
           FROM projects p
         INNER JOIN
           employer e
             ON e.uid = p.user_id
            AND e.is_banned = '0'
          LEFT JOIN projects_blocked pb ON pb.project_id = p.id
          LEFT JOIN sbr_reqv AS fin ON fin.user_id = e.uid
          LEFT JOIN country ON country.id = e.country 
          LEFT JOIN city ON city.id = e.city
          LEFT JOIN project_to_spec AS pts ON pts.project_id = p.id
          LEFT JOIN prof_group AS groups ON pts.category_id = groups.id
          LEFT JOIN professions ON pts.subcategory_id = professions.id
          WHERE pb.project_id IS NULL
            /*AND (p.moderator_status > 0 OR e.is_pro = TRUE)*/
            AND p.post_date >= '{$date}'
            AND p.closed = false       
            AND p.kind = 7
          ORDER BY p.kind, p.post_date DESC";
        $nodes = array();
        $project_exRates = project_exrates::GetAll();
        $translate_exRates = array
        (
            0 => 2,
            1 => 3,
            2 => 4,
            3 => 1
        );
        $rows = $DB->cache($cache_expire)->rows($sql);
        foreach ($rows as $row) {
            $rubprice = preg_replace('/.00$/', '', sprintf("%.2f", round($row['cost'] * $project_exRates[trim($translate_exRates[$row['currency']]) . '4'], 2)));
            $boss = $compname = $row['uname']." ".$row['usurname'];
            $inn = $row['_1_inn'];
            $address = $row['_1_address'];
            $phone   = $row['phis_phone'];
            $email   = $row['phis_email'];
            $url     = $row['site'];
            $postPosition = '';
            $editDate = $row["edit_date"];
            if (!$editDate) {
                $editDate = $row["post_date"];
            }
            if ($row['form_type'] == 2) {
                $compname = $row['_2_full_name'] ? $row['_2_full_name'] : $row['compname'];
                $inn = $row['_2_inn'];
                $address = $row['_2_address_fct'];
                $phone   = $row['jur_phone'];
                $email   = $row['jur_email'];
                $url     = $row['site'];
                $boss    = $row['boss'];
                $postPosition    = '����������� ��������';
            }
            $filesData = self::getAllAttach($row['id']);
            $files = '';
            if (count($filesData)) {
	            $files = array();
	            foreach ($filesData as $file) {
	                $files[] = "<file ID=\"{$file['file_id']}\">
	                    <url>{$file_url}</url>
	                    <name>{$file['name']}</name>
	                    <type>������������</type>
	                    <lastUpdate>{$row['modified']}</lastUpdate>
	                </file>";
	            }
	            $files = "<files>".join("\n", $files)."</files>";
            }
            $offers = '';
            if (count($filesData)) {
	            $offersData = projects_offers::GetPrjOffers($c, $row['id'], 'ALL');
	            $offers = array();
	            foreach ($offersData as $offer) {
	                $winner = "isWinner='1";
	                $status = "�����������";
	                if ($row['exec_id'] != $offer['uid']) {
	                    $winner = "";
	                    if ($offer['refused'] == 't') {
	                        $status = "��������";
	                    } elseif($offer['selected'] == 't') {
	                        $status = "��������";
	                    }
	                }
	                $cost = preg_replace('/.00$/', '', sprintf("%.2f", round($offer['cost_from'] * $project_exRates[trim($translate_exRates[$offer['cost_type']]) . '4'], 2)));
	                $lancer = $offer['uname']." ".$offer['usurname'];
	                $offers[] = "<competitor ID=\"1\" {$winner}>
	                    <name>{$lancer}</name>
	                    <cost>{$cost}</cost>                
	                    <rating>{$offer['rating']}</rating>
	                    <status>{$status}</status>
	                </competitor>";
	            }
	            $offers = "<competitors>".join("\n", $offers)."</competitors>";
            }
            $nodes[] = "    <tender ID=\"{$row['id']}\" editDate = '{$editDate}'>
        <name>{$row['name']}</name>
        <type>�������� �������</type>
        <dateStart>{$row['post_date']}</dateStart>
        <dateStop>{$row['end_date']}</dateStop>
        <text>{$row['descr']}</text>
        <cost>{$rubprice}</cost>
        <country>{$row['country_name']}</country>
        <address>����� ���������� �������</address>
        <field name='{$row['category']}'>
            <subfield>{$row['prof']}</subfield>
        </field>
        <company>
            <name>{$compname}</name>
            <inn>{$inn}</inn>
            <address>{$address}</address>
            <phone>{$phone}</phone>
            <email>{$email}</email>
            <url>{$url}</url>
        </company>

        <contact>
            <name>{$boss}</name>
            <position>{$postPosition}</position>
        </contact>
        {$files}
        {$offers}
    </tender>";
        }
        $tenders = "<?xml version=\"1.0\" encoding=\"utf-8\"?> 
<tenders>".join("\n", $nodes)."</tenders>";
        return iconv("WINDOWS-1251", "UTF-8//IGNORE", $tenders);
    }
    
    /**
     * ������� ����������� �������� ���� � ��� � ������� 2� ���� �� ���� �� ������ ������
     * ��������� ������ 1 ���
     * 
     * @global object $DB 
     */
    static public function autoSetTopProject() {
        global $DB;
        // ������ ������� (������ 7 ����) �� ��������
        $sql = "
            SELECT 
              p.*, u.email, u.subscr, u.login, u.uname, u.usurname, us.key as unsubscribe_key 
            FROM projects p
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id
            INNER JOIN employer u ON u.uid = p.user_id
            INNER JOIN users_subscribe_keys us ON us.uid = u.uid
            WHERE
              pb.id IS NULL AND 
              p.strong_top = 0 AND top_to IS NULL AND
              p.post_date = p.create_date AND 
              p.create_date + interval '2 days' <= now() AND 
              (now() - p.create_date) < '7 days' AND 
              p.offers_count = 0 AND 
              p.closed = false
        ";
        
        $update = "UPDATE projects SET post_date = post_date + interval '2 days' WHERE id IN (?l)";
        
        $update_projects = $DB->rows($sql);
        foreach($update_projects as $prj) {
            $prjid[] = $prj['id'];
        }
        
        if( $DB->query($update, $prjid) ) {
            $smail = new smail();
            $smail->sendAutoSetTopProject($update_projects);
        }
    }
    /**
     * ����� ����� ������� � ��������� �������� �� ��������� �����
     *
     * @param string  $error       ���� ���������� ������ ���� ����
     * @param integer $timeOffset  ���������� ������ �������� ���� �� ������� �������� �������. 0 - ������
     * @return array ������ �������
     */
    function GetNewProjectsWithBudjet(&$error, $timeOffset = 0) {
        global $DB;
        if ( $timeOffset ) {
            $lOffset = $DB->parse("- interval '?i seconds'", $timeOffset);
            $rOffset = $DB->parse("AND p.post_date <= (now() - interval '?i seconds')", $timeOffset);
        }
        $sql =
        "SELECT p.kind, p.name, p.descr, p.id, p.post_date, e.login, p.cost, p.priceby, p.currency, p.pro_only, p.videolnk, p.verify_only
           FROM projects p
         INNER JOIN
           employer e
             ON e.uid = p.user_id
            AND e.is_banned = '0'
          LEFT JOIN projects_blocked pb ON pb.project_id = p.id
          WHERE pb.project_id IS NULL
            /*AND p.moderator_status > 0  */
            AND p.post_date > DATE_TRUNC('hour', now() - interval '96 hours' {$lOffset})
            {$rOffset}
            AND p.closed = false 
            AND p.kind <> 9 
          ORDER BY p.cost DESC, p.kind, p.post_date DESC";

        if( ($prjs = $DB->rows($sql))  ) {
            foreach($prjs as &$prj) {
                $prj['specs'] = new_projects::getSpecs($prj['id']);
            }
        }
        $error = $DB->error;
        return $prjs;
    }
    
    /**
     * �������� �� 30 ����
     * 
     * @return type
     */
    public function countProjectsLastMonth() {
        return 0; // ������ ������
        global $DB;
        $sql = "SELECT COUNT(p.*) 
            FROM projects p
            INNER JOIN employer e
                ON e.uid = p.user_id AND e.is_banned = '0'
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
            WHERE pb.project_id IS NULL
            AND p.create_date >= DATE_TRUNC('day', now() - interval '30 days')";
        return $DB->cache(108000)->val($sql);
    }

    /**
     * ���-�� ����������� �� ��������� �����
     * @todo: ����� join � ��������?
     * 
     * @return type
     */
    public function countOffersLastMonth() {
        global $DB;
        $sql = "SELECT COUNT(po.*) 
            FROM projects_offers po
            INNER JOIN projects p
                ON p.id = po.project_id
            INNER JOIN employer e
                ON e.uid = p.user_id AND e.is_banned = '0'
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
            WHERE pb.project_id IS NULL
            AND po.post_date >= DATE_TRUNC('day', now() - interval '30 days')
            AND po.is_deleted = 'f'";
        return $DB->cache(108000)->val($sql);
    }
    
    /**
     * ������� ������ � ������� � ������ ���������
     * @global type $DB
     * @param int $project_id
     * @param int $stop_words_count ���������� ������������ ����. ������������ ��� ����������
     * � ������� �������� (��� ��������� �������) ������� ����-���� ���������� ��-�� ���������� ������ � �������.
     * ���� ���������� ��������, ����� ����� �������� ������ � �������
     */
    protected function addModeration($project_id, $stop_words_count = 0)
    {
        $projectData = self::GetProject($project_id);
        if ($projectData['kind'] == self::KIND_PROJECT) {
            global $DB;
        
            $DB->insert('moderation', array(
                'rec_id' => $project_id, 
                'rec_type' => user_content::MODER_PROJECTS, 
                'stop_words_cnt' => $stop_words_count
            ));
        }
    }
    
    /**
     * ������� ������ �� ������ ���������
     * @global type $DB
     * @param type $project_id
     */
    protected function cancelModeration($project_id)
    {
        global $DB;
        
        $DB->query('DELETE FROM moderation WHERE rec_id = ?i AND rec_type = ?i;', 
            $project_id, 
            user_content::MODER_PROJECTS
        );
    }
    
    /**
     * �������� ��������� ������� � ������� �� ��������������� (������ <-> ������������)
     *
     * @param integer $uid			uid ������������
     * @param integer $project_id	id �������
     * @param boolean $st           ������ �������
     * @return string				��������� �� ������
     */
    function switchTrashProject($uid, $project_id, $st = null){
        global $DB;
        if($st === null) {
            $sql = "UPDATE projects SET trash = NOT trash::bool WHERE (user_id = ?i AND id = ?i) RETURNING kind";
        } else {
            $sql = "UPDATE projects SET trash = " . ( $st === true ? "true" : "false") . " WHERE (user_id = ?i AND id = ?i) RETURNING kind";
        }
        $kind = $DB->val($sql, $uid, $project_id);
        if(!$kind) {
            $error = '������ �� ������.';
        }
        return ($error);
    }

    
    /**
     * ���� �� � ������������ ������� ������ ����
     * 
     * @global type $DB
     * @staticvar array $_isExistProjects
     * @param type $uid
     * @return type
     */
    public static function isExistProjects($uid)
    {
        global $DB;
        static $_isExistProjects = array();
        $is_owner = isset($_SESSION['uid'])? $_SESSION['uid'] == $uid : false;
        
        if (isset($_SESSION['isExistProjects']) && $is_owner) {
            return $_SESSION['isExistProjects'];
        }
        
        if (isset($_isExistProjects[$uid])) {
            return $_isExistProjects[$uid];
        }
        
        
        $res = $DB->val("SELECT 1 FROM projects WHERE user_id = ?i", $uid);
        $_isExistProjects[$uid] = $res?true:false;
        
        
        if ($is_owner) {
            $_SESSION['isExistProjects'] = $_isExistProjects[$uid];
        }
        
        return $_isExistProjects[$uid];
    }
    
    
    /**
     * ��� �� ���� ��� ��������� ��������� ������������ � ������� ���������� ���������
     * 
     * @global type $DB
     * @param type $frl_id
     * @param type $emp_id
     * @return type
     */
    public static function isExec($frl_id, $emp_id)
    {
        global $DB;
        return $DB->val("SELECT 1 FROM projects WHERE exec_id = ?i AND user_id = ?i LIMIT 1", $frl_id, $emp_id);
    }
    
    
}

/**
 * ����� ��� ����� ������� ��������� ��� ������� ������ projects
 *
 */
class new_projects extends projects
{
    /**
     * ������������ ������ �������� �����
     *
     */
	const MAX_FILE_SIZE     = 5242880;
	
	/**
	 * �������������� ���� ����� ��� �� ��� �������������
	 *
	 */
	const PRICE_ADDED = 300;
	
	/**
	 * ���� ���� �������� � ����
	 *
	 */
    const PRICE_TOP1DAY     = 1200;//700;
    
    /**
	 * ���� ���� �������� � ���� ��� ���
	 *
	 */
    const PRICE_TOP1DAYPRO     = 1000;//750;
    
    /**
	 * ���� ���� ��������� � ����
	 *
	 */
    const PRICE_CONTEST_TOP1DAY = 1200;//700;
    /**
	 * ���� ���� ��������� � ���� ��� PRO
	 *
	 */
    const PRICE_CONTEST_TOP1DAY_PRO = 1000;//750;
    
    /**
     * ���� �� ���� ����
     *
     */
    const PRICE_COLOR       = 600;
    
    /**
     * ���� �� ��������� ������
     *
     */
    const PRICE_BOLD        = 300;

    /**
     * ���� �� ������� ������
     *
     */
    const PRICE_URGENT      = 300;

    /**
     * ���� �� ������� ������ 
     *
     */
    const PRICE_HIDE        = 300;
    
    /**
     * ���� �� �������
     *
     */
    const PRICE_LOGO        = 600;//400;

    /**
     * ���� �� ������� ��� �����
     *
     */
    const PRICE_LOGO_NOPRO  = 900;//600;
        
    /**
     * ������ �� �������
     *
     */
    const PAYED_IDX_LOGO    = 0;
    
    /**
     * ������ �� ����
     *
     */
    const PAYED_IDX_COLOR   = 1;
    
    /**
     * ������ �� ��������� ������
     *
     */
    const PAYED_IDX_BOLD    = 2;
    
    /**
     * ������ �� ���������� ������� "� ����"
     */
    const PAYED_IDX_OFFICE = 4;
    
    /**
     * ��� �������� ������ �������� (op_codes)
     *
     */
    const OPCODE_KON        = 9;

    /**
     * ��� �������� ������ ������� � ���� (op_codes)
     *
     */
    const OPCODE_PRJ_OFFICE        = 113;
    
    /**
     * ��� �������� ������ ������� � ���� �� ��� (op_codes)
     *
     */
    const OPCODE_PRJ_OFFICE_PRO    = 192;
    
    /**
     * ��� �������� �������� � ��������� ����� (op_codes)
     *
     */
    const OPCODE_KON_BNS    = 9;
    
    
    /** 
     * ��� �������� ������ �������� (op_codes) (�� ���)
     *
     */
    const OPCODE_KON_NOPRO        = 106;
    
    /**
     * ��� �������� �������� � ��������� ����� (op_codes) (�� ���)
     *
     */
    const OPCODE_KON_BNS_NOPRO    = 106;

    /**
     * ��� �������� ������ �������� �������� (op_codes)
     *
     */
    const OPCODE_PAYED_KON        = 86;
    
    /**
     * ��� �������� �������� �������� � ��������� ����� (op_codes)
     *
     */
    const OPCODE_PAYED_KON_BNS    = 86;
    
    /**
     * ��� ������ �������� �������� (op_codes)
     *
     */
    const OPCODE_KON_UP  = 88;
    
     /**
     * ��� ������ �������� �������� (op_codes) (no PRO)
     *
     */
    const OPCODE_KON_UP_NOPRO  = 104;
    
    /**
     * ��� ������ ������� (op_codes)
     *
     */
    const OPCODE_PAYED      = 53;
    
    /**
     * ��� ������ ������� � ��������� ����� (op_codes)
     *
     */
    const OPCODE_PAYED_BNS  = 54;
    
    /**
     * ��� ������ �������� ������� (op_codes)
     *
     */
    const OPCODE_UP  = 7;
    
    /**
     * ��� ������ �������� ������� (op_codes) (no PRO)
     *
     */
    const OPCODE_UP_NOPRO  = 103;
    
    /**
     * ��� ������ "������� ������ � ������������" (op_codes)
     *
     */
    const OPCODE_TOP  = 87;
    
    /**
     * ��� ������ "������� ������ � ������������" (op_codes) (no PRO)
     *
     */
    const OPCODE_TOP_NOPRO  = 105;
    
    
    /**
     * ��� ������ ����������� ������� (op_codes)
     * @TODO ���������� �� ��������� NEW, ����� �������� �������������� ����
     *
     */
    const OPCODE_TOP_NEW  = 141;
    
    /**
     * ��� ������ �������� (op_codes)
     *
     */
    const OPCODE_LOGO  = 140;
    
    /**
     * ��� ������ "������� ������" (op_codes)
     *
     */
    const OPCODE_URGENT  = 138;
    
    /**
     * ��� ������ "������� ������" (op_codes)
     *
     */
    const OPCODE_HIDE  = 139;
    
    /**
     * ������������ ���������� ������
     *
     */
    const MAX_FILE_COUNT    = 10;
    
    /**
     * ������ ��������
     *
     */
    const LOGO_WIDTH  = 150;
    
    /**
     * ������ ��������
     *
     */
    const LOGO_HEIGHT = 150;
    
    /**
     * ������ ��������
     *
     */
    const LOGO_SIZE   = 512000;
   
    /**
     * ������ �� RSS ��������
     *
     */
    const RSS_KEY_PFX = '/rss/projects.php';
    
    /**
     * ����������� ������ ��� ���������
     * � ������
     */
    const CONTEST_MIN_BUDGET = 3000;
    
    /**
     * ����� ����������� ������, ������ ��� ����� ����� ������� ������� ��������� ���������� ��������
     */
    const NEW_CONTEST_MIN_BUDGET = 3000;
    
    /**
     * ���� � ������� ����� ����� ������ ��������� ���������� ��������
     * ��������� ������������� ������ �������������� ��������
     * ������� ����������� ������
     */
    const NEW_CONTEST_BUDGET_DATE = '2013-08-14 00:00:00';
    
    
    /**
     * �������� ����� ������
     *
     * @param array  $prj    ������ �� �������
     * @param object $attach �������� @see CFile
     * @return integer 1 - ���� ������ ��������, ����� 0
     */
    function addPrj(&$prj, $attach, $categories = false)
    {
        global $DB;

        if($prj['top_days']) {
            $top_from = 'now()';
            $top_to   = $DB->parse("now() + '?i days'::interval", $prj['top_days']);
        }
        else {
            $top_from = NULL;
            $top_to   = NULL;
        }
        if ($prj['kind'] == 2) return 2;  // ������ �������� ��������� ������
		if ($prj['kind'] == 7) {
			preg_match("/^([0-9]{1,2})\-([0-9]{1,2})\-([0-9]{4})$/", $prj['end_date'], $o1);
			preg_match("/^([0-9]{1,2})\-([0-9]{1,2})\-([0-9]{4})$/", $prj['win_date'], $o2);
			$end_date = date('Y-m-d', mktime(0, 0, 0, $o1[2], $o1[1], $o1[3]));
			$win_date = date('Y-m-d', mktime(0, 0, 0, $o2[2], $o2[1], $o2[3]));
		} else {
			$end_date = NULL;
			$win_date = NULL;
		}
        $DB->start();
        
        $prj['strong_top'] = hasPermissions('projects') ? (int) $prj['strong_top'] : 0;
        //$sModVal = is_pro() ? 'NULL' : '0';
        $sModVal = '0';
        $prj['payed'] = round($prj['payed'], 0);
		$sql = 
        "INSERT INTO projects(state, user_id, name, cost, descr, currency, kind, country, city, payed, pro_only,
                              logo_id, link, is_color, is_bold, top_from, top_to, billing_id, payed_items, end_date, win_date, budget_type, priceby, prefer_sbr, moderator_status, strong_top, verify_only, contacts, urgent, hide, o_urgent, o_hide, videolnk)
         VALUES (?i, ?i, ?, ?f,
                 ?, ?i, ?i, 
                 ?i, ?i, ?, ?b,
                 ?i, ?, ?b,
                 ?b, ?x, ?x, ?i, ?, ?, ?,
                 ?i, ?i, ?b, ?i, ?i, ?b,
                 ?, ?b, ?b, ?b, ?b, ?)
         RETURNING id";
		
        $prj['id'] = $DB->val($sql,
            (int)$prj['state'], $prj['user_id'], $prj['name'], $prj['cost'],
            $prj['descr'], $prj['currency'], $prj['kind'], 
            $prj['country'], $prj['city'], $prj['payed'], $prj['pro_only'],
            $prj['logo_id'], $prj['link'], $prj['is_color'],
            $prj['is_bold'], $top_from, $top_to, $prj['billing_id'], $prj['payed_items'], $end_date, $win_date,
            $prj['budget_type'], $prj['priceby'], $prj['prefer_sbr'], $sModVal, $prj['strong_top'], $prj['verify_only'],
            $prj['contacts'], $prj['urgent'], $prj['hide'], $prj['urgent'], $prj['hide'], $prj['videolnk']
        
        );

        if(!$prj['id']) {
            $DB->rollback();
            return 0;
        }
        
        require_once( $_SERVER['DOCUMENT_ROOT'] . '/classes/stop_words.php' );
        
        $stop_words    = new stop_words();
        $nStopWordsCnt = $stop_words->calculate( $prj['name'], $prj['descr'] );
        
        $this->addModeration($prj['id'], $nStopWordsCnt);
        
        $this->saveSpecs($prj['id'], $categories);
        $this->setFirstProjectsList($prj['id']);
        if($attach && is_array($attach)) {
            $sql = "INSERT INTO project_attach (project_id, file_id) VALUES ";
            $i = 0;
            foreach($attach as $a)
                $sql .= ($i++ ? ',' : '') . $DB->parse('(?i, ?i)', $prj['id'], $a['file_id']);
            if($i && !$DB->query($sql)) {
                $DB->rollback();
                return 0;
            }
        }

        // ��������� ������ ��� �������
        $files = '';
        if($attach && is_array($attach)) {
            foreach($attach as $a) {
                $files .= $a['name'].',';
            }
        }
        $files = preg_replace("/,$/", '', $files);
        $specs = '';
        if($categories) {
            foreach ($categories as $key => $value) {
                $specs .= (int)$value['category_id'].'|'.(int)$value['subcategory_id'].',';
            }
        }

        $specs = preg_replace("/,$/", '', $specs);
        $sql = "INSERT INTO projects_history (
                                id,
                                name,
                                cost,
                                descr,
                                currency,
                                kind,
                                files,
                                specs,
                                pro_only,
                                prefer_sbr,
                                end_date,
                                win_date,
                                country,
                                city,
                                priceby,
                                verify_only,
                                contacts,
                                urgent,
                                hide 
                            ) VALUES (
                                ?i,
                                ?,
                                ?f,
                                ?,
                                ?i,
                                ?i,
                                ?,
                                ?,
                                ?b,
                                ?b,
                                ?, 
                                ?,
                                ?i, 
                                ?i,
                                ?i,
                                ?b,
                                ?,
                                ?b,
                                ?b
                            );";

        $DB->query($sql,
             $prj['id'],
             $prj['name'],
             $prj['cost'],
             $prj['descr'],
             $prj['currency'],
             $prj['kind'],
             $files,
             $specs,
             $prj['pro_only'],
             $prj['prefer_sbr'],
             $end_date, 
             $win_date,
             $prj['country'],
             $prj['city'],
             $prj['priceby'],
             $prj['verify_only'],
             $prj['contacts'],
             $prj['urgent'],
             $prj['hide']
        );



        if(!$DB->commit()) {
            $DB->rollback();
            return 0;
        }
        return 1;
    }
    
    /**
     * �������� ������������� ������
     * 
     * @param  int $id ID �������
     * @param  array $data ������������� array(array('category_id'=>x, 'subcategory_id'=>y), ...)
     * @return resource
     */
    function saveSpecs($id,$data){
        if(!$data) return false;
        
        // ����� ��� ��������� ��������: ����� ������������� ������
        if ( $this->_project['user_id'] != $_SESSION['uid'] && hasPermissions('projects') ) {
            $aPrevSpecs = $GLOBALS['DB']->rows( 'SELECT category_id, subcategory_id FROM project_to_spec WHERE project_id = ?i', $id );
            
            if ( !empty($aPrevSpecs) ) { 
                if(!function_exists('_cmp_spec')) {
                    function _cmp_spec($a, $b) {
                        $intA = intval( $a['category_id'] );
                        $intB = intval( $b['category_id'] );
                        $intR = $intA < $intB ? -1 : ( $intA > $intB ? 1 : 0 );

                        if ( $r1 == 0 ) {
                            $intA = intval( $a['subcategory_id'] );
                            $intB = intval( $b['subcategory_id'] );
                            $intR = $intA < $intB ? -1 : ( $intA > $intB ? 1 : 0 );
                        }

                        return $intR;
                    }
                }
                
                $aCurrSpecs = $data;
                
                usort( $aPrevSpecs, '_cmp_spec' );
                usort( $aCurrSpecs, '_cmp_spec' );
                
                if ( $aPrevSpecs != $aCurrSpecs ) { 
                    require_once( $_SERVER['DOCUMENT_ROOT'] . '/classes/admin_log.php' );
                    
                	$sReason  = '�� �����:<br/>' . $this->_getSpecsStr( $aPrevSpecs,' / ', ', ' );
                	$sReason .= '<br/>�����:<br/>' . $this->_getSpecsStr( $data,' / ', ', ' );
                	$sLink    = getFriendlyURL( 'project', $this->_project['id'] );
                	
                	admin_log::addLog( admin_log::OBJ_CODE_PROJ, admin_log::ACT_ID_PRJ_CH_SPEC, $this->_project['user_id'], $this->_project['id'], $this->_project['name'], $sLink, 0, '', 0, $sReason );
                }
            }
        }
        
        $sql = "DELETE FROM project_to_spec WHERE project_id = {$id};";
        global $DB;
        $DB->query($sql);
        $sql = "INSERT INTO project_to_spec (project_id, category_id, subcategory_id) VALUES ";
        $parts = array();
        foreach ($data as $key => $value) {
            $cat = (int)$value['category_id'];
            $sub = (int)$value['subcategory_id'];
            $parts[] .= "({$id}, {$cat}, {$sub})";
        }
        $sql .= implode(", ", $parts);
        return $DB->query($sql);
    }

    
    /**
     * ���������� ������������� ������
     *
     * @param  mixed $id ID ������� ��� ������ ID ��������
     * @return array
     */
    function getSpecs( $id = array() ) {
        $id = is_array($id) ? $id : array($id);
        
        $sql = "SELECT * FROM project_to_spec WHERE project_id IN (?l);";
        global $DB;
        return $DB->rows($sql,$id);
    }
    
	/**
	 * ������������� ������
	 *
	 * @param array  $prj        ������ ��� ��������������
	 * @param object $newattach  ����� ����� (@see class CFile)
	 * @return boolean true ���� �������������� ������ �������, ����� false
	 */
    function editPrj($prj, $newattach, $categories = false)
    {
        global $DB;
		if ($prj['kind'] == 7) {
			preg_match("/^([0-9]{1,2})\-([0-9]{1,2})\-([0-9]{4})$/", $prj['end_date'], $o1);
			preg_match("/^([0-9]{1,2})\-([0-9]{1,2})\-([0-9]{4})$/", $prj['win_date'], $o2);
            if($o1) { $end_date = date('Y-m-d', mktime(0, 0, 0, $o1[2], $o1[1], $o1[3])); } else { $end_date = $prj['end_date']; }
            if($o2) { $win_date = date('Y-m-d', mktime(0, 0, 0, $o2[2], $o2[1], $o2[3])); } else { $win_date = $prj['win_date']; }
		} else {
			$end_date = NULL;
			$win_date = NULL;
		}

        $top_set = $post_set = '';
        
        if($prj['top_days']) {
            $top_set = $DB->parse(",
                  top_from    = CASE WHEN COALESCE(top_to,'epoch') >= now() THEN top_from ELSE now() END,
                  top_to      = CASE WHEN COALESCE(top_to,'epoch') >= now() THEN top_to + '?i days'::interval ELSE now() + '?i days'::interval END
              ", $prj['top_days'], $prj['top_days']
            );
        }
        
        if($prj['post_now']) {
            $post_set = ", post_date = NOW()";
        }
        
        $sql = '';
        
        if ( $prj['user_id'] == $_SESSION['uid'] && !hasPermissions('projects') /*&& !is_pro()*/ ) {
            // �����, �� �����, �� ��� - ��������� �� �������������
            $sModeration = 'moderator_status = 0, ';
            
            require_once( $_SERVER['DOCUMENT_ROOT'] . '/classes/stop_words.php' );
            
            $stop_words    = new stop_words();
            $nStopWordsCnt = $stop_words->calculate( $prj['name'], $prj['descr'] );
            
            $this->addModeration($prj['id'], $nStopWordsCnt);
        }
        $prj['payed'] = round($prj['payed'], 0);
        $prj['strong_top'] = hasPermissions('projects') ? (int) $prj['strong_top'] : 0;
		$sql .= $DB->parse(
        "UPDATE projects
            SET state       = ?i,
                name        = ?,
                cost        = ?f,
                descr       = ?,
                currency    = ?i,
                kind        = ?i,
                country     = ?i,
                city        = ?i,
                payed       = ?,
                pro_only    = ?b,
                verify_only = ?b,
                videolnk    = ?,
                strong_top  = ?i,
                logo_id     = ?i,
                link        = ?,
                is_color    = ?b,
                is_bold     = ?b,
                billing_id  = ?i,
                payed_items = ?,
                edit_date   = NOW(),
				end_date    = ?,
				budget_type = ?i,
				priceby     = ?i,
                prefer_sbr  = ?b,
                urgent      = ?b,
                hide        = ?b,
                o_urgent    = ?b,
                o_hide      = ?b,
                contacts    = ?,
                {$sModeration}
				win_date    = ?
                {$top_set}
                {$post_set}
          WHERE id = ?i
       ",
            $prj['state'],
            $prj['name'],
            $prj['cost'],
            $prj['descr'],
            $prj['currency'],
            $prj['kind'],
            $prj['country'],
            $prj['city'],
            $prj['payed'],
            $prj['pro_only'],
            $prj['verify_only'],
            $prj['videolnk'],
            $prj['strong_top'],
            $prj['logo_id'],
            $prj['link'],
            $prj['is_color'],
            $prj['is_bold'],
            $prj['billing_id'],
            $prj['payed_items'],
            $end_date,
            $prj['budget_type'],
            $prj['priceby'],
            $prj['prefer_sbr'],
            $prj['urgent'],
			$prj['hide'],
            $prj['urgent'],
			$prj['hide'],
            $prj['contacts'],
            $win_date,
            $prj['id']
        );


        if($categories) $this->saveSpecs($prj['id'], $categories);
          
        $DB->query("DELETE FROM project_attach WHERE project_id = ?i", $prj['id']); // ������� ���
        if($newattach && is_array($newattach)) {
            $sql .= ";INSERT INTO project_attach (project_id, file_id) VALUES ";
            $i = 0;
            foreach($newattach as $a)
                $sql .= ($i++ ? ',' : '') . $DB->parse('(?i, ?i)', $prj['id'], $a['file_id']);
        }

        //��������� ���������� � ������ �������� (������� ��������� ����� � ������� blogs_msgs)

        if (is_new_prj($prj['post_date']))
        {
            $base = $prj['kind'] == 2 ? 5 : 3;

            $sql .= $DB->parse(
              ";UPDATE blogs_msgs b
                   SET title = ?,
                       msgtext = ?,
                       modified = NOW()
                  FROM blogs_themes_old t
                 WHERE t.id_gr = ?i
                   AND t.base = ?i
                   AND b.thread_id = t.thread_id
                   AND b.reply_to IS NULL",
               $prj['name'], $prj['descr'], $prj['id'], $base
            );
        }
        return !!$DB->squery($sql);
    }
    

    /**
     * ��������� SQL-������� �� ��������� ������� (������� � ����������� �� ������� � � ���� "�������").
     *
     * @global $project_exRates ���� ������ ������
     * 
     * @param array   $filter ��������� �������. ���� ������ �������� ����� �������� NULL ��� � $filter['is_active'] ������ false.
     * @param integer $kind   ��� �������� �������� (@see new_projects::getProjects()), ���� ��������� �� ������� ��������.
     * @return string   SQL-������� ��� ������������� � �������.
     */
    function createFilterSql($filter, $kind = NULL)
    {
        global $DB, $project_exRates;

        if(!$filter || $filter['active']!='t') return '';

        $fSql = '';
        
        //�������� �� ������� ������������� �������
        if (isset($filter['not_project_ids']) && 
            is_array($filter['not_project_ids'])) {
            
            $fSql .= $DB->parse(" AND p.id NOT IN(?l) ", $filter['not_project_ids']);
        }
        
        if($filter['only_sbr'] == 't') {
            $fSql .= ' AND  p.prefer_sbr = true ';
        }
        if($filter['urgent_only'] == 't') {
            $fSql .= ' AND  p.urgent = true ';
        }
        if($filter['urgent'] == 't') {
            $fSql .= ' AND  p.urgent = true ';
        }
        if($filter['pro_only'] == 't') {
            $fSql .= ' AND  p.pro_only = true ';
        }
        if($filter['verify_only'] == 't') {
            $fSql .= ' AND  p.verify_only = true ';
        }
        if($filter['less_offers'] == 't') {
            $fSql .= ' AND p.offers_count < 2 ';
        }
        
        //������ ������� � ��������� ������������
        if(isset($filter['hide_exec']) && 
           $filter['hide_exec'] == 't') {
            
            $fSql .= ' AND (p.exec_id <= 0 OR p.exec_id IS NULL) ';
        }
        
        
        if(hasPermissions('projects')) {
            if($filter['block_only'] == 't') {
                $fSql .= ' AND EXISTS (SELECT 1 FROM projects_blocked WHERE project_id=p.id) ';
            }
        }
        
        if($kind!=1 && $kind!=2 && $kind!=7) {
            if(intval($filter['country']))
            {
              $fSql .= 'AND (p.country='.intval($filter['country']).' ';
              if(intval($filter['city'])) $fSql .= 'AND p.city='.intval($filter['city']);
              $fSql .= ') AND kind = 4 ';
            }
        }
        
        if($filter['wo_cost']=='f')  $fSql .= 'AND p.cost > 0 ';
        
        if ($filter['my_specs'] == 't' && $filter['user_specs']) {
            $profsWithMirrors = professions::GetMirroredProfs(implode(',', $filter['user_specs']));
            $fSql .= 'AND EXISTS (SELECT 1 from project_to_spec WHERE project_id = p.id AND subcategory_id IN ('.implode(',', $profsWithMirrors).'))';
        }
        elseif($filter['categories'])
        {
            $categories = array();

            for ($ci=0; $ci<2; $ci++)
            {
              if (sizeof($filter['categories'][$ci])) {
                foreach($filter['categories'][$ci] as $ckey => $cvalue) {
                  $categories[$ci][] = (int)$ckey;
                }
              }
            }

            $fSql .= 'AND EXISTS (SELECT 1 from project_to_spec WHERE project_id = p.id AND (';

            $sProfCat    = '';
            $sProfSubcat = '';
            
            // �������� ���������� ��������� ��������
            if (sizeof($categories[0])) {
                $sProfCat = professions::getProfIdForGroups( $categories[0] );
            }
            
            // �������� ��������� ����������
            if (sizeof($categories[1])) {
                $sProfSubcat = implode( ',', $categories[1] );
            }
            
            // ��������� � �������� ��� ���������� ������ � ���������
            $sProf = $sProfCat . (($sProfCat && $sProfSubcat) ? ',' : '') . $sProfSubcat;
            $aProf = professions::GetMirroredProfs( $sProf );
            
            $fSql .= 'subcategory_id in ('.implode(',', $aProf).') ';
            if(sizeof($categories[0])) {
                $fSql .= 'OR category_id IN ('.implode(',', $categories[0]).')';
            } 
            $fSql .= ')) ';
        }
        
        if($filter['keywords'] = trim($filter['keywords'])) {
            if(defined('FTS_PROJECTS') && FTS_PROJECTS) {
                if($filter_keywords = $DB->parse('?ts', $filter['keywords'])) {
                    // ��� ���������� ����� ���������� ������� ����� ������ ������ "ixts projects/name_descr".
                    $fSql .= "
                      AND ( to_tsvector('pg_catalog.russian', COALESCE(p.name, '') || ' ' || COALESCE(p.descr, ''))
                            @@ to_tsquery('pg_catalog.russian', {$filter_keywords}) )
                    ";
                }
            }
            else {
                foreach(explode(',', $filter['keywords']) as $val) {
                    $val = trim(preg_replace('/([%_])/','\\\\\\\$1',htmlspecialchars($val, ENT_QUOTES, 'cp1251')));
                    if($val) {
                        $filter_keywords[] = $val;
                    }
                }
                $fSql .= 'AND ( ';
                $fSql .= "(p.name ILIKE '%" . implode("%' OR p.name ILIKE '%", $filter_keywords) . "%') OR ";
                $fSql .= "(p.descr ILIKE '%" . implode("%' OR p.descr ILIKE '%", $filter_keywords) . "%') ";
                $fSql .= ') ';
            }
        }
        
        if($filter['cost_from'] || $filter['cost_to']) {
            $cr = (int)$filter['currency'];
            $cex = array(2,3,4,1); 
            if(($cost_from = (float)$filter['cost_from']) < 0) $cost_from = 0;
            if(($cost_to = (float)$filter['cost_to']) < 0)     $cost_to = 0;
            if($cost_to < $cost_from && $cost_to != 0)       $cost_to = $cost_from;
            if($cost_to || $cost_from) {
                $fSql .= 'AND (';
                
                //##0028132
                /*
                $priceby = (int)$filter['priceby'];
                if($kind == 7) {
                    $priceby = NULL;
                }
                if($priceby) {
                    $fSql .= 'p.priceby = ' . $priceby . ' AND (';
                }
                */
                
                for($i=0;$i<4;$i++) {
                    $exfr = round($cost_from * $project_exRates[$cex[$cr].$cex[$i]],4);
                    $exto = round($cost_to * $project_exRates[$cex[$cr].$cex[$i]],4);
                    $fSql .= ($i ? ' OR ' : '')."(p.currency = {$i} AND p.cost >= {$exfr}".($cost_to ? " AND p.cost <= {$exto}" : '').')';
                }
                
                //##0028132
                /*
                if($priceby) {
                    $fSql .= ')';
                }
                */
                
                if($filter['wo_cost']=='t') {
                    $fSql .= ' OR p.cost = 0';
                }
                $fSql .= ')';
            }
        }

        if ($kind == 2 || $kind == 7) {
            if ($filter['konkurs_end_days_from'] !== null) {
                $fSql .= $DB->parse(' AND p.end_date::date - NOW()::date >= ? ', $filter['konkurs_end_days_from']);
            }
            if ($filter['konkurs_end_days_to'] !== null) {
                $fSql .= $DB->parse(' AND p.end_date::date - NOW()::date <= ? ', $filter['konkurs_end_days_to']);
            }
        }
        
        return $fSql;
    }
	/**
	 * ����� ������ �� ��� ��
	 *
	 * @param integer $prj_id �� �������
	 * @return array  ���������
	 */
    function getPrj($prj_id)
    {
        global $DB;
        $sql = 
        "SELECT p.*, NULL as catname, e.login, e.uname, e.usurname, e.is_pro, e.email, e.is_team,
                e.uid, NULL as sub_catname, (pb.project_id IS NOT NULL)::bool as is_blocked
           FROM projects p
         INNER JOIN
           employer e
             ON e.uid = p.user_id
         LEFT JOIN
           projects_blocked pb
             ON pb.project_id = p.id
          WHERE p.id = ?i";

        return $DB->row($sql, $prj_id);
    }

    
    /**
     * ���������� ������ ��������.
     * 
     * @param integer $num_prjs		���������� ���-�� ��������
     * @param integer|array $kind   ��� �������� (-1=5=��� �������; 2=��������; 4=� ����; 6=������ ��� ���)
     *                              ���� ������, ��: array(���, tops_only), ���
     *                              tops_only: true, ���� ����� �������� ������ ������������ �������.
     * @param integer $page			�������� �������� (���-�� �������� �� �������� PAGE_SIZE)
     * @param boolean $comments		���������� �� ����������� � �������� (������� ��� ��������-�����������)				
     * @param array   $filter		������ � �������� ��������				
     * @param integer $prj_id       ID �������, ���� �� NULL �� ������� ����������� ������ �� ����� �������
     * @param integer $is_closed    ������� ��� ������ ������ ��� ���
     * @param integer $to_date      ����� ������� ������ �� ���� ����.
     * @param boolean $withouttop   ���������� �� ������� �� �������� �� �����������
     * @return array				������ � ����������� � ��������
     */
    function getProjects(&$num_prjs, $kind = -1, $page = 1, $comments = false, $filter = NULL, $is_blocked = true, $is_ajax = false, $prj_id = NULL,
                         $is_closed = false, $to_date = NULL, $withouttop = false)
    {
        global $DB;
        list($kind, $tops_only) = (array)$kind;
        $is_emp = is_emp();
        $is_moder = hasPermissions('projects');
        $uid = $_SESSION['uid'];
        if($uid && !$_SESSION['ph'] && !$is_ajax) {
            projects_filters::initClosedProjects();
        }
        $phidd = $_SESSION['ph'];

        $filterSql = new_projects::createFilterSql($filter, $kind);

        $ret = NULL;
        $limit = $this->page_size;
        if((int)$page < 1) $page = 1;
        $offset = $to_date ? 0 : ($page-1)*$limit;
        $slimit = $limit + (int)(!!$filterSql); // ��� ��������, ���� �� ����. ��������.
        
        $addit = '';
        if ($filterSql) {
            if ($tops_only) {
                $addit = "(p.edit_date IS NOT NULL AND p.edit_date > NOW() - interval '2 month') AND ";
            } else {
                $addit = "(p.post_date > NOW() - interval '2 month') AND ";
            }
        }
        
        $addit .= (get_uid(false) ? '' : 'COALESCE(p.hide, false) = false AND ').'p.closed = false AND p.user_id <> 0 AND p.kind <> 9';
        
        if($is_closed) {
            $addit .= ' AND ( p.end_date > NOW() OR p.end_date IS NULL )';
        }
        
        if($kind == 6) $addit .= ' AND p.pro_only = true';
        else if($kind == 2) $addit .= " AND (p.kind = 2 OR p.kind = 7)";
        else if($kind != -1 && $kind != 5) $addit .= " AND p.kind = '$kind'";
    
        if($phidd && is_array($phidd)) {
            $hidden_projects = array();
            foreach($phidd as $pkey => $pvalue) {
                $hidden_projects[] = $pkey;
            }

            $addit .= ' AND p.id NOT IN ('.implode(',', $hidden_projects).')';
        }

        if($comments) {
            $comm = ' LEFT JOIN blogs_themes_old bt ON bt.id_gr = p.id AND bt.base = '.($kind == 2 ? 5 : 3);
            $sel  = ', bt.thread_id, bt.messages_cnt - 1 as comm_count';
            if($uid) {
               $comm .= " LEFT JOIN projects_watch pw ON pw.user_id = {$uid} AND pw.prj_id = p.id ";
               $sel  .= ', pw.status AS prj_status';
            }
            
        }

        //������� ����������� �� ������� ������������
        if ($uid && !$is_emp) {
            $sel_offer = ", po.id as offer_id, po.refused, po.selected";
            $join_offer = " LEFT JOIN projects_offers po ON po.project_id = p.id AND po.user_id = '{$uid}' ";
        }
        
        // ��������� ��������������� �������
        $sel_blocked = ", pb.reason as blocked_reason, pb.blocked_time, pb.project_id::boolean as is_blocked";
        $join_blocked = "LEFT JOIN projects_blocked pb ON p.id = pb.project_id ";
        if ($is_moder) {
            $sel_blocked  .= ", admins.login as admin_login, admins.uname as admin_uname, admins.usurname as admin_usurname";
            $join_blocked .= "LEFT JOIN users as admins ON pb.admin = admins.uid ";
        } else {
            $join_c_blocked = $join_blocked;
            $wb = "(" . ($is_emp? "p.user_id = {$uid} OR ": "") . " pb.project_id IS NULL) ";
            $where_blocked = "WHERE $wb";
            
            if ($filterSql) {
                $where_blocked = "";
            }
            
            $where_c_blocked = "AND $wb";
            if(!$is_blocked) {
                $join_is_blocked = $join_blocked;
                $where_is_blocked = "WHERE $wb";
                $where_is_c_blocked = "AND $wb";
            }
        }

        $offset = intvalPgSql( (string) $offset);
        
        // �������, ��� ������� ������ � ������ ������ ��������� ������� �����.
        $top_cond = ' ( (top_from IS NOT NULL AND now() BETWEEN top_from AND top_to) OR strong_top = 1 ) ';
        $top_payed_col = 'top_from';
        if (!$tops_only) {
            // ������������ �������� ��������� ��������.
            if($withouttop) {
                $top_cond = "NOT(1=0)";
                $top_payed_col = "'epoch'";
                $order = 'p.post_date DESC';
                $tops = array();
                $tops_cnt = 0;
            } else {
                $top_cond = "NOT({$top_cond})";
                $top_payed_col = "'epoch'";
                $order = 'p.strong_top DESC, p.post_date DESC';
                $tops = $to_date ? array() : $this->getProjects($x, array($kind, true), 1, $comments, $filter, $is_blocked, $is_ajax, $prj_id);
                $tops_cnt = count($tops);
            }

            if ($offset >= $tops_cnt) {
                // ������, ����� ���� �� �������� �� �������� ��������, �� ���� �������� ������ ���
                // ��������� ������� ��������.
                $x_offset = $offset - ($tops_cnt > $slimit ? $slimit : $tops_cnt);
                $x_limit = $slimit;
                $tops = array();
            } else {
                // ����� ���-�������� ������ ��� ���������� �� ���� ��������
                // ��-������ ��� ���� ������ ���� �� ������ ��������, ������� �������� ��� �� �����
                if ($page === 1) {
                    if ($tops_cnt > $limit) {
                        $limit = $tops_cnt;
                        $x_limit = 0; // ������� �������� ��� �� ����
                    } else {
                        $x_limit = $slimit - $tops_cnt;
                    }
                } else {
                    $x_limit = $slimit - (($tops_cnt > $offset ? $offset : $tops_cnt) - $offset);
                }
                $x_offset = 0;
                //$tops = array_splice($tops, $offset, $slimit); // �� �������� ��������� ���� ����������� � �����.
                if($x_limit < 0) {
                    // �.�. �� �������� ���������� ������ ����.
                    $x_limit = 0;
                }
                if ($page !== 1) {
                    $tops = array();
                }
            }
            $limit_str = "LIMIT {$x_limit} OFFSET {$x_offset}";
            if($to_date) {
	            $where_date = $DB->parse(' AND p.post_date < ? ', $to_date);
            }
        } else {
            $order = 'p.strong_top DESC, sort_date ASC, p.post_date ASC';
            //$order = 'p.strong_top DESC, p.top_from DESC, p.post_date ASC';
            //$order = 'p.post_date DESC';
        }

        if($prj_id) {
            $prjid_sql = " AND p.id = ".intval($prj_id);
            $limit_str = '';
        }

        // ���������� ��� ����, ����� ����������� � ����������, ������ ������ �� �������� "�����������" (projects.state = 0)
        /*
        if (!$is_moder) {
            //$addit .= ' AND (p.state = 0 OR p.user_id = '.intval($uid).')';
            $addit .= " AND NOT(p.payed = 0 AND p.kind = ".self::KIND_VACANCY." AND p.state = ".self::STATE_MOVED_TO_VACANCY.")";
        }
        */
        
        $sql = "
         SELECT p.*, {$top_payed_col} as top_payed, f.fname as logo_name, f.path as logo_path, NULL as category_name, city.city_name, country.country_name, e.login,
                e.uid, e.uname, e.usurname, e.warn, e.role, e.is_pro, e.is_team, e.email, e.is_banned, e.self_deleted, e.is_verify, e.photo, e.reg_date, e.modified_time, e.photo_modified_time, fl.is_banned  as exec_is_banned 
                {$sel_offer} {$sel} {$sel_blocked}
           FROM (
             -- ���� ������ ������������� ��� ������ 'ix projects/main'.
             SELECT p.*,
                -- ������ ����������: �������� ������� �� ����������� ���� ����������, ��������� ������������ �� �������� ���� �����������
                CASE WHEN p.strong_top = 1 THEN (p.post_date - NOW()) ELSE (NOW() - p.top_from) END as sort_date
             FROM projects p {$join_is_blocked}
              WHERE {$addit} {$where_is_c_blocked} {$where_date} {$filterSql} AND {$top_cond} {$prjid_sql}
              ORDER BY {$order} {$limit_str}
           ) as p
         INNER JOIN
           employer e
             ON e.uid = p.user_id
         LEFT JOIN
           freelancer fl
             ON fl.uid = p.exec_id
         LEFT JOIN
           file_projects f
             ON f.id = p.logo_id
         LEFT JOIN 
           city 
             ON city.id = p.city
         LEFT JOIN 
           country
             ON country.id = p.country
         {$join_blocked}
         {$join_offer}
         {$comm}
         {$where_blocked}
         ORDER BY {$order}";

         $ret = $DB->rows($sql);
        
        if(($ret || $tops) && !$tops_only) {
            if ($tops) {
                $ret = array_merge($tops, $ret ? $ret : array());
            }
            
            $blocked_cnt = 0;
            $scnt = count($ret);
            if ($scnt > $limit) {
                array_pop($ret); // ������� ����������� ������ (��. $slimit).
            }
            if (!$is_moder) {
                foreach ($ret as $k => $v) {
                    if ( ($v['is_blocked'] && !($is_emp && $v['user_id'] == $uid)) || $v['is_banned']==1 ) {
                        unset($ret[$k]);
                        $blocked_cnt++;
                    }
                }
            }
            if($num_prjs != 'nenado') {
                if(!$filterSql) {
                    $num_prjs = (int)new_projects::getProjectsCount($kind, $page, $is_emp ? $uid : null, $is_moder);
                } else {
                    $cnt = $scnt; //+ $blocked_cnt;
                    $num_prjs = $offset + $cnt; // + (int)($cnt > $limit) * $limit; // 0012679
                }
            }
        }

        setlocale(LC_ALL, 'en_US.UTF-8');

		return $ret;
    }
    
    /**
     * ������� xml ���� ��� �����.������
     * 
     * @param   $filename   string  ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     * @param   $kind       array   ���� �������� ��� �������� (���� kind � ������� projects)
     * @return text $filename ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     */
    function yandexGenerateRss($filename, $kind) {
        global $DB;

        $rXml  = iconv( 'CP1251', 'UTF-8', '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<!DOCTYPE source>' . "\n" );
        $rXml .= iconv( 'CP1251', 'UTF-8', '<source creation-time="' . date('Y-m-d H:i:s') . ' GMT+3" host="' . $host . '">' . "\n" );
        $rXml .= iconv( 'CP1251', 'UTF-8', '   <vacancies>' . "\n" );
        
        $sql = "SELECT 
                p.id, p.post_date, p.name AS project_name, NULL AS cat_name, NULL AS subcat_name,
                co.country_name, ci.city_name, p.descr, p.moderator_status, p.create_date
            FROM projects p
            INNER JOIN employer e ON e.uid = p.user_id 
            LEFT JOIN country co ON co.id = p.country 
            LEFT JOIN city ci ON ci.id = p.city 
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
            WHERE 
                p.kind IN (?l) 
                AND p.pro_only = false 
                AND pb.project_id IS NULL
                AND p.closed = false 
                AND e.is_banned < 1::bit
                AND p.post_date > DATE_TRUNC('hour', now() - interval '1 week')
                /*AND (p.moderator_status <> 0 OR p.moderator_status IS NULL)*/
            ORDER BY p.kind ASC, p.post_date DESC";
        
        $host = str_replace(HTTP_PREFIX, '', $GLOBALS['host']);
        $HTTP_PREFIX = 'https://'; 
        $res  = $DB->query($sql, $kind);
        
        while ( $row = pg_fetch_assoc($res) ) {
            /*if ( $row['moderator_status'] == '0' ) {
                continue;
            }*/

            $xml = '';
            
            $row['categories'] = self::getProjectCategories($row['id']);

            // Yandex �� ���������� �������� � ������� �����������
            if (empty($row['categories'])) { 
                continue;
            }

            $city      = ( $row['city_name'] ) ? $row['city_name'] : '������';
            $location  = ( $row['country_name'] ) ? $row['country_name'] : '������';
            $location .= ( $location ) ? ', ' . $city : $city;
            $location  = html_entity_decode( $location, ENT_QUOTES, 'cp1251' );
            $name = html_entity_decode( $row['project_name'], ENT_QUOTES, 'cp1251' );
            $cat  = html_entity_decode( $row['cat_name'],     ENT_QUOTES, 'cp1251' );
            $descr = html_entity_decode( $row['descr'], ENT_QUOTES, 'cp1251' );

            $func = create_function('$matches', 'ucwords($matches[0]);');
            $name = preg_replace_callback('/([A-Z�-ߨ]+[\!\?\.\,\;\:\"\\\'0-9\s]+){2,}/', $func, $name);
            $descr = preg_replace_callback('/([A-Z�-ߨ]+[\!\?\.\,\;\:\"\\\'0-9\s]+){2,}/', $func, $descr);

            
            $xml .= '        <vacancy>
            <url>' . $HTTP_PREFIX . $host . getFriendlyUrl('project',$row['id']) . '</url>
            <creation-date>' . date('Y-m-d H:i:s', strtotimeEx($row['create_date'])) . ' GMT+3</creation-date>
            ';
            if ( $row['create_date'] != $row['post_date'] ) {
                $xml .= '<update-date>' . date('Y-m-d H:i:s', strtotimeEx($row['post_date'])) . "</update-date>\r\n";
            }
            
            if ( !empty($row['categories']) ) { 
                $name_case = false;
                foreach($row['categories'] as $cat) {
                    $xml .= '<category>
                <industry>' . htmlspecialchars($cat['category_name'], ENT_QUOTES) . "</industry>\n";
                    if ( $cat['subcategory_name'] ) {
                        $xml .= '<specialization>' . htmlspecialchars($cat['subcategory_name'], ENT_QUOTES) . "</specialization>\n";
                        if($cat['name_case']) $name_case[] = $cat['name_case'];
                    }
                    $xml .= "           </category>\n";
                }
                if($name_case) $name_case = implode(". ", $name_case).". ";
            }
            
            $xml .= '           <job-name>' . htmlspecialchars($name_case . $name, ENT_QUOTES) . '</job-name>';
            $xml .= '<description>'. htmlspecialchars($descr, ENT_QUOTES) .'</description>
            <addresses>
                <address>
                    <location>' . htmlspecialchars($location, ENT_QUOTES) . "</location>
                </address>
            </addresses>
            <anonymous-company>
                <description />
            </anonymous-company>
        </vacancy>\n";
            
            unset($name_case);
            $rXml .= iconv( 'CP1251', 'UTF-8//TRANSLIT', $xml );
        }
        
        
        $rXml .= iconv( 'CP1251', 'UTF-8', "</vacancies>\n</source>" );
        
        $file = new CFile;
        return $file->putContent($filename, $rXml);
       
    }

    
    /**
     * ������� csv ���� ��� AdWords
     * 
     * @param   $filename   string  ������ ���� � ����� ���� webdav ������ ��������� ������������ csv
     * @return  boolean  �����
     */
    function adWords($filename) {
        global $DB;
        $profs  = array();
        $groups = array();
        $rows = $DB->rows("SELECT * FROM professions");
        foreach ( $rows as $row ) {
            $profs[$row['id']] = $row;
        }
        $rows = $DB->rows("SELECT * FROM prof_group");
        foreach ( $rows as $row ) {
            $groups[$row['id']] = $row;
        }
        $sql = "
            SELECT
                p.id, e.compname, country.country_name, city.city_name,
                date_trunc('seconds', p.create_date) c_date, p.name, p.descr, p.cost, p.currency, p.priceby,
                array_agg(pts.category_id) cats, array_agg(pts.subcategory_id) subcats
            FROM
                projects p
            INNER JOIN
                employer e ON e.uid = p.user_id AND e.is_banned = B'0'
            LEFT JOIN
                country ON country.id = p.country
            LEFT JOIN
                city ON city.id = p.city
            LEFT JOIN
                project_to_spec pts ON pts.project_id = p.id
            LEFT JOIN
                projects_blocked pb ON pb.project_id = p.id
            WHERE
                /*( p.moderator_status <> 0 OR p.moderator_status IS NULL ) AND*/ 
                pb.project_id IS NULL 
                AND p.post_date > DATE_TRUNC('day', now() - interval '2 weeks')
            GROUP BY
                p.id, e.compname, country.country_name, city.city_name, c_date, p.name, p.descr, p.cost, p.currency, p.priceby
            ORDER BY
                id DESC
        ";
        $tmpfile = "/var/tmp/adwords.csv";
        $fp  = fopen($tmpfile, "a");
        $res = $DB->query($sql);
        
        $c = 0;
        
        while ( $row = pg_fetch_assoc($res) ) {
            $data = array();
            // ������
            $data['url'] = $GLOBALS['host'] . '/projects/' . $row['id'] . '/' . translit(strtolower(htmlspecialchars_decode($row['name'], ENT_QUOTES))) . '.html';
            // ����
            if ( !empty($row['cost']) ) {
                switch ( $row['currency'] ) {
                    case 0: {
                        $cost = "{$row['cost']}\$";
                        break;
                    }
                    case 1: {
                        $cost = "�{$row['cost']}";
                        break;
                    }
                    case 2: {
                        $cost = "{$row['cost']} ���.";
                        break;
                    }
                    case 4: {
                        $cost = "{$row['cost']} FM";
                        break;
                    }
                }
                switch ( $row['priceby'] ) {
                    case 1: {
                        $priceby = '�� ���';
                        break;
                    }
                    case 2: {
                        $priceby = '�� ����';
                        break;
                    }
                    case 3: {
                        $priceby = '�� �����';
                        break;
                    }
                    case 4: {
                        $priceby = '�� ������';
                        break;
                    }
                }
                $data['Wage'] = "{$cost} {$priceby}";
            } else {
                $data['Wage'] = '�� ��������������';
            }
            // ������������� (���� ���������, ����� ������ ������)
            $cats    = $DB->array_to_php($row['cats']);
            $subcats = $DB->array_to_php($row['subcats']);
            $data['Vacancy'] = '';
            $data['Vacancy_title'] = '';
            $data['Category 1'] = '';
            $data['Category 2'] = '';
            if ( $cats[0] ) {
                $data['Vacancy'] = $groups[(int) $cats[0]]['name_case'];
                $data['Category 1'] = $groups[(int) $cats[0]]['name'];
                $data['Category 1'] = preg_replace("/[\.\,\_\\\\\/\*\;\:\?]+/", " ", $data['Category 1']);
                $data['Category 1'] = preg_replace("/\\s{2,}/", " ", $data['Category 1']);
                $data['Category 1'] = preg_replace("/[^-A-Za-z�-��-���0-9\\s]+/", "", $data['Category 1']);
            } else {
                $data['Category 1'] = '������';
            }
            if ( $subcats[0] ) {
                $data['Vacancy'] = $profs[(int) $subcats[0]]['name_case'];
                $data['Category 2'] = $profs[(int) $subcats[0]]['name'];
                $data['Category 2'] = preg_replace("/[\.\,\_\\\\\/\*\;\:\?]+/", " ", $data['Category 2']);
                $data['Category 2'] = preg_replace("/\\s{2,}/", " ", $data['Category 2']);
                $data['Category 2'] = preg_replace("/[^-A-Za-z�-��-���0-9\\s]+/", "", $data['Category 2']);
            } else {
                $data['Category 2'] = $data['Category 1'];
            }
            if ( empty($data['Vacancy']) ) {
                $data['Vacancy'] = '������';
            } else {
                $data['Vacancy'] = preg_replace("/[\.\,\_\\\\\/\*\;\:\?]+/", " ", $data['Vacancy']);
                $data['Vacancy'] = preg_replace("/\\s{2,}/", " ", $data['Vacancy']);
                $data['Vacancy'] = preg_replace("/[^-A-Za-z�-��-���0-9\\s]+/", "", $data['Vacancy']);
            }
            $data['Vacancy_title'] = LenghtFormatEx($data['Vacancy'], 30, '');
            $data['vacancy_id'] = $row['id'];
            // ���������
            if ( !$c ) {
                $rowsNames = array_keys($data);
                $dataStr = implode(',', $rowsNames)  . "\r\n";
                fwrite($fp, chr(255) . chr(254) . iconv('CP1251', 'UTF-16LE//TRANSLIT', $dataStr));
            }
            $dataStr = implode(',', $data)  . "\r\n";
            fwrite($fp, iconv('CP1251', 'UTF-16LE//TRANSLIT', $dataStr));
            $c++;
        }
        
        fclose($fp);
        $path = pathinfo($filename);
        $oldFile = new CFile;
        $newFile = new CFile(array('tmp_name'=>$tmpfile, 'name'=>NULL, 'size'=>filesize($tmpfile)));
        $oldFile->server_root = 1;
        $newFile->server_root = 1;
        $oldFile->Delete(0, $path['dirname'].'/', $path['basename']);
        $newFile->max_size = 1024 * 1048576;
        $newFile->MoveUploadedFile($path['dirname'] . '/');
        $newFile->Rename($filename);
        unlink($tmpfile);
        return true;
    }
    
    
    /**
     * ������� xml ���� ��� jobradio
     * 
     * @param   $filename   string  ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     * @return text $filename ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     */
    function jobradioGenerateRss($filename) {
        global $DB;
        $profs  = array();
        $groups = array();
        $rows = $DB->rows("SELECT * FROM professions");
        foreach ( $rows as $row ) {
            $profs[$row['id']] = $row;
        }
        $rows = $DB->rows("SELECT * FROM prof_group");
        foreach ( $rows as $row ) {
            $groups[$row['id']] = $row;
        }
        $sql = "
            SELECT
                p.id, e.compname, country.country_name, city.city_name, p.moderator_status, 
                date_trunc('seconds', p.create_date) c_date, p.name, p.descr, 
                array_agg(pts.category_id) cats, array_agg(pts.subcategory_id) subcats
            FROM
                projects p
            INNER JOIN
                employer e ON e.uid = p.user_id AND e.is_banned = B'0'
            LEFT JOIN
                country ON country.id = p.country
            LEFT JOIN
                city ON city.id = p.city
            LEFT JOIN
                project_to_spec pts ON pts.project_id = p.id
            LEFT JOIN
                projects_blocked pb ON pb.project_id = p.id
            WHERE
                /*( p.moderator_status <> 0 OR p.moderator_status IS NULL ) AND*/ pb.project_id IS NULL 
                AND p.post_date > DATE_TRUNC('day', now() - interval '2 weeks') 
                AND p.kind <> 9 
            GROUP BY
                p.id, e.compname, country.country_name, city.city_name, c_date, p.name, p.descr
            ORDER BY
                id DESC
        ";
        $res = $DB->query($sql);
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<jobs>\n";
        while ( $row = pg_fetch_assoc($res) ) {
            /*if ( $row['moderator_status'] == '0' ) {
                continue;
            }*/
            
            $categories = array();
            $cats    = $DB->array_to_php($row['cats']);
            $subcats = $DB->array_to_php($row['subcats']);
            for ( $i=0; $i<count($cats); $i++ ) {
                if ( $subcats[$i] ) {
                    $categories[] = $profs[(int) $subcats[$i]]['name_case'];
                } else if ( $cats[$i] ) {
                    $categories[] = $groups[(int) $cats[$i]]['name_case'];
                } else {
                    $categories[] = '������';
                }
            }
            $categoriesText = implode(', ', $categories);
            
            $job  = "<job>\n";
            $job .= "\t<title><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', $categoriesText) . "]]></title>\n";
            $job .= "\t<company>" . xmloutofrangechars(iconv('CP1251', 'UTF-8',($row['compname']? htmlspecialchars(htmlspecialchars_decode($row['compname'], ENT_QUOTES), ENT_QUOTES): '�������� �������� ������'))) . "</company>\n";

            $location = '';
            if ( $row['country_name'] ) {
                $location .= $row['country_name'];
            }
            if ( $row['city_name'] ) {
                $location .= ($location? ", {$row['city_name']}": $row['city_name']);
            }
            if ( !$location ) {
                $location = '������';
            }
            $host = str_replace("http://", "https://", $GLOBALS['host']);
            $job .= "\t<location>" . iconv('CP1251', 'UTF-8//TRANSLIT', $location) . "</location>\n";
            $job .= "\t<publishdate>{$row['c_date']}</publishdate>\n";
            $job .= "\t<url>{$host}/projects/{$row['id']}/" . translit(strtolower(htmlspecialchars_decode($row['name'], ENT_QUOTES))) . ".html</url>\n";
            $job .= "\t<description><![CDATA[" . xmloutofrangechars(iconv('CP1251', 'UTF-8', htmlspecialchars(htmlspecialchars_decode($row['descr'], ENT_QUOTES), ENT_QUOTES))) . "]]></description>\n";
            $job .= "\t<keywords><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', $categoriesText) . "]]></keywords>\n";
            $job .= "</job>\n";
            
            $xml .= $job;
        }
        
        $xml .= "</jobs>\n";
        
        $file = new CFile;
        return $file->putContent($filename, $xml);
    }

    
    /**
     * ������� xml ���� ��� careerjet
     * 
     * @param   $filename   string  ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     * @return text $filename ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     */
    function careerjetGenerateRss($filename) {
        global $DB;
        $profs  = array();
        $groups = array();
        $rows = $DB->rows("SELECT * FROM professions");
        foreach ( $rows as $row ) {
            $profs[$row['id']] = $row;
        }
        $rows = $DB->rows("SELECT * FROM prof_group");
        foreach ( $rows as $row ) {
            $groups[$row['id']] = $row;
        }
        $sql = "
            SELECT
                p.id, p.kind, p.cost, p.priceby, p.currency, e.compname, country.country_name, city.city_name, 
                date_trunc('seconds', p.create_date) c_date, p.name, p.descr, 
                array_agg(pts.category_id) cats, array_agg(pts.subcategory_id) subcats
            FROM
                projects p
            INNER JOIN
                employer e ON e.uid = p.user_id AND e.is_banned = B'0'
            LEFT JOIN
                country ON country.id = p.country
            LEFT JOIN
                city ON city.id = p.city
            LEFT JOIN
                project_to_spec pts ON pts.project_id = p.id
            LEFT JOIN
                projects_blocked pb ON pb.project_id = p.id
            WHERE
                /*( p.moderator_status <> 0 OR p.moderator_status IS NULL ) AND*/ 
                pb.project_id IS NULL 
                AND p.post_date > DATE_TRUNC('day', now() - interval '2 weeks')
            GROUP BY
                p.id, e.compname, country.country_name, city.city_name, c_date, p.name, p.descr
            ORDER BY
                id DESC
        ";
        $res = $DB->query($sql);
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<jobs>\n";
        $currency = array('USD', 'Euro', '���', 'FM');
        $priceby = array(1=>'� ���', '� ����', '� �����', '�� ������');
        while ( $row = pg_fetch_assoc($res) ) {
            
            $categories = array();
            $cats    = $DB->array_to_php($row['cats']);
            $subcats = $DB->array_to_php($row['subcats']);
            for ( $i=0; $i<count($cats); $i++ ) {
                if ( $subcats[$i] > 0 ) {
                    $categories[] = $profs[(int) $subcats[$i]]['name'];
                } else if ( $cats[$i] > 0 ) {
                    $categories[] = $groups[(int) $cats[$i]]['name'];
                } else {
                    $categories[] = '������';
                }
            }
            $categoriesText = implode(', ', $categories);
            
            $job  = "<job>\n";
            $job .= "\t<title><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', $categoriesText) . "]]></title>\n";
            $job .= "\t<company>" . xmloutofrangechars(iconv('CP1251', 'UTF-8',($row['compname']? htmlspecialchars(htmlspecialchars_decode($row['compname'], ENT_QUOTES), ENT_QUOTES): '�������� �������� �� �������'))) . "</company>\n";

            $location = '';
            if ($row['kind'] == 4 && ($row['country_name'] || $row['city_name'])) {
                $location .= $row['country_name'];
                $comma = $row['country_name'] ? ", " : "";
                $location .= $comma . $row['city_name'];
            } else {
                $location = '������, �����-���������';
            }
            $job .= "\t<location>" . iconv('CP1251', 'UTF-8//TRANSLIT', $location) . "</location>\n";
            $job .= "\t<publishdate>{$row['c_date']}</publishdate>\n";
            $job .= "\t<url>{$GLOBALS['host']}/projects/{$row['id']}/" . translit(strtolower(htmlspecialchars_decode($row['name'], ENT_QUOTES))) . ".html</url>\n";
            $job .= "\t<description><![CDATA[" . xmloutofrangechars(iconv('CP1251', 'UTF-8', htmlspecialchars(htmlspecialchars_decode($row['descr'], ENT_QUOTES), ENT_QUOTES))) . "]]></description>\n";
            $job .= "\t<keywords><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', $categoriesText) . "]]></keywords>\n";
            $job .= "\t<contracttype><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', ($row['kind'] == 4 ? '� ����' : '��������'))  . "]]></contracttype>\n";
            
            if ($row['cost'] > 0 && $row['currency'] !== null && $row['priceby'] !== null) {
                $salary = $row['cost'] . " " . $currency[$row['currency']] . " " . $priceby[$row['priceby']];
                
                $job .= "\t<salary><![CDATA[" . iconv('CP1251', 'UTF-8//TRANSLIT', $salary) . "]]></salary>\n";
            }
            
            $job .= "</job>\n";
            
            $xml .= $job;
        }
        
        $xml .= "</jobs>\n";
        
        $file = new CFile;
        return $file->putContent($filename, $xml);
    }
    

    function getProjectsForXml($interval = '1 month')
    {
        global $DB;

        $sql = "SELECT 
                p.id, COALESCE(p.edit_date, p.create_date) as create_date, p.cost, p.currency, p.name AS project_name, NULL AS cat_name, NULL AS subcat_name,
                p.kind, p.end_date, p.moderator_status, 
                co.country_name, ci.city_name, p.descr, e.uname as e_name, e.usurname as e_surname, e.login as e_login   
            FROM projects p
            INNER JOIN employer e ON e.uid = p.user_id 
            LEFT JOIN country co ON co.id = p.country 
            LEFT JOIN city ci ON ci.id = p.city 
            LEFT JOIN projects_blocked pb ON pb.project_id = p.id 
            WHERE 
                p.post_date > DATE_TRUNC('hour', now() - interval '$interval')
                AND p.closed = false 
                AND p.kind <> 9 
                AND e.is_banned < 1::bit
                AND p.exec_id = 0
                AND pb.project_id IS NULL
            ORDER BY p.post_date DESC
        ";

        $prjs  = $DB->rows($sql);
        return $prjs;       
    }
    
    function trovitGenerateRss($filename, $prjs) {
        $HTTP_PREFIX = "https://";
        require_once ($_SERVER['DOCUMENT_ROOT']."/classes/project_exrates.php");
        $project_exRates = project_exrates::GetAll();
        $exch = array(1=>'FM', 'USD','Euro','���');
        $translate_exRates = 
        array (
            0 => 2,
            1 => 3,
            2 => 4,
            3 => 1
        );
        
        $xml  = '';
        $host = str_replace(HTTP_PREFIX, '', $GLOBALS['host']);

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<trovit>' . "\n";

        $jobtypes = array(7 => 'contest', 4 => 'vacancy');        

        foreach ( $prjs  as $row ) {            
            $city      = ( $row['city_name'] ) ? $row['city_name'] : '';
            $location  = ( $row['country_name'] ) ? $row['country_name'] : '������';
            $location  = preg_replace("/, $/", "", $location);
            $location  = html_entity_decode( $location, ENT_QUOTES, 'cp1251' );
            if (!$location) $location = '������';

            
            $row['categories'] = self::getProjectCategories($row['id']);
            
            $cat  = html_entity_decode( $row['cat_name'],     ENT_QUOTES, 'cp1251' );
            
            if ( !empty($row['categories']) ) { 
                $name_case = false;
                foreach($row['categories'] as $cat) {
                    $c[] = iconv( 'CP1251', 'UTF-8', htmlspecialchars($cat['category_name'], ENT_QUOTES) );
                    if ( (int) $cat['subcategory_id'] > 0 ) {
                        $c[] = iconv( 'CP1251', 'UTF-8', htmlspecialchars($cat['subcategory_name'], ENT_QUOTES) );
                        if($cat['name_case']) $name_case[] = $cat['name_case'];
                    }
                }
                $category = implode(", ", $c);
                if($name_case) $name_case = implode(". ", $name_case).". ";
                unset($c);
            }

            $name   = html_entity_decode( $name_case . $row['project_name'],     ENT_QUOTES, 'cp1251' );
            $descr  = html_entity_decode( $row['descr'],     ENT_QUOTES, 'cp1251' );
            unset($name_case);
            $currency = '';
            switch($row['currency']) {
                case 0: $currency = '$'; break;
                case 1: $currency = ' Euro'; break;
                case 2: $currency = ' ���.'; break;
                case 3: $currency = ' ���.'; break;
            }
            
            if($row['cost'] && $row['currency']==3) {
                $row['cost'] = preg_replace("/\.00$/", "", sprintf("%.2f", round($row['cost'] * $project_exRates[trim($translate_exRates[$row['currency']]) . '4'], 2)));
            }

            $name .= ' (��������)';
            
            $xml .= '<ad>';
            $xml .= "<id><![CDATA[{$row['id']}]]></id>";
            $xml .= '<url><![CDATA[' . $HTTP_PREFIX . $host . getFriendlyURL('project', $row['id']) .']]></url>';
            $xml .= '<title><![CDATA[' . xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($name, ENT_QUOTES))) . ']]></title>';
            $xml .= '<content><![CDATA[' . xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($descr, ENT_QUOTES) )) . ']]></content>';
            $xml .= '<type><![CDATA['.$jobtype.']]></type>';
            $xml .= '<category><![CDATA[' . $category . ']]></category>';
            $xml .= '<date><![CDATA['. date('d/m/Y H:i:00', strtotime($row['create_date'])) .']]></date>';

            // ��� �������: ������, �������, ��������
            $jobtype = isset($jobtypes[$row['kind']])?$jobtypes[$row['kind']]:'project';

            if ($city != '') $xml .= '<city><![CDATA[' . iconv( 'CP1251', 'UTF-8', htmlspecialchars($city, ENT_QUOTES) ) . ']]></city>' ."\n";; 
            $xml .= '<region><![CDATA[' . iconv( 'CP1251', 'UTF-8', htmlspecialchars($location, ENT_QUOTES) ) . ']]></region>' ."\n";;

            $xml .= '</ad>' ."\n";
        }

        $xml .= '</trovit>' . "\n";

        $file = new CFile;
        return $file->putContent($filename, $xml);        
    }

    function indeedGenerateRss($filename, $prjs) {
        $HTTP_PREFIX = "https://";
        require_once ($_SERVER['DOCUMENT_ROOT']."/classes/project_exrates.php");
        $project_exRates = project_exrates::GetAll();
        $exch = array(1=>'FM', 'USD','Euro','���');
        $translate_exRates = 
        array (
            0 => 2,
            1 => 3,
            2 => 4,
            3 => 1
        );
        
        $xml  = '';
        $host = str_replace(HTTP_PREFIX, '', $GLOBALS['host']); 

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<source>' . "\n";
        $xml .= '<publisher>Free-lance.ru</publisher>' ."\n";
        $xml .= '<publisherurl>' . $HTTP_PREFIX . $host . '</publisherurl>';
        $xml .= '<lastBuildDate>' . date('r') . '</lastBuildDate>';

        $jobtypes = array(7 => 'contest', 4 => 'vacancy');

        foreach ( $prjs  as $row ) {
            /*if ( $row['moderator_status'] == '0' ) {
                continue;
            }*/
            
            $city      = ( $row['city_name'] ) ? $row['city_name'] : '';
            $location  = ( $row['country_name'] ) ? $row['country_name'] : '������';
           // $location .= ( $location ) ? ', ' . $city : $city;
            $location  = preg_replace("/, $/", "", $location);
            $location  = html_entity_decode( $location, ENT_QUOTES, 'cp1251' );
            if(!$location) $location = '������';

            // ��� �������: ������, �������, ��������
            $jobtype = isset($jobtypes[$row['kind']])?$jobtypes[$row['kind']]:'project';

            $row['categories'] = self::getProjectCategories($row['id']);
            
            $cat  = html_entity_decode( $row['cat_name'],     ENT_QUOTES, 'cp1251' );
            
            if ( !empty($row['categories']) ) { 
                $name_case = false;
                foreach($row['categories'] as $cat) {
                    $c[] = iconv( 'CP1251', 'UTF-8', htmlspecialchars($cat['category_name'], ENT_QUOTES) );
                    if ( (int) $cat['subcategory_id'] > 0 ) {
                        $c[] = iconv( 'CP1251', 'UTF-8', htmlspecialchars($cat['subcategory_name'], ENT_QUOTES) );
                        if($cat['name_case']) $name_case[] = $cat['name_case'];
                    }
                }
                $category = implode(", ", $c);
                if($name_case) $name_case = implode(". ", $name_case).". ";
                unset($c);
            }

            $name   = html_entity_decode( $name_case . $row['project_name'],     ENT_QUOTES, 'cp1251' );
            $descr  = html_entity_decode( $row['descr'],     ENT_QUOTES, 'cp1251' );
            unset($name_case);
            $currency = '';
            switch($row['currency']) {
                case 0: $currency = '$'; break;
                case 1: $currency = ' Euro'; break;
                case 2: $currency = ' ���.'; break;
                case 3: $currency = ' ���.'; break;
            }
            
            if($row['cost'] && $row['currency']==3) {
                $row['cost'] = preg_replace("/\.00$/", "", sprintf("%.2f", round($row['cost'] * $project_exRates[trim($translate_exRates[$row['currency']]) . '4'], 2)));
            }

            $name .= ' (��������)';
            
            $xml .= '<job>' ."\n";
            $xml .= '<title><![CDATA[' . xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($name, ENT_QUOTES))) . ']]></title>' . "\n";
            $xml .= '<date><![CDATA['. date('r', strtotime($row['create_date'])) .']]></date>' . "\n";
            $xml .= '<referencenumber><![CDATA[' . $row['id'] . ']]></referencenumber>'. "\n";
            $xml .= '<url><![CDATA[' . $HTTP_PREFIX . $host . getFriendlyURL('project', $row['id']) .']]></url>' ."\n";;
            //$xml .= '<company><![CDATA[Big ABC Corporation]]></company>';
            if($city != '') $xml .= '<city><![CDATA[' . iconv( 'CP1251', 'UTF-8', htmlspecialchars($city, ENT_QUOTES) ) . ']]></city>' ."\n";; 
            $xml .= '<country><![CDATA[' . iconv( 'CP1251', 'UTF-8', htmlspecialchars($location, ENT_QUOTES) ) . ']]></country>' ."\n";;
            $xml .= '<description><![CDATA[' . xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($descr, ENT_QUOTES) )) . ']]></description>' ."\n";; //
            $xml .= $row['cost'] ? ('<salary><![CDATA[' . (iconv( 'CP1251', 'UTF-8', $row['cost'].$currency)) . ']]></salary>' ."\n") : "";//
            $xml .= '<type><![CDATA['.$jobtype.']]></type>';
            $xml .= '<category><![CDATA[' . $category . ']]></category>' ."\n";;//
            //$xml .= '<experience><![CDATA[' . ($row['kind']==7 ? dateFormat("d.m.Y",$row['end_date']) : '') . ']]></experience>' ."\n";;
            $xml .= '</job>' ."\n";
        }
        
        $xml .= "</source>";
        
        $file = new CFile;
        return $file->putContent($filename, $xml);
       
    }
    
    /**
     * ������� xml ���� ��� Jooble.ru
     * 
     * @param   $filename   string  ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     * @param string $interval �������� (1 day, 2 days, 1 month)
     * @return text $filename ������ ���� � ����� ���� webdav ������ ��������� ������������ xml
     */
    function joobleGenerateRss($filename, $prjs) {
        require_once ($_SERVER['DOCUMENT_ROOT']."/classes/project_exrates.php");
        $project_exRates = project_exrates::GetAll();
        $exch = array(1=>'FM', 'USD','Euro','���');
        $translate_exRates = array
        (
        0 => 2,
        1 => 3,
        2 => 4,
        3 => 1
        );
        
        $xml  = '';
        $host = str_replace(HTTP_PREFIX, '', $GLOBALS['host']);
        $HTTP_PREFIX = "https://";

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<source creation-time="' . date('Y-m-d H:i:s') . ' GMT+3" host="' . $host . '">' . "\n";
        $xml .= '   <jobs>' . "\n";

        foreach ( $prjs  as $row ) {
            $city      = ( $row['city_name'] ) ? $row['city_name'] : '';
            $location  = ( $row['country_name'] ) ? $row['country_name'] : '';
            $location .= ( $location ) ? ', ' . $city : $city;
            $location  = preg_replace("/, $/", "", $location);
            $location  = html_entity_decode( $location, ENT_QUOTES, 'cp1251' );
            if(!$location) $location = '������';
            
            $row['categories'] = self::getProjectCategories($row['id']);
            if ( !empty($row['categories']) ) { 
                $name_case = false;
                foreach($row['categories'] as $cat) {
                    if ( (int) $cat['subcategory_id'] > 0 ) {
                        if($cat['name_case']) $name_case[] = $cat['name_case'];
                    }
                }
                if($name_case) $name_case = implode(". ", $name_case).". ";
            }
            
            $cat  = html_entity_decode( $row['cat_name'],     ENT_QUOTES, 'cp1251' );

            $name  = html_entity_decode( $name_case . $row['project_name'],     ENT_QUOTES, 'cp1251' );
            unset($name_case);
            $descr  = html_entity_decode( $row['descr'],     ENT_QUOTES, 'cp1251' );

            $contacts = $row['e_login'];
            $contacts = ($row['e_surname'] ? html_entity_decode($row['e_surname'], ENT_QUOTES, 'cp1251' ).', '.$contacts : $contacts);
            $contacts = ($row['e_name'] ? html_entity_decode($row['e_name'], ENT_QUOTES, 'cp1251' ).' '.$contacts : $contacts);
            $currency = '';
            switch($row['currency']) {
                case 0: $currency = '$'; break;
                case 1: $currency = ' Euro'; break;
                case 2: $currency = ' ���.'; break;
                case 3: $currency = ' ���.'; break;
            }
            
            if($row['cost'] && $row['currency']==3) {
                $row['cost'] = preg_replace("/\.00$/", "", sprintf("%.2f", round($row['cost'] * $project_exRates[trim($translate_exRates[$row['currency']]) . '4'], 2)));
            }

            $xml .= '        <job id="'.$row['id'].'">
            <link>' . $HTTP_PREFIX . $host . getFriendlyURL('project', $row['id']) . '</link>
            ';
            
            $name .= ' (��������)';
         
            $xml .= '           <name>' . xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($name, ENT_QUOTES) )) . '</name>';
            $xml .= '<description>'. xmloutofrangechars(iconv( 'CP1251', 'UTF-8', htmlspecialchars($descr, ENT_QUOTES) )).'</description>
                    <region>' . iconv( 'CP1251', 'UTF-8', htmlspecialchars($location, ENT_QUOTES) ) . "</region>
                    <salary>".($row['cost'] ? iconv( 'CP1251', 'UTF-8', $row['cost'].$currency) : '')."</salary>
                    <contacts>".iconv( 'CP1251', 'UTF-8',$contacts)."</contacts>
                    <company></company>
                    <expire>".($row['kind']==7 ? dateFormat("d.m.Y",$row['end_date']) : '')."</expire>
                    <updated>".dateFormat("d.m.Y", $row['create_date'])."</updated>
        </job>\n";
            
        }
        
        $xml .= "</jobs>\n</source>";
        
        $file = new CFile;
        return $file->putContent($filename, $xml);
       
    }   

    /**
     * �������� ��������� N �������������� ��������.
     *
     * ��������! ������������ � externalApi.
     * @see externalApi_Freetray
     *
     * @param integer $kind         ��� �������� (-1=5=��� �������; 2=��������; 4=� ����; 6=������ ��� ���)
     * @param integer $limit        ������� ������� ��������
     * @param array   $filter       ������ � �������� ��������              
     *
     * @return array    ������ ��������.
     */
    function getLastProjects($kind = -1, $filter = NULL, $limit = 'ALL', $is_tray = false) 
    {
        global $DB;
        
        $addit = 'p.closed = false AND p.kind <> 9';
        //$addit = 'p.closed = false AND p.kind <> 9 AND p.state = '.self::STATE_PUBLIC;
        //$addit = "p.closed = false AND p.kind <> 9 AND NOT(p.payed = 0 AND p.kind = ".self::KIND_VACANCY." AND p.state = ".self::STATE_MOVED_TO_VACANCY.") ";
        
        if($kind == 6) $addit .= ' AND p.pro_only = true';
        else if($kind == 2) $addit .= " AND (p.kind = 2 OR p.kind = 7)";
        else if($kind != -1 && $kind != 5) $addit .= " AND p.kind = {$kind}";
        
        $filterSql = new_projects::createFilterSql($filter, $kind);
        $limit1 = $limit;
        $limit2 = '';
        if (is_int($limit)) {
            $limit1 *= 2;
            $limit2 = " LIMIT {$limit} ";
        }
        if($is_tray === false) {
            $order = "p.post_date";
        } else {
            $order = "p.create_date";
        }
        
        $sql = "
        SELECT p.*, f.path||f.fname as logo, e.is_pro, e.is_verify
            FROM (
              SELECT p.*, NULL AS category, NULL AS subcategory
                FROM projects p
                LEFT JOIN projects_blocked pb ON p.id = pb.project_id

               WHERE {$addit} {$filterSql} AND pb.project_id IS NULL /*AND (p.moderator_status IS NULL OR p.moderator_status > 0)*/
               ORDER BY post_date DESC LIMIT {$limit1}
             ) as p

          INNER JOIN
            employer e
              ON e.uid = p.user_id
             AND e.is_banned = '0'
          LEFT JOIN
            file_projects f
              ON f.id = p.logo_id
             
           ORDER BY {$order} DESC {$limit2}
        ";

        if($rows = $DB->rows($sql))
            foreach($rows as &$row){
            $cats = self::getProjectCategories($row['id'], true);
                $row['category'] = $cats['category_id'];
                $row['subcategory'] = $cats['subcategory_id'];
            }
        return $rows;
    }

    /**
     * ���������� ������ �������� ������������ ������� �����
     * 
     * @return array		������ �������� ������������ ������� �����
     */
    function getTopProjects($check_blocked = true, $limit = 'ALL') {
        global $DB;
        if($check_blocked) {
            $join_blocked = "
              INNER JOIN employer e ON e.uid = p.user_id AND e.is_banned = '0' 
              LEFT JOIN projects_blocked pb ON p.id = pb.project_id 
            ";
            $where_blocked = "AND pb.project_id IS NULL";
        }
        $sql = "
          SELECT p.*
            FROM projects p
          {$join_blocked}
          WHERE p.closed = false AND (p.top_to >= now() OR p.strong_top = 1) {$where_blocked}
          ORDER BY p.strong_top, p.top_from DESC
          LIMIT {$limit}
        ";

        return $DB->rows($sql);
    }
    
	/**
	 * ���������, ����� �� ����� RSS �� ����.
	 *
	 * @param integer $kind     ��� �������� (-1=5=��� �������; 2=��������; 4=� ����; 6=������ ��� ���)
	 * @param integer $category ���������
	 * @param integer $service  ��� ������� (1 - RSS, 2 - ������.������)
	 * @return boolean true - ���� ���� ����� ������, ����� false
	 */
    function checkRss( $kind, $category = 0, $service = 1, $subcategory=0 ) {
        global $DB;
        $category = (int)$category;
        $sql = "SELECT 1 FROM projects_rss 
                WHERE COALESCE(last_modified,'epoch') > COALESCE(last_created,'epoch') 
                AND kind = ?i AND category = ?i AND service = ?i AND sub_category = ?i LIMIT 1";
        if($res = $DB->query($sql, $kind, $category, $service, $subcategory))

            return !!pg_num_rows($res);
        return false;
    }
      
	/**
	 * ������������ ���� ���������� ��������� RSS �� ����.
	 *
	 * @param integer $kind     ��� �������� (-1=5=��� �������; 2=��������; 4=� ����; 6=������ ��� ���)
	 * @param integer $category ���������
	 * @param integer $service  ��� ������� (1 - RSS, 2 - ������.������)
	 * @return boolean true - ���� ��� ������ �������, ����� false
	 */
    function regRss( $kind, $category = 0, $service = 1, $sub_category = 0 ) {
        global $DB;
    	if (!$kind) $kind = 1;
    	if (!$category) $category = 0;
        if (!$sub_category) $sub_category = 0;
        $where = 'WHERE kind = ?i AND category = ?i AND service = ?i AND sub_category = ?i';
        $sql = "
          SELECT * FROM projects_rss {$where} FOR UPDATE NOWAIT; -- �� �����
          UPDATE projects_rss SET last_created = now() {$where}
        ";
        $res = $DB->query($sql, $kind, $category, $service, $sub_category, $kind, $category, $service, $sub_category);
        if($res && !pg_affected_rows($res))
            return $DB->query("INSERT INTO projects_rss(kind,category,last_created,last_modified,service,sub_category) VALUES(?i, ?i, now(), now(), ?i, ?i)", $kind, $category, $service, $sub_category);
        return !!$res;
    } 

	/**
	 * ���������� ���������� � ���������� ���������� ���������� ������� �� �������
	 *
	 * @param integer $pay_answers - ���������� ���������� ������� ������� �� �������
	 * @param integer $answers - ���������� ���������� ���������� ������� �� �������
	 * @return string
	 */   
    function showUserOffers($pay_answers, $answers)
    {
    	$pay_answers = intval($pay_answers);
    	$answers = intval($answers);
	
        return "������ �� �������: <A href=\"/service/offers/\">����������</A> {$answers} / <A href=\"/service/offers/\">�������</A> {$pay_answers}";
    }
	
	
	/**
	 * ��������� ���������� � ��������� ������� � projects_payed
	 *
	 * @param   array    $items    ������ � ������� � ��������� �������
	 * @param   integer  $prj_id   id �������
	 * @param   integer  $bill_id  id �������
	 * @param   integer  $topDays  ���-�� ���� ��� ����������� ������� �������
	 */
	function SavePayedInfo($items, $prj_id, $bill_id, $topDays) {
        global $DB;
		$sql = '';
		$addedPrc = is_pro() ? 0 : self::PRICE_ADDED;
		if ($items['logo']) {
            $sql .= ",({$prj_id}, {$bill_id}, " . self::PAYED_IDX_LOGO . ", " . (is_pro() ? self::PRICE_LOGO : self::PRICE_LOGO_NOPRO) . ", 1)";
        }
        if ($items['color']) {
            $sql .= ",({$prj_id}, {$bill_id}, " . self::PAYED_IDX_COLOR . ", " . self::PRICE_COLOR . ", 1)";
        }
        if ($items['bold']) {
            $sql .= ",({$prj_id}, {$bill_id}, " . self::PAYED_IDX_BOLD . ", " . (self::PRICE_BOLD + $addedPrc) . ", 1)";
        }
        if ($items['top']) {
            $nPrice = ( $this->isKonkurs() ) ? ( (is_pro() ? self::PRICE_CONTEST_TOP1DAY_PRO : self::PRICE_CONTEST_TOP1DAY) + $addedPrc) : ( (is_pro() ? self::PRICE_TOP1DAYPRO : self::PRICE_TOP1DAY) + $addedPrc);
            $sql .= ",({$prj_id}, {$bill_id}, 3, " . ($nPrice * $topDays) . ", {$topDays})";
        }
        if ($items['office']) {
            $sql .= ",({$prj_id}, {$bill_id}, " . self::PAYED_IDX_OFFICE . ", " . $this->getProjectInOfficePrice(is_pro()) . ", 1)";
        }
        if ($sql) $DB->query("INSERT INTO projects_payments(project_id, opid, pay_type, ammount, trs_sum) VALUES".substr($sql, 1));
	}
	
    /**
     * �������� ���� ���������� ������� ("�������" ������)
     *
     * @param integer $id �� �������
     * @return boolean ���� ���� ������ ���������� false, ����� true
     */
    function UpPublicProject($id, $uid, $tr_id, &$error) {
        global $DB;
        $error = NULL;
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/account.php");
        $account = new account();
        if (!$tr_id) {
            $account->view_error("���������� ��������� ����������. ���������� ��������� �������� � ������ ������.");
            $error['noxact'] = 1;
            return false;;
        }

        $account->GetInfo($uid, true);

        $prj = self::getPrj($id);
        $kind = $prj['kind'];
        $cTime = date('Y-m-d H:i:s');
        if ( $prj['top_from'] && $prj['top_from'] <= $cTime && $prj['top_to'] >= $cTime ) {
            $error = '������ ��������� ������������ ������';
            return false;
        }
        $is_konkurs = self::isKonkurs($kind);
        $op_code = $is_konkurs ? ( is_pro() ? self::OPCODE_KON_UP : self::OPCODE_KON_UP_NOPRO ) : ( is_pro() ? self::OPCODE_UP : self::OPCODE_UP_NOPRO );
        $price = self::getPriceByCode($op_code);
        $descr = '������ '.($is_konkurs ? '��������' : '�������')." #{$id}";

        // ������ � ��������� �����, ���� ���������� �������
        if($account->bonus_sum >= $price) {
            $descr = '������ '.($is_konkurs ? '��������' : '�������')." #{$id} (������� � ��������� �����)";
            if(!($error['buy'] = $account->BuyFromBonus($bill_id, $tr_id, $op_code, $uid, $descr, $descr))) {
                $sql = "UPDATE projects SET post_date = now(), is_upped = true WHERE id = ?i";
                if($DB->query($sql, $id))
                    return true;
            }
            return false;
        }

        if($account->sum < $price) {
            $error['nomoney'] = $price - $account->sum;
            return false;
        }

        if(!($error['buy'] = $account->Buy($bill_id, $tr_id, $op_code, $uid, $descr, $descr))) {
            $sql = "UPDATE projects SET post_date = now(), is_upped = true WHERE id = {$id}";
            if($DB->query($sql, $id))
                return true;
        }
        return false;
    }
    
    /**
     * �������� ���� ���������� ������� ���������. ������� ��� ����������� ("�������" ������)
     *
     * @param integer $id �� �������
     * @return boolean ���� ���� ������ ���������� false, ����� true
     */
    function FreeUpPublicProject($id) {
        global $DB;
        if(!hasPermissions("projects")) return false;
        $sql = "UPDATE projects SET post_date = now()  WHERE id = {$id}";
        if($DB->query($sql, $id)) {
        	$sql = "UPDATE projects SET top_from = now()  WHERE id = {$id} AND top_to > now()";
        	$DB->query($sql, $id);
            return true;
        }
        return false;
    }
    
    /**
     * ������� ������ � ������������ - �������� top_from
     * 
     * @param  int $id ID �������
     * @param  int $uid UID ������������ 
     * @param  array $error ���������� ��������� �� �������
     * @return bool true - �����, false - ������
     */
    function topPublicProject($id, $uid, &$error) {
        global $DB;
        
        $error = NULL;
        
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/account.php");
        
        $account = new account();
        $tr_id   = $account->start_transaction( $uid );
        
        if ( !$tr_id ) {
            $account->view_error( "���������� ��������� ����������. ���������� ��������� �������� � ������ ������." );
            $error['noxact'] = 1;
            return false;
        }
        
        $account->GetInfo( $uid, true );
        
        $kind       = self::GetField( $id, 'kind' );
        $is_konkurs = self::isKonkurs( $kind );
        $descr      = '�������� ������������� ' . ( $is_konkurs ? '��������' : '�������' ) . " #{$id}";
        $price      = self::getPriceByCode( is_pro() ? self::OPCODE_TOP : self::OPCODE_TOP_NOPRO );

        
        // ������ � ��������� �����, ���� ���������� �������
        if ( $account->bonus_sum > $price ) {
            $descr .= ' (������� � ��������� �����)';
            
            if ( !$error['buy'] = $account->BuyFromBonus($bill_id, $tr_id, ( is_pro() ? self::OPCODE_TOP : self::OPCODE_TOP_NOPRO ), $uid, $descr, $descr) ) {
                $sql = "UPDATE projects SET top_from = now() WHERE id = ?i";
                
                if ( $DB->query($sql, $id) ) {
                    return true;
                }
            }
            return false;
        }
        
        // ������ � �������� �����, ���� ���������� �������
        if ( $account->sum < $price ) {
            $error['nomoney'] = $price - $account->sum;
            return false;
        }

        if ( !$error['buy'] = $account->Buy($bill_id, $tr_id, ( is_pro() ? self::OPCODE_TOP : self::OPCODE_TOP_NOPRO ), $uid, $descr, $descr) ) {
            $sql = "UPDATE projects SET top_from = now() WHERE id = {$id}";
            
            if ( $DB->query($sql, $id) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * �������� ���� �������
     * @param integer $kind   �������� ���� projects.kind 
     * @return boolean   ������� ��� ������� ������. 
     */
    function isKonkurs($kind) {
        return ($kind==2 || $kind==7);
    }

    /**
     * ���������� ��������� ���������� ��������.
     * @param bool $isPRO - ����� ���� ������� ��� ��� �����, ���� �� ������, �� ���/����� ������������ �������������
     * @return float   ���������.
     */
    function getKonkursPrice($isPRO = null) {
        if ($isPRO === null) {
            $isPRO = is_pro();
        }
        return self::getPriceByCode($isPRO ? self::OPCODE_KON : self::OPCODE_KON_NOPRO);
    }

    /**
     * ���������� ��������� ���������� �������� � ����.
     * @todo: ����� ���������� � ������ is_pro ��� ��� ����� �� ������� ��� ���� ��� ����������
     * 
     * @return float   ���������.
     */
    public static function getProjectInOfficePrice($is_pro = false) {
        return $is_pro ? self::getPriceByCode(self::OPCODE_PRJ_OFFICE_PRO) : self::getPriceByCode(self::OPCODE_PRJ_OFFICE);
    }
    
    public function isPayedProjectInOffice($project_id) {
        global $DB;
        if(!$project_id) return false; 
        return $DB->val('SELECT id FROM projects_payments WHERE project_id = ?i AND pay_type = ?i', (int) $project_id, (int) self::PAYED_IDX_OFFICE);
    }
    
    
    
    /**
     * ���������� ��������� �� ���� ��������.
     * @param integer $op_code   ��� �������� 
     * @return float   ���������.
     */
    function getPriceByCode($op_code) 
    {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/op_codes.php");
        $op_codes = new op_codes();
        return round($op_codes->getPriceByOpCodeWithoutDiscount($op_code), 2);
    }




    /**
     * ������������ � ���������� ���������� �������� �� ��������� ��� ����������� � �����,
     * ���������� � memcache �� 30 �����.
     * ���� $uid != null ����� �� ��������� ��������� ����������� ����������
     * ���������� �������� ������������ � ����������� � �����.
     *
     * @param integer $kind  ��� �������� (-1=5=��� �������; 2=��������; 4=� ����; 6=������ ��� ���)
     * @param integer $page  ����� ������� ��������
     * @param integer $uid   �� ������������, ���� ����.
     * @return integer
     */
    function getProjectsCount($kind = 0, $page = 1, $uid = null, $is_moder = null) 
    {
        //$_uid = intval($uid);
        $_where = '';
        //$_where = !$is_moder?" AND (p.status = ".self::STATE_PUBLIC." OR e.uid = {$_uid})":'';
        //$_where = !$is_moder?"AND NOT(p.payed = 0 AND p.kind = ".self::KIND_VACANCY." AND p.state = ".self::STATE_MOVED_TO_VACANCY.")":'';
        
        $sql = "SELECT
                    SUM(pro_only::int) as pro_only,
                    SUM((kind = 1)::int) as prj_fl,
                    SUM((kind IN (2,7))::int) as prj_kon,
                    SUM((kind = 4)::int) as prj_off
                FROM projects p
                LEFT JOIN projects_blocked pb ON pb.project_id = p.id
                INNER JOIN employer e ON e.uid = p.user_id AND e.is_banned = '0'
                WHERE 
                    ".(get_uid(false) ? '' : 'COALESCE(p.hide, false) = false AND ').
                    "p.closed = false AND pb.project_id IS NULL AND p.kind <> 9 {$_where}";
          
        $memBuff = new memBuff();
        $a_num_prjs = $memBuff->getSql($error, $sql, 1800);
        if(!$a_num_prjs) return 0;
        
        $res = $a_num_prjs[0];

        $cnt = 0;
        
        switch ($kind) {
            case 6: //������ ���
                $cnt = $res['pro_only'];
                $where_kind = ' AND pro_only = true AND kind <> 9';

                break;
            case 1: //�������
                $cnt = $res['prj_fl'];
                $where_kind = ' AND kind = 1 ';
                break;
            case 2: //��������
                $cnt = $res['prj_kon'];
                $where_kind = ' AND kind IN (2,7) ';
                break;
            case 4: //����
                $cnt = $res['prj_off'];
                $where_kind = ' AND kind = 4 ';
                break;
            default: //���
                $cnt = $res['prj_fl'] + $res['prj_kon'] + $res['prj_off'];
                $where_kind = ' AND kind <> 9';
        }

        //����� �������� ������� �� ��������� ���������, � ��������
        //� ������ ���-�� ���������� �������� ������������
        if($uid && $page+10 >= ceil($cnt/projects::PAGE_SIZE)) {
            $sql = "SELECT COUNT(*) as cnt FROM projects_blocked pb
                    INNER JOIN projects p ON p.id = pb.project_id
                    WHERE p.user_id = {$uid} {$where_kind}";

            $res_cnt = $memBuff->getSql($error, $sql, 300);
            
            if($res_cnt) {
                $ban = $res_cnt[0];
                $cnt += (int)$ban['cnt'];
            }
        }

        return $cnt;
    }
    
    /**
     * ���������� ������� �� ������� ������� (��������) �� ��������� ������
     * 
     * @param integer $kind    ��� (�������, ������)
     * @param integer $size    ��������
     */
    public function getTopProjectBudget($kind = 1, $size = 3, $interval = '7 days') {
        global $DB;
        
        $sql = "SELECT p.*
                FROM projects p
                LEFT JOIN project_exrates e ON e.id = 
                        (CASE 
                                WHEN p.currency = 0 THEN " . project_exrates::USD ." 
                                WHEN p.currency = 1 THEN " . project_exrates::EUR ."  -- EUR 
                                WHEN p.currency = 2 THEN " . project_exrates::RUR ."  -- RUR
                                ELSE " . project_exrates::FM ."  -- FM
                        END) * 10 + " . project_exrates::RUR . "
                WHERE p.kind = ?i AND p.create_date > NOW() - ?::interval 

                ORDER BY ( p.cost * e.val ) DESC, p.create_date DESC 
                LIMIT ?i";
                
        return $DB->rows($sql, $kind, $interval, $size);
    }
    
    /**
     * ����� ������� �� �� ��
     * 
     * @global type $DB
     * @param array $ids
     * @return boolean
     */
    public function getProjectsById($ids) {
        global $DB;
        if(!is_array($ids)) return false;
        
        $sql = "SELECT * FROM projects WHERE id IN (?l)";
        return $DB->rows($sql, $ids);
    }
    
    /**
     * ���� ������� ��� ���������� ���������
     */
    private static $contestTaxes = array(
        'nopro' => array(
            '121' => array('id' => 121, 'min' => 5000, 'max' => 10000),
            '122' => array('id' => 122, 'min' => 10001, 'max' => 50000),
            '123' => array('id' => 123, 'min' => 50001, 'max' => 100000),
            '124' => array('id' => 124, 'min' => 100001, 'max' => 500000),
            '125' => array('id' => 125, 'min' => 500001),
        ),
        'pro' => array(
            '126' => array('id' => 126, 'min' => 5000, 'max' => 10000),
            '127' => array('id' => 127, 'min' => 10001, 'max' => 50000),
            '128' => array('id' => 128, 'min' => 50001, 'max' => 100000),
            '129' => array('id' => 129, 'min' => 100001, 'max' => 500000),
            '130' => array('id' => 130, 'min' => 500001),
        ),
    );
    private static $contestTaxes_;
    /**
     * ���� �������� �� ������� op_codes ������� ����������� ��� ������ ��������� ���������� ��������
     */
    public static $contestTaxesCodes = array(121, 122, 123, 124, 125, 126, 127, 128, 129, 130);
    
    
    /**
     * ���������� ������ �� ���������� ��������
     * @return array
     */
    public static function getContestTaxes() {
        global $DB;
        
        // ���� ������ � ������ ��� ��� �����������, �� ���������� ���
        if (self::$contestTaxes_) {
            return self::$contestTaxes_;
        }
        
        
        // ����� ���� ���������� �������� �� ����
        $contestTaxesCodesSql = implode(',', self::$contestTaxesCodes);
        $sql = "
            SELECT id, sum
            FROM op_codes
            WHERE id IN ($contestTaxesCodesSql)";
        $rows = $DB->cache(1800)->rows($sql);
        
        $contestTaxes_ = self::$contestTaxes;
        foreach ($rows as $row) {
            if (isset($contestTaxes_['nopro'][$row['id']])) {
                $contestTaxes_['nopro'][$row['id']]['sum'] = (int)$row['sum'];
            } else {
                $contestTaxes_['pro'][$row['id']]['sum'] = (int)$row['sum'];
            }
        }
        
        self::$contestTaxes_ = $contestTaxes_;
        
        return $contestTaxes_;
    }
    
    /**
     * ���������� ��������� ���������� ��������
     */
    public static function getContestTax ($budget, $isPro, &$opCode = null) {
        if ($budget < self::NEW_CONTEST_MIN_BUDGET) {
            return null;
        }
        $contestTaxes = self::getContestTaxes();
        $proKey = $isPro ? 'pro' : 'nopro';
        foreach ($contestTaxes[$proKey] as $tax) {
            if ($tax['min'] <= $budget && $budget <= $tax['max']) {
                break;
            }
        }
        $opCode = $tax['id'];
        return $tax['sum'];
    }
    
    /**
     * ���������� ��� �������� ������ ��������, � ����������� �� ��� � �������
     */
    public static function getContestTaxOpCode ($budget, $isPro) {
        self::getContestTax ($budget, $isPro, $opCode);
        return $opCode;
    }
    
    /**
     * ������� �� ����� ������� �������� ��������� ���������� ���������
     * ���� ������� ���� ���������� ��������, �� ���� ��� ������ ���� ����� ����� �������, �� �������� false
     * 
     * @param string $postDate ���� ���������� ������� (��������)
     */
    public static function isNewContestBudget ($postDate = null) {
        return false;
        /*
        $newContestBudgetTime = strtotime(new_projects::NEW_CONTEST_BUDGET_DATE);
        if ($postDate) {
            return strtotime($postDate) > $newContestBudgetTime;
        }            
        return time() > $newContestBudgetTime;
        */
    }

    /**
     * ���������� ������ �� ����� ������ ���������
     */
    public static function getContestOpCodes () {
        //return array_merge(new_projects::$contestTaxesCodes, array(new_projects::OPCODE_KON, new_projects::OPCODE_PAYED_KON, new_projects::OPCODE_KON_NOPRO, new_projects::OPCODE_PRJ_OFFICE, new_projects::OPCODE_PAYED, new_projects::OPCODE_PAYED_BNS));
        return array_merge(new_projects::$contestTaxesCodes, array(new_projects::OPCODE_KON, new_projects::OPCODE_PAYED_KON, new_projects::OPCODE_KON_NOPRO));
    }
    
    
    /**
     * ������� ����� �������� 
     * ��� �������� ���������
     */
    public function getVacancyOpCode()
    {
        return $this->isOwnerPro()?
                self::OPCODE_PRJ_OFFICE_PRO:
                self::OPCODE_PRJ_OFFICE;
    }

    

    /**
     * ���������� op_code ��� ������ �� �� ��������
     * @param string $serviceName ��������� �������� ������ �� ��������
     */
    public static function getOpCodeByService($serviceName) 
    {
        $opCode = self::OPCODE_PAYED;//false;
        
        switch ($serviceName) {
            
            case 'contest':
                $opCode = self::OPCODE_KON;
                break;
            
            case 'top':
                $opCode = self::OPCODE_TOP_NEW;
                break;
            
            case 'logo':
                $opCode = self::OPCODE_LOGO;
                break;
            
            case 'urgent':
                $opCode = self::OPCODE_URGENT;
                break;
            
            case 'hide':
                $opCode = self::OPCODE_HIDE;
                break;

            default:
                break;
            
        }
        
        return $opCode;
    }            
}

/**
 * ����� ��� ������ � ���������� ��������� ��� ���������� ��� �������������� ����.
 *
 * ������ �������������/����������� ������ � ������� ���� �� ����� ������ ����� destroy().
 * ��� ������ ������ ���������� ���������������� �����, ����� ���� ������� ����� init(),
 * ������� �������������� ��� ����������� ���������� � ����������� �� ������ ����,
 * �������������� ������� � �.�.
 * ����� ��������� ������ � ��������� �������� � ��������� ��� ��������� � ����
 * ��� ����������� ������, ���������� ������� ����� fix().
 */
class tmp_project extends new_projects
{
    /**
     * ��� ��� �������� ����� ���������� ���������� ������� � �����.
     *
     */
    const SESS_LAST_KEY  = 'public.tmp_project.last';

    /**
     * ������� ����� ��� �������� ���������� ������� � �������.
     *
     */
    const MEM_KEY_PFX  = 'public.tmp_project';

    /**
     * ����� ����� ���������� �������.
     *
     */
    const MEM_LIFE_TIME = 7200;
	
    /**
     * ��� ���������� ��� �������� ��������� ������.
     *
     */
    const TMP_DIR = 'tmpproj';

	/**
	 * ������
	 *
	 * @var array
	 */
    protected $_project;
    
    /**
     * ��������� ����� � �������
     *
     * @var object
     */
    private $_attach;

    /**
     * ���������
     *
     * @var array
     */
    private $_categories;
    
    /**
     * ���������� �� ��������
     *
     * @var array
     */
    private $_logo;
    
    /**
     * ���������� � ���. ���������� ����, ����������� �� ����� ��������������.
     *
     * @var integer
     */
    private $_addedTopDays = 0;
    
    
    /**
     * ���� � ��������� �����
     *
     * @var string
     */
    private $_tmpAbsdir;
    
    /**
     * ���������� ����
     *
     * @var string
     */
    private $_dstAbsdir;
    
    /**
     * ������������� ��������� ��������
     *
     * @var integer
     */
    private $_transactionId;
    
    /**
     * ������������� ������������
     *
     * @var integer
     */
    private $_uid;
    
    /**
     * ��������� �����
     *
     * @var array
     */
    private $_tmpFiles;
    
    /**
     * ��������� �����
     *
     * @var array 
     */
    private $_deletedFiles;
    
    /**
     * ����� ��������������
     *
     * @var boolean
     */
    private $_isEdit;
    
    /**
     * ������
     *
     * @var array
     */
    private $_buffer;

    /**
     * ���� �������� ������� � ����
     *
     * @var string
     */
    private $_memkey;
    
#public:

    /**
     * ��������� ����� ������� ������� �� ���������� ������.
     * ���������� �������� ����, ���� ������ � �������� ��������������/��������. ���� ����������� ����� get/post. ���� ������ �����,
     * ����� ������������ ����� ���������� ����.
     *
     * @param string $key   ����, ���������������� ������������� (�����������) ������.
     */
    function __construct($key)
    {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/payed.php");
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/account.php");
        if(!session_id()) session_start();
        if(!$_SESSION['uid']) return;
        $this->_memkey = self::getMemGrKey($_SESSION['login']).':'.$key;
        $memBuff = new memBuff();
        if($ss = $memBuff->get($this->_memkey)) {
            $_SESSION[self::SESS_LAST_KEY] = $key;
            foreach($ss as $member=>$value) {
                $this->$member = $value;
            }
        }
    }

    
    /**
     * (��)�������������� ��������� ������ ���� ��� ����������, ������ ��������,
     * �������������� ����� � ������.
     * ������ �������� � ������� �� ������ ��� �������.
     *
     * @param integer step     ����� ���� � ���������� �������: 0, 1, 2 ��� < 0 -- ��� �� �������, ���������� �������� ������.
     * @param integer prj_id   ��. �������������� �������. ���� �������, �� ����� ���������������� �� ���� ������� �������. �������
     *                         ��� �������������� ����� ������ ���� ��� �������� ���� ��������� � ����� ������, ����� ����� ����� ��������� ������������.
     * @return array           ����� ������� $this->_project.
     */
    function init($step, $prj_id = NULL)
    {
        // ����� �� ����� �������� � ����������������� �������.
        if(!$_SESSION['uid'])
            return $this->destroy();

        // ����� ����������, ���� ������� ������ ������������� � ���� �����-�� ������� ������ �� ������� ���,
        // ����� ������ ���� ��������� ������� � ������� ������ � ��������.
        if($step < 0 || $step==0 && $this->_project['id'] && $this->_project['payed'])
            $this->destroy();

        // ������ ������������� -- �������������� ��������� ������ �� ����.
        if($prj_id) {

            $this->destroy();
            if (!$this->_initFromDB($prj_id)) return $this->destroy();
            if ($this->CheckBlocked($prj_id) && !(hasPermissions('projects'))) return $this->destroy();
            $this->_isEdit = true;

            if($this->_project['user_id'] != $_SESSION['uid'] && !hasPermissions('projects'))
                return $this->destroy();

            $this->_uid = $this->_project['user_id'];
        }

        // ������ ��������� -- ��������� �������������.
        if(!$this->_uid || !$this->_project['login']) {

            if(!is_emp())
                return $this->destroy();

            $this->_uid = $_SESSION['uid'];
            $this->_project['login']    = $_SESSION['login'];
            $this->_project['uname']    = $_SESSION['name'];
            $this->_project['usurname'] = $_SESSION['surname'];
            $this->_project['user_id']  = $this->_uid;
            $this->_setPath();
        }
        
        // ���������� ��������� ������ ���, �.�. ������� ����� ������ ������ � ��� ����� ����������.
        $this->_project['is_pro'] = (payed::checkProByUid($this->_uid) ? 't' : 'f');

        return $this->getProject();
    }

    
    
    /**
     * ������������� ������� 
     * ��� ������������ � ����������
     * 
     * @param user $user
     * @return array
     */
    public function initForUser($user) {
        
        if (!isset($user->uid) || !$user->uid) {
            return $this->destroy();
        }
        
        $this->_uid = $user->uid;
        $this->_project['login'] = $user->login;
        $this->_project['uname'] = $user->uname;
        $this->_project['usurname'] = $user->usurname;
        $this->_project['user_id']  = $this->_uid;
        $this->_setPath();
        $this->_project['is_pro'] = (payed::checkProByUid($this->_uid) ? 't' : 'f');
        
        return $this->getProject();
    }




    /**
	 * �������� ������ ��������������
	 *
	 * @return boolean true - ���� ������ �������������, ����� false
	 */
    function isEdit() {
        return $this->_isEdit;
    }
    
    /**
     * ������������� ��������������. ������ ��� ������������!
     * @param bool $edit
     */
    function setEdit($edit) {
        $this->_isEdit = $edit;
    }
    

    /**
     * ���������� ����� ��������� ������� ��������������/������������ �������.
     * ���������� �������� ����� ����� ������ ��������� �������� ������� ����� ������ ������� ������� ������,
     * ��������, setProjectField()
     *
     * @see tmp_project::setProjectField()
     * @return array    ����� ��������� �������.
     */
    function getProject() {
        return $this->_project;
    }

    /**
     * ������������� �������� ���������� �������.
     *
     * ����� ���������� ���������� �������� ���������� �������, ������� ���������� ����� ��������.
     * ����� ������ ��������� ����� ������� $this->_project ����� ��������� �������.
     * �������� �������� ���� ������ ��������, ����� �� ��������, ��� ��� ������, � ��� ����, � �������
     * ��� ����� �����������, ����� ���� ������������ � ���� ������������:
     * 1) ��� ������ � ���� ������;
     * 2) ��� ������ ����� � ����� (��������, ��� ������).
     *
     * @param string  $field ���� �������
     * @param string  $value �������� ����
     * @return array    ����� ��������� �������.
     */
    function setProjectField($field, $value)
    {
        switch($field) {
            case 'contacts':
                $this->_project[$field] = $value;
                break;
            case 'state' :
            case 'old_state':
            case 'descr' :
            case 'kind' :
            case 'name' :
            case 'currency' :
            case 'cost' :
            case 'category' :
            case 'subcategory' :
            case 'country' :
            case 'city' :
            case 'pro_only' :
            case 'link' :
            case 'strong_top':    
            case 'is_color' :
            case 'is_bold' :
			case 'end_date' :
			case 'win_date' :
			case 'budget_type' :
			case 'priceby' :
			case 'prefer_sbr' :
            case 'urgent' :
            case 'hide' :
            case 'o_urgent' :
            case 'o_hide' :
            case 'agreement' :
            case 'verify_only' :
            case 'videolnk': 
            case 'logo_id' :
            case 'top_days' :
            case 'post_now' :
                if(get_magic_quotes_gpc())
                    $value = stripslashes($value);
                $this->_project[$field] = $value;
                break;
        }

        return $this->getProject();
    }


    /**
     * �������� ���������� ����, ����� ������������� ������ (���) ��������� ������ �����.
     *
     * @return integer
     */
    function getTopDays() {
        $td = round((strtotime($this->_project['top_to']) - strtotime($this->_project['top_from'])) / (24*3600));
        return $td > 0 ? $td : 0;
   }

    /**
     * ������ ������ -- ������� �������� ���. ������� ������ ������� �����.
     *
     * @param integer $days      ���������� ���������� ����� ����.
     * @param integer $hours     ���������� ���������� ����� �����.
     * @param integer $minutes   ���������� ���������� ����� �����.
     * @param string  $verb  	 ���������� ������ "��������" | "�������"
     * @return string ������
     */
    function getRemainingTopPeriod(&$days, &$hours, &$minutes, &$verb = NULL) {
        $time = time();
        $topTo = strtotime($this->_project['top_to']);
        if(($remTime = $topTo - $time) <= 0)
            return NULL;

        $days = (int)($remTime / (24*3600));
        $hours = (int)(($remTime-$days*24*3600) / 3600);
        $minutes = (int)(($remTime-$days*24*3600-$hours*3600) / 60);
        getSymbolicName($hours, NULL, $he);
        if($days) {
            getSymbolicName($days, NULL, $de);
            $period = $days.' '.($de==1 ? '����' : ($de==2 ? '���' : '����')).' '
                    . $hours.' '.($he==1 ? '���' : ($he==2 ? '����' : '�����'));
            $et = $de;
        }
        else {
            getSymbolicName($minutes, NULL, $me);
            $period = $hours.' '.($he==1 ? '���' : ($he==2 ? '����' : '�����')).' '
                    . $minutes.' '.($me==1 ? '������' : ($me==2 ? '������' : '�����'));


            $et = $he;


        }

        $verb = '�����'.($et==1?'��':'���');

        return $period;
    }

    /**
     * ���������� ���������� ���� ����������� ������� �����, �������� �� ����� ������ 
     *
     * @see tmp_project::setAddedTopDays()
     * @return integer
     */
    function getAddedTopDays() {
        return (int)$this->_addedTopDays;
    }

    /**
     * ��������� ���������� � ���, ����� ���������� ���� ������� ����� ����� ����.
     * ���� ���� "��������" �� N-��� ���������� ����, �� ��� N ���� ����� ������ ����� ��� �������.
     * �����, ��� ���������� ������� � ���� ����� �������������� $this->_addedTopDays, ����� ����������
     * ���� projects.top_from � projects.top_to.
     *
     * @param integer num   ���������� ����, �� ������� ����� ��������� ������ ������� �����.
     */
    function setAddedTopDays($num) {
        $this->_addedTopDays = (int)((int)$num > 0 ? $num : 0);
    }

    /**
     * ���������� ������������ �������
     * @see tmp_project::setCategories()
     *
     * @return array
     */
    function getCategories(){
        return $this->_categories;
    }

    /**
     * ��������� ������������ �������
     *
     * @param array $data ������������ �������
     */
    function setCategories($data){
        $this->_categories = $data;
    }
    

    /**
     * ���������� ���������� � ������������� ����� (������) �� ��� ��������������/�������.
     * � ������� ����� ����������� ����� ��� ����������� �� ����� ������, ��� � 
     * ��� ������������ � ���� �� �������������� �������
     *
     * @see tmp_project::_initFromDB()
     * @see tmp_project::addAttach()
     * @see tmp_project::delAttach()
     * @param integer attach_id   ������ ������� $this->_attach ��� NULL, ����� ������� ��� �����.
     * @return array
     */
    function getAttach($attach_id = NULL) {
        if($attach_id === NULL)
            return $this->_attach;
        return $this->_attach[$attach_id];
    }

    /**
     * �������� ������ ������������� �� ����� ������ ������.
     *
     * @return array
     */
    function getNewAttach() {
        $attach = NULL;
        if($this->_attach) {
            foreach($this->_attach as $f) {
                if( $f['is_new'] )
                    $attach[] = $f;
            }
        }
        return $attach;
    }

    /**
     * ��������� �� ������ ���������������� ����� � ��������� ���������� � ��� � ������ $this->_attach,
     * ������� ����� �������� ��� ���������� ������ � ����.
     *
     * @param array   files      ������ $_FILES � ����� ��� ����������� �������. ����� ���� ������� ������������ � ����������� ���.
     * @param boolean formatted   ��� �� ������������ � ���: $files = array(0=>new CFile(array('name'->...,'size'=>...)),1=>new CFile(array(...)),...).
     * @return integer 0 - ���� �� ������� ���������� �����
     */
    function addAttach($files, $formatted = false)
    {
        if(!$files)
            return 0;

        $attach = NULL;
        if(!$formatted) {
            foreach($files['name'] as $idx=>$value) {
                foreach($files as $key=>$a)
                    $att[$key] = $files[$key][$idx];
                $attach[] = new CFile($att);
            }
        }
        else {
            $attach = $files;
        }

        foreach($attach as $file) {
            if(!$file->size) continue;
            $file->server_root = 1;
            $file->table = 'file_projects';
            $file->max_size = self::MAX_FILE_SIZE;
            if(!$file->MoveUploadedFile($this->_tmpAbsDir) || !isNulArray($file->error))
                return $file->StrError();
            $this->_attach[] = array('file_id'=>$file->id, 'path'=>$file->path, 'name'=>$file->name, 'size'=>$file->size, 'ftype'=>$file->getext(), 'is_new' => true);
            $this->_tmpFiles[] = $file->name;
        }
        
        return 0;
    }

    /**
     * ��������� ����� ����������� ����������� � �������� � ��������� ���������� � ��� � ������ $this->_attach
     *
     * @param   array     $files         ������ ����������� ������
     * @param   boolean   $from_draft    ����� �� ���������
     */
    function addAttachedFiles($files, $from_draft=false) {
        global $DB;

        $old_files = array();
        if($this->_attach) {
            foreach($this->_attach as $f) {
                array_push($old_files, $f['file_id']);
            }
        }
        if($from_draft) {
            if($this->_attach) {
                foreach($this->_attach as $attach_id=>$attach) {
                    if(!in_array($attach['file_id'], $old_files)) { $this->delAttach($attach_id, true); }
                }
            }
        }
        if($files) {
            foreach($files as $file) {
                switch($file['status']) {
                    case 4:
                        // ������� ����
                        if($this->_attach) {
                            foreach($this->_attach as $attach_id=>$attach) {
                                if($attach['file_id']==$file['id']) {
                                    $this->delAttach($attach_id, true);
                                }
                            }
                        }
                        break;
                    case 1:
                    case 3:
                        // ��������� ����
                        if(!in_array($file['id'], $old_files)) {
                            if(in_array($file['id'], $old_files)) {
                                $need_copy = false;
                            } else {
                                $need_copy = true;
                            }
                            $cFile = new CFile($file['id']);
                            $cFile->table = 'file_projects';
                            $ext = $cFile->getext();
                            if($need_copy) {
                                $tmp_dir = $this->_tmpAbsDir;
                                $tmp_name = $cFile->secure_tmpname($tmp_dir, '.'.$ext);
                                $tmp_name = substr_replace($tmp_name,"",0,strlen($tmp_dir));
                                $cFile->_remoteCopy($tmp_dir.$tmp_name, true);
                            }
                            $this->_attach[] = array('file_id'=>$cFile->id, 'path'=>$cFile->path, 'name'=>$cFile->name, 'size'=>$cFile->size, 'ftype'=>$cFile->getext(), 'is_new' => true);
                            $this->_tmpFiles[] = $cFile->name;
                        }
                        break;
                }
            }
        }
    }

    /**
     * ������� ���� �� ������� $this->_attach. ���� ���� ���������, �� ����� ������� � �������, ����� �����������
     * � ����. ������� ��� ���������� ���������.
     *
     * @param integer $attach_id   ������������� �����. ������� ���� �� ����, ���� ��� ������� ��������� ������ ���������� ����� � ������� $this->_attach.
     * @param boolean $fix   ������������ � ���� ��� ���.
     */
    function delAttach($attach_id, $fix = false)
    {
        if($f = $this->_attach[$attach_id]) {
            if($f['path']==$this->_tmpAbsDir) {
                $cfile = new CFile();
                $cfile->Delete(0, $this->_tmpAbsDir, $f['name']);
            }
            else {
                $this->_deletedFiles[] = $f;
            }
            unset($this->_attach[$attach_id]);
            if($fix)
                $this->fix();
        }
    }
    
    function clearAttaches () {
        $this->_attach = array();
    }

    /**
     * @see tmp_project::_logo
     * @return array   ���������� � ��������
     */
    function getLogo() {
        return $this->_logo;
    }

    /**
     * ��������� ������� � �������
     * 
     * @param object file  ���� �������� (@see CFile)
     * @return string|integer ��������� �� ������. ���� 0
     */
    function setLogo($file)
    {
        if(!$file->size) return 0;
        $file->server_root = 1;
        $file->table = 'file_projects';
        $file->max_size = self::LOGO_SIZE;
        $file->disable_animate = true;
        $file->max_image_size = array("width"=>self::LOGO_WIDTH, "height"=>self::LOGO_HEIGHT, "less"=>1);
        
        if(!$file->MoveUploadedFile($this->_tmpAbsDir) || !isNulArray($file->error))
            return $file->StrError();

        if($file->image_size['width'] != self::LOGO_WIDTH) {
            $file->Delete($file->id);
            return '������ �������� ������ ���� ����� '.self::LOGO_WIDTH.' ��������.';
        }

        $this->delLogo();
        $this->_tmpFiles[] = $file->name;
        $file_name = get_unanimated_gif($_SESSION['login'], $file->name, $file->path);
        $this->_tmpFiles[] = $file_name;
        $this->_logo = array('id'=>$file->id, 'path'=>$file->path, 'name'=>$file->name, 'size'=>$file->size, 'ftype'=>$file->getext());
        return 0;
    }
    
    /**
     * ������������� �������� ���� �� ��� ����
     * 
     * @param type $file
     */
    function initLogo($file, $link=null) {
        if($link !== null) $this->_project['link'] = $link;
        $this->_logo = array('id'=>$file->id, 'path'=>$file->path, 'name'=>$file->name, 'size'=>$file->size, 'ftype'=>$file->getext());
    }
    
    /**
     * ������� ���������� � ��������
     */
    function clearLogo() {
        $this->_logo = null;
        $this->_project['link'] = '';
    }
    
    /**
     * ��������� ������� � �������
     * 
     * @param object file  ���� �������� (@see CFile)
     * @return string|integer ��������� �� ������. ���� 0
     */
    function setLogoNew(CFile $file)
    {
        $this->delLogo();
        $this->_tmpFiles[] = $file->name;
        $file_name = get_unanimated_gif($_SESSION['login'], $file->name, $file->path);
        $this->_tmpFiles[] = $file_name;
        $this->_logo = array('id'=>$file->id, 'path'=>$file->path, 'name'=>$file->name, 'size'=>$file->size, 'ftype'=>$file->getext());
        return 0;
    }

    /**
     * ������� �������
     * @param boolean $fix   ������������ � ���� ��� ���.
     */
    function delLogo($fix = false)
    {
        if($f = $this->getLogo()) {
            if($f['path']==$this->_tmpAbsDir){
                $cfile = new CFile();
                $cfile->Delete($f['id']);
            }
            else
                $this->_deletedFiles[] = $f;
            $this->_logo = NULL;
            if($fix)
                $this->fix();
        }
    }

    
    /**
     * ������ �������� ������ � ������ �� �������
     * 
     * @param type $data
     * @return boolean
     */
    function addSimpleProject($data = array())
    {
        if(empty($data)) return false;

        if (isset($data['categories'])) {
            $this->setCategories($data['categories']);
        }
        
        if (!isset($data['budget_type'])) {
            $data['budget_type'] = 0;
        }
        
        if (!isset($data['prefer_sbr'])) {
            $data['prefer_sbr'] = false;
        }
        
        foreach ($data as $key => $value) {
            
            switch ($key) {
                case 'name': $value = substr(antispam($value), 0, 512); break;
                case 'descr': $value = antispam($value); break;
            }
            
            $this->setProjectField($key, $value);
        }
        
        $prePrj = $this->_preDb(0, 0);
        
        $success = $this->addPrj(
                $this->_project, 
                $this->_attach, 
                array_reverse($this->_categories));
        
        if (!$success) {
            $this->_postDbFailed($prePrj, 0, 0);
            
            return false;
        }
        
        $ret = $this->getProject();
        $this->_postDbSuccess();
        
        return $ret;
    }






    /**
     * ��������� ��������� ������ � ����.
     *
     * @param integer $buyer_id     ��. �����-���������� �������. ���� ������, �� ������ ����� ������ �� ��� ���� (��������, ��� �������).
     * @param mixed &$proj     ���� ���������� ���������� � ������� ����� ��� ����������
     * @return integer|string   0 � ������ �������� ������� ��� ����� ������.
     */
    function saveProject($buyer_id = NULL, &$proj, $promo_codes = array())
    {	
        $price = 0;

        // ���� ������ ����� ����������, �� ���� �������� �����������
        if(!$this->isEdit()) {
            $need_send_email = true;
        } else {
            $need_send_email = false;
        }

        $operations = array();
        
        if($ammount = $this->getAmmount()) {
            $buyer_id = $buyer_id ? $buyer_id : $this->_uid;
            $account = new account();
            if(!$account->GetInfo($buyer_id, true)) {
                    return '������ � ��������� ���������� �� �����.';
            }

            $is_pro = is_pro(true, $this->_project["user_id"]);

            $price = $this->getPrice($items);
            
            if ($this->isKonkurs()) {
                if ($items['contest']) {
                    $operations['contest'] = array(
                        'op_code' => $is_pro ? self::OPCODE_KON : self::OPCODE_KON_NOPRO,
                        'op_code_bns' => $is_pro ? self::OPCODE_KON_BNS : self::OPCODE_KON_BNS_NOPRO,
                        'ammount' => 1,
                        'comment' => $this->getOperationComment('contest')
                    );
                }
            }
            
            if ($this->isVacancy()) {
                if ($items['office']) {
                    $operations['office'] = array(
                        'op_code' => $this->getVacancyOpCode(),
                        'op_code_bns' => self::OPCODE_PAYED_BNS,
                        'ammount' => 1,
                        'comment' => $this->getOperationComment('office')
                    );
                }
            }            
            
            $services = array('top', 'logo', 'urgent', 'hide');
            foreach ($services as $service) {
                if ($items[$service]) {
                    $operations[$service] = array(
                        'op_code' => self::getOpCodeByService($service),
                        'ammount' => $items[$service],
                        'comment' => $this->getOperationComment($service)
                    );
                }
            }
                   
            foreach ($operations as $service => $operation) {
                $bill_id = 0;
                $this->_transactionId = $account->start_transaction($buyer_id, $this->_transactionId);
                if (!isset($operation['op_code_bns'])) {
                    $operation['op_code_bns'] = $operation['op_code'];
                }
                
                $descr = trim($operation['comment'],'&');
                if ($this->_project['billing_id']) {
                    $descr .= ' - ���������� � �������� #'.$this->_project['billing_id'];
                }
                
                if ($account->bonus_sum >= $price) {
                    
                    $error = $account->BuyFromBonus(
                        $bill_id, 
                        $this->_transactionId, 
                        $operation['op_code_bns'], 
                        $buyer_id,
                        "{$descr} �� ���� �������",
                        "{$operation['comment']} �� ���� �������",
                        $operation['ammount'], 
                        true
                    );
                            
                    if ($error) {
                        return $error;
                    }
                    
                } else {

                    $error = $account->Buy(
                        $bill_id,
                        $this->_transactionId, 
                        $operation['op_code'], 
                        $buyer_id, 
                        $descr, 
                        $operation['comment'],
                        $operation['ammount'], 
                        0,
                        (isset($promo_codes[$service]) ? $promo_codes[$service] : 0)
                    );
                    if ($error) {
                        return $error;
                    }
                }
                
                if (!$bill_id) {
                    return '�� ������� �����. '.$account->sum.' �� '.$price;
                }
                
                $this->account_operation_id = $bill_id;
                $operations[$service]['bill_id'] = $bill_id;
            }
        }

        $prePrj = $this->_preDb($price, $bill_id);
        $success = $this->isEdit() ? $this->editPrj($this->_project, $this->_attach, array_reverse($this->getCategories()))

                                   : $this->addPrj($this->_project, $this->_attach, array_reverse($this->_categories));
        
        if (!$this->isEdit()) {
            foreach ($operations as $service => $operation) {
                $account->updateComment($this->getOperationComment($service), 
                    $operation['bill_id'], 
                    array($operation['op_code'], $operation['op_code_bns'])
                );
            }
        }
        
        $proj = $this->_project;
        
        if(!$success) {
            $this->_postDbFailed($prePrj, $bill_id, $buyer_id);
            return $error ? $error : 'Error';
        }

		$this->SavePayedInfo($items, $this->_project['id'], $bill_id, ($topDays? $topDays: 1));

        $this->_postDbSuccess();

        if(!$_SESSION['quickprjbuy_ok_id']) { $_SESSION['quickprjbuy_ok_id'] = $this->_project['id']; }

        return 0;
    }

    private function getOperationComment($service) {
        $text = '';
        switch ($service) {
         case 'contest':
             $text = "���������� �������� �{$this->_project['id']} & ";
             break;
         
         case 'office':
             $text = "���������� �������� �{$this->_project['id']} & ";
             break;
         
         case 'top':
             $topDays = $this->getAddedTopDays();
             $days = $topDays . ' ' . getTermination($topDays, array(0 => '����', 1 => '���', 2=> '����'));
             $kind = $this->isKonkurs() ? '��������' : ($this->isVacancy() ? '��������' : '�������');
             $text = "����������� {$kind} �{$this->_project['id']} � ����� & �� {$days}";
             break;
         
         case 'logo':
             $kind = $this->isKonkurs() ? '�������' : ($this->isVacancy() ? '��������' : '������');
             $text = "���������� �������� � {$kind} �{$this->_project['id']} & ";
             break;
         
         case 'urgent':
             $kind = $this->isVacancy() ? '������� ��������' : '������� '.($this->isKonkurs() ? '�������' : '������');
             $text = "�����: {$kind} �{$this->_project['id']} & ";
             break;
         
         case 'hide':
             $kind = $this->isVacancy() ? '������� ��������' : '������� '.($this->isKonkurs() ? '�������' : '������');
             $text = "�����: {$kind} �{$this->_project['id']} & ";
             break;

         default:
             break;
     }
        return $text;
    } 

    /**
     * �������� ���� �������
     * @return boolean   ������� ��� ������� ������. 
     */
    function isKonkurs() {
        return parent::isKonkurs($this->_project['kind']);
    }
    
    /**
     * ��������, �������� �� �������
     * @return boolean   false - ������� ��������
     */    
    function isActiveKonkurs() {
        return ($this->_project['end_date'] > date('Y-m-d H:i:s'));
    }
    
    private function isStateChangedToPublic() {
        return isset($this->_project['old_state']) 
            && $this->_project['old_state'] == self::STATE_MOVED_TO_VACANCY
            && $this->_project['state'] == self::STATE_PUBLIC ;
    }

    /**
     * ������ ��������� ��������������� �������
     * 
     * @param array  $items ���������� ������ �� ������� (� ������ ������ �� ���������� ���� �� ��������������� ���������� (�������, ����,���������,����� �������))
     * @param double &$PROprice ����� ������, ���� �� � ���������� ��� PRO 
     * @param bool   $pricesAsArray = false ���� true, ����� ��� ������� $items["logo"]  � $items["top"] ����� ����� 
     *                                 ������ array("no_pro" => a, "pro" => b), ��� b - ��������� ����� ��� ������� � ���������� pro
     *                                 a - ��������� ����� ����������� � ����������� �� ������� pro � ������ �������
     * @return integer ����� ������
     */
    function getPrice(&$items = NULL, &$PROprice = 0, $pricesAsArray = false)
    {
        $isPayedUrgent = $this->_project['o_urgent'] == 't';
        $isPayedHide = $this->_project['o_hide'] == 't';
        
        $is_pro = is_pro(true, $this->_project["user_id"]);
        $addedPrc = $is_pro ? 0 : self::PRICE_ADDED;
        $items['logo']["no_pro"] = ($this->getLogo() && !(int)$this->_project['payed_items'][self::PAYED_IDX_LOGO]) * ( $is_pro ? self::PRICE_LOGO : self::PRICE_LOGO_NOPRO );
        $nPrice         = ( $this->isKonkurs() ) ? ( ($is_pro?self::PRICE_CONTEST_TOP1DAY_PRO:self::PRICE_CONTEST_TOP1DAY) + $addedPrc ) : ( ($is_pro?self::PRICE_TOP1DAYPRO:self::PRICE_TOP1DAY) + $addedPrc );
        $nProPrice      = ( $this->isKonkurs() ) ? ( self::PRICE_CONTEST_TOP1DAY_PRO): ( (self::PRICE_TOP1DAYPRO) );

        $items['urgent'] = array("no_pro" => ($this->_project['urgent']=='t' && !$isPayedUrgent ? self::PRICE_URGENT : 0));
        $items['hide'] = array("no_pro" => ($this->_project['hide']=='t' && !$isPayedHide ? self::PRICE_HIDE : 0));

        $items['top']   = array("no_pro" => ($this->getAddedTopDays() * $nPrice) );
        if (!$pricesAsArray) {
            $items['top']   = $items["top"]["no_pro"];
            $items['urgent']   = $items["urgent"]["no_pro"];
            $items['hide']   = $items["hide"]["no_pro"];
            $items['logo'] = $items['logo']["no_pro"];
            $price = $items['logo'] + $items['top'] + $items['urgent'] + $items['hide'];
        } else {
            $price = $items['logo']["no_pro"] + $items['top']["no_pro"] + $items['urgent']['no_pro'] + $items['hide']['no_pro'];
        }

        $PROitems['urgent'] = $this->_project['urgent']=='t' && !$isPayedUrgent ? self::PRICE_URGENT : 0;
        $PROitems['hide'] = $this->_project['hide']=='t' && !$isPayedHide ? self::PRICE_HIDE : 0;
        $PROitems['logo']  = ($this->getLogo() && !(int)$this->_project['payed_items'][self::PAYED_IDX_LOGO]) * ( self::PRICE_LOGO  );
        $PROnPrice         = ( $this->isKonkurs() ) ? ( ($is_pro?self::PRICE_CONTEST_TOP1DAY_PRO:self::PRICE_CONTEST_TOP1DAY)  ) : ( $is_pro?self::PRICE_TOP1DAYPRO:self::PRICE_TOP1DAY  );
        $PROitems['top']  = $this->getAddedTopDays() * $nProPrice;
        if ($pricesAsArray) {
            $items['top']["pro"] = $PROitems['top'];
            $items['logo']["pro"] = $PROitems['logo'];
            $items['urgent']["pro"] = $PROitems['urgent'];
            $items['hide']["pro"] = $PROitems['hide'];
            $PROprice = $PROitems['logo']["pro"] + $PROitems['top']["pro"] + $PROitems['urgent']["pro"] + $PROitems['hide']["pro"];
        } else {
            $PROprice = $PROitems['logo'] + $PROitems['top'] + $PROitems['urgent'] + $PROitems['hide'];
        }


        if($this->isKonkurs() && !$this->isEdit()) {
            if (new_projects::isNewContestBudget()) {
                //���� ������� �� �����������, �.�. ����� � ������� ������ ���������� false
                $isPro = $this->_project['is_pro'] === 't';
                $cost = $this->_costRub;
                $contestPrice = new_projects::getContestTax($cost, $isPro);
                $price += new_projects::getContestTax($cost, $isPro);
                $PROprice += new_projects::getContestTax($cost, true);
            } else {
                $items['contest']['pro'] = $this->getKonkursPrice(true);
                $items['contest']['no_pro'] = $this->getKonkursPrice();
                $contestPrice = $is_pro ? $items['contest']['pro'] : $items['contest']['no_pro'];
                $price = $price + $contestPrice;
                $PROprice = $PROprice + $items['contest']['pro'];
                if (!$pricesAsArray) {
                    $items['contest'] = $contestPrice;
                }
            }
        }
        if ($this->isVacancy() && (!$this->isEdit() || $this->isStateChangedToPublic())) {
            $items['office'] = $this->getProjectInOfficePrice($is_pro);
            $price = $price + $this->getProjectInOfficePrice($is_pro);
            $PROprice = $PROprice + $this->getProjectInOfficePrice(true);
        }

		return ( $price > 0 ? $price : 0 );
    }

    /**
     * ���������� ������������� ������������, ���������� ������.
     * 
     * @return integer ID ������������
     */
    function getAuthorId() {
    	return $this->_project["user_id"];
    }
    
	/**
     * ���������� ����� ������������, ���������� ������.
     * 
     * @return integer ID ������������
     */
    function getAuthorLogin() {
    	return $this->_project["login"];
    }
    
    /**
     * ����� ����� ������
     * 
     * @return integer
     */
    function getAmmount()
    {
        $ammount = $this->getPrice();

        return ( $ammount > 0 ? $ammount : 0 );
    }

    /**
     * ������� ��� ��������� ������� �� ���� � ���������� ��������� ������.
     * ���������� ��� ��������������.
     * 
     * @param integer $login   ����� ������������.
     */
    static function clearTmpAll($login)
    {
        if(!$login) return;
        $memBuff = new memBuff();
        $memBuff->flushGroup(self::getMemGrKey($login));
        $cfile = new CFile();
        $cfile->DeleteDir(self::getTmpPath($login).self::TMP_DIR.'/');
    }

    /**
     * �������� ���� �� ���������� ��������� ������.
     * 
     * @param integer $login   ����� ������������.
     * @return string   ����
     */
    static function getTmpPath($login) {
        return 'users/'.substr($login, 0, 2).'/'.$login.'/';
    }

    /**
     * �������� ��� ������ �������, � ������� ��������� ��� ��������� ������� ������� ������������.
     * 
     * @param integer $login   ����� ������������.
     * @return string   ���
     */
    static function getMemGrKey($login) {
        return self::MEM_KEY_PFX.':'.$login;
    }

    /**
     * ���������� ������ �� ���������� �������, ������� �� ����.
     * 
     * @return integer 0
     */
    function destroy()
    {
        if($this->_tmpFiles) {
            $cfile = new CFile();
            foreach($this->_tmpFiles as $fname) {
                $cfile->Delete(0, $this->_tmpAbsDir, $fname);
            }
        }

        $this->_project = NULL;
        $this->_attach = NULL;
        $this->_logo = NULL;
        $this->_transactionId = NULL;
        $this->_addedTopDays = 0;
        $this->_uid = NULL;
        $this->_tmpFiles = NULL;
        $this->_deletedFiles = NULL;
        $this->_isEdit = NULL;
        $this->_buffer = NULL;
        $this->_categories = NULL;

        if($this->_memkey) {
            $memBuff = new memBuff();
            $memBuff->delete($this->_memkey);

        }

        return 0;
    }


    /**
     *  ��������� ������ � ����.
     */
    function fix()
    {
        if($this->_memkey) {
            $data = array();
            $memBuff = new memBuff();
            foreach($this as $member=>$value)
                $data[$member] = $value;
            $memBuff->set($this->_memkey, $data, self::MEM_LIFE_TIME, self::getMemGrKey($_SESSION['login']));
        }
    }


    /**
     * ��������� �������� ���������� � ������ �����
     * 
     * @param string $name �������� ���������� ������� (����)
     * @param mixed  $data ���������� ��� ������
     */
    function push($name, $data)
    {
		$this->_buffer[$name] = $data;
    }


    /**
     * ����� ���������� �� ����� � ������� �� �� �����
     * 
     * @param string $name �������� ���������� ������� (����)
     * @return mixed $data �������� �������
     */
    function pop($name)
    {
		$data = $this->_buffer[$name];
        unset($this->_buffer[$name]);
        return $data;
    }


#private:


    /**
     * ������ ���� � ��������� ��� ������� ������ � �������
     */
    private function _setPath() {
        if($this->_project['login']) {

            $cfile = new CFile();
            $tmp_path = self::getTmpPath($this->_project['login']);
            $this->_tmpAbsDir = $tmp_path.self::TMP_DIR.'/';
            $month = date('Ym');
            $this->_dstAbsDir = 'projects/upload/' . $month . '/';
        }
    }

    function initFromDraft($draft_id, $uid) {
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/drafts.php");
        require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/project_exrates.php");
        $drafts   = new drafts;
        $this->_project =  $drafts->getDraft($draft_id, $uid, 1);
        if( !$this->_project['id'] ) {
            return false;
        }
        if($this->_project['logo_id'] > 0) {
            $this->_project['link'] = $this->_project['logo_link'];
            $LogoFile = new CFile($this->_project['logo_id']);
            $this->initLogo($LogoFile);
        }
        
        $cat = explode(",", $this->_project['categories']);
        foreach($cat as $category) {
            list($cat_id, $subcat_id) = explode("|", $category);
            $categories[] = array('category_id' => $cat_id, 'subcategory_id' => $subcat_id);
        }
        $this->setCategories($categories);
        
        $pExrates = project_exrates::getAll();
        if ($this->_project['currency'] === 0) { // USD
            $costRub = $this->_project['cost'] * $pExrates['24']; // ������ � ������
        } elseif ($this->_project['currency'] === 1) { // EURO
            $costRub = $this->_project['cost'] * $pExrates['34'];
        } else { // �����
            $costRub = $this->_project['cost'];
        }
        $this->setCostRub($costRub);
        
        $this->_project['draft_id'] = $this->_project['id'];
        unset($this->_project['id']);
        $this->_project['user_id'] = $this->_project['uid'];
        $this->_project['is_pro'] = is_pro(true, $uid) ? 't' : 'f';
        
        if($this->_project['top_days'] > 0) {
            $this->setAddedTopDays($this->_project['top_days']);
        }
        
        if($this->isKonkurs()) {
            $this->_project['end_date'] = date('d-m-Y', strtotime($this->_project['end_date']));
            $this->_project['win_date'] = date('d-m-Y', strtotime($this->_project['win_date']));
        }
        
        $attach = drafts::getAttachedFiles($draft_id, 4, $this->_project['uid']);
        if(!empty($attach)) {
            foreach ($attach as $file_id) {
                $ret[$file_id] = array('status' =>1, 'id' => $file_id);
            }
            $this->addAttachedFiles($ret, true);
        }
        
        return true;
    }
    
    public function setInitFromDB($prj_id) {
        $this->_initFromDB($prj_id);
        $this->_isEdit = true;
    }
    
    /**
     * ��������� ����� ������� �� ����.
     *

     * @param integer $prj_id   ��. �������.
     * @return boolean true - ���� ��� ������������� ������ �������
     */
    private function _initFromDB($prj_id)
    {
        if($this->_project = $this->getPrj($prj_id)) {
            $this->_setPath();
            $this->_attach = $this->getAllAttach($prj_id);
            $this->_categories = $this->getSpecs($prj_id);
            if($this->_project['logo_id']) {
                $file = new CFile($this->_project['logo_id']);
                if($file->name) {
                    $this->_logo = array( 'id'=>$file->id,
                                          'path'=>$file->path,
                                          'name'=>$file->name,
                                          'size'=>$file->size,
                                          'ftype'=>$file->getext() );
                }
            }
            return true;
        }

        return false;
    }


    /**
     * ����������� � ������������ ������ ���� � ������������ �� ���������� ������ ��������� �����.
     * ����������� ����� ��������� �������� ������� � ����.
     *
     * @see tmp_project::getPrice()
     * 
     * @param float    $price       ��������� ������� �� ������ ���������� (��������� ������� $this->getPrice()).
     * @param integer  $bill_id     account_operations.id -- ����� �������� ��� ������� �������.
     * @return array   ���������� ���������� ��������� ������� ��� ������ � �������� ������ ��� ���������� ����������.
     */
    private function _preDb($price, $bill_id)
    {
        $pre = $this->_project;
        
        $this->_project['kind']     = (int)$this->_project['kind'] ? (int)$this->_project['kind'] : 1;
        $this->_project['is_color'] = ($this->_project['is_color'] ? $this->_project['is_color'] : 'f');
        $this->_project['is_bold']  = ($this->_project['is_bold'] ? $this->_project['is_bold'] : 'f');
        $this->_project['top_days'] = $this->getAddedTopDays();
        
        $this->_project['payed_items'] =
            ((int)(!!$this->_logo) | (int)$this->_project['payed_items'][self::PAYED_IDX_LOGO])
          . ((int)($this->_project['is_pro']!='t' && $this->_project['is_color']=='t') | (int)$this->_project['payed_items'][self::PAYED_IDX_COLOR])
          . ((int)($this->_project['is_bold']=='t') | (int)$this->_project['payed_items'][self::PAYED_IDX_BOLD]);
        
        $this->_project['payed']      = (int)$this->_project['payed'] + $price;
        $this->_project['billing_id'] = $this->_project['billing_id'] ? $this->_project['billing_id'] : ($bill_id ? $bill_id : NULL);
        $this->_project['currency']   = (int)$this->_project['currency'];
        $this->_project['cost']       = (int)$this->_project['cost'];
        $this->_project['country']    = $this->_project['country'] ? (int)$this->_project['country'] : NULL;
        $this->_project['city']       = $this->_project['city'] ? (int)$this->_project['city'] : NULL;
        $this->_project['subcategory'] = $this->_project['subcategory'] ? (int)$this->_project['subcategory'] : NULL;
        $this->_project['pro_only']   = $this->_project['pro_only'] ? $this->_project['pro_only'] : 'f';
        $this->_project['verify_only']   = $this->_project['verify_only'] ? $this->_project['verify_only'] : 'f';
        $this->_project['descr']      = $this->_project['descr'];
        $this->_project['name']       = $this->_project['name'];
        $this->_project['videolnk']       = $this->_project['videolnk'];
        $this->_project['link']       = $this->_project['link'];
        $this->_project['logo_id']    = $this->_logo['id'] ? $this->_logo['id'] : NULL;
        $this->_project['state']      = isset($this->_project['state'])?$this->_project['state']:projects::STATE_PUBLIC;
        
        return $pre;
    }


    /**
     * ����������� ����� ��������� ��������� ������� � ��.
     * ������� �������� ����� � �������, ���������� �����, ��������� RSS-����.
     * ��� �������� ������ ������� � ������� � �� ���� file_projects, ��� ��������� ��������� �� project_attach.
     */
    private function _postDbSuccess()
    {
        if($this->_tmpFiles) {
            foreach($this->_tmpFiles as $name) {
                $cfile = new CFile($this->_tmpAbsDir.$name);
                $cfile->Rename($this->_dstAbsDir.$name);               
            }
            $this->_tmpFiles = NULL;
        }

        if($this->_deletedFiles) {
//echo '<pre>';
//print_r($this->_deletedFiles);
            $this->_fixDeletedFilesInHistory();
//print_r($this->_deletedFiles);
//echo '</pre>';
//exit;
            $cfile = new CFile();
            foreach($this->_deletedFiles as $f)
                $cfile->Delete(0, $f['path'], $f['name']);
        }

        $_SESSION['isExistProjects'] = true;
        
        $this->destroy();
    }


    /**
     * ����������� ����� ������ ��� ��������� ������� � ��.
     * ���������� ������� ������� (���� ������� ����) � ���������� ������ ������������/�������������� �������
     * � ���������� ��������� (�� ������� ��������� ������).
     *
     * @see tmp_project::_preDb()  
     * 
     * @param array   $pre        ��������� ������� �� ������� ���������� ��� � �� (��������� ����� ������� $this->_preDb())
     * @param integer $bill_id    account_operations.id -- ����� �������� ��� ������� �������.
     * @param integer $buyer_id   users.uid -- ��. �����-���������� �������.
     */
    private function _postDbFailed($pre, $bill_id, $buyer_id)
    {
        if($bill_id && $buyer_id) {
            $account = new account();
            $account->Del($buyer_id, $bill_id);
        }

        $this->_project = $pre;
    }

    /**
     * ������� �� _deletedFiles ����� ������� ���� ��������� ��� �������� �������, ��� ���� ����� ��� �������� ��������� ��� ������� �������
     *
     */
    private function _fixDeletedFilesInHistory()
    {
        global $DB;
        foreach($this->_deletedFiles as $k=>$f) {
            if($f['project_id']) {
                $sql = "SELECT 1 FROM projects_history WHERE id={$f['project_id']} AND files LIKE '%{$f['name']}%'";
                if($DB->val($sql)) {
                    $sql = "DELETE FROM project_attach WHERE project_id={$f['project_id']} AND file_id={$f['file_id']}";
                    $DB->query($sql);
                    unset($this->_deletedFiles[$k]);
                }
            }
        }
    }
    
    public function getTmpAbsDir() {
        return $this->_tmpAbsDir; 
    }
    public function getDstAbsDir() {
        return $this->_dstAbsDir; 
    }
    
    /*
     * �������� ��������� ������ �����������  (������������ ��� ��������� ������ �������� �������(?) )
     * */
    public function clearWinners() {
        global $DB;
        $DB->update("projects_contest_offers", array('position' => 0), " project_id = ".$this->_project['id']);
        $DB->update("projects", array('exec_id' => 0), " id = ".$this->_project['id']);
    }
    
    /**
     * ���� �� ��������� ����� ��� ����������� ���������� ������ ������� �������������, �� ������������ ����� ��� ����������
     * ����� - false
     * @param integer $kind
     */
    public function isPublicLastDay () {
        global $DB;
        $sql = "
            SELECT p.post_date
            FROM projects p
            LEFT JOIN projects_blocked pb
                ON p.id = pb.project_id
            WHERE p.post_date > current_date - interval '1 day'     -- ������ �������������� �� ��������� �����
            AND pb.id IS NULL                                       -- �� ���������������
            AND p.payed = 0                                         -- ���������� ������
            AND p.user_id = ?i
            ORDER BY p.post_date
            LIMIT 1";
        $res = $DB->val($sql, get_uid(0));
        return $res ? $res : false;
    }
    
    /**
     * ��������� � ������
     */
    private $_costRub;
    public function setCostRub ($costRub) {
        $this->_costRub = $costRub;
    }
    public function getCostRub () {
        return $this->_costRub;
    }
        
}