<?php
namespace Rakuten;
use RakutenRws_Client;
/**
* 楽天商品の登録ページクラス.
*/
class Controller {

const MY_RAKUTEN_ID = '1066514361780820331';
const MY_RAKUTEN_AFFI = '13f8577c.fe41f1cb.13f8577d.55f2e982';
const MAX_PAGE = 100;

public $SDK;
public $OP; // Option Obj
public $shopCode; //null
public $totalItemCount;
public $minPrice;
public $maxPrice;
public $item;

public $Redis;
public $_postData;
public $separator;
	public $objQuery;

// }}}
// {{{ functions

	/**
	* Page を初期化する.
	*
	* @var string $shop_code
	*/
	function __construct($shop_code=null)
	{
		$this->SDK = new RakutenRws_Client;
		$rakuten_id = self::MY_RAKUTEN_ID;
		$this->SDK->setApplicationId($rakuten_id);
		$affi_id = self::MY_RAKUTEN_AFFI;
		$this->SDK->setAffiliateId($affi_id);

		$this->setSeparator();

		$this->shopCode = $shop_code;
		try
		{
			if (is_null($this->Redis))
			{
				$this->Redis = new \Redis();
				$this->Redis->connect("127.0.0.1",6379);
			}
		}
		catch( Exception $e )
		{
			error_log('redis not connected!!');
			error_log($e->getMessage());
		}

		// Batch Common Func
		$objQuery = \SC_Query_Ex::getSingletonInstance();
		////表示メッセージ
		$this->arrDoneMessage = '';
		$this->gross_alert_mail_body = '';
		$this->objDb = new \SC_Helper_DB_Ex();

		$this->arrMtbProductDetail = $objQuery->getAll('SELECT * FROM wcs_mtb_product_detail');
		$this->arrProductDetail = $objQuery->getAll('SELECT * FROM wcs_mtb_product_detail');
		//店舗データ
		$tmp_arrShops = $objQuery->getAll('SELECT * FROM wcs_shops WHERE mtb_ss_id != 12');
		foreach ($tmp_arrShops as $shop)
		{
			$this->arrShops[$shop['id']] = $shop;
			$this->arrShops[$shop['id']]['data'] = unserialize($shop['data']);
		}
//		return $this;
	}

    public function chkStockItem($product_class_id)
    {
        $objQuery =& \SC_Query_Ex::getSingletonInstance();
        $product_code = $objQuery->getOne('SELECT down_filename FROM dtb_products_class WHERE product_class_id = ?', array($product_class_id));

        $options = array(
            'itemCode' => $product_code
        );
        sleep(1);
        $response = $this->SDK->execute('IchibaItemSearch', $options);
        if($response->isOk())
        {
            foreach($response as $data)
            {
                return $data['availability'];
            }
        }
        else
        {
            return false;
        }
	    return false;
    }

