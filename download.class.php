<?php

//
//  Skinod Downloader
//
//  Created by sajjad hashemian
//  Copyright (c) 2014 Skinod. All rights reserved.
//


class sod_download {
	public $boundry = NULL;
	public $is_url = FALSE;
	public $testOnly = FALSE;

	public function sendFile($path, $filename = "", $options=array()) {		

		error_reporting(0);

		$this->options = array_merge(array(
			'throttle'					=> FALSE, // max connection speed (KB) per each connection
			'lifetime'					=> 86400,
			'forcedownload'				=> TRUE,
			'encode'					=> "utf-8",
			'content_type'				=> NULL,
			'max_connection'			=> FALSE,
			'remote_force'				=> FALSE,
			'sessions_path'				=> './sessions/'
		), $options);

		$this->boundry	= md5( rand() . mt_rand( 11111, 99999 ) . uniqid() );

		$this->is_url = FALSE;

		if (($url = filter_var($path, FILTER_VALIDATE_URL)) !== FALSE) {
			$path = $url;
			$this->is_url = TRUE;
			$url_headers = $this->getUrlHeader($url);

			if(intval(substr($url_headers[0], -6, 3)) !== 200)
				throw new sodDownloadException("File Not Found");
			
			if($url_headers['Accept-Ranges'] != 'bytes')
				if(!$this->options['remote_force'])
					throw new sodDownloadException("Remote File Cannot Download");
				else
					$this->options['lifetime'] = 0;

			$lastmodified = strtotime($url_headers['Last-Modified']);
			$this->filesize = $filesize = ( (isset($url_headers['Content-Length']) && !empty($url_headers['Content-Length']))?$url_headers['Content-Length']:self::remote_filesize($url));
		} else {
			$path = realpath($path);
			if (!file_exists($path) || !is_readable($path))
				throw new sodDownloadException("File Not Found");

			$lastmodified = filemtime($path);
			$this->filesize = $filesize	= filesize($path);
		}

		if($this->testOnly)
			return true;


		$filename = empty($filename)?basename($path):$filename;
		$mimetype = self::getMimeType($filename);

		if($this->options['max_connection'] == 1) {
			$this->options['lifetime'] = 0;
		}elseif($this->options['lifetime'] == 0) {
			$this->options['max_connection'] = 0;
		}

		if(($sp = realpath($this->options['sessions_path'])) && $this->options['max_connection'] > 0 ) {
			// TODO: remove old sessions
			$sp_fpath = $sp . '/' . md5($path . $filename . $_SERVER['REMOTE_ADDR']) . '.txt';

			self::remove_old_session($sp);

			$connections_number = self::get_sessionval($sp_fpath);
			if( $connections_number >= $this->options['max_connection'])
				self::rateLimit();

			self::increase_sessionfile($sp_fpath);

			register_shutdown_function(array('sod_download', 'decrease_sessionfile'), $sp_fpath);
		}

		if($this->options['throttle'] > 0) {
			$this->options['throttle'] *= 1024;
		}

		// header('HTTP/1.1 429 Too Many Requests');
		// self::rateLimit();

		session_write_close();
		@ini_set('session.use_trans_sid', 'FALSE');

		if (@ini_get('zlib.output_compression')) {
			@ini_set('zlib.output_compression', 'Off');
		}


		header('Last-Modified: '. gmdate('D, d M Y H:i:s', $lastmodified) .' GMT');
		header('Content-Disposition:' . self::getContentDisposition(($mimetype == 'application/forcedownload' || isset($this->options['forcedownload']))?'attachment':'inline', $filename));

		if ($this->options['lifetime'] > 0) {

			header('Cache-Control: max-age=' . $this->options['lifetime']);
			header('Expires: '. gmdate('D, d M Y H:i:s', time() + $this->options['lifetime']) .' GMT');
			header('Pragma: ');

			if($mimetype != 'text/plain' && $mimetype != 'text/html')
				@header('Accept-Ranges: bytes');
			else
				$_SERVER['HTTP_RANGE'] = '';

		} else {
			if (self::is_https() === TRUE) { //IE KB812935 & KB316431
				header('Cache-Control: max-age=10');
				header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
				header('Pragma: ');
			}
			else {
				header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
				header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
				header('Pragma: no-cache');
			}
			header('Accept-Ranges: none');
		}

		if (!empty($_SERVER['HTTP_RANGE']) && strpos($_SERVER['HTTP_RANGE'],'bytes=') !== FALSE) {
		
			$ranges = array();

			if (preg_match_all('/(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $ranges, PREG_SET_ORDER)) {
				foreach ($ranges as $key => $value) {
					if ($ranges[$key][1] == '') {
						// Suffix case
						$ranges[$key][1] = $filesize - $ranges[$key][2];
						$ranges[$key][2] = $filesize - 1;
					}
					else if ($ranges[$key][2] == '' || $ranges[$key][2] > $filesize - 1) {
						// Fix range length
						$ranges[$key][2] = $filesize - 1;
					}
					if ($ranges[$key][2] != '' && $ranges[$key][2] < $ranges[$key][1]) {
						// Invalid byte-range
						// $ranges = array();
						// break;
						header('HTTP/1.1 416 Requested Range Not Satisfiable');
						exit;
					}

					// Prepare multipart header
					$ranges[$key][0] =  "\r\n--" . $this->boundry . "\r\nContent-Type: $mimetype\r\n";
					$ranges[$key][0] .= "Content-Range: bytes {$ranges[$key][1]}-{$ranges[$key][2]}/$filesize\r\n\r\n";
				}
			}

			if (count($ranges) > 0) {
				$this->readFileRange($path, $mimetype, $ranges);
				exit;
			}
		}

		$content_type = !empty($this->options['content_type'])?$this->options['content_type']:$mimetype;
		$encode = '';

		if(($content_type == 'text/plain' || $content_type == 'text/html') && !empty($this->options['encode']))
			$encode = '; charset=' . $this->options['encode'];

		header('Content-Type: '  . $content_type . $encode);
		header('Content-Length: ' . $filesize);
		$this->readFile($path);
		exit;
	}

	protected function getUrlHeader($url) {
		stream_context_set_default(array('http' => array('method' => 'HEAD')));
		$headers = get_headers($url, 1);
		stream_context_set_default(array('http' => array('method' => 'GET')));
		return $headers;
	}

	protected function curlGet( $filename, $range = FALSE, $chunksize = 0 ) {
		$ch = curl_init($filename);
		
		// if($ranges && !empty($_SERVER['HTTP_RANGE']))
			// curl_setopt($ch, CURLOPT_RANGE, $_SERVER['HTTP_RANGE']);

		if($range)
			curl_setopt($ch, CURLOPT_RANGE, $range);

		// curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
		curl_setopt($ch, CURLOPT_HEADER, 0);

		if(!$chunksize)
			$chunksize = (($this->options['throttle'] !== FALSE)?$this->options['throttle']:(1024 * 1024));

		curl_setopt($ch, CURLOPT_BUFFERSIZE, $chunksize);

		curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'curlWriter'));

		curl_exec($ch);

