<?php
require "Predis/Autoloader.php";

class Nolzabot {

	const API_URL_PREFIX = "https://apis.daum.net";
	const MYPEOPLE_BOT_APIKEY = "e8afc584bd51c77ae8a41f7dd8cff75dd34ee5ab";
	
	const MSG_NO = "o_msgNo";
	const USER = "o_user:";
	const KEYWORD = "o_keyword:";
	const KEYWORDLIST = "o_keywordList:";
	const MSG = "o_msg:";
	

	private $redis;

 	public function __construct()
    {
    	Predis\Autoloader::register();
        $this->redis = new Predis\Client();
    }


	public function init()
	{
		switch($_POST['action']) {
			case "addBuddy":
				$this->greetingMessageToBuddy();	//봇을 친구로 등록한 사용자의 이름을 가져와 환영 메시지를 보냅니다.
				break;
			case "sendFromMessage":		
				$this->echoMessageToBuddy();		//말을 그대로 따라합니다.
				break;
		}

	}

	private function intro()
	{
		$sIntro =  "친구와 놀고싶거나 공부하고싶은데 지금친구는 내가 하고싶은것에 관심이 없을때 ";
		$sIntro .= "\r\n";
		$sIntro .= "자신의 관심있어하는 키워드를 등록하여 친구를 기다리거나 ";
		$sIntro .= "\r\n";
		$sIntro .= "자신이 관심있는 키워드로 친구를 찾아주는 놀자봇 입니다!!^^";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "===============================";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "1.우선 자신이 관심있어하는 키워드를 등록하여 주세요.";
		$sIntro .= "\r\n";
		$sIntro .= "(키워드를 등록하셔야 메시지를 받을수 있습니다.)";
		$sIntro .= "\r\n";
		$sIntro .= "예) 키:야구, 축구, JAVA, php, 롤";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "2.자신의 키워드를 확인하는 방법";
		$sIntro .= "\r\n";
		$sIntro .= "예) 키워드확인";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "3.관심있는 '키워드'로 친구를 찾고 싶을때 (마지막에 느낌표를 보내주세요)";
		$sIntro .= "\r\n";
		$sIntro .= "예) '롤' 3:3한판하실분~두자리 비어요!";
		$sIntro .= "\r\n";
		$sIntro .= "예) 일산시 '야구'동호회에 관심있으신분 찾아요!";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "4.메시지에 응답하는 방법은 메시지번호와 다음ID를 입력하시면 됩니다.";
		$sIntro .= "\r\n";
		$sIntro .= "예) 33 daumID";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "5.총 키워드 이력 확인.";
		$sIntro .= "\r\n";
		$sIntro .= "예) 키워드이력";
		$sIntro .= "\r\n";
		$sIntro .= "\r\n";
		$sIntro .= "6.도움말이 필요할 경우";
		$sIntro .= "\r\n";
		$sIntro .= "예) 도움말";


		return $sIntro;

	}

	/**
	 *  처음 친구를 추가하였을 경우
	 **/
	private function greetingMessageToBuddy()
	{
		$buddyId = $_POST['buddyId'];		//봇을 친구추가한 친구ID  

		$msg = $this->getBuddyName($buddyId). "님 안녕하세요! ";
		$msg .= $this->intro();

		$this->sendMessage("buddy", $buddyId, $msg);

	}

	/**
	 *  1:1 대화 메시지를 받을 경우
	 **/
	private function echoMessageToBuddy()
	{
		$buddyId = $_POST['buddyId'];		//메시지를 보낸 친구ID
		$sMsgContent =  $_POST['content'];			//메시지 내용

		$iKeyward = strpos($sMsgContent, '키:');		
		if($iKeyward === 0) {
			$aContentKeywardData = explode(':', $sMsgContent);
			if($aContentKeywardData[1] != null) {
				$resultMsg = $this->setKeyword($buddyId, $aContentKeywardData[1]);
			}else {
				$resultMsg = '키워드를 찾을수 없습니다.';
			}
			
		}

		if($sMsgContent == '도움말') {
			$resultMsg = $this->intro();
		}

		if($sMsgContent == '키워드확인') {
			$sKeyList = $this->getKeywordList($buddyId);
			if($sKeyList != null || $sKeyList != "") {
				$resultMsg = $this->getBuddyName($buddyId).'님의 키워드는 ';
				$resultMsg .= $this->getKeywordList($buddyId);
				$resultMsg .= '입니다.';
			}else {
				$resultMsg = '설정하신 키워드가 없습니다.';
			}
		}


		$iNolza = strpos($sMsgContent, '!');
		if($iNolza > -1) {
			
			$iMsgNo = $this->redis->get(self::MSG_NO);
			$this->redis->set(self::MSG.$iMsgNo, $buddyId);
			$iMsgNo++;
			$this->redis->set(self::MSG_NO, $iMsgNo);

			$pattern = '/\'(.*)\'/';
			preg_match($pattern, $sMsgContent, $matches);

			if($matches != null) {
				$sSearchKeyword = md5($matches[1]);
				$aSearchIdList = unserialize($this->redis->get(self::KEYWORD.$sSearchKeyword));

				if($aSearchIdList != null) {
					$sSendMesage =  $this->getBuddyName($buddyId).'님의 메시지 : ';
			
					foreach ($aSearchIdList as $searchBuddyId) {
						if($buddyId != $searchBuddyId) {
							$sSendMesage .= $sMsgContent;
							$sSendMesage .= "\r\n";
							$sSendMesage .= "메시지번호 [".($iMsgNo-1)."] 입니다.";
							$sSendMesage .= "\r\n";
							$sSendMesage .= "친구를 하고 싶으시면 메시지번호와 다음아이디를 보내주세요";
							$sSendMesage .= "\r\n";
							$sSendMesage .= "예) 23 daumID";

							$this->sendMessage("buddy", $searchBuddyId, $sSendMesage);
						}
					}
					$resultMsg = '관심있는 친구들에게 메시지를 보냈습니다.';

				}else {
					$resultMsg = $matches[1].'에 관심있는 친구가 없습니다.';
				}

			}

		}

		if(is_numeric(substr($sMsgContent, 0, 1))) {
			$aAnswerData =  explode(' ', $sMsgContent);
			$iAnswerMsgNo = $aAnswerData[0];
			$sDaumID = $aAnswerData[1];

			$sAnswerBuddyId = $this->redis->get(self::MSG.$iAnswerMsgNo);

			if($sAnswerBuddyId != null) {
				$sAnswerUserName = $this->getBuddyName($buddyId);
				$sAnswerMsg =  $sAnswerUserName."님이 친구를 하고 싶어합니다.";
				$sAnswerMsg .= "\r\n";
				$sAnswerMsg .= $sAnswerUserName."님의 ID는 ".$sDaumID."입니다.";
				$sAnswerMsg .= "\r\n";
				$sAnswerMsg .= "ID를 이용하여 친구를 추가하시기 바랍니다.";

				$this->sendMessage("buddy", $sAnswerBuddyId, $sAnswerMsg);

				$resultMsg = '친구요청을 성공적으로 보냈습니다.';
			}else {
				$resultMsg = '잘못된 메시지 번호 입니다.';
			}
		}


		if($sMsgContent == '키워드이력') {
			$aKeyHotChart = array();
			$aKeyList = unserialize($this->redis->get(self::KEYWORDLIST));

			foreach ($aKeyList as $key) {
				$mKey = md5($key);
				$aKeyUserIDList = unserialize($this->redis->get(self::KEYWORD.$mKey));

				$iKeyCount = count($aKeyUserIDList);

				$resultMsg .= $key." : ".$iKeyCount."명";
				$resultMsg .= "\r\n";
				
			}

		}


		$this->sendMessage("buddy", $buddyId, $resultMsg);

	}


