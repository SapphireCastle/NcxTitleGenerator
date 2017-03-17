<?php

Bd_Init::init();
Bd_LayerProxy::init(Bd_Conf::getConf('/layerproxy/'));

$ncxTmpl = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no" ?>
<!DOCTYPE ncx PUBLIC "-//NISO//DTD ncx 2005-1//EN"
 "http://www.daisy.org/z3986/2005/ncx-2005-1.dtd">
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head>
    <meta content="1730fafb-1410-4838-a0b5-e3781f3af4ee" name="dtb:uid"/>
    <meta content="2" name="dtb:depth"/>
    <meta content="0" name="dtb:totalPageCount"/>
    <meta content="0" name="dtb:maxPageNumber"/>
  </head>
  <docTitle></docTitle>
  <navMap></navMap>
</ncx>
XML;

/**
 * 自定义zip类
 */
class MyZipArchive {
    public $archiveName = '';
    public $za;

    function __construct ($archiveName) {
    	$this->archiveName = $archiveName;
    	$this->za = new ZipArchive();
    }

	/**
     * 获取包内文件的相对路径-相对以包为根的全路径
     */
    public function getFullPath ($fileName) {
    	$za = $this->za;
    	$fullpath = '';
    	if ($za->open($this->archiveName) === TRUE) {
    		$pattern = '/'.$fileName.'/';
	    	$i = 0;
	    	while ($i < $za->numFiles && $fullpath == '') {
		        $fn = $za->statIndex($i);
		        preg_match($pattern, $fn['name'], $matches);
		        if (count($matches) != 0) {
		            $fullpath = $fn['name'];
		        }
		        $i++ ;
		    }
    		$za->close();
    	}
	    return $fullpath;
    }

    /**
     * 获取包内容
     */
    public function getContentByFileName ($fileName) {
    	$za = $this->za;
    	$content = '';
    	if ($za->open($this->archiveName) === TRUE) {
        	$content = $za->getFromName($fileName);
        	$za->close();
    	}
    	return $content;
    }

    /**
     * 把文件加入包内
     */
    public function addFile ($str, $path) {
    	$za = $this->za;
    	$res = false;
    	if ($za->open($this->archiveName) === TRUE) {
        	$res = $za->addFromString($path, $str);
        	$za->close();
    	}
    	return $res;
    }

    /**
     * 把文件从包内删除
     */
    public function deleteFile ($delPath) {
    	$za = $this->za;
    	$res = false;
    	if ($za->open($this->archiveName) === TRUE) {
        	$res = $za->deleteName($delPath);
        	$za->close();
    	}
    	return $res;
    }
}


/**
 * toc.xhtml格式化
 * NcxTreeGenerator
 */

class NTGxhtml {
	/**
	 * ncx内容的目录队列
	 * weight 权重（标题级数）
	 * href 目标位置
	 * title 标题名称
	 */
	public $ncxQueue = array();

	function __construct ($content) {}

	/**
	 * otherOp
	 */
	public function otherOp ($za) {
		$fullpath = $za->getFullPath('content.opf');
		$content = $za->getContentByFileName($fullpath);
		$resContent = str_replace('contents/toc.xhtml', 'toc.ncx', $content);
		$za->deleteFile($fullpath);
		$za->addFile($resContent, $fullpath);
	}

	/**
	 * ncx扁平树生成
	 * 使用递归即可遍历
	 */
	public function generateNcxTree ($ncx) {
		// 构造遍历器-不区分大小写，过滤空白符号
		$xpc = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($xpc, $ncx, $orgNcx);
		xml_parser_free($xpc);

		// 标题树关联数组
		$ncxAssoc = array();
		// 书名
		$bookName = '';

		// 过滤标签中的cdata
		foreach ($orgNcx as $onKey => $onVal) {
			if ($onVal['type'] != 'cdata') {
				$ncxAssoc[] = $onVal;
			}

			if ($onVal['tag'] == 'TITLE') {
				$bookName = $onVal['value'];
			}
		}

		// 记录当前索引位置
		$cur = 0;
		// 找到nav#toc起点终点索引
		$startNavIndex = 0;
		// 找到nav#toc结束符号
		$endNavIndex = 0;
		// 命中的nav所在层级
		$tocNavLevel = 0;
		// 是否可以开始记录结束位置
		$allowFindCloseNav = false;

		// 找出两个位置
		foreach ($ncxAssoc as $naKey => $naVal) {
			// 找到终点
			if ($allowFindCloseNav && $naVal['tag'] === 'NAV' && $naVal['level'] === $tocNavLevel) {
				$endNavIndex = $cur;
				$allowFindCloseNav = false;
				break;
			}

			// 找到起点
			if ($naVal['tag'] === 'NAV' && $naVal['attributes']['ID'] === 'toc') {
				$startNavIndex = $cur;

				$tocNavLevel = $naVal['level'];
				$allowFindCloseNav = true;
			}

			$cur ++;
		}

		$ncxQueue = array();
		// 提取三要素，均在a标签内
		for ($i = $startNavIndex; $i < $endNavIndex; $i ++) {
			$curItem = $ncxAssoc[$i];
			if ($curItem[type] == 'complete') {
				$tmp_level = ($curItem['level'] - 6) / 2 + 1;
				$ncxQueue[] = array(
					weight => $tmp_level,
					href => 'contents/'.$curItem['attributes']['HREF'],
					title => $curItem['value']
				);
				// echo $tmp_level."\t".$curItem['value']."\n";
			}
		}
		return array(
			queue => $ncxQueue,
			bookName => $bookName
		);
	}
}

