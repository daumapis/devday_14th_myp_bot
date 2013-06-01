<?php

include "./libCRedis.php";

class Zzirashi 
{
    const API_URL_PREFIX = "https://apis.daum.net";
    const MYPEOPLE_BOT_APIKEY = "2da684af51d2c933f1594da5e28a256143911d01";

    const REDIS_USEQ = 'j_user_seq';
    const REDIS_ZSEQ = 'j_zzira_seq';
    const REDIS_USER = 'j_user';
    const REDIS_ZZIRA = 'j_zzira';

    const TODAY_LIMIT_CNT = 500;
    const BLOCK_LIMIT_CNT = 5;
    const BLOCK_LIMIT_TIME = 60; //마이크로 아닌, 초단위
    const ZZIRA_PP_CNT = 2;

    private $redis;
    private $sBid;
    private $sMsg;

    public function __construct()
    {
        $this->redis = new libCRedis();
    }

    public function Run()
    {
        //$this->flushAllData(); return;

        try{
            if ($_POST['action'] == "addBuddy") {
                $this->sBid = $_POST['buddyId'];
                //TODO validate param
                $aResult = $this->registerFriends();
                $this->sendMessage($this->sBid, $aResult['msg']);
            } else if ($_POST['action'] == "sendFromMessage") {
                $this->sBid = $_POST['buddyId'];
                $this->sMsg =  $_POST['content'];
                //TODO validate param
                //TODO command check
                $this->isCommand($this->sMsg);
                
            } else {
                return false;
            }

        } catch(Exception $e) {
            $this->sendMessage($this->sBid, 'Error');
        }
        
    }

    private function flushAllData()
    {
        $this->redis->delData(self::REDIS_USER);
        $this->redis->delData(self::REDIS_USEQ);
        $this->redis->delData(self::REDIS_ZSEQ);
        $this->redis->delData(self::REDIS_ZZIRA);
    }

    private function isBlock()
    {
        $aUser = $this->getUser($this->sBid);
        if ($aUser) {
            $aBlock = &$aUser['block'];
            if ($aBlock['bexptime'] === -1) {
                return false;
            }
            if ($aBlock['bexptime'] > time()) {
                return true;
            } else {
                $aBlock['bexptime'] = -1;
                if ($aBlock['bcnt'] > 0) {
                    $aBlock['bcnt'] = 0;
                }
                $this->setUser($this->sBid, $aUser);
                return false;
            }
        }

        return true;
    }

    private function isCountOver()
    {
        $aUser = $this->getUser($this->sBid);
        if ($aUser) {
            if ($aUser['lstime'] < strtotime("today midnight")) {
                if ($aUser['todaycnt'] > 0) {
                    $aUser['todaycnt'] = 0;
                    $this->setUser($this->sBid, $aUser);
                }
                return false;
            } else {
                if ($aUser['todaycnt'] > self::TODAY_LIMIT_CNT) {
                    return true;
                }
                return false;
            }
        }

        return true;
    }