	/**
	* ポストデータ登録（必要時）
	*
	* @param array $post_data
	*/
	public function getPostData($post_data=array())
	{
		$this->_postData = $post_data;
	}
    /**
     * 置き換え文字登録
     *
     * @param const array
     */
    public function setSeparator()
    {
        $this->separator = array(
	        'word' => '%##%', //単語置き換え
	        'separate' => '|', //分割
	        'repeat' => '*', //繰り返し *2, *4 等
	        'bullet' => '^', //行頭
	        'end' => '$'
        );
    }
	/**
	 * 置き換え文字登録
	 *
	 * @param const array
	 */
	public function splitSymbols($haystack, $symbol='word', $another=null)
	{
		$needle = $this->separator[$symbol];
		switch($symbol)
		{
			case 'word':
				if($another==$this->separator['bullet']||$another==$this->separator['bullet'])goto ptnA;
				if($another==$this->separator['end']||$another==$this->separator['end'])goto ptnB;
				$result = str_replace($another, $needle.$another, $haystack);
				break;
			case 'separate':
				$result = explode($needle, $haystack);
				break;
			case 'repeat':
//				$needle = preg_quote($needle);
//				$haystack = preg_quote($haystack);
//				preg_split($needle, $haystack);
				$result = explode($needle, $haystack);
				break;
			case 'bullet':
				ptnA:
//				$needle = preg_quote($needle);
//				$haystack = preg_quote($haystack);
				$result = preg_replace('/^(.*)/', $this->separator['bullet']."$1", $haystack);
				break;
			case 'end':
				ptnB:
//				$needle = preg_quote($needle);
//				$haystack = preg_quote($haystack);
				$result = preg_replace('/(.*)$/', "$1".$this->separator['end'], $haystack);
				break;
			default:
				break;
		}
		return $result;
	}
    /**
     * itemUrl=product_code切り出し
     *
     * @param string $itemUrl
     */
    public function extProductCode($itemUrl)
    {
    try{
            $ptn = '#\/|%2F#';
            $urlArray = preg_split($ptn, $itemUrl);
            $urlArray = array_filter($urlArray);
            $url = array_pop($urlArray);
            return $url;
        }catch( Exception $e){
            return false;
        }
    }
	/**
	 * itemUrl=product_code切り出し
	 *
	 * @param string $original
	 * @param array ['id'=>$id, 'name'=>$name]
	 */
	public function rktReplace($original, $keyword, $role="step1")
	{
		switch($role)
		{
			case 'step1':
				// 分割確認
				list($first, $second) = $this->splitSymbols($keyword['name'], 'separate');
				if(is_null($second)) //分割なし
				{
					$original =& $this->splitSymbols($original, 'word', $first);
				}
				if(!is_null($second)) //分割あり
				{
					// 繰り返し確認
					$repeat1 = $repeat2 = 1;
					list($word1, $repeat1) = $this->splitSymbols($first, 'repeat');
					list($word2, $repeat2) = $this->splitSymbols($second, 'repeat');
//					$word1 = preg_quote($word1);
//					$word2 = preg_quote($word2);
//					$original = preg_quote($original);
					$_shadow1  = $_shadow2 = null;
					$i = $j = 1;
					while($i < $repeat1){
						$_shadow1 .= $word1.'.*?';
						$i++;
					}
					while($j < $repeat2){
						$_shadow2 .= $word2.'.*?';
						$j++;
					}
					// マーキング
					if($word1==$this->separator['bullet'])
					{
						$ptn = '/^(.*?'.$_shadow2.$word2.')(.*?)/';
						$original =& preg_replace($ptn, $this->separator['bullet']."$1".$this->separator['word']."$2", $original);
					}
					elseif($word2==$this->separator['end'])
					{
						$ptn = '/(.*?'.$_shadow1.')('.$word1.'.*?)$/';
						$original =& preg_replace($ptn, "$1".$this->separator['word']."$2".$this->separator['end'], $original);
					}
					else
					{
						$ptn = '/(.*?'.$_shadow1.')('.$word1.'.*?'.$_shadow2.$word2.')(.*?)/';
						$original =& preg_replace($ptn, "$1".$this->separator['word']."$2".$this->separator['word']."$3", $original);
					}
				}
				break;
			case 'step2':
				// 分割確認
				$repeat1 = $repeat2 = 1;
				list($first, $second) = $this->splitSymbols($keyword['name'], 'separate');
				if(is_null($second)) //分割なし
				{
					$original = $first;
				}
				if(!is_null($second)) //分割あり
				{
					// 繰り返し確認
					list($word1, $repeat1) = $this->splitSymbols($first, 'repeat');
					list($word2, $repeat2) = $this->splitSymbols($second, 'repeat');
					if($word1==$this->separator['bullet'])
					{
						$original = $repeat1;
						$keyword = array($this->separator['bullet']);
						return $keyword;
					}
					elseif($word2==$this->separator['end'])
					{
						$original = $repeat2;
						$keyword = array($this->separator['end']);
						return $keyword;
					}
					else
					{
						$original = ($repeat1 > $repeat2) ? $repeat1 : $repeat2;
						$original = array($word1, $word2);
					}
				}
				break;
		}
		return $original;
	}

