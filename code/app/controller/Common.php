<?php


namespace app\controller;


use app\BaseController;
use app\model\AuthRuleModel;
use app\model\UserLogModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use think\exception\HttpResponseException;
use think\facade\Cookie;
use think\facade\Db;
use think\facade\Request;
use think\facade\Response;
use think\facade\View;

class Common extends BaseController
{
    protected $userId, $auth_group_id, $menudata, $userRules, $menu, $userInfo;


    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->userInfo = $this->isLogin('scan_user');
        if (!$this->userInfo) {
            if (request()->isAjax()) {
                $this->apiReturn('0', [], '请先登录');
            } else {
                header("Location: ".url('login/index'));
                exit();
            }
        }
        View::assign('userInfo', $this->userInfo);
        $this->userId = $this->userInfo ? $this->userInfo['id'] : 0;
        $this->auth_group_id = $this->userInfo['auth_group_id'];
        //$href = strtolower(Request::controller() . '/' . Request::action());
        // 判断是否已经添加到rule表
        $href = cc_format(Request::controller() . '/' . Request::action());
        if (!AuthRuleModel::isExist($href)) {
            //echo '<pre>';
            $data['href'] = $href;
            $data['title'] = '未知';
            $data['pid'] = 43;
            $data['level'] = 3;
            $data['created_at'] = time();
            //var_dump($data);exit;
            Db::name('auth_rule')->insert($data);
        }
        //$auth_white = array('login/index', 'login/tncode', 'login/dologin', 'login/check'); // 白名单
        if (!$this->is_auth($href) && !in_array($href, config('app.NOT_AUTH_ACTION'))) {
            if (request()->isAjax()) {
                return $this->apiReturn(1,[],'暂无权限');
            } else {
                $this->error('暂无权限');
            }
        }
        if ($this->userId) {
            if ($this->userId == 1 || in_array($this->userId, config('app.ADMINISTRATOR'))) {
                $map = "is_delete = 0 and menu_status = 1 and level <= 2";
                $this->menudata = Db::name('auth_rule')->where($map)->field('auth_rule_id,href,title,icon_url,pid,level')->order('sort asc')->select();
            } else {    // 获取分组
                $where = "auth_group_id = $this->auth_group_id and status = 1";
                $auth_group = Db::name('auth_group')->where($where)->field('rules')->find();
                if (!empty($auth_group['rules'])) {
                    $this->userRules = rtrim($auth_group['rules'], ',');
                }
                if (!$this->userRules) {
                    $this->userRules = 0;
                }
                $map = "is_delete = 0 and menu_status = 1 and auth_rule_id in ($this->userRules) and level <= 2";
                $this->menudata = Db::name('auth_rule')->where($map)->field('auth_rule_id,href,title,icon_url,pid,level')->order('sort asc')->select();
            }
            $menu_list = \Leftnav::menuList($this->menudata);
            /*$map = "href = '{$href}' and menu_status = 1 and is_delete = 0";
            $count = Db::name('auth_rule')->where($map)->count('auth_rule_id');
            if ($count > 1) {
                $map .= ' and level >= 1';
            }
            $str_auth_rule = Db::name('auth_rule')->where($map)->field('auth_rule_id,href,title,icon_url,pid,level')->find();
            if ($str_auth_rule) {
                if ($str_auth_rule['level'] == 3) {
                    $auth_rule = Db::name('auth_rule')->where("auth_rule_id = {$str_auth_rule['pid']}")->field('href')->find();
                    $href = strtolower($auth_rule['href']);
                }
            } else {
                if (request()->isAjax()) {
                    exit(json_encode(['code' => 1, 'msg' => '菜单不存在'],JSON_UNESCAPED_UNICODE));x
                } else {
                    $this->error('菜单不存在');
                }
            }
            if ($str_auth_rule['level'] == 2 || $str_auth_rule['level'] == 3) {
                if ($str_auth_rule['level'] == 2) {
                    $map = "auth_rule_id = {$str_auth_rule['pid']} and is_delete = 0 and level = 1";
                } else {
                    $map = "auth_rule_id = {$str_auth_rule['pid']} and is_delete = 0 and level = 2";

                    $auth_rule = Db::name('auth_rule')->where($map)->field('pid')->find();

                    $map = "auth_rule_id = {$auth_rule['pid']} and is_delete = 0 and level = 1";
                }
                $auth_rule = Db::name('auth_rule')->where($map)->field('href')->find();
                View::assign('parent_href',$auth_rule['href']);
            }*/
            View::assign('menu_list',$menu_list);
        }
        View::assign('href',$href);
        $rule = Db::name('auth_rule')->where('href',$href)->field('pid,level,title')->find();
        if ($rule['level'] == 3) {
            $rule = Db::name('auth_rule')->where('auth_rule_id',$rule['pid'])->field('pid,level,title')->find();
        }
        View::assign('title',$rule['title'].' QingScan');
    }

    // 权限判断
    private function is_auth($name)
    {
        if (!in_array($this->userId, config('app.ADMINISTRATOR'))) {
            $rules = Db::name('auth_group')->where('auth_group_id',$this->auth_group_id)->value('rules');
            if (Db::name('auth_rule')->where('href',$name)->value('is_open_auth') == 0) {
                return true;
            }
            $user_auth_rule = Db::name('auth_rule')->where('auth_rule_id','in',"({$rules})")->field('title,href,is_open_auth')->select()->toArray();
            $is_auth = false;
            foreach ($user_auth_rule as $v) {
                if (strtolower($name) == strtolower($v['href'])) {
                    $is_auth = true;
                }
            }
            return $is_auth;
        }
        return true;
    }

    /**
     * 判断用户是否登录
     * @param name
     * @return int|array
     */
    public function is_login($name)
    {
        return session($name);
    }

    /**
     * 判断用户是否登录
     * @param $cookie_name
     * @return int|array
     */
    public function isLogin($cookie_name){
        if (!$cookie_name) {
            return 0;
        }
        parse_str(think_decrypt(Cookie::get($cookie_name)), $arr);
        if (!$arr)
            return 0;
        return $arr;
    }

    public function getMyAppList(){
        $where[] = ['is_delete','=',0];
        if ($this->auth_group_id != 5 && !in_array($this->userId, config('app.ADMINISTRATOR'))) {
            $where[] = ['user_id', '=', $this->userId];
        }
        //查询项目数据
        $projectArr = Db::table('app')->where($where)->field('id,name')->select()->toArray();
        $projectList = array_column($projectArr, 'name', 'id');
        return $projectList;
    }


    /**
     * 使用PHPEXECL导入
     *
     * @param string $file 文件地址
     * @param int $sheet 工作表sheet(传0则获取第一个sheet)
     * @param int $columnCnt 列数(传0则自动获取最大列)
     * @param array $options 操作选项
     *                          array mergeCells 合并单元格数组
     *                          array formula    公式数组
     *                          array format     单元格格式数组
     * @return array
     * @throws Exception
     */
    public function importExecl($file = '', $sheet = 0, $columnCnt = 0, &$options = [])
    {
        try {
            /* 转码 */
            //$file = iconv("gb2312", "utf-8", $file);

            if (empty($file) OR !file_exists($file)) {
                return  ['code'=>0,'data'=>[],'msg'=>'文件不存在!'];
            }
            /** @var Xlsx $objRead */
            $objRead = IOFactory::createReader('Xls');

            if (!$objRead->canRead($file)) {
                $objRead = new Csv();
                if (!$objRead->canRead($file)) {
                    return  ['code'=>0,'data'=>[],'msg'=>'只支持导入Xls、Csv格式文件!'];
                }
            }

            /* 如果不需要获取特殊操作，则只读内容，可以大幅度提升读取Excel效率 */
            empty($options) && $objRead->setReadDataOnly(true);
            /* 建立excel对象 */
            $obj = $objRead->load($file);
            /* 获取指定的sheet表 */
            $currSheet = $obj->getSheet($sheet);

            if (isset($options['mergeCells'])) {
                /* 读取合并行列 */
                $options['mergeCells'] = $currSheet->getMergeCells();
            }

            if (0 == $columnCnt) {
                /* 取得最大的列号 */
                $columnH = $currSheet->getHighestColumn();
                /* 兼容原逻辑，循环时使用的是小于等于 */
                $columnCnt = Coordinate::columnIndexFromString($columnH);
            }

            /* 获取总行数 */
            $rowCnt = $currSheet->getHighestRow();
            $data = [];

            /* 读取内容 */
            for ($_row = 0; $_row <= $rowCnt; $_row++) {
                $isNull = true;
                for ($_column = 1; $_column <= $columnCnt; $_column++) {
                    $cellName = Coordinate::stringFromColumnIndex($_column);
                    $cellId = $cellName . $_row;
                    $cell = $currSheet->getCell($cellId);

                    if (isset($options['format'])) {
                        /* 获取格式 */
                        $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
                        /* 记录格式 */
                        $options['format'][$_row][$cellName] = $format;
                    }

                    if (isset($options['formula'])) {
                        /* 获取公式，公式均为=号开头数据 */
                        $formula = $currSheet->getCell($cellId)->getValue();

                        if (0 === strpos($formula, '=')) {
                            $options['formula'][$cellName . $_row] = $formula;
                        }
                    }

                    if (isset($format) && 'm/d/yyyy' == $format) {
                        /* 日期格式翻转处理 */
                        $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy/mm/dd');
                    }

                    $data[$_row][$cellName] = trim($currSheet->getCell($cellId)->getFormattedValue());

                    if (!empty($data[$_row][$cellName])) {
                        $isNull = false;
                    }
                }

                /* 判断是否整行数据为空，是的话删除该行数据 */
                if ($isNull) {
                    unset($data[$_row]);
                }
            }
            return  ['code'=>1,'data'=>array_values($data),'msg'=>''];
        } catch (\Exception $e) {
            return  ['code'=>0,'data'=>[],'msg'=>$e->getMessage()];
        }
    }

    // 批量删除
    public function batch_del_that($request,$table){
        $ids = $request->param('ids');
        $this->addUserLog($table,"批量删除数据[$ids]");
        if (!$ids) {
            return $this->apiReturn(0,[],'请先选择要删除的数据');
        }
        $map[] = ['id','in',$ids];
        if ($this->auth_group_id != 5 && !in_array($this->userId, config('app.ADMINISTRATOR'))) {
            $map[] = ['user_id', '=', $this->userId];
        }
        if (Db::name($table)->where($map)->delete()) {
            return $this->apiReturn(1,[],'批量删除成功');
        } else {
            return $this->apiReturn(0,[],'批量删除失败');
        }
    }

    public function addUserLog($type,$content){
        UserLogModel::addLog($this->userInfo['username'],$type,$content);
    }
}