	private function setKeyword( $buddyId, $sContentData )
	{
		$aContentTrimData = array();

		$sContentData = trim($sContentData);
		$aContentList =  explode(',', $sContentData);

		$index = 0;
		foreach ($aContentList as $keyward) {

			$aKeywordList[$index] = str_replace(" ", "", $keyward);
			$setKeyData = md5($aKeywordList[$index]);

			$sKeywordList = $this->redis->get(self::KEYWORDLIST);

			if($sKeywordList != null) {
				$aKeywordListData = unserialize($sKeywordList);
				if(!in_array($keyward, $aKeywordListData)){
					array_push($aKeywordListData, $keyward);
				}				
			}else {
				$aKeywordListData = array($aKeywordList[$index]);
			}

			$this->redis->set(self::KEYWORDLIST, serialize($aKeywordListData));


			$sIdList = $this->redis->get(self::KEYWORD.$setKeyData);
			
			if($sIdList != null) {
				$aIdListData = unserialize($sIdList);
				if(!in_array($buddyId, $aIdListData)){
					array_push($aIdListData, $buddyId);
				}				
			}else {
				$aIdListData = array($buddyId);
			}

			$this->redis->set(self::KEYWORD.$setKeyData, serialize($aIdListData));
			
			$index++;
		} 

		$this->redis->set(self::USER.$buddyId, serialize($aKeywordList));

		$msg .= $this->getKeywordList($buddyId);

		$msg .= "로 키워드가 설정되었습니다.";


		return $msg;
	}



	private function getKeywordList($buddyId)
	{
		$aUserKeywordList = unserialize($this->redis->get(self::USER.$buddyId));

		foreach ($aUserKeywordList as $keyword) {
			$msg .= $keyword." ";
		}

		return $msg;
	}



	private function sendMessage($target, $targetId, $msg)
	{
		//메시지 전송 url 지정
		$url =  self::API_URL_PREFIX."/mypeople/" .$target. "/send.xml?apikey=" .self::MYPEOPLE_BOT_APIKEY;

		//CR처리. \n 이 있을경우 에러남
		// $msg = urlencode(str_replace(array("\n",'\n'), "\r", $msg));
		$msg = urlencode($msg);

		//파라미터 설정
		$postData = array();
		$postData[$target."Id"] = $targetId;
		$postData['content'] = $msg;		
		$postVars = http_build_query($postData);

		//cURL을 이용한 POST전송
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


	private function getBuddyName( $buddyId )
	{

		//프로필 정보보기 url 지정
		$url = self::API_URL_PREFIX."/mypeople/profile/buddy.xml?buddyId=" .$buddyId ."&apikey=".self::MYPEOPLE_BOT_APIKEY;

		//cURL을 통한 http요청
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$result = curl_exec($ch);
		curl_close($ch);

		//결과 출력
		echo "getBuddyName";
		var_dump($result);

		//결과 파싱 및 리턴 
		$xml = simplexml_load_string($result);
		if ($xml->code == 200) {
			return $xml->buddys->name;
		} else {
			return null;		//오류
		}
	}


	private function setRedis($sKey, $mData)
	{
		if (!isset($sKey) || !isset($mData)) {
			return false;
		}

		return $this->redis->set($sKey, serialize($mData));

	}

	private function getRedis($sKey)
	{
		
		if (!isset($sKey)) {
			return false;
		}

		if ($redis->exists($sKey)) {
			$sRaw = $this->redis->get($sKey);
			if ($sRaw) {
				return unserialize($sRaw);
			}
		}

		return false;
	}



}

$APP_START = new Nolzabot();
$APP_START->init();