	/**
	* 検索ファンクション.
	*
	* @return array
	*/
	public function searchShopCode($shopCode, $page=1, $option='defaults', $area='NONE', $word=NULL)
	{
		$this->OP = new stdClass;
		// 標準
		$this->OP->defaults = array(
		'shopCode'		 => $shopCode,
		'availability'	 => 1,
		'imageFlag'		 => 1,
		'page'			 => $page,
		'sort'			 => '-updateTimestamp',
		);
		// キーワード検索
		$this->OP->keyword = array(
		'sort'			 => 'standard',
		'keyword'		 => $word,
		);
		// 国指定オプション
		$this->OP->global = array(
		'US' => array(
		'shipOverseasFlag' => 1,
		'shipOverseasArea' => array('US', 'ALL'),
		'orFlag' => 1,
		),
		'CN1' => array(
		'shipOverseasFlag' => 1,
		'shipOverseasArea' => array('CN', 'ALL'),
		'orFlag' => 1,
		),
		'CN2' => array(
		'shipOverseasFlag' => 1,
		'shipOverseasArea' => array('CN', 'ALL'),
		'orFlag' => 1,
		),
		'KR' => array(
		'shipOverseasFlag' => 1,
		'shipOverseasArea' => array('KR', 'ALL'),
		'orFlag' => 1,
		),
		'NONE' => array(),
		);
		// あす楽フラグ
		$this->OP->asuraku = array(
		'asurakuFlag' => 1,
		'asurakuArea' => 0,
		);
		// レビューフラグ
		$this->OP->review = array(
		'hasReviewFlag' => 1,
		'sort' => '-reviewCount',
		);
		// ポイントアップフラグ
		$this->OP->point = array(
		'pointRateFlag' => 1,
		'pointRate' => 2,
		);

		$options = array_merge($this->OP->defaults, $this->OP->$option);
		$options = array_merge($options, $this->OP->global[$area]);
		sleep(1);
		$response = $this->SDK->execute('IchibaItemSearch', $options);

		// レスポンスが正しいかを isOk() で確認することができます
		if ($response->isOk()) {
		// 配列アクセスによりレスポンスにアクセスすることができます。
		foreach ($response as $item)
		{
		$items[] = $item;
		}
		return (!empty($items)) ? $items : null ;
		} else {
		return 'Error:'.$response->getMessage();
		//            return false;
		}
	}

/**
* @param int $batch_id = wcs_batch_cue.id
* @param int $range
* @return string
*/
public function getAllItems($batch_id, $range = 5000)
{
try {
	$valids = array('shopCode', 'totalItemCount', 'minPrice', 'maxPrice');
	foreach ($valids as $must)
	{
		if(is_null($this->{$must}))
		{
			return $must. ' Not Valids!!!';
		}
	}

	$objQuery = $this->objQuery;
	$this->Tmp_Holder = new \stdClass;
	$this->contents_all = array();
	$this->csv_contents = array();

	$_min = $this->minPrice;
	$range = 5000;
	$progress_count = 0;
	do{
		error_log($_min);
		$progress_count++;
		$page = 1;
		$_max = $_min + $range;
		$options = array(
		'shopCode'      => $this->shopCode,
		'availability'  => 1,
		'imageFlag'		=> 1,
		'sort'          => '+itemPrice',
		'hits'          => 30,
		'page'          => $page,
		'minPrice'      => $_min,
		'maxPrice'      => $_max,
		);
		sleep(1);
		$response = $this->SDK->execute('IchibaItemSearch', $options);
		if($response['count'] > 3000)
		{
			$range = ( int )$range / 2;
			continue;
		}
		foreach( $response['Items'] as $data )
		{
			// 登録済みかどうかの判定
			if (!$this->Redis->exists($data['Item']['itemCode']))
			{
				$this->Redis->sAdd($data['Item']['itemCode'], $data['Item']);
				if($batch_id=="csv")
				{
					$this->makeCsv($data);
				}
				else
				{
					$this->_insertEachTable($data);
				}
			}
		}
		// 複数ページの場合
		if( $response['pageCount'] > 1 )
		{
			$pages = range(2, $response['pageCount']);
			foreach($pages as $p)
			{
				error_log($p);
				$options['page'] = $p;
				sleep(1);
				$response = $this->SDK->execute('IchibaItemSearch', $options);
				$this->_chkResponse($response);
				foreach( $response['Items'] as $data )
				{
					if (!$this->Redis->exists($data['Item']['itemCode']))
					{
						$this->Redis->sAdd($data['Item']['itemCode'], $data['Item']);

						if($batch_id=="csv")
						{
							$this->makeCsv($data);
						}
						else
						{
							$this->_insertEachTable($data);
						}
					}
				}
			}
		}
		$_min = $_max + 1;
		$_max = $_min + $range - 1;
		error_log($_max);
		error_log($_min);
		if($_max > $this->maxPrice) $_max = $this->maxPrice;
		//            $objQuery->update('wcs_batch_cue', array('batch_id' => $batch_id, 'progress' => $progress_count, 'result' => 'progress'));
	} while( $_min < $this->maxPrice );

	if($batch_id=='csv')$this->exportCsv();
//        $objQuery->update('wcs_batch_cue', array('id' => $batch_id, 'result' => 'finished'));
}
catch( Exception $e ) {
	error_log('Exception!!');
	error_log($e->getMessage());
	error_log($objQuery->getLastQuery());
	//            $objQuery->update('wcs_batch_cue', array('batch_id' => $batch_id, 'result' => 'Exception failed'));
}


}