/**
 * ncx生成器
 */
class NcxGenerator { 
    public $ncxGeneratorQueue = array();
    public $za; //zipArchive
    public $ng; //ncx generator type
    public $dom;
    public $ncxTitleTmpl;


    function __construct ($archiveName) {
    	global $ncxTmpl;
    	$this->za = new MyZipArchive($archiveName);
    	$this->dom = new DOMDocument();
    	$this->ncxTitleTmpl = $ncxTmpl;
    	$this->dom->loadXML($this->ncxTitleTmpl);
   		$this->pickGenerator();
    }
    
	/**
	 * 策略选择器-选择ncx树的生成方式
	 */
    public function pickGenerator () {
    	$this->ng = new NTGxhtml();
    }

	/**
	 * 生成Ncx树
	 */
    public function generateNcxTree () {
    	// 获取目录文件内容
    	$za = $this->za;
    	$tocPath = $za->getFullPath('toc');
    	$tocContent = $za->getContentByFileName($tocPath);

    	// 生成ncx树
    	$ng = $this->ng;
    	$ncxTree = $ng->generateNcxTree($tocContent);
    	return $ncxTree;
    }

	/**
	 * 插入书名节点
	 */
	function insertBooknameNode ($bookname) {
		$dom = &$this->dom;
		$booknameNode = $dom->createElement('text', $bookname);
		$docTitleEls = $dom->getElementsByTagName('docTitle');
		$docTitle = $docTitleEls->item(0);
		$docTitle->appendChild($booknameNode);
		$dom->saveXML();
	}

    /**
     * 插入navpoint节点
     */
    function &createNewTitleNode ($title, $href, $num) {
    	$dom = &$this->dom;

		$navPoint = $dom->createElement('navPoint');
		$navLabel = $dom->createElement('navLabel');
		$text = $dom->createElement('text', $title);
		$content = $dom->createElement('content');

		$navPoint->appendChild($navLabel);
		$navPoint->appendChild($content);
		$navLabel->appendChild($text);

		$navPoint->setAttribute('id', 'navPoint-'.$num);
		$navPoint->setAttribute('playOrder', ''.$num);
		$content->setAttribute('src', $href);

		return $navPoint;
	}


	/**
	 * 建立标题节点
	 */
	function generateTitleNode ($titleData) {
		$num = 0;
		$dadQueue = array();
		$levelQueue = array();
		$dom = &$this->dom;
		// 获取模板中的NavMap
		$navMaps = $dom->getElementsByTagName('navMap');
		$root = $navMaps->item(0);
		$preW = 0; //权重为0代表NavMap
		$preNode = &$root; 
		$dadNode;

		while ($num < count($titleData))
		{
			$curTitle = $titleData[$num];
			$w = $curTitle['weight'];
			$title = $curTitle['title'];
			$href = $curTitle['href'];

			if ($w > $preW)
			{
				// 获取父亲节点
				array_push($dadQueue, &$preNode);
				array_push($levelQueue, $preW);
				$dadNode = &$preNode;
			}
			else if ($w < $preW)
			{
				do {
					array_pop($dadQueue);
					array_pop($levelQueue);

					$lastWeightIndex = count($levelQueue);
					$lastWeight = $levelQueue[$lastWeightIndex - 1];
				} while ($w <= $lastWeight);
				$lastDadIndex = count($dadQueue);
				$dadNode = $dadQueue[$lastDadIndex - 1];
			}

			// 创建新节点，追加至父节点
			$newNode = &$this->createNewTitleNode($title, $href, $num + 1);
			$dadNode->appendChild($newNode);

			$preW = $w;
			$preNode = &$newNode;
			$num ++;
		}
		return $dom->saveXML();
	}

    /**
     * 渲染Ncx树
     */
    public function renderTreeOnNcx ($nt) {
    	$dom = &$this->dom;
    	$this->insertBooknameNode($nt['bookName']);
    	$this->generateTitleNode($nt['queue']);
    }

    // 其它琐碎操作
    public function otherOperation () {
    	$ng = $this->ng;
    	$ng->otherOp(&$this->za);
    }

    /**
     * 更新包内ncx文件
     */
    public function refreshNcxFile () {
    	$ncxFileStr = $this->dom->saveXML();
		// ncx写回
		$za = $this->za;
    	$ncxpath = 'OEBPS/toc.ncx';
		if (($path = $za->getFullPath('toc.ncx')))
		{
			$za->deleteFile($path);
		}
		$ncxFileStr = str_replace('NavPoint', 'navPoint', $ncxFileStr);
		$za->addFile($ncxFileStr, $ncxpath);
    }

    // public function 

    /**
     * execute
     */
    public function execute () {
    	if (!$this->za->getFullPath('toc.ncx')) {
    		// 生成ncx树
	    	$nt = $this->generateNcxTree();
	    	// 渲染ncx树
	    	$ncxXML = $this->renderTreeOnNcx($nt);
	    	// 其它操作 - 比如处理opf文件
	    	$this->otherOperation();
	    	// 在epub包内生成新的ncx文件
	    	$this->refreshNcxFile();
    	}
    }
}

$fn = 'example.epub';

$ng = new NcxGenerator($fn);
$ng->execute();


/* vim: set expandtab ts=4 sw=4 sts=4 tw=100: */
?>