		curl_close($ch);
	}

	protected function curlWriter($ch, $chunk) {
		echo $chunk;
		flush();
		@ob_flush();
		if($this->options['throttle'])
			sleep(1);
		return strlen($chunk);
	}

	protected function culrHandle() {
		if($this->is_url) {
			if( is_callable( 'curl_init' ) )
				return true;

			// elseif ( !ini_get( 'allow_url_fopen' ) )
				throw new sodDownloadException("Can't Open File");
		}
		return FALSE;
	}

	protected function fopenWriter(&$handle, $chunksize = 0, $start = FALSE, $length = FALSE, $close = TRUE) {
		if ($handle === FALSE) {
			return FALSE;
		}

		// if( $this->options['throttle'] === FALSE AND !($start AND $length) AND function_exists('readfile') )
		// {
		// 	fclose($handle);
		// 	readfile( $file );
		// 	return;
		// }

		if(!$chunksize)
			$chunksize = (($this->options['throttle'] !== FALSE)?$this->options['throttle']:(1024 * 1024));

		if($start !== FALSE)
			fseek($handle, $start);

		while (!feof($handle) && ($length === FALSE || $length > 0)) {
			@set_time_limit(60 * 30);
			
			$read = ( ($length === FALSE) ? $chunksize : ($chunksize < $length ? $chunksize : $length) );

			echo fread($handle, $read);

			flush();

			if($length !== FALSE)
				$length -= $read;

			if($this->options['throttle'])
				sleep(1);
		}

		return ($close?fclose($handle):TRUE);
	}

	protected function readFileRange($filename, $mimetype, $ranges) {
		if(!$this->culrHandle()) {
			$handle = fopen($filename, 'rb');
			if ($handle === FALSE)
				throw new sodDownloadException("Can't Open File");
		}

		self::ob_end_clean();

		header('HTTP/1.1 206 Partial Content');

		if (count($ranges) == 1) { // http://stackoverflow.com/questions/19290033/http-byte-ranges-and-multipart-byteranges-alternatives
			$length = $ranges[0][2] - $ranges[0][1] + 1;

			header('Content-Length: ' . $length);
			header('Content-Range: bytes ' . $ranges[0][1] . '-' . $ranges[0][2] . '/' . $this->filesize);
			header('Content-Type: ' . $mimetype);

			if($this->culrHandle())
				$this->curlGet($filename, $ranges[0][1] . '-' . $ranges[0][2]);
			else
				$this->fopenWriter($handle, 0, $ranges[0][1], $length);
		} else {
			$totallength = 0;
			foreach($ranges as $range) {
				$totallength += strlen($range[0]) + $range[2] - $range[1] + 1;
			}
			$totallength += strlen("\r\n--" . $this->boundry . "--\r\n");
			header('Content-Length: ' . $totallength);
			header('Content-Type: multipart/x-byteranges; boundary=' . $this->boundry);

			foreach($ranges as $range) {
				$length = $range[2] - $range[1] + 1;
				echo $range[0];
				if($this->culrHandle())
					$this->curlGet($filename, $range[1] . '-' . $range[2]);
				else
					$this->fopenWriter($handle, 0, $range[1], $length, FALSE);
			}

			echo "\r\n--" . $this->boundry . "--\r\n";

			if(!$this->culrHandle())
				fclose($handle);
		}
	}


	protected function readFile($filename) {
		self::ob_end_clean();

		if($this->culrHandle())
			return $this->curlGet($filename);
		

		$handle = fopen($filename, 'rb');
		$this->fopenWriter($handle);
	}


	public static function is_https() {
		return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
	}

	public static function getMimeType($path = '') {
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		if ( array_key_exists( $ext, $all = self::all_mimes() ) )
			return $all[ $ext ];

		return 'application/forcedownload';
	}

	public static function getContentDisposition( $disposition='attachment', $filename=NULL )
	{
		if( $filename === NULL ) {
			return $disposition;
		}

		if($disposition != 'attachment' && $disposition != 'inline')
			$disposition = 'attachment';

		$return	= $disposition . '; filename';

		switch( self::getBrowser('ub') )
		{
			case 'firefox':
			case 'opera':
				$return	.= "*=UTF-8''" . rawurlencode( $filename );
			break;
			case 'msie':
				$return	.= '="' . rawurlencode( $filename ) . '"';
			break;
			default:
				$return	.= '="' . $filename . '"';
			break;
		}

		return $return;
	}

	# source: http://php.net/manual/en/function.get-browser.php#101125
	public static function getBrowser($key = '')
	{
		$u_agent = $_SERVER['HTTP_USER_AGENT'];
		$bname = 'Unknown';
		$platform = 'Unknown';
		$version= "";

		//First get the platform?
		if (preg_match('/linux/i', $u_agent)) {
			$platform = 'linux';
		}
		elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
			$platform = 'mac';
		}
		elseif (preg_match('/windows|win32/i', $u_agent)) {
			$platform = 'windows';
		}
	   
		// Next get the name of the useragent yes seperately and for good reason
		if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent))
		{
			$bname = 'Internet Explorer';
			$ub = "MSIE";
		}
		elseif(preg_match('/Firefox/i',$u_agent))
		{
			$bname = 'Mozilla Firefox';
			$ub = "Firefox";
		}
		elseif(preg_match('/Chrome/i',$u_agent))
		{
			$bname = 'Google Chrome';
			$ub = "Chrome";
		}
		elseif(preg_match('/Safari/i',$u_agent))
		{
			$bname = 'Apple Safari';
			$ub = "Safari";
		}
		elseif(preg_match('/Opera/i',$u_agent))
		{
			$bname = 'Opera';
			$ub = "Opera";
		}
		elseif(preg_match('/Netscape/i',$u_agent))
		{
			$bname = 'Netscape';
			$ub = "Netscape";
		}
	   
		// finally get the correct version number
		$known = array('Version', $ub, 'other');
		$pattern = '#(?<browser>' . join('|', $known) .
		')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
		if (!preg_match_all($pattern, $u_agent, $matches)) {
			// we have no matching number just continue
		}
	   
		// see how many we have
		$i = count($matches['browser']);
		if ($i != 1) {
			//we will have two since we are not using 'other' argument yet
			//see if version is before or after the name
			if (strripos($u_agent,"Version") < strripos($u_agent,$ub)){
				$version= $matches['version'][0];
			}
			else {
				$version= $matches['version'][1];
			}
		}
		else {
			$version= $matches['version'][0];
		}
	   
		// check if we have a number
		if ($version==NULL || $version=="") {$version="?";}
	   
		$return = array(
			'userAgent' => $u_agent,
			'name'      => $bname,
			'ub'		=> strtolower($ub),
			'version'   => $version,
			'platform'  => $platform,
			'pattern'   => $pattern,
		);

		if(!empty($key) && array_key_exists($key, $return))
			return $return[$key];

		return $return;
	}

	public static function ob_end_clean() {
		while( ob_get_level() > 0 )
			ob_end_clean();
	}
	
	public static function remote_filesize($url) {
		return strlen(file_get_contents($url));
	}
	
	private static function get_sessionval($path) {
		if(!empty($path)) {
			return intval(@file_get_contents($path));
		}
	}
	
	private static function increase_sessionfile($path) {
		if(!empty($path)) {
			$current = intval(@file_get_contents($path));
			file_put_contents($path, ++$current);
		}
	}
	
	private static function decrease_sessionfile($path) {
		if(!empty($path)) {
			$current = intval(@file_get_contents($path));
			if($current < 1)
				$current = 1;
			file_put_contents($path, --$current);
		}
	}
	
	private static function remove_old_session($path) {
		if(!empty($path)) {
			$files = glob($path.'/*');
			$time  = time();

			foreach ($files as $file)
				if (is_file($file))
					// if ($time - filemtime($file) >= 60*60*24)
					if ($time - filemtime($file) >= 60*30) // as we are testing this I left it to 30 min for now
						unlink($file);
		}
	}
	
	public static function rateLimit() {
		$message =
			"To Many Connection\n".
			"You are issuing too many connection.\n".

		header('Content-Type: text/plain; charset=utf-8', true, 503); // 429

		echo $message;

		exit(1);
	}

	// source https://github.com/anwarjaved/mimetype
	public static function all_mimes() {
		return array(
			'0.123' => 'application/vnd.lotus-1-2-3',
			'123' => 'application/vnd.lotus-1-2-3',
			'323' => 'text/h323',
			'3dmf' => 'x-world/x-3dmf',
			'3dml' => 'text/vnd.in3d.3dml',
			'3g2' => 'video/3gpp2',
			'3gp' => 'video/3gpp',
			'7z' => 'application/x-7z-compressed',
			'a' => 'application/octet-stream',
			'aab' => 'application/x-authorware-bin',
			'aac' => 'audio/x-aac',
			'aam' => 'application/x-authorware-map',
			'aas' => 'application/x-authorware-seg',
			'abc' => 'text/vnd.abc',
			'abw' => 'application/x-abiword',
			'ac' => 'application/pkix-attr-cert',
			'acc' => 'application/vnd.americandynamics.acc',
			'ace' => 'application/x-ace-compressed',
			'acgi' => 'text/html',
			'acu' => 'application/vnd.acucobol',
			'acutc' => 'application/vnd.acucorp',
			'acx' => 'application/internet-property-stream',
			'adp' => 'audio/adpcm',
			'aep' => 'application/vnd.audiograph',
			'afl' => 'video/animaflex',
			'afm' => 'application/x-font-type1',
			'afp' => 'application/vnd.ibm.modcap',
			'ahead' => 'application/vnd.ahead.space',
			'ai' => 'application/postscript',
			'aif' => 'audio/x-aiff',
			'aifc' => 'audio/aiff',
			'aiff' => 'audio/aiff',
			'aim' => 'application/x-aim',
			'aip' => 'text/x-audiosoft-intra',
			'air' => 'application/vnd.adobe.air-application-installer-package+zip',
			'ait' => 'application/vnd.dvb.ait',
			'ami' => 'application/vnd.amiga.ami',
			'ani' => 'application/x-navi-animation',
			'aos' => 'application/x-nokia-9000-communicator-add-on-software',
			'apk' => 'application/vnd.android.package-archive',
			'application' => 'application/x-ms-application',
			'apr' => 'application/vnd.lotus-approach',
			'aps' => 'application/mime',
			'arc' => 'application/octet-stream',
			'arj' => 'application/arj',
			'art' => 'image/x-jg',
			'asc' => 'application/pgp-signature',
			'asf' => 'video/x-ms-asf',
			'asm' => 'text/x-asm',
			'aso' => 'application/vnd.accpac.simply.aso',
			'asp' => 'text/asp',
			'asr' => 'video/x-ms-asf',
			'asx' => 'video/x-ms-asf',
			'atc' => 'application/vnd.acucorp',
			'atom' => 'application/atom+xml',
			'atom, .xml' => 'application/atom+xml',
			'atomcat' => 'application/atomcat+xml',
			'atomsvc' => 'application/atomsvc+xml',
			'atx' => 'application/vnd.antix.game-component',
			'au' => 'audio/basic',
			'avi' => 'video/x-msvideo',
			'avs' => 'video/avs-video',
			'aw' => 'application/applixware',
			'axs' => 'application/olescript',
			'azf' => 'application/vnd.airzip.filesecure.azf',
			'azs' => 'application/vnd.airzip.filesecure.azs',
			'azw' => 'application/vnd.amazon.ebook',
			'bas' => 'text/plain',
			'bat' => 'application/x-msdownload',
			'bcpio' => 'application/x-bcpio',
			'bdf' => 'application/x-font-bdf',
			'bdm' => 'application/vnd.syncml.dm+wbxml',
			'bed' => 'application/vnd.realvnc.bed',
			'bh2' => 'application/vnd.fujitsu.oasysprs',
			'bin' => 'application/octet-stream',
			'bm' => 'image/bmp',
			'bmi' => 'application/vnd.bmi',
			'bmp' => 'image/bmp',
			'boo' => 'application/book',
			'book' => 'application/vnd.framemaker',
			'box' => 'application/vnd.previewsystems.box',
			'boz' => 'application/x-bzip2',
			'bpk' => 'application/octet-stream',
			'bsh' => 'application/x-bsh',
			'btif' => 'image/prs.btif',
			'bz' => 'application/x-bzip',
			'bz2' => 'application/x-bzip2',
			'c' => 'text/plain',
			'c++' => 'text/plain',
			'c11amc' => 'application/vnd.cluetrust.cartomobile-config',
			'c11amz' => 'application/vnd.cluetrust.cartomobile-config-pkg',
			'c4d' => 'application/vnd.clonk.c4group',
			'c4f' => 'application/vnd.clonk.c4group',
			'c4g' => 'application/vnd.clonk.c4group',
			'c4p' => 'application/vnd.clonk.c4group',
			'c4u' => 'application/vnd.clonk.c4group',
			'cab' => 'application/vnd.ms-cab-compressed',
			'car' => 'application/vnd.curl.car',
			'cat' => 'application/vndms-pkiseccat',
			'cc' => 'text/x-c',
			'ccad' => 'application/clariscad',
			'cco' => 'application/x-cocoa',
			'cct' => 'application/x-director',
			'ccxml' => 'application/ccxml+xml',
			'cdbcmsg' => 'application/vnd.contact.cmsg',
			'cdf' => 'application/x-cdf',
			'cdkey' => 'application/vnd.mediastation.cdkey',
			'cdmia' => 'application/cdmi-capability',
			'cdmic' => 'application/cdmi-container',
			'cdmid' => 'application/cdmi-domain',
			'cdmio' => 'application/cdmi-object',
			'cdmiq' => 'application/cdmi-queue',
			'cdx' => 'chemical/x-cdx',
			'cdxml' => 'application/vnd.chemdraw+xml',
			'cdy' => 'application/vnd.cinderella',
			'cer' => 'application/x-x509-ca-cert',
			'cgm' => 'image/cgm',
			'cha' => 'application/x-chat',
			'chat' => 'application/x-chat',
			'chm' => 'application/vnd.ms-htmlhelp',
			'chrt' => 'application/vnd.kde.kchart',
			'cif' => 'chemical/x-cif',
			'cii' => 'application/vnd.anser-web-certificate-issue-initiation',
			'cil' => 'application/vnd.ms-artgalry',
			'cla' => 'application/vnd.claymore',
			'class' => 'application/java-vm',
			'clkk' => 'application/vnd.crick.clicker.keyboard',
			'clkp' => 'application/vnd.crick.clicker.palette',
			'clkt' => 'application/vnd.crick.clicker.template',
			'clkw' => 'application/vnd.crick.clicker.wordbank',
			'clkx' => 'application/vnd.crick.clicker',
			'clp' => 'application/x-msclip',
			'cmc' => 'application/vnd.cosmocaller',
			'cmdf' => 'chemical/x-cmdf',
			'cml' => 'chemical/x-cml',
			'cmp' => 'application/vnd.yellowriver-custom-menu',
			'cmx' => 'image/x-cmx',
			'cod' => 'image/cis-cod',
			'com' => 'application/x-msdownload',
			'conf' => 'text/plain',
			'cpio' => 'application/x-cpio',
			'cpp' => 'text/x-c',
			'cpt' => 'application/mac-compactpro',
			'crd' => 'application/x-mscardfile',
			'crl' => 'application/pkix-crl',
			'crt' => 'application/x-x509-ca-cert',
			'cryptonote' => 'application/vnd.rig.cryptonote',
			'csh' => 'application/x-csh',
			'csml' => 'chemical/x-csml',
			'csp' => 'application/vnd.commonspace',
			'css' => 'text/css',
			'cst' => 'application/x-director',
			'csv' => 'text/csv',
			'cu' => 'application/cu-seeme',
			'curl' => 'text/vnd.curl',
			'cww' => 'application/prs.cww',
			'cxt' => 'application/x-director',
			'cxx' => 'text/x-c',
			'dae' => 'model/vnd.collada+xml',
			'daf' => 'application/vnd.mobius.daf',
			'dataless' => 'application/vnd.fdsn.seed',
			'davmount' => 'application/davmount+xml',
			'dcr' => 'application/x-director',
			'dcurl' => 'text/vnd.curl.dcurl',
			'dd2' => 'application/vnd.oma.dd2+xml',
			'ddd' => 'application/vnd.fujixerox.ddd',
			'deb' => 'application/x-debian-package',
			'deepv' => 'application/x-deepv',
			'def' => 'text/plain',
			'deploy' => 'application/octet-stream',
			'der' => 'application/x-x509-ca-cert',
			'dfac' => 'application/vnd.dreamfactory',
			'dib' => 'image/bmp',
			'dic' => 'text/x-c',
			'dif' => 'video/x-dv',
			'dir' => 'application/x-director',
			'dis' => 'application/vnd.mobius.dis',
			'disco' => 'text/xml',
			'dist' => 'application/octet-stream',
			'distz' => 'application/octet-stream',
			'djv' => 'image/vnd.djvu',
			'djvu' => 'image/vnd.djvu',
			'dl' => 'video/dl',
			'dll' => 'application/x-msdownload',
			'dmg' => 'application/octet-stream',
			'dms' => 'application/octet-stream',
			'dna' => 'application/vnd.dna',
			'doc' => 'application/msword',
			'docm' => 'application/vnd.ms-word.document.macroenabled.12',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'dot' => 'application/msword',
			'dotm' => 'application/vnd.ms-word.template.macroenabled.12',
			'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'dp' => 'application/vnd.osgi.dp',
			'dpg' => 'application/vnd.dpgraph',
			'dra' => 'audio/vnd.dra',
			'drw' => 'application/drafting',
			'dsc' => 'text/prs.lines.tag',
			'dssc' => 'application/dssc+der',
			'dtb' => 'application/x-dtbook+xml',
			'dtd' => 'application/xml-dtd',
			'dts' => 'audio/vnd.dts',
			'dtshd' => 'audio/vnd.dts.hd',
			'dump' => 'application/octet-stream',
			'dv' => 'video/x-dv',
			'dvi' => 'application/x-dvi',
			'dwf' => 'model/vnd.dwf',
			'dwg' => 'image/vnd.dwg',
			'dxf' => 'image/vnd.dxf',
			'dxp' => 'application/vnd.spotfire.dxp',
			'dxr' => 'application/x-director',
			'ecelp4800' => 'audio/vnd.nuera.ecelp4800',
			'ecelp7470' => 'audio/vnd.nuera.ecelp7470',
			'ecelp9600' => 'audio/vnd.nuera.ecelp9600',
			'ecma' => 'application/ecmascript',
			'edm' => 'application/vnd.novadigm.edm',
			'edx' => 'application/vnd.novadigm.edx',
			'efif' => 'application/vnd.picsel',
			'ei6' => 'application/vnd.pg.osasli',
			'el' => 'text/x-script.elisp',
			'elc' => 'application/octet-stream',
			'eml' => 'message/rfc822',
			'emma' => 'application/emma+xml',
			'env' => 'application/x-envoy',
			'eol' => 'audio/vnd.digital-winds',
			'eot' => 'application/vnd.ms-fontobject',
			'eps' => 'application/postscript',
			'epub' => 'application/epub+zip',
			'es' => 'application/ecmascript',
			'es3' => 'application/vnd.eszigno3+xml',
			'esf' => 'application/vnd.epson.esf',
			'et3' => 'application/vnd.eszigno3+xml',
			'etx' => 'text/x-setext',
			'evy' => 'application/envoy',
			'exe' => 'application/octet-stream',
			'exi' => 'application/exi',
			'ext' => 'application/vnd.novadigm.ext',
			'ez' => 'application/andrew-inset',
			'ez2' => 'application/vnd.ezpix-album',
			'ez3' => 'application/vnd.ezpix-package',
			'f' => 'text/x-fortran',
			'f4v' => 'video/x-f4v',
			'f77' => 'text/x-fortran',
			'f90' => 'text/x-fortran',
			'fbs' => 'image/vnd.fastbidsheet',
			'fcs' => 'application/vnd.isac.fcs',
			'fdf' => 'application/vnd.fdf',
			'fe_launch' => 'application/vnd.denovo.fcselayout-link',
			'fg5' => 'application/vnd.fujitsu.oasysgp',
			'fgd' => 'application/x-director',
			'fh' => 'image/x-freehand',
			'fh4' => 'image/x-freehand',
			'fh5' => 'image/x-freehand',
			'fh7' => 'image/x-freehand',
			'fhc' => 'image/x-freehand',
			'fif' => 'application/fractals',
			'fig' => 'application/x-xfig',
			'fli' => 'video/x-fli',
			'flo' => 'application/vnd.micrografx.flo',
			'flr' => 'x-world/x-vrml',
			'flv' => 'video/x-flv',
			'flw' => 'application/vnd.kde.kivio',
			'flx' => 'text/vnd.fmi.flexstor',
			'fly' => 'text/vnd.fly',
			'fm' => 'application/vnd.framemaker',
			'fmf' => 'video/x-atomic3d-feature',
			'fnc' => 'application/vnd.frogans.fnc',
			'for' => 'text/x-fortran',
			'fpx' => 'image/vnd.fpx',
			'frame' => 'application/vnd.framemaker',
			'frl' => 'application/freeloader',
			'fsc' => 'application/vnd.fsc.weblaunch',
			'fst' => 'image/vnd.fst',
			'ftc' => 'application/vnd.fluxtime.clip',
			'fti' => 'application/vnd.anser-web-funds-transfer-initiation',
			'funk' => 'audio/make',
			'fvt' => 'video/vnd.fvt',
			'fxp' => 'application/vnd.adobe.fxp',
			'fxpl' => 'application/vnd.adobe.fxp',
			'fzs' => 'application/vnd.fuzzysheet',
			'g' => 'text/plain',
			'g2w' => 'application/vnd.geoplan',
			'g3' => 'image/g3fax',
			'g3w' => 'application/vnd.geospace',
			'gac' => 'application/vnd.groove-account',
			'gdl' => 'model/vnd.gdl',
			'geo' => 'application/vnd.dynageo',
			'gex' => 'application/vnd.geometry-explorer',
			'ggb' => 'application/vnd.geogebra.file',
			'ggt' => 'application/vnd.geogebra.tool',
			'ghf' => 'application/vnd.groove-help',
			'gif' => 'image/gif',
			'gim' => 'application/vnd.groove-identity-message',
			'gl' => 'video/gl',
			'gmx' => 'application/vnd.gmx',
			'gnumeric' => 'application/x-gnumeric',
			'gph' => 'application/vnd.flographit',
			'gqf' => 'application/vnd.grafeq',
			'gqs' => 'application/vnd.grafeq',
			'gram' => 'application/srgs',
			'gre' => 'application/vnd.geometry-explorer',
			'grv' => 'application/vnd.groove-injector',
			'grxml' => 'application/srgs+xml',
			'gsd' => 'audio/x-gsm',
			'gsf' => 'application/x-font-ghostscript',
			'gsm' => 'audio/x-gsm',
			'gsp' => 'application/x-gsp',
			'gss' => 'application/x-gss',
			'gtar' => 'application/x-gtar',
			'gtm' => 'application/vnd.groove-tool-message',
			'gtw' => 'model/vnd.gtw',
			'gv' => 'text/vnd.graphviz',
			'gxt' => 'application/vnd.geonext',
			'gz' => 'application/x-gzip',
			'gzip' => 'application/x-gzip',
			'h' => 'text/plain',
			'h261' => 'video/h261',
			'h263' => 'video/h263',
			'h264' => 'video/h264',
			'hal' => 'application/vnd.hal+xml',
			'hbci' => 'application/vnd.hbci',
			'hdf' => 'application/x-hdf',
			'help' => 'application/x-helpfile',
			'hgl' => 'application/vnd.hp-hpgl',
			'hh' => 'text/x-c',
			'hlb' => 'text/x-script',
			'hlp' => 'application/winhlp',
			'hpg' => 'application/vnd.hp-hpgl',
			'hpgl' => 'application/vnd.hp-hpgl',
			'hpid' => 'application/vnd.hp-hpid',
			'hps' => 'application/vnd.hp-hps',
			'hqx' => 'application/mac-binhex40',
			'hta' => 'application/hta',
			'htc' => 'text/x-component',
			'htke' => 'application/vnd.kenameaapp',
			'htm' => 'text/html',
			'html' => 'text/html',
			'htmls' => 'text/html',
			'htt' => 'text/webviewhtml',
			'htx' => 'text/html',
			'hvd' => 'application/vnd.yamaha.hv-dic',
			'hvp' => 'application/vnd.yamaha.hv-voice',
			'hvs' => 'application/vnd.yamaha.hv-script',
			'i2g' => 'application/vnd.intergeo',
			'icc' => 'application/vnd.iccprofile',
			'ice' => 'x-conference/x-cooltalk',
			'icm' => 'application/vnd.iccprofile',
			'ico' => 'image/x-icon',
			'ics' => 'text/calendar',
			'idc' => 'text/plain',
			'ief' => 'image/ief',
			'iefs' => 'image/ief',
			'ifb' => 'text/calendar',
			'ifm' => 'application/vnd.shana.informed.formdata',
			'iges' => 'model/iges',
			'igl' => 'application/vnd.igloader',
			'igm' => 'application/vnd.insors.igm',
			'igs' => 'model/iges',
			'igx' => 'application/vnd.micrografx.igx',
			'iif' => 'application/vnd.shana.informed.interchange',
			'iii' => 'application/x-iphone',
			'ima' => 'application/x-ima',
			'imap' => 'application/x-httpd-imap',
			'imp' => 'application/vnd.accpac.simply.imp',
			'ims' => 'application/vnd.ms-ims',
			'in' => 'text/plain',
			'inf' => 'application/inf',
			'ins' => 'application/x-internet-signup',
			'ip' => 'application/x-ip2',
			'ipfix' => 'application/ipfix',
			'ipk' => 'application/vnd.shana.informed.package',
			'irm' => 'application/vnd.ibm.rights-management',
			'irp' => 'application/vnd.irepository.package+xml',
			'iso' => 'application/octet-stream',
			'isp' => 'application/x-internet-signup',
			'isu' => 'video/x-isvideo',
			'it' => 'audio/it',
			'itp' => 'application/vnd.shana.informed.formtemplate',
			'iv' => 'application/x-inventor',
			'ivf' => 'video/x-ivf',
			'ivp' => 'application/vnd.immervision-ivp',
			'ivr' => 'i-world/i-vrml',
			'ivu' => 'application/vnd.immervision-ivu',
			'ivy' => 'application/x-livescreen',
			'jad' => 'text/vnd.sun.j2me.app-descriptor',
			'jam' => 'application/vnd.jam',
			'jar' => 'application/java-archive',
			'jav' => 'text/plain',
			'java' => 'text/x-java-source',
			'jcm' => 'application/x-java-commerce',
			'jfif' => 'image/pjpeg',
			'jfif-tbnl' => 'image/jpeg',
			'jisp' => 'application/vnd.jisp',
			'jlt' => 'application/vnd.hp-jlyt',
			'jnlp' => 'application/x-java-jnlp-file',
			'joda' => 'application/vnd.joost.joda-archive',
			'jpe' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'jpgm' => 'video/jpm',
			'jpgv' => 'video/jpeg',
			'jpm' => 'video/jpm',
			'jps' => 'image/x-jps',
			'js' => 'application/x-javascript',
			'json' => 'application/json',
			'jut' => 'image/jutvision',
			'kar' => 'audio/midi',
			'karbon' => 'application/vnd.kde.karbon',
			'kfo' => 'application/vnd.kde.kformula',
			'kia' => 'application/vnd.kidspiration',
			'kml' => 'application/vnd.google-earth.kml+xml',
			'kmz' => 'application/vnd.google-earth.kmz',
			'kne' => 'application/vnd.kinar',
			'knp' => 'application/vnd.kinar',
			'kon' => 'application/vnd.kde.kontour',
			'kpr' => 'application/vnd.kde.kpresenter',
			'kpt' => 'application/vnd.kde.kpresenter',
			'ksh' => 'application/x-ksh',
			'ksp' => 'application/vnd.kde.kspread',
			'ktr' => 'application/vnd.kahootz',
			'ktx' => 'image/ktx',
			'ktz' => 'application/vnd.kahootz',
			'kwd' => 'application/vnd.kde.kword',
			'kwt' => 'application/vnd.kde.kword',
			'la' => 'audio/nspaudio',
			'lam' => 'audio/x-liveaudio',
			'lasxml' => 'application/vnd.las.las+xml',
			'latex' => 'application/x-latex',
			'lbd' => 'application/vnd.llamagraphics.life-balance.desktop',
			'lbe' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
			'les' => 'application/vnd.hhe.lesson-player',
			'lha' => 'application/octet-stream',
			'lhx' => 'application/octet-stream',
			'link66' => 'application/vnd.route66.link66+xml',
			'list' => 'text/plain',
			'list3820' => 'application/vnd.ibm.modcap',
			'listafp' => 'application/vnd.ibm.modcap',
			'lma' => 'audio/nspaudio',
			'log' => 'text/plain',
			'lostxml' => 'application/lost+xml',
			'lrf' => 'application/octet-stream',
			'lrm' => 'application/vnd.ms-lrm',
			'lsf' => 'video/x-la-asf',
			'lsp' => 'application/x-lisp',
			'lst' => 'text/plain',
			'lsx' => 'video/x-la-asf',
			'ltf' => 'application/vnd.frogans.ltf',
			'ltx' => 'application/x-latex',
			'lvp' => 'audio/vnd.lucent.voice',
			'lwp' => 'application/vnd.lotus-wordpro',
			'lzh' => 'application/octet-stream',
			'lzx' => 'application/lzx',
			'm' => 'text/plain',
			'm13' => 'application/x-msmediaview',
			'm14' => 'application/x-msmediaview',
			'm1v' => 'video/mpeg',
			'm21' => 'application/mp21',
			'm2a' => 'audio/mpeg',
			'm2v' => 'video/mpeg',
			'm3a' => 'audio/mpeg',
			'm3u' => 'audio/x-mpegurl',
			'm3u8' => 'application/vnd.apple.mpegurl',
			'm4u' => 'video/vnd.mpegurl',
			'm4v' => 'video/x-m4v',
			'ma' => 'application/mathematica',
			'mads' => 'application/mads+xml',
			'mag' => 'application/vnd.ecowin.chart',
			'maker' => 'application/vnd.framemaker',
			'man' => 'application/x-troff-man',
			'manifest' => 'application/x-ms-manifest',
			'map' => 'application/x-navimap',
			'mar' => 'text/plain',
			'mathml' => 'application/mathml+xml',
			'mb' => 'application/mathematica',
			'mbd' => 'application/mbedlet',
			'mbk' => 'application/vnd.mobius.mbk',
			'mbox' => 'application/mbox',
			'mc$' => 'application/x-magic-cap-package-1.0',
			'mc1' => 'application/vnd.medcalcdata',
			'mcd' => 'application/vnd.mcd',
			'mcf' => 'image/vasa',
			'mcp' => 'application/netmc',
			'mcurl' => 'text/vnd.curl.mcurl',
			'mdb' => 'application/x-msaccess',
			'mdi' => 'image/vnd.ms-modi',
			'me' => 'application/x-troff-me',
			'mesh' => 'model/mesh',
			'meta4' => 'application/metalink4+xml',
			'mets' => 'application/mets+xml',
			'mfm' => 'application/vnd.mfmp',
			'mgp' => 'application/vnd.osgeo.mapguide.package',
			'mgz' => 'application/vnd.proteus.magazine',
			'mht' => 'message/rfc822',
			'mhtml' => 'message/rfc822',
			'mid' => 'audio/mid',
			'midi' => 'audio/midi',
			'mif' => 'application/vnd.mif',
			'mime' => 'message/rfc822',
			'mj2' => 'video/mj2',
			'mjf' => 'audio/x-vnd.audioexplosion.mjuicemediafile',
			'mjp2' => 'video/mj2',
			'mjpg' => 'video/x-motion-jpeg',
			'mlp' => 'application/vnd.dolby.mlp',
			'mm' => 'application/base64',
			'mmd' => 'application/vnd.chipnuts.karaoke-mmd',
			'mme' => 'application/base64',
			'mmf' => 'application/vnd.smaf',
			'mmr' => 'image/vnd.fujixerox.edmics-mmr',
			'mny' => 'application/x-msmoney',
			'mobi' => 'application/x-mobipocket-ebook',
			'mod' => 'audio/mod',
			'mods' => 'application/mods+xml',
			'moov' => 'video/quicktime',
			'mov' => 'video/quicktime',
			'movie' => 'video/x-sgi-movie',
			'mp2' => 'video/mpeg',
			'mp21' => 'application/mp21',
			'mp2a' => 'audio/mpeg',
			'mp3' => 'audio/mpeg',
			'mp4' => 'video/mp4',
			'mp4a' => 'audio/mp4',
			'mp4s' => 'application/mp4',
			'mp4v' => 'video/mp4',
			'mpa' => 'video/mpeg',
			'mpc' => 'application/vnd.mophun.certificate',
			'mpe' => 'video/mpeg',
			'mpeg' => 'video/mpeg',
			'mpg' => 'video/mpeg',
			'mpg4' => 'video/mp4',
			'mpga' => 'audio/mpeg',
			'mpkg' => 'application/vnd.apple.installer+xml',
			'mpm' => 'application/vnd.blueice.multipass',
			'mpn' => 'application/vnd.mophun.application',
			'mpp' => 'application/vnd.ms-project',
			'mpt' => 'application/vnd.ms-project',
			'mpv' => 'application/x-project',
			'mpv2' => 'video/mpeg',
			'mpx' => 'application/x-project',
			'mpy' => 'application/vnd.ibm.minipay',
			'mqy' => 'application/vnd.mobius.mqy',
			'mrc' => 'application/marc',
			'mrcx' => 'application/marcxml+xml',
			'ms' => 'application/x-troff-ms',
			'mscml' => 'application/mediaservercontrol+xml',
			'mseed' => 'application/vnd.fdsn.mseed',
			'mseq' => 'application/vnd.mseq',
			'msf' => 'application/vnd.epson.msf',
			'msh' => 'model/mesh',
			'msi' => 'application/x-msdownload',
			'msl' => 'application/vnd.mobius.msl',
			'msty' => 'application/vnd.muvee.style',
			'mts' => 'model/vnd.mts',
			'mus' => 'application/vnd.musician',
			'musicxml' => 'application/vnd.recordare.musicxml+xml',
			'mv' => 'video/x-sgi-movie',
			'mvb' => 'application/x-msmediaview',
			'mwf' => 'application/vnd.mfer',
			'mxf' => 'application/mxf',
			'mxl' => 'application/vnd.recordare.musicxml',
			'mxml' => 'application/xv+xml',
			'mxs' => 'application/vnd.triscape.mxs',
			'mxu' => 'video/vnd.mpegurl',
			'my' => 'audio/make',
			'mzz' => 'application/x-vnd.audioexplosion.mzz',
			'n3' => 'text/n3',
			'nap' => 'image/naplps',
			'naplps' => 'image/naplps',
			'nb' => 'application/mathematica',
			'nbp' => 'application/vnd.wolfram.player',
			'nc' => 'application/x-netcdf',
			'ncm' => 'application/vnd.nokia.configuration-message',
			'ncx' => 'application/x-dtbncx+xml',
			'n-gage' => 'application/vnd.nokia.n-gage.symbian.install',
			'ngdat' => 'application/vnd.nokia.n-gage.data',
			'nif' => 'image/x-niff',
			'niff' => 'image/x-niff',
			'nix' => 'application/x-mix-transfer',
			'nlu' => 'application/vnd.neurolanguage.nlu',
			'nml' => 'application/vnd.enliven',
			'nnd' => 'application/vnd.noblenet-directory',
			'nns' => 'application/vnd.noblenet-sealer',
			'nnw' => 'application/vnd.noblenet-web',
			'npx' => 'image/vnd.net-fpx',
			'nsc' => 'application/x-conference',
			'nsf' => 'application/vnd.lotus-notes',
			'nvd' => 'application/x-navidoc',
			'nws' => 'message/rfc822',
			'o' => 'application/octet-stream',
			'oa2' => 'application/vnd.fujitsu.oasys2',
			'oa3' => 'application/vnd.fujitsu.oasys3',
			'oas' => 'application/vnd.fujitsu.oasys',
			'obd' => 'application/x-msbinder',
			'oda' => 'application/oda',
			'odb' => 'application/vnd.oasis.opendocument.database',
			'odc' => 'application/vnd.oasis.opendocument.chart',
			'odf' => 'application/vnd.oasis.opendocument.formula',
			'odft' => 'application/vnd.oasis.opendocument.formula-template',
			'odg' => 'application/vnd.oasis.opendocument.graphics',
			'odi' => 'application/vnd.oasis.opendocument.image',
			'odm' => 'application/vnd.oasis.opendocument.text-master',
			'odp' => 'application/vnd.oasis.opendocument.presentation',
			'ods' => 'application/oleobject',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'oga' => 'audio/ogg',
			'ogg' => 'audio/ogg',
			'ogv' => 'video/ogg',
			'ogx' => 'application/ogg',
			'omc' => 'application/x-omc',
			'omcd' => 'application/x-omcdatamaker',
			'omcr' => 'application/x-omcregerator',
			'onepkg' => 'application/onenote',
			'onetmp' => 'application/onenote',
			'onetoc' => 'application/onenote',
			'onetoc2' => 'application/onenote',
			'opf' => 'application/oebps-package+xml',
			'oprc' => 'application/vnd.palm',
			'org' => 'application/vnd.lotus-organizer',
			'osf' => 'application/vnd.yamaha.openscoreformat',
			'osfpvg' => 'application/vnd.yamaha.openscoreformat.osfpvg+xml',
			'otc' => 'application/vnd.oasis.opendocument.chart-template',
			'otf' => 'application/x-font-otf',
			'otg' => 'application/vnd.oasis.opendocument.graphics-template',
			'oth' => 'application/vnd.oasis.opendocument.text-web',
			'oti' => 'application/vnd.oasis.opendocument.image-template',
			'otp' => 'application/vnd.oasis.opendocument.presentation-template',
			'ots' => 'application/vnd.oasis.opendocument.spreadsheet-template',
			'ott' => 'application/vnd.oasis.opendocument.text-template',
			'oxt' => 'application/vnd.openofficeorg.extension',
			'p' => 'text/x-pascal',
			'p10' => 'application/pkcs10',
			'p12' => 'application/x-pkcs12',
			'p7a' => 'application/x-pkcs7-signature',
			'p7b' => 'application/x-pkcs7-certificates',
			'p7c' => 'application/pkcs7-mime',
			'p7m' => 'application/pkcs7-mime',
			'p7r' => 'application/x-pkcs7-certreqresp',
			'p7s' => 'application/pkcs7-signature',
			'p8' => 'application/pkcs8',
			'par' => 'text/plain-bas',
			'part' => 'application/pro_eng',
			'pas' => 'text/x-pascal',
			'paw' => 'application/vnd.pawaafile',
			'pbd' => 'application/vnd.powerbuilder6',
			'pbm' => 'image/x-portable-bitmap',
			'pcf' => 'application/x-font-pcf',
			'pcl' => 'application/vnd.hp-pcl',
			'pclxl' => 'application/vnd.hp-pclxl',
			'pct' => 'image/x-pict',
			'pcurl' => 'application/vnd.curl.pcurl',
			'pcx' => 'image/x-pcx',
			'pdb' => 'application/vnd.palm',
			'pdf' => 'application/pdf',
			'pfa' => 'application/x-font-type1',
			'pfb' => 'application/x-font-type1',
			'pfm' => 'application/x-font-type1',
			'pfr' => 'application/font-tdpfr',
			'pfunk' => 'audio/make',
			'pfx' => 'application/x-pkcs12',
			'pgm' => 'image/x-portable-graymap',
			'pgn' => 'application/x-chess-pgn',
			'pgp' => 'application/pgp-encrypted',
			'pic' => 'image/x-pict',
			'pict' => 'image/pict',
			'pkg' => 'application/octet-stream',
			'pki' => 'application/pkixcmp',
			'pkipath' => 'application/pkix-pkipath',
			'pko' => 'application/vndms-pkipko',
			'pl' => 'text/plain',
			'plb' => 'application/vnd.3gpp.pic-bw-large',
			'plc' => 'application/vnd.mobius.plc',
			'plf' => 'application/vnd.pocketlearn',
			'pls' => 'application/pls+xml',
			'plx' => 'application/x-pixclscript',
			'pm' => 'image/x-xpixmap',
			'pm4' => 'application/x-pagemaker',
			'pm5' => 'application/x-pagemaker',
			'pma' => 'application/x-perfmon',
			'pmc' => 'application/x-perfmon',
			'pml' => 'application/x-perfmon',
			'pmr' => 'application/x-perfmon',
			'pmw' => 'application/x-perfmon',
			'png' => 'image/png',
			'pnm' => 'image/x-portable-anymap',
			'portpkg' => 'application/vnd.macports.portpkg',
			'pot' => 'application/vnd.ms-powerpoint',
			'potm' => 'application/vnd.ms-powerpoint.template.macroenabled.12',
			'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
			'pov' => 'model/x-pov',
			'ppa' => 'application/vnd.ms-powerpoint',
			'ppam' => 'application/vnd.ms-powerpoint.addin.macroenabled.12',
			'ppd' => 'application/vnd.cups-ppd',
			'ppm' => 'image/x-portable-pixmap',
			'pps' => 'application/vnd.ms-powerpoint',
			'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroenabled.12',
			'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
			'ppt' => 'application/vnd.ms-powerpoint',
			'pptm' => 'application/vnd.ms-powerpoint.presentation.macroenabled.12',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'ppz' => 'application/mspowerpoint',
			'pqa' => 'application/vnd.palm',
			'prc' => 'application/x-mobipocket-ebook',
			'pre' => 'application/vnd.lotus-freelance',
			'prf' => 'application/pics-rules',
			'prt' => 'application/pro_eng',
			'ps' => 'application/postscript',
			'psb' => 'application/vnd.3gpp.pic-bw-small',
			'psd' => 'image/vnd.adobe.photoshop',
			'psf' => 'application/x-font-linux-psf',
			'pskcxml' => 'application/pskc+xml',
			'ptid' => 'application/vnd.pvi.ptid1',
			'pub' => 'application/x-mspublisher',
			'pvb' => 'application/vnd.3gpp.pic-bw-var',
			'pvu' => 'paleovu/x-pv',
			'pwn' => 'application/vnd.3m.post-it-notes',
			'pwz' => 'application/vnd.ms-powerpoint',
			'py' => 'text/x-script.phyton',
			'pya' => 'audio/vnd.ms-playready.media.pya',
			'pyc' => 'applicaiton/x-bytecode.python',
			'pyv' => 'video/vnd.ms-playready.media.pyv',
			'qam' => 'application/vnd.epson.quickanime',
			'qbo' => 'application/vnd.intu.qbo',
			'qcp' => 'audio/vnd.qcelp',
			'qd3' => 'x-world/x-3dmf',
			'qd3d' => 'x-world/x-3dmf',
			'qfx' => 'application/vnd.intu.qfx',
			'qif' => 'image/x-quicktime',
			'qps' => 'application/vnd.publishare-delta-tree',
			'qt' => 'video/quicktime',
			'qtc' => 'video/x-qtc',
			'qti' => 'image/x-quicktime',
			'qtif' => 'image/x-quicktime',
			'qwd' => 'application/vnd.quark.quarkxpress',
			'qwt' => 'application/vnd.quark.quarkxpress',
			'qxb' => 'application/vnd.quark.quarkxpress',
			'qxd' => 'application/vnd.quark.quarkxpress',
			'qxl' => 'application/vnd.quark.quarkxpress',
			'qxt' => 'application/vnd.quark.quarkxpress',
			'ra' => 'audio/x-pn-realaudio',
			'ram' => 'audio/x-pn-realaudio',
			'rar' => 'application/x-rar-compressed',
			'ras' => 'image/x-cmu-raster',
			'rast' => 'image/cmu-raster',
			'rcprofile' => 'application/vnd.ipunplugged.rcprofile',
			'rdf' => 'application/rdf+xml',
			'rdz' => 'application/vnd.data-vision.rdz',
			'rep' => 'application/vnd.businessobjects',
			'res' => 'application/x-dtbresource+xml',
			'rexx' => 'text/x-script.rexx',
			'rf' => 'image/vnd.rn-realflash',
			'rgb' => 'image/x-rgb',
			'rif' => 'application/reginfo+xml',
			'rip' => 'audio/vnd.rip',
			'rl' => 'application/resource-lists+xml',
			'rlc' => 'image/vnd.fujixerox.edmics-rlc',
			'rld' => 'application/resource-lists-diff+xml',
			'rm' => 'application/vnd.rn-realmedia',
			'rmi' => 'audio/mid',
			'rmm' => 'audio/x-pn-realaudio',
			'rmp' => 'audio/x-pn-realaudio-plugin',
			'rms' => 'application/vnd.jcp.javame.midlet-rms',
			'rnc' => 'application/relax-ng-compact-syntax',
			'rng' => 'application/ringing-tones',
			'rnx' => 'application/vnd.rn-realplayer',
			'roff' => 'application/x-troff',
			'rp' => 'image/vnd.rn-realpix',
			'rp9' => 'application/vnd.cloanto.rp9',
			'rpm' => 'audio/x-pn-realaudio-plugin',
			'rpss' => 'application/vnd.nokia.radio-presets',
			'rpst' => 'application/vnd.nokia.radio-preset',
			'rq' => 'application/sparql-query',
			'rs' => 'application/rls-services+xml',
			'rsd' => 'application/rsd+xml',
			'rss' => 'application/rss+xml',
			'rss, .xml' => 'application/rss+xml',
			'rt' => 'text/richtext',
			'rtf' => 'application/rtf',
			'rtx' => 'text/richtext',
			'rv' => 'video/vnd.rn-realvideo',
			's' => 'text/x-asm',
			's3m' => 'audio/s3m',
			'saf' => 'application/vnd.yamaha.smaf-audio',
			'saveme' => 'application/octet-stream',
			'sbk' => 'application/x-tbook',
			'sbml' => 'application/sbml+xml',
			'sc' => 'application/vnd.ibm.secure-container',
			'scd' => 'application/x-msschedule',
			'scm' => 'application/vnd.lotus-screencam',
			'scq' => 'application/scvp-cv-request',
			'scs' => 'application/scvp-cv-response',
			'sct' => 'text/scriptlet',
			'scurl' => 'text/vnd.curl.scurl',
			'sda' => 'application/vnd.stardivision.draw',
			'sdc' => 'application/vnd.stardivision.calc',
			'sdd' => 'application/vnd.stardivision.impress',
			'sdkd' => 'application/vnd.solent.sdkm+xml',
			'sdkm' => 'application/vnd.solent.sdkm+xml',
			'sdml' => 'text/plain',
			'sdp' => 'application/sdp',
			'sdr' => 'application/sounder',
			'sdw' => 'application/vnd.stardivision.writer',
			'sea' => 'application/sea',
			'see' => 'application/vnd.seemail',
			'seed' => 'application/vnd.fdsn.seed',
			'sema' => 'application/vnd.sema',
			'semd' => 'application/vnd.semd',
			'semf' => 'application/vnd.semf',
			'ser' => 'application/java-serialized-object',
			'set' => 'application/set',
			'setpay' => 'application/set-payment-initiation',
			'setreg' => 'application/set-registration-initiation',
			'sfd-hdstx' => 'application/vnd.hydrostatix.sof-data',
			'sfs' => 'application/vnd.spotfire.sfs',
			'sgl' => 'application/vnd.stardivision.writer-global',
			'sgm' => 'text/sgml',
			'sgml' => 'text/sgml',
			'sh' => 'application/x-sh',
			'shar' => 'application/x-shar',
			'shf' => 'application/shf+xml',
			'shtml' => 'text/html',
			'sid' => 'audio/x-psid',
			'sig' => 'application/pgp-signature',
			'silo' => 'model/mesh',
			'sis' => 'application/vnd.symbian.install',
			'sisx' => 'application/vnd.symbian.install',
			'sit' => 'application/x-stuffit',
			'sitx' => 'application/x-stuffitx',
			'skd' => 'application/vnd.koan',
			'skm' => 'application/vnd.koan',
			'skp' => 'application/vnd.koan',
			'skt' => 'application/vnd.koan',
			'sl' => 'application/x-seelogo',
			'sldm' => 'application/vnd.ms-powerpoint.slide.macroenabled.12',
			'sldx' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
			'slt' => 'application/vnd.epson.salt',
			'sm' => 'application/vnd.stepmania.stepchart',
			'smf' => 'application/vnd.stardivision.math',
			'smi' => 'application/smil+xml',
			'smil' => 'application/smil+xml',
			'snd' => 'audio/basic',
			'snf' => 'application/x-font-snf',
			'so' => 'application/octet-stream',
			'sol' => 'application/solids',
			'spc' => 'application/x-pkcs7-certificates',
			'spf' => 'application/vnd.yamaha.smaf-phrase',
			'spl' => 'application/futuresplash',
			'spot' => 'text/vnd.in3d.spot',
			'spp' => 'application/scvp-vp-response',
			'spq' => 'application/scvp-vp-request',
			'spr' => 'application/x-sprite',
			'sprite' => 'application/x-sprite',
			'spx' => 'audio/ogg',
			'src' => 'application/x-wais-source',
			'sru' => 'application/sru+xml',
			'srx' => 'application/sparql-results+xml',
			'sse' => 'application/vnd.kodak-descriptor',
			'ssf' => 'application/vnd.epson.ssf',
			'ssi' => 'text/x-server-parsed-html',
			'ssm' => 'application/streamingmedia',
			'ssml' => 'application/ssml+xml',
			'sst' => 'application/vndms-pkicertstore',
			'st' => 'application/vnd.sailingtracker.track',
			'stc' => 'application/vnd.sun.xml.calc.template',
			'std' => 'application/vnd.sun.xml.draw.template',
			'step' => 'application/step',
			'stf' => 'application/vnd.wt.stf',
			'sti' => 'application/vnd.sun.xml.impress.template',
			'stk' => 'application/hyperstudio',
			'stl' => 'application/vndms-pkistl',
			'stm' => 'text/html',
			'stp' => 'application/step',
			'str' => 'application/vnd.pg.format',
			'stw' => 'application/vnd.sun.xml.writer.template',
			'sub' => 'image/vnd.dvb.subtitle',
			'sus' => 'application/vnd.sus-calendar',
			'susp' => 'application/vnd.sus-calendar',
			'sv4cpio' => 'application/x-sv4cpio',
			'sv4crc' => 'application/x-sv4crc',
			'svc' => 'application/vnd.dvb.service',
			'svd' => 'application/vnd.svd',
			'svf' => 'image/vnd.dwg',
			'svg' => 'image/svg+xml',
			'svgz' => 'image/svg+xml',
			'svr' => 'application/x-world',
			'swa' => 'application/x-director',
			'swf' => 'application/x-shockwave-flash',
			'swi' => 'application/vnd.aristanetworks.swi',
			'sxc' => 'application/vnd.sun.xml.calc',
			'sxd' => 'application/vnd.sun.xml.draw',
			'sxg' => 'application/vnd.sun.xml.writer.global',
			'sxi' => 'application/vnd.sun.xml.impress',
			'sxm' => 'application/vnd.sun.xml.math',
			'sxw' => 'application/vnd.sun.xml.writer',
			't' => 'application/x-troff',
			'talk' => 'text/x-speech',
			'tao' => 'application/vnd.tao.intent-module-archive',
			'tar' => 'application/x-tar',
			'tbk' => 'application/toolbook',
			'tcap' => 'application/vnd.3gpp2.tcap',
			'tcl' => 'application/x-tcl',
			'tcsh' => 'text/x-script.tcsh',
			'teacher' => 'application/vnd.smart.teacher',
			'tei' => 'application/tei+xml',
			'teicorpus' => 'application/tei+xml',
			'tex' => 'application/x-tex',
			'texi' => 'application/x-texinfo',
			'texinfo' => 'application/x-texinfo',
			'text' => 'text/plain',
			'tfi' => 'application/thraud+xml',
			'tfm' => 'application/x-tex-tfm',
			'tgz' => 'application/x-compressed',
			'thmx' => 'application/vnd.ms-officetheme',
			'tif' => 'image/tiff',
			'tiff' => 'image/tiff',
			'tmo' => 'application/vnd.tmobile-livetv',
			'torrent' => 'application/x-bittorrent',
			'tpl' => 'application/vnd.groove-tool-template',
			'tpt' => 'application/vnd.trid.tpt',
			'tr' => 'application/x-troff',
			'tra' => 'application/vnd.TRUEapp',
			'trm' => 'application/x-msterminal',
			'tsd' => 'application/timestamped-data',
			'tsi' => 'audio/tsp-audio',
			'tsp' => 'application/dsptype',
			'tsv' => 'text/tab-separated-values',
			'ttc' => 'application/x-font-ttf',
			'ttf' => 'application/x-font-ttf',
			'ttl' => 'text/turtle',
			'turbot' => 'image/florian',
			'twd' => 'application/vnd.simtech-mindmapper',
			'twds' => 'application/vnd.simtech-mindmapper',
			'txd' => 'application/vnd.genomatix.tuxedo',
			'txf' => 'application/vnd.mobius.txf',
			'txt' => 'text/plain',
			'u32' => 'application/x-authorware-bin',
			'udeb' => 'application/x-debian-package',
			'ufd' => 'application/vnd.ufdl',
			'ufdl' => 'application/vnd.ufdl',
			'uil' => 'text/x-uil',
			'uls' => 'text/iuls',
			'umj' => 'application/vnd.umajin',
			'uni' => 'text/uri-list',
			'unis' => 'text/uri-list',
			'unityweb' => 'application/vnd.unity',
			'unv' => 'application/i-deas',
			'uoml' => 'application/vnd.uoml+xml',
			'uri' => 'text/uri-list',
			'uris' => 'text/uri-list',
			'urls' => 'text/uri-list',
			'ustar' => 'application/x-ustar',
			'utz' => 'application/vnd.uiq.theme',
			'uu' => 'text/x-uuencode',
			'uue' => 'text/x-uuencode',
			'uva' => 'audio/vnd.dece.audio',
			'uvd' => 'application/vnd.dece.data',
			'uvf' => 'application/vnd.dece.data',
			'uvg' => 'image/vnd.dece.graphic',
			'uvh' => 'video/vnd.dece.hd',
			'uvi' => 'image/vnd.dece.graphic',
			'uvm' => 'video/vnd.dece.mobile',
			'uvp' => 'video/vnd.dece.pd',
			'uvs' => 'video/vnd.dece.sd',
			'uvt' => 'application/vnd.dece.ttml+xml',
			'uvu' => 'video/vnd.uvvu.mp4',
			'uvv' => 'video/vnd.dece.video',
			'uvva' => 'audio/vnd.dece.audio',
			'uvvd' => 'application/vnd.dece.data',
			'uvvf' => 'application/vnd.dece.data',
			'uvvg' => 'image/vnd.dece.graphic',
			'uvvh' => 'video/vnd.dece.hd',
			'uvvi' => 'image/vnd.dece.graphic',
			'uvvm' => 'video/vnd.dece.mobile',
			'uvvp' => 'video/vnd.dece.pd',
			'uvvs' => 'video/vnd.dece.sd',
			'uvvt' => 'application/vnd.dece.ttml+xml',
			'uvvu' => 'video/vnd.uvvu.mp4',
			'uvvv' => 'video/vnd.dece.video',
			'uvvx' => 'application/vnd.dece.unspecified',
			'uvx' => 'application/vnd.dece.unspecified',
			'vcd' => 'application/x-cdlink',
			'vcf' => 'text/x-vcard',
			'vcg' => 'application/vnd.groove-vcard',
			'vcs' => 'text/x-vcalendar',
			'vcx' => 'application/vnd.vcx',
			'vda' => 'application/vda',
			'vdo' => 'video/vdo',
			'vew' => 'application/groupwise',
			'vis' => 'application/vnd.visionary',
			'viv' => 'video/vnd.vivo',
			'vivo' => 'video/vivo',
			'vmd' => 'application/vocaltec-media-desc',
			'vmf' => 'application/vocaltec-media-file',
			'voc' => 'audio/voc',
			'vor' => 'application/vnd.stardivision.writer',
			'vos' => 'video/vosaic',
			'vox' => 'application/x-authorware-bin',
			'vqe' => 'audio/x-twinvq-plugin',
			'vqf' => 'audio/x-twinvq',
			'vql' => 'audio/x-twinvq-plugin',
			'vrml' => 'model/vrml',
			'vrt' => 'x-world/x-vrt',
			'vsd' => 'application/vnd.visio',
			'vsf' => 'application/vnd.vsf',
			'vss' => 'application/vnd.visio',
			'vst' => 'application/vnd.visio',
			'vsw' => 'application/vnd.visio',
			'vtu' => 'model/vnd.vtu',
			'vxml' => 'application/voicexml+xml',
			'w3d' => 'application/x-director',
			'w60' => 'application/wordperfect6.0',
			'w61' => 'application/wordperfect6.1',
			'w6w' => 'application/msword',
			'wad' => 'application/x-doom',
			'wav' => 'audio/wav',
			'wax' => 'audio/x-ms-wax',
			'wb1' => 'application/x-qpro',
			'wbmp' => 'image/vnd.wap.wbmp',
			'wbs' => 'application/vnd.criticaltools.wbs+xml',
			'wbxml' => 'application/vnd.wap.wbxml',
			'wcm' => 'application/vnd.ms-works',
			'wdb' => 'application/vnd.ms-works',
			'web' => 'application/vnd.xara',
			'weba' => 'audio/webm',
			'webm' => 'video/webm',
			'webp' => 'image/webp',
			'wg' => 'application/vnd.pmi.widget',
			'wgt' => 'application/widget',
			'wiz' => 'application/msword',
			'wk1' => 'application/x-123',
			'wks' => 'application/vnd.ms-works',
			'wm' => 'video/x-ms-wm',
			'wma' => 'audio/x-ms-wma',
			'wmd' => 'application/x-ms-wmd',
			'wmf' => 'application/x-msmetafile',
			'wml' => 'text/vnd.wap.wml',
			'wmlc' => 'application/vnd.wap.wmlc',
			'wmls' => 'text/vnd.wap.wmlscript',
			'wmlsc' => 'application/vnd.wap.wmlscriptc',
			'wmv' => 'video/x-ms-wmv',
			'wmx' => 'video/x-ms-wmx',
			'wmz' => 'application/x-ms-wmz',
			'woff' => 'application/font-woff',
			'word' => 'application/msword',
			'wp' => 'application/wordperfect',
			'wp5' => 'application/wordperfect',
			'wp6' => 'application/wordperfect',
			'wpd' => 'application/vnd.wordperfect',
			'wpl' => 'application/vnd.ms-wpl',
			'wps' => 'application/vnd.ms-works',
			'wq1' => 'application/x-lotus',
			'wqd' => 'application/vnd.wqd',
			'wri' => 'application/x-mswrite',
			'wrl' => 'x-world/x-vrml',
			'wrz' => 'x-world/x-vrml',
			'wsc' => 'text/scriplet',
			'wsdl' => 'text/xml',
			'wspolicy' => 'application/wspolicy+xml',
			'wsrc' => 'application/x-wais-source',
			'wtb' => 'application/vnd.webturbo',
			'wtk' => 'application/x-wintalk',
			'wvx' => 'video/x-ms-wvx',
			'x32' => 'application/x-authorware-bin',
			'x3d' => 'application/vnd.hzn-3d-crossword',
			'xaf' => 'x-world/x-vrml',
			'xap' => 'application/x-silverlight-app',
			'xar' => 'application/vnd.xara',
			'xbap' => 'application/x-ms-xbap',
			'xbd' => 'application/vnd.fujixerox.docuworks.binder',
			'xbm' => 'image/x-xbitmap',
			'xdf' => 'application/xcap-diff+xml',
			'xdm' => 'application/vnd.syncml.dm+xml',
			'xdp' => 'application/vnd.adobe.xdp+xml',
			'xdr' => 'video/x-amt-demorun',
			'xdssc' => 'application/dssc+xml',
			'xdw' => 'application/vnd.fujixerox.docuworks',
			'xenc' => 'application/xenc+xml',
			'xer' => 'application/patch-ops-error+xml',
			'xfdf' => 'application/vnd.adobe.xfdf',
			'xfdl' => 'application/vnd.xfdl',
			'xgz' => 'xgl/drawing',
			'xht' => 'application/xhtml+xml',
			'xhtml' => 'application/xhtml+xml',
			'xhvml' => 'application/xv+xml',
			'xif' => 'image/vnd.xiff',
			'xl' => 'application/excel',
			'xla' => 'application/vnd.ms-excel',
			'xlam' => 'application/vnd.ms-excel.addin.macroenabled.12',
			'xlb' => 'application/excel',
			'xlc' => 'application/vnd.ms-excel',
			'xld' => 'application/excel',
			'xlk' => 'application/excel',
			'xll' => 'application/excel',
			'xlm' => 'application/vnd.ms-excel',
			'xls' => 'application/vnd.ms-excel',
			'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroenabled.12',
			'xlsm' => 'application/vnd.ms-excel.sheet.macroenabled.12',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xlt' => 'application/vnd.ms-excel',
			'xltm' => 'application/vnd.ms-excel.template.macroenabled.12',
			'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
			'xlv' => 'application/excel',
			'xlw' => 'application/vnd.ms-excel',
			'xm' => 'audio/xm',
			'xml' => 'text/xml',
			'xmz' => 'xgl/movie',
			'xo' => 'application/vnd.olpc-sugar',
			'xof' => 'x-world/x-vrml',
			'xop' => 'application/xop+xml',
			'xpi' => 'application/x-xpinstall',
			'xpix' => 'application/x-vnd.ls-xpix',
			'xpm' => 'image/x-xpixmap',
			'x-png' => 'image/png',
			'xpr' => 'application/vnd.is-xpr',
			'xps' => 'application/vnd.ms-xpsdocument',
			'xpw' => 'application/vnd.intercon.formnet',
			'xpx' => 'application/vnd.intercon.formnet',
			'xsd' => 'text/xml',
			'xsl' => 'text/xml',
			'xslt' => 'application/xslt+xml',
			'xsm' => 'application/vnd.syncml+xml',
			'xspf' => 'application/xspf+xml',
			'xsr' => 'video/x-amt-showrun',
			'xul' => 'application/vnd.mozilla.xul+xml',
			'xvm' => 'application/xv+xml',
			'xvml' => 'application/xv+xml',
			'xwd' => 'image/x-xwindowdump',
			'xyz' => 'chemical/x-xyz',
			'yang' => 'application/yang',
			'yin' => 'application/yin+xml',
			'z' => 'application/x-compress',
			'zaz' => 'application/vnd.zzazz.deck+xml',
			'zip' => 'application/x-zip-compressed',
			'zir' => 'application/vnd.zul',
			'zirz' => 'application/vnd.zul',
			'zmm' => 'application/vnd.handheld-entertainment+xml',
			'zoo' => 'application/octet-stream',
			'zsh' => 'text/x-script.zsh',
		);
	}
}

class sodDownloadException extends exception {}