    private function isCommand($sCmd)
    {
        $sCmd = trim($sCmd);
        $aCmds = split(' ', $sCmd);
        $mSecCmd = isset($aCmds[1]) ? $aCmds[1] : NULL;

        //TODO resend, trash cmd
        if (strpos($sCmd, '/ui') === 0) {
            $this->showUser();
        } else if (strpos($sCmd, '/zi') === 0) {
            $this->showZzira($mSecCmd);
        } else if (strpos($sCmd, '돌려') === 0) {

            //이미 돌렷는지
            $aZzira = $this->getZzira($mSecCmd);
            $aUser = $this->getUser($aZzira['bid']);
            $aActor = $this->getUser($this->sBid);

            if (is_array($aZzira) && is_array($aUser) && is_array($aActor)) {

                if (!in_array($aZzira['seq'], $aActor['zzira']['receive'])) {
                        $this->sendMessage($this->sBid, "잘못된 찌라시 번호에요.");
                        return;
                    } else if (in_array($aZzira['seq'], $aActor['zzira']['resend'])) {
                        $this->sendMessage($this->sBid, "이미 돌린 찌라시 입니다.");
                        return;
                    }

                    $this->sendZzira($aZzira);

                    array_push($aActor['zzira']['resend'], $aZzira['seq']);
                    $this->setUser($this->sBid, $aActor);

                    $this->sendMessage($this->sBid, "찌라시를 돌렸어요~");
                    return;
            }


        } else if (strpos($sCmd, '버려') === 0) {
            if (isset($mSecCmd) && is_numeric($mSecCmd)) {
                $aZzira = $this->getZzira($mSecCmd);
                $aUser = $this->getUser($aZzira['bid']);
                $aActor = $this->getUser($this->sBid);

                if (is_array($aZzira) && is_array($aUser) && is_array($aActor)) {
                    
                    if (!in_array($aZzira['seq'], $aActor['zzira']['receive'])) {
                        $this->sendMessage($this->sBid, "잘못된 찌라시 번호에요.");
                        return;
                    } else if (in_array($aZzira['seq'], $aActor['zzira']['trash'])) {
                        $this->sendMessage($this->sBid, "이미 버린 찌라시 입니다.");
                        return;
                    }

                    $aZzira['trashcnt'] += 1;
                    $this->setZzira($mSecCmd, $aZzira);

                    $aUser['block']['bcnt'] += 1;
                    if ($aUser['block']['bcnt'] > self::BLOCK_LIMIT_CNT) {
                        $aUser['block']['bexptime'] = self::BLOCK_LIMIT_TIME + time();
                    }

                    array_push($aActor['zzira']['trash'], $aZzira['seq']);
                    $this->setUser($this->sBid, $aActor);
                    $this->setUser($aUser['bid'], $aUser);
                    $this->sendMessage($this->sBid, "저질 찌라시로 신고했어요.");
                    return;
                }
            }
            $this->sendMessage($this->sBid, "저질 찌라시 신고 실패했어요. ㅜㅜ");
        } else if (strpos($sCmd, '뿌려') === 0){
            if ($this->isBlock()) {
                $this->sendMessage($this->sBid, "저질 찌라시로 신고당해 당분간 찌라시를 돌릴수 없어요.");
            } else if ($this->isCountOver()) {
                $this->sendMessage($this->sBid, "오늘 너무 많은 찌라시를 돌려서 오늘 찌라시를 돌릴수 없어요.");
            } else {
                $mSecCmd = str_replace('뿌려 ', '', $this->sMsg);
                if (!isset($mSecCmd) || strlen($mSecCmd) < 1) {
                    $this->sendMessage($this->sBid, "내용이 없어요.");
                    return;
                }

                $aZzira = $this->registerZzira($mSecCmd);
                if ($aZzira !== false && $this->sendZzira($aZzira)) {
                    $this->sendMessage($this->sBid, "찌라시를 뿌렸습니다.");
                } else {
                    $this->sendMessage($this->sBid, "찌라시를 못돌렸어요. ㅜㅜ");
                }
            }
        } else if (strpos($sCmd, '도움') === 0) {
            $this->sendMessage($this->sBid, $this->sendNotice());
        }
    }

    private function registerFriends()
    {
        $aResult = array('code'=>200, 'msg'=>'');
        $aAllUser = $this->getUserAll();
        if (!is_array($aAllUser)) {
            $aAllUser = array();
        }

        if (array_key_exists($this->sBid, $aAllUser)) {
            $aResult['code'] = 400;
            $aResult['msg'] = '이미 등록된 친구네요.';
        } else {
            $iSeq = $this->redis->incrSeq(self::REDIS_USEQ);
            if (is_numeric($iSeq)) {
                $aUser = array(
                'seq'=>$iSeq,
                'bid'=>$this->sBid,
                'zzira'=>array(
                    'send'=>array(),
                    'resend'=>array(),
                    'trash'=>array(),
                    'receive'=>array()
                    ),
                'block'=>array(
                    'bcnt'=>0,
                    'bexptime' => -1
                    ),
                'todaycnt'=>0,
                'lstime'=>time() //lastsend
                );

                if ($this->setUser($this->sBid, $aUser)) {
                    $aResult['code'] = 200;
                    $aResult['msg'] = $this->sendNotice();
                } else {
                    $aResult['code'] = 400;
                    $aResult['msg'] = '등록 실패했어요. 다시 시도해봐요.';
                }
            } else {
                $aResult['code'] = 400;
                $aResult['msg'] = '등록 실패했어요. 다시 시도해봐요.';
            }
        }
        return $aResult;
    }

