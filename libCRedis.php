<?php 

require "Predis/Autoloader.php";

class libCRedis
{
	public $redis;
	public function __construct()
	{
		Predis\Autoloader::register();
		$this->redis = new Predis\Client();
	}

	public function incrSeq($sKey)
	{
		if ($sKey && $this->redis->exists($sKey)) {
			$iSeq = $this->redis->get($sKey);
			$iSeq = (int)$iSeq;
			$iSeq += 1;
			$this->redis->set($sKey, $iSeq);
			return $iSeq;
		} else if ($sKey) {
			$this->redis->set($sKey, 1);
			return 1;
		} else {
			return false;
		}

	}

	public function delData($sKey)
	{
		if (!isset($sKey)) {
			return false;
		}

		return $this->redis->del($sKey);
	}

	public function setData($sKey, $mData)
	{
		//global $redis;

		if (!isset($sKey) || !isset($mData)) {
			return false;
		}

		return $this->redis->set($sKey, serialize($mData));

	}

	public function getData($sKey)
	{
		global $redis;
		
		if (!isset($sKey)) {
			return false;
		}

		if ($this->redis->exists($sKey)) {
			$sRaw = $this->redis->get($sKey);
			if ($sRaw) {
				return unserialize($sRaw);
			}
		}

		return false;
	}
}
?>