	public function makeCsv($data)
	{
						// 切り分けワードを取得
						$detailsAll = array('itemName' => $this->_postData['itemName'], 'itemCaption' => $this->_postData['itemCaption']);
						foreach ($detailsAll as $type => $details)
						{
							foreach($details as $key => $detail)
							{
								/** @var string $redis_key  ex. 9:detailId_85 */
								$valId = $detail['id'];
								$delFlg = $detail['delete'];
								$redis_key = SHOP_DOMAIN. ":$type" .":detailId_$valId";
								if(!$delFlg)
								{
									$detail['name'] = $this->Redis->get($redis_key);
								}
								$post_data[$type][$key]['name'] = (!empty($detail['name'])) ? $detail['name'] : $this->arrMtbProductDetail[$key-1]['name'];
							}
						}
						// キーワード毎に切り取り
						$_key = $this->separator;
						$itemCaption = $data['Item']['itemCaption'];
						$itemName = $data['Item']['itemName'];
						// Part: itemName
						if( isset($post_data['itemName']) && is_array($post_data['itemName']) )
						{
							foreach($post_data['itemName'] as $pd)
							{
								$itemName = $this->rktReplace($itemName, $pd, 'step1');
//							        $csv_nam = array();
//							        foreach($post_data['itemName'] as $pd)
//							        {
								//                            $pd['name'] = empty($pd['name']) ? '' : $pd['name'].' '; //BeforeSpaceOnly
							}

							$details = explode($_key['word'], $itemName);
							$details = array_filter($details);
							$csv_nam = array();
							foreach($post_data['itemName'] as $pd)
							{
								$index = $repeat = 1;
								foreach ($details as $key => $dt)
								{
									$_cnt = 0;
//								        $ptn = '/'.preg_quote($pd['name']).'(.+)/';
//								        $dt2 = preg_replace($ptn, '$1', $dt, $_cnt);
									$_word = $this->rktReplace($repeat, $pd, 'step2');
									$dt2 = str_replace($_word, '', $dt, $_cnt);
									if ($_cnt==count($_word)) {
										if($index==$repeat){
											$csv_nam[$pd['id']]['name'] = $this->arrMtbProductDetail[$pd['id']-1]['name'];
											$csv_nam[$pd['id']]['content'] = $dt2;
										}
										$index++;
									}
								}
							}
							$csv_nam = array_values($csv_nam);
							foreach ($csv_nam as $_id => $_nam) {
								$_num = "csv_content_$_id";
								//			                $Tmp_Holder->$_num = isset($Tmp_Holder->$_num) ? $Tmp_Holder->$_num : new stdClass;
								$this->Tmp_Holder->$_num->itemNameTitle = (isset($_nam['name'])) ? $_nam['name'] : '';
								$this->Tmp_Holder->$_num->itemNameContent = (isset($_nam['content'])) ? $_nam['content'] : '';
								$this->Tmp_Holder->$_num->itemCaptionTitle = (empty($this->Tmp_Holder->$_num->itemCaptionTitle)) ? '' : $this->Tmp_Holder->$_num->itemCaptionTitle;
								$this->Tmp_Holder->$_num->itemCaptionContent = (empty($this->Tmp_Holder->$_num->itemCaptionContent)) ? '' : $this->Tmp_Holder->$_num->itemCaptionContent;
								$this->Tmp_Holder->$_num->itemCode = '';
								//			                $this->csv_contents[$_id] = $this->Tmp_Holder->$_num;
							}
							unset($csv_nam);
						}

						// Part: itemCaption
						if( isset($post_data['itemCaption']) && is_array($post_data['itemCaption']) )
						{
							foreach($post_data['itemCaption'] as $pd) {
//                            list($first, $second) = explode($_key['separate'], $pd['name']);
//							        list($first, $second) = $this->controller->splitSymbols($pd['name'], 'separate');
								$itemCaption = $this->rktReplace($itemCaption, $pd, 'step1');
							}
							$details = explode($_key['word'], $itemCaption);
							$details = array_filter($details);
							$csv_cap = array();
							foreach($post_data['itemCaption'] as $pd)
							{
//                            $pd['name'] = empty($pd['name']) ? '' : $pd['name'].' '; //BeforeSpaceOnly
								foreach ($details as $key => $dt)
								{
									$_cnt = 0;
									$_word = $this->rktReplace('', $pd, 'step2');
									$dt2 = str_replace($_word, '', $dt, $_cnt);
									if ($_cnt >= 1) {
//                                    $csv_cap[$key]['name'] = $pd['name'];
//                                    $csv_cap[$key]['content'] = $dt;
										$csv_cap[$pd['id']]['name'] = $this->arrMtbProductDetail[$pd['id']-1]['name'];
										$csv_cap[$pd['id']]['content'] = $dt2;
									}
								}
							}
							$csv_cap = array_values($csv_cap);
							foreach($csv_cap as $_id => $_cap)
							{
								$_num = "csv_content_$_id";
//                            $this->Tmp_Holder->$_num = isset($this->Tmp_Holder->$_num) ? $this->Tmp_Holder->$_num : new stdClass;
								$this->Tmp_Holder->$_num->itemNameTitle = (empty($this->Tmp_Holder->$_num->itemNameTitle)) ? '' : $this->Tmp_Holder->$_num->itemNameTitle;
								$this->Tmp_Holder->$_num->itemNameContent = (empty($this->Tmp_Holder->$_num->itemNameContent)) ? '' : $this->Tmp_Holder->$_num->itemNameContent;
								$this->Tmp_Holder->$_num->itemCaptionTitle = (isset($_cap['name'])) ? $_cap['name'] : '';
								$this->Tmp_Holder->$_num->itemCaptionContent = (isset($_cap['content'])) ? $_cap['content'] : '';
								$this->Tmp_Holder->$_num->itemCode = '';
								$this->csv_contents[$_id] = $this->Tmp_Holder->$_num;
								unset($this->Tmp_Holder->$_num);
							}
							unset($csv_cap);
						}

						$origin = array('itemNameTitle' => '', 'itemNameContent' => $data['Item']['itemName'], 'itemCaptionTitle' => '', 'itemCaptionContent' => $data['Item']['itemCaption'], 'itemCode' => $data['Item']['itemCode']);
						$_after = array('itemNameTitle' => '-----', 'itemNameContent' => '-----', 'itemCaptionTitle' => '-----', 'itemCaptionContent' => '-----', 'itemCode' => '-----');
						array_unshift($this->csv_contents, $origin);
						array_push($this->csv_contents, $_after);
						$this->contents_all[] = $this->csv_contents;
						$this->csv_contents = array();

					}


public function exportCsv()
{

			$csv_header = array("商品名（項目）", "商品名（内容）","商品説明（項目）","商品説明（内容）","商品コード");
			mb_convert_variables('SJIS-win', 'UTF-8', $csv_header);
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename=TestDetail.csv');
			$stream = fopen('php://output', 'w');
			fputcsv($stream, $csv_header);
			foreach ($this->contents_all as $contents)
			{
				foreach($contents as $content)
				{
					$content = ( array )$content;
					mb_convert_variables('SJIS-win', 'UTF-8', $content);
					fputcsv($stream, $content);
				}
			}
			fclose($stream);

		}

/**
* 各テーブルへの登録ファンクション
* @param array $data ['Item'][_PARAMS_] fromSDK
*/
private function _insertEachTable($data)
{
	$objQuery =& SC_Query_Ex::getSingletonInstance();

	// 画像URL 成型
	$L_images = (is_array($data['Item']['mediumImageUrls'])) ? $data['Item']['mediumImageUrls'] : array();
	$S_images = (is_array($data['Item']['smallImageUrls'])) ? $data['Item']['smallImageUrls'] : array();
	$product_id = $objQuery->nextVal('dtb_products_product_id');
	$product_cls_id = $objQuery->nextVal('dtb_products_class_product_class_id');
	error_log($product_id);

	/**
	* TABLE VARIABLES
	*/
	/** @var array $ins_val  商品テーブル用 dtb_products　*/
	$ins_val = array(
		'product_id' => $product_id,
		'name' => $data['Item']['itemName'],
		'status' => 1,
		'main_comment' => $data['Item']['itemCaption']
	);

	/** @var array $ins_val_ss_category  モール別カテゴリ dtb_ss_product_category　*/
	$ins_val_ss_category = array(
		//                        'id' => $objQuery->nextVal('dtb_ss_product_category'),
		'product_id' => $product_id,
		'shop_id' => 1, // 楽天
		'category_data' => $data['Item']['genreId']
	);

	/** @var array $ins_val_ss_category  店内カテゴリ dtb_product_categories　*/
	//                    $ins_val_categories = array(
	//                        'product_id' => $product_id,
	//                        'shop_id' => 1, // 楽天
	//                        'category_data' => $data['Item']['genreId']
	//                    );

	/** @var array $ins_val_cls  商品規格用 dtb_products_class　*/
	// 商品規格
	$ins_val_cls = array(
		'product_class_id'  => $product_cls_id,
		'product_id'        => $product_id,
		//                        'classcategory_id1' => 0,
		//                        'classcategory_id2' => 0,
		'product_type_id'   => 1,
		'product_code'      => $data['Item']['itemCode'],
		'stock'             => 1,
		//                        'stock_unlimited'   => NULL,
		//                        'sale_limit'        => NULL,
		'price01'           => $data['Item']['itemPrice'],
		'price02'           => $data['Item']['itemPrice'],
		'deliv_fee'         => $data['Item']['postageFlag'], // 0:送料込み 1:送料別
		'point_rate'        => $data['Item']['pointRate'],
		'create_date'       => date('Y-m-d H:i:s'),
		'update_date'       => date('Y-m-d H:i:s')
	);
	/** @var array $ins_val_disp モール別表示非表示 wcs_shop_disp*/
	$ins_val_disp = array(
		"product_id" => $product_id,
		"shop_disp " => ',1,2,3,4,5,6,7,8,9,10,'
	);
	/** @var array $ins_val_ss_product_status オークション状況テーブル wcs_ss_product_status */
	//                    $ins_val_ss_product_status = array(
	//                        "id" => '',
	//                        "product_code" => $data['Item']['itemCode'],
	//                        "shop_id" => '1', // 楽天
	//                        "ss_id" => $data['Item']['itemCode'],
	//                        "url" => $data['Item']['itemUrl'],
	//                        "now_price" => $data['Item']['itemPrice'],
	//                        'name' => $data['Item']['itemName']
	//                    );


	//                    $ins_val_order = array(
	//                        'order_detail_id'   => $order_detail_id,
	//                        'order_id'          => $order_id,
	//                        'product_id'        => $product_id,
	//                        'product_class_id'  => $product_cls_id,
	//                        'product_name'      => $data['Item']['itemName'],
	//                        'product_code'      => $data['Item']['itemCode']
	//                    );
	$L_urls = array();
	foreach($L_images as $key => $img)
	{
	//            if($key==0){
	//                $ins_val['main_large_image'] = serialize($img);
	//            }else{
	//                $ins_val["sub_large_image$key"] = serialize($img);
	//            }
		$L_urls[] = preg_replace('/^(.*)\?.+=.+$/', "$1", $img['imageUrl']);
	}
		$ins_val['main_large_image'] = implode("\n", $L_urls);
	//        $ins_val['main_large_image'] = serialize($L_images[0]);
	//        $ins_val['main_large_image'] = serialize(implode("¥n", $L_images));

	$S_urls = array();
	foreach($S_images as $key => $img)
	{
	//            if($key==0){
	//                $ins_val['main_image'] = serialize($img);
	//            }else{
	//                $ins_val["sub_image$key"] = serialize($img);
	//            }
		$S_urls[] = preg_replace('/^(.*)\?.+=.+$/', "$1", $img['imageUrl']);
	}
	$ins_val['main_image'] = serialize(implode("\n", $S_urls));
	$ins_val['main_list_image'] = serialize($S_urls[0]);
	//        $ins_val['main_image'] = serialize($S_images[0]);
	//        $ins_val['main_image'] = serialize(implode("¥n", $S_images));
	// タイトル・キャプション保存
	$objQuery->begin();
	$objQuery->insert('dtb_products', $ins_val);
	$objQuery->insert('dtb_ss_product_category', $ins_val_ss_category);
	$objQuery->insert('dtb_products_class', $ins_val_cls);
	$objQuery->insert('wcs_shop_disp', $ins_val_disp);
	//                    $objQuery->insert('wcs_ss_product_status', $ins_val_ss_product_status);
	//                    $objQuery->insert('dtb_order_detail', $ins_val_order);
	$objQuery->commit();
	unset($ins_val);
	unset($ins_val_cls);


	// キーワード毎に切り取り
	// TODO:: itemName & itemCaption
	$details = $result = array();
	$_key = '_|_';
	static $types = array('itemName', 'itemCaption');
	foreach ($types as $type)
	{
		$cap = $data['Item'][$type];
		
		foreach($this->_postData['detail'][$type] as $pd)
		{
			//                $pd['name'] = empty($pd['name']) ? '' : $pd['name'].' '; //BeforeSpaceOnly
			$this->rktReplace($cap, $pd['name']);
			$this->splitSymbols($pd['name'], 'separate');

			$cap = str_replace($pd['name'], $_key.$pd['name'], $cap);
		}
	$details = explode($_key, $cap);
	foreach($this->_postData['detail'][$type] as $pd)
	{
		if($pd['input_require']['format']=='text')
		{
			//                    $pd['name'] = empty($pd['name']) ? '' : $pd['name'].' '; //BeforeSpaceOnly
			foreach($details as $key => $dt)
			{
				$_cnt = 0;
				$dt = str_replace($pd['name'], '', $dt, $_cnt);
				if($_cnt >= 1)
				{
				$detail_id = $objQuery->nextVal('wcs_products_detail');
				error_log($detail_id);
				$dt = preg_replace("/^¥s(.+)?¥s$/", "$1", $dt);
				$ins_val_dt[$pd['id']] = array(
				'id' => $detail_id,
				'product_id' => $product_id,
				'mtb_pd_id' => $pd['id'],
				'content' => $dt
				);
				$objQuery->begin();
				$objQuery->insert('wcs_product_detail', $ins_val_dt[$pd['id']]);
				$objQuery->commit();
			}
		}
	}
	elseif ($pd['input_require']['format']=='master')
	{
		$_master_list = $objQuery->getAll('SELECT * FROM wcs_master WHERE category =\'' .$pd['input_require']['keywords']. '\'');
		foreach($_master_list as $_id => $record)
		{
			if(stripos($data['Item']['itemName'], $record['data1']))
			{
				$detail_id = $objQuery->nextVal('wcs_products_detail');
				$ins_val_dt[$pd['id']] = array(
					'id' => $detail_id,
					'product_id' => $product_id,
					'mtb_pd_id' => $pd['id'],
					'content' => $record['id']
				);
				$objQuery->begin();
				$objQuery->insert('wcs_product_detail', $ins_val_dt[$pd['id']]);
				$objQuery->commit();
				break;
			}
		}
	}
elseif($pd['input_require']['format']=='datetime')
{
$detail_id = $objQuery->nextVal('wcs_products_detail');
$ins_val_dt[$pd['id']] = array(
'id' => $detail_id,
'product_id' => $product_id,
'mtb_pd_id' => $pd['id'],
'content' => date('Y-m-d H:i:s')
);
$objQuery->begin();
$objQuery->insert('wcs_product_detail', $ins_val_dt[$pd['id']]);
$objQuery->commit();
}
}
// カテゴリ登録
$this->applyProductCategory($ins_val_dt);
}
//        exit;
}
/**
* 商品カテゴリ登録を行う.
* @param array $ins_val_dt
*/
public function applyProductCategory($ins_val_dt) {

$objQuery =& SC_Query_Ex::getSingletonInstance();

//カテゴリテーブル
$this->arrCategoryTable = $objQuery->getRow('mtb_pd_id1, mtb_pd_id2, mtb_pd_id3, mtb_pd_id4, mtb_pd_id5, mtb_pd_id6', 'wcs_category_table', 'id > 0');
//店舗データ
//        $tmp_arrShops = $objQuery->getAll('SELECT * FROM wcs_shops WHERE mtb_ss_id != 12');
//        foreach ($tmp_arrShops as $shop) {
//            $this->arrShops[$shop['id']] = $shop;
//            $this->arrShops[$shop['id']]['data'] = unserialize($shop['data']);
//        }

$where = array();
$pd_datas = array();
foreach ($this->arrCategoryTable as $key=>$value) {
foreach(array('itemName', 'itemCaption') as $type)
{
//                if ($this->arrProductDetail[$value]['colum_name']) {
//                    if ($ins_val_dt[$value]['name']) {
//                        $where[] = preg_replace('/mtb_pd_id/', 'pd_data', $key)." = ".$objQuery->quote($this->_postData['detail'][$type][$value]['name']);
//                        $pd_datas[preg_replace('/mtb_pd_id/', 'pd_data', $key)] = $this->_postData['detail'][$type][$value]['name'];
//                    }
//                } else if ($this->_postData['detail'][$type][$value]['name']) {
//                    $where[] = preg_replace('/mtb_pd_id/', 'pd_data', $key)." = ".$objQuery->quote($this->_postData['detail'][$type][$value]['name']);
//                    $pd_datas[preg_replace('/mtb_pd_id/', 'pd_data', $key)] = $this->_postData['detail'][$type][$value]['name'];
//                }
if($ins_val_dt[$value])
{
$where[] = preg_replace('/mtb_pd_id/', 'pd_data', $key)." = ".$objQuery->quote($ins_val_dt[$value]['content']);
$pd_datas[preg_replace('/mtb_pd_id/', 'pd_data', $key)] = $ins_val_dt[$value]['content'];
$product_id = $ins_val_dt[$value]['product_id'];
}
}
//            if ($this->arrProductDetail[$value]) {
//                $where[] = preg_replace('/mtb_pd_id/', 'pd_data', $key)." = ".$objQuery->quote($this->arrProductDetail[$value]['name']);
//                $pd_datas[preg_replace('/mtb_pd_id/', 'pd_data', $key)] = $this->arrProductDetail[$value]['name'];
//            }
}


if (count($where)) {
$where = array_unique($where);
//ショップ毎にカテゴリを登録
foreach ($this->arrShops as $shop) {

//商品詳細に一致するものを抽出
$matched_category_list = $objQuery->getAll('SELECT pd_data1, pd_data2, pd_data3, pd_data4, pd_data5, pd_data6, category_data FROM wcs_category_table WHERE shop_id = ? AND ('.implode(' OR ', $where).')', array($shop['id']));

//適合するカテゴリにスコアをつける
$matched = array();
foreach ($matched_category_list as $value) {
$match_flag = 0;
for ($i=1; $i<=6; $i++) {
if ($value['pd_data'.$i]) {
if ($value['pd_data'.$i] == $pd_datas['pd_data'.$i]) {
$match_flag++;
} else {
$match_flag = 0;
break;
}
}
}
if ($match_flag) {
$matched[] = array('match_score' => $match_flag, 'category_data' => $value['category_data']);
}
}
unset($matched_category_list);

//スコア順で並び替え
usort($matched, compare);

switch ($shop['mtb_ss_id']) {

case 1:	//ECCubeの場合
case 2:	//楽天市場の場合
$category_ids = array();
foreach ($matched as $value) {
$rank = $objQuery->getOne('SELECT rank FROM dtb_category WHERE category_id = ?', array($value['category_data']));
$unique = $objQuery->getOne('SELECT * FROM dtb_product_categories WHERE category_id = ? AND product_id = ?', array($value['category_data'], $product_id));
error_log($rank);
if($rank && !$unique){
$objQuery->insert('dtb_product_categories', array('product_id' => $product_id, 'category_id' => $value['category_data'], 'rank' => $rank));
}
//                            $category_ids[] = $value['category_data'];
}
//                        $this->arrForm['category_id'] = $category_ids;
break;

case 3:	//ショップサーブの場合
case 4:	//eBayの場合
case 5:	//Amazonの場合
case 6:	//ヤフオクの場合
case 7:	//タオバオの場合
case 9:	//ヤフーショッピングの場合
//                        if (count($matched)) {
//                            $this->arrForm['ss_category'.$shop['id']] = $matched[0]['category_data'];
//                        }
break;
}
}
}
}

public function _searchMinPrice()
{
$options = array(
'shopCode'		 => $this->shopCode,
'availability'	 => 1,
'imageFlag'		 => 1,
'hits'	    	 => 1,
'sort'			 => '+itemPrice',
);
sleep(1);
$response = $this->SDK->execute('IchibaItemSearch', $options);
$this->_chkResponse($response);

$this->totalItemCount = (is_null($this->totalItemCount)) ? $response['count'] : $this->totalItemCount;
$item = array_shift($response['Items']);
$this->minPrice = $item['Item']['itemPrice'];

return true;
}
public function _searchMaxPrice()
{
$options = array(
'shopCode'		 => $this->shopCode,
'availability'	 => 1,
'imageFlag'		 => 1,
'hits'	    	 => 1,
'sort'			 => '-itemPrice',
);

sleep(1);
$response = $this->SDK->execute('IchibaItemSearch', $options);

self::_chkResponse($response);

$this->totalItemCount = (is_null($this->totalItemCount)) ? $response['count'] : $this->totalItemCount;
$item = array_shift($response['Items']);
$this->maxPrice = $item['Item']['itemPrice'];

return true;
}
public function _chkResponse($response)
{
// レスポンスが正しいかを isOk() で確認することができます
if ($response->isOk()) {
return $response;
//            return (!empty($response)) ? $response : null ;
} else {
return 'Error:'.$response->getMessage();
//            return false;
}
}

/**
* Page のプロセス.
*
* @return void
*/
function exec($batch_id) {
//        $res = $this->searchShopCode($post_data['shop_code']);
$objQuery =& SC_Query_Ex::getSingletonInstance();

$result = $this->_searchMinPrice();
$result = $this->_searchMaxPrice();
$result = $this->getAllItems($batch_id);
error_log('Batch_id is :'.$batch_id.' finished!!');exit;

}
	public function execCsv() {
//        $res = $this->searchShopCode($post_data['shop_code']);
		$objQuery = $this->objQuery;

		$result = $this->_searchMinPrice();
		$result = $this->_searchMaxPrice();
		$result = $this->getAllItems('csv');
		error_log('Batch_id is :'.'csv'.' finished!!');exit;

	}

/**
* Page のアクション.
*
* @return void
*/
function action() {
var_dump('This is Action');

}


/**
* デストラクタ.
*
* @return void
*/
function destroy() {
parent::destroy();
}

}

?>