    private function registerZzira($sMsg)
    {
        $aUser = $this->getUser($this->sBid);
        $sZzira = $sMsg;
        $iSeq = $this->redis->incrSeq(self::REDIS_ZSEQ);

        if (is_array($aUser) && isset($sZzira) && is_numeric($iSeq)) {
            $aAllZzira = $this->getZziraAll();
            if (!is_array($aAllZzira)) {
                $aAllZzira = array();
            }
            $aZzira = array(
                'seq'=>$iSeq,
                'bid'=>$this->sBid,
                'msg'=>$sZzira,
                'trashcnt'=>0,
                'resendcnt'=>0,
                'resendbid'=>array($this->sBid),
                'ctime'=>time()
                );

            if ($this->setZzira($iSeq, $aZzira)) {
                array_push($aUser['zzira']['send'], $iSeq);
                $aUser['todaycnt'] += 1;
                $aUser['lstime'] = time();
                $this->setUser($this->sBid, $aUser);
                return $aZzira;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    private function sendZzira($aZzira)
    {
        if (is_array($aZzira)) {
            $aTargets = array();
            $aAllUser = $this->getUserAll();
            $aTargetBid = array_keys($aAllUser);
            $aTargetBid = array_diff($aTargetBid, $aZzira['resendbid']);
            if (is_array($aTargetBid) && count($aTargetBid) > 0) {

                $aZzira['resendcnt'] += 1;
                $iPPCnt = self::ZZIRA_PP_CNT * $aZzira['resendcnt'];
                if (count($aTargetBid) > $iPPCnt) {
                    $aRandInx = array_rand($aTargetBid, $iPPCnt);
                    if (is_array($aRandInx)) {
                        foreach($aRandInx as $iInx) {
                            $aTargets[] = $aTargetBid[$iInx];
                        }
                    } else if (is_numeric($aRandInx)) {
                        $aTargets[] = $aTargetBid[$aRandInx];
                    }
                }

                if (count($aTargets) < 1) {
                    $aTargets = $aTargetBid;
                }

                $aZzira['resendbid'] = array_merge($aZzira['resendbid'], $aTargets);
                $this->setZzira($aZzira['seq'], $aZzira);
                foreach ($aTargets as $sBid) {
                    $aUser = $this->getUser($sBid);
                    array_push($aUser['zzira']['receive'], $aZzira['seq']);
                    $this->setUser($sBid, $aUser);
                    $this->sendMessage($sBid, $this->makeZziraMsg($aZzira));
                }
                return true;
            }
        }
        return false;
    }

    private function makeZziraMsg($aZzira)
    {
        $sMsg = '';
        $sMsg .= "번호 : ".$aZzira['seq']." - 익명의 찌라시 도착!!";
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "내용: ".$aZzira['msg'];
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "이 찌라시가 돌릴 가치가 있으면 [돌려 ".$aZzira['seq']."] 를, 쓰레기라면 [버려 ".$aZzira['seq']."] 를 입력해주세요.";
        return $sMsg;
    }

    private function sendNotice()
    {
        $sMsg = '';
        $sMsg .= "찌라시를 익명으로 돌려봅시다. 자신만 아는 스캔들, 재미있는 이야기, 뒷담화 등등 익명으로 돌려보세요.";
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "다른 사람들이 전파하면 할수록 더욱 더 많은 불특정 다수에게 뿌려집니다.";
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "주의 : 저질의 찌라시를 보낼경우 일정시간 블럭 당할수도 있습니다. ㅎㅎㅎ";
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "--사용법--";
        $sMsg .= "\r\n";
        $sMsg .= "\r\n";
        $sMsg .= "찌라시를 뿌릴려면 : 뿌려 [내용]";
        $sMsg .= "\r\n";
        $sMsg .= "받은 찌라시를 전파하려면 : 돌려 [글번호]";
        $sMsg .= "\r\n";
        $sMsg .= "받은 찌라시를 버리려면 : 버려 [글번호]";
        $sMsg .= "\r\n";
        $sMsg .= "도움말은 : 도움 ";
        return $sMsg;
    }

    private function sendMessage($buddyId, $msg)
    {
        //global $API_URL_PREFIX, $API_URL_POSTFIX, $MYPEOPLE_BOT_APIKEY;
        
        //메시지 전송 url 지정
        $url =  self::API_URL_PREFIX."/mypeople/buddy/send.xml";
        
        //CR처리. \n 이 있을경우 에러남
        //$msg = urlencode(str_replace(array("\n",'\n'), "\r", $msg));
        $msg = urlencode($msg);    
        
        //파라미터 설정
        $postData = array();
        $postData['buddyId'] = $buddyId;
        $postData['content'] = $msg;    
        $postData['apikey'] = self::MYPEOPLE_BOT_APIKEY;    
        $postVars = http_build_query($postData);
        
        //cURL을 통한 http요청 (cURL은 php 4.0.2 이상에서 지원합니다.)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postVars);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $result = curl_exec($ch);
        curl_close($ch);
        
        //결과 출력
        echo "sendMessage";
        var_dump($result);
    }

    private function getUserAll()
    {
        return $this->redis->getData(self::REDIS_USER);
    }

    private function getUser($sBid)
    {
        $aUser = $this->getUserAll();
        if (is_array($aUser) && array_key_exists($sBid, $aUser)) {
            return $aUser[$sBid];
        } else {
            return false;
        }
    }

    private function setUser($sBid, $aData)
    {
        $aUser = $this->getUserAll();
        $aUser[$sBid] = $aData;
        return $this->redis->setData(self::REDIS_USER, $aUser);
    }

    private function delUser($sBid)
    {
        $aUser = $this->getUserAll();
        if (is_array($aUser) && array_key_exists($sBid, $aUser)) {
            unset($aUser[$sBid]);
            return $this->redis->setData(self::REDIS_USER, $aUser);
        } else {
            return false;
        }
    }

    private function showUser($sBid=NULL)
    {
        if (!isset($sBid)) {
            $aUser = $this->getUserAll();
        } else {
            $aUser = $this->getUser($sBid);
        }
        
        $sInfo = $this->toInfo($aUser);
        $this->sendMessage($this->sBid, $sInfo);
    }

    private function getZziraAll()
    {
        return $this->redis->getData(self::REDIS_ZZIRA);
    }

    private function getZzira($iSeq)
    {
        $aZzira = $this->getZziraAll();
        if (is_array($aZzira) && array_key_exists($iSeq, $aZzira)) {
            return $aZzira[$iSeq];
        } else {
            return false;
        }
    }

    private function setZzira($iSeq, $aData)
    {
        $aZzira = $this->getZziraAll();
        $aZzira[$iSeq] = $aData;
        return $this->redis->setData(self::REDIS_ZZIRA, $aZzira);
    }

    private function delZzira($iSeq)
    {
        $aZzira = $this->getZziraAll();
        if (is_array($aZzira) && array_key_exists($iSeq, $aZzira)) {
            unset($aZzira[$iSeq]);
            return $this->redis->setData(self::REDIS_ZZIRA, $aZzira);
        } else {
            return false;
        }
    }

    private function showZzira($iSeq=NULL)
    {
        if (!isset($iSeq)) {
            $aZzira = $this->getZziraAll();
        } else {
            $aZzira = $this->getZzira($iSeq);
        }

        $sInfo = $this->toInfo($aZzira);
        $this->sendMessage($this->sBid, $sInfo);
    }

    private function toInfo($mData)
    {
        return json_encode($mData);
    }
}

$APP = new Zzirashi();
$APP->Run();


