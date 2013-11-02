<?php
/**
 * PHP Real Ajax Uploader 3.0
 * Copyright Alban Xhaferllari
 * albanx@gmail.com
 * http://www.albanx.com/ajaxuploader
 * This PHP script handles all types of uploads, AJAX, FLASH and HTML
 * It expect 1 file per time in the $_FILES
 */


//===========================CONFIGURATION HERE. OVERRIDE JAVASCRIPT PARAMETERS HERE==========================================\\

#Set a value different from NULL to override settings set from Javascript
$MAX_FILES_SIZE 	= null;
$ALLOW_EXTENSIONS 	= null;
$UPLOAD_PATH 		= null;
$OVERRIDE 			= false;
$ALLOW_DELETE		= true;

#Set email notification, to send an email on upload finish
$EMAIL_TO 	= null;
$EMAIL_FROM = null;

#deny extension by default for security reason
$DENY_EXT = array('php','php3', 'php4', 'php5', 'phtml', 'exe', 'pl', 'cgi', 'html', 'htm', 'js', 'asp', 'aspx', 'bat', 'sh', 'cmd');


/*
 * function that runs on the end, customize here insert to db, or other action todo on the end of upload
 * name can be customized
 */
$FINISH_FUNCTION = 'success';
function success($file_path)
{
}
//=========================================================================================================\\



/*
 * It is not reccomended to change the following class until you know what are you doing
 * For customizing javascript settings, ovveriding them from here
 */

//=============================== Upload Class =================================================\\
class RealAjaxUploader
{
	public $file_name 	= '';
	public $file_size 	= 0;
	public $upload_path = 'uploads/';
	public $temp_path 	= 'temp/';
	public $allow_ext 		= array();
	public $max_file_size 	= '10M';
	
	public $override = false;
	public $deny_ext = array();
	public $upload_errors = array(
		UPLOAD_ERR_OK        	=> "No errors.",
		UPLOAD_ERR_INI_SIZE    	=> "The uploaded file exceeds the upload_max_filesize directive in php.ini",
		UPLOAD_ERR_FORM_SIZE    => "Larger than form MAX_FILE_SIZE.",
		UPLOAD_ERR_PARTIAL   	=> "Partial upload.",
		UPLOAD_ERR_NO_FILE      => "No file.",
		UPLOAD_ERR_NO_TMP_DIR   => "No temporary directory.",
		UPLOAD_ERR_CANT_WRITE   => "Can't write to disk.",
		UPLOAD_ERR_EXTENSION    => "File upload stopped by extension."
	);
	
	public $mail_receiver = '';
	public $finish_function ='';
	
	public $cross_origin = false;
	function __construct($deny_ext=array())
	{
		//set data from JAVASCRIPT
		if(isset($_REQUEST['ax-max-file-size'])) 	$this->setMaxFileSize($_REQUEST['ax-max-file-size']);
		if(isset($_REQUEST['ax-file-path']))	 	$this->setUploadPath($_REQUEST['ax-file-path']);
		if(isset($_REQUEST['ax-allow-ext']))		$this->setAllowExt( !empty($_REQUEST['ax-allow-ext']) ? explode('|', $_REQUEST['ax-allow-ext']): array() );
		if(isset($_REQUEST['ax-override']))			$this->setOverride(true);
		//set deny
		$this->deny_ext = $deny_ext;
		
		//active parameters neccessary for upload
		$this->file_name = isset($_REQUEST['ax-file-name']) ? $_REQUEST['ax-file-name']:$_FILES['ax_file_input']['name'];
		$this->file_size = isset($_REQUEST['ax-file-size']) ? $_REQUEST['ax-file-size']:$_FILES['ax_file_input']['size'];

		//create a temp folder for uploading the chunks
		$ini_val = @ini_get('upload_tmp_dir');
		$this->temp_path = $ini_val ? $ini_val : sys_get_temp_dir();
		$this->temp_path = $this->temp_path.DIRECTORY_SEPARATOR;
		//$this->makeDir($this->temp_path);
	}
	
	/**
	 * Set the maximum file size, expected string with byte notation
	 * @param string $max_file_size
	 */
	public function setMaxFileSize($max_file_size = '10M')
	{
		$this->max_file_size = $max_file_size;
	}
	
	/**
	 * Set the allow extension file to upload
	 * @param array $allow_ext
	 */
	public function setAllowExt($allow_ext=array())
	{
		$this->allow_ext = $allow_ext;
	}
	
	/**
	 * Set the upload poath as string
	 * @param string $upload_path
	 */
	public function setUploadPath($upload_path)
	{
		$upload_path = rtrim($upload_path, '\\/');
		$this->upload_path = $upload_path.DIRECTORY_SEPARATOR;
		// Create thumb path if do not exits
		$this->makeDir($this->upload_path);
	}
	
	public function setOverride($bool){
		$this->override=$bool;	
	}
	
	private function makeDir($dir)
	{
		// Create thumb path if do not exits
		if(!file_exists($dir) && !empty($dir))
		{
			$done = @mkdir($dir, 0777, true);
			if(!$done)
			{
				$this->message(-1, 'Cannot create upload folder');
			}
		}
	}
	
	//Create a image thumb
	private function createThumbGD($quality=75)
	{
		//Settings for thumbnail generation, can be changed here or from js
		$maxheight	= isset($_REQUEST['ax-thumbHeight'])?$_REQUEST['ax-thumbHeight']:0;
		$maxwidth	= isset($_REQUEST['ax-thumbWidth'])?$_REQUEST['ax-thumbWidth']:0;
		$postfix	= isset($_REQUEST['ax-thumbPostfix'])?$_REQUEST['ax-thumbPostfix']:'_thumb';
		$thumb_path	= isset($_REQUEST['ax-thumbPath'])?$_REQUEST['ax-thumbPath']:'';
		$format		= isset($_REQUEST['ax-thumbFormat'])?$_REQUEST['ax-thumbFormat']:'png';
		
		$filepath = $this->upload_path.$this->file_name;
		
		if( ($maxwidth<=0 && $maxheight<=0) || !is_numeric($maxwidth) || !is_numeric($maxheight) )
		{
			return 'No valid width and height given';
		}
	
		$web_formats= array('jpg','jpeg','png','gif');//web formats
		$file_name	= pathinfo($filepath);
		if(empty($format)) $format = $file_name['extension'];
	
		if(!in_array(strtolower($file_name['extension']), $web_formats))
		{
			return 'Not supported file type';
		}
	
		$thumb_name	= $file_name['filename'].$postfix.'.'.$format;//filename 5.2++
	
		if(empty($thumb_path))	$thumb_path=$file_name['dirname'];
		
		$thumb_path.= (!in_array(substr($thumb_path, -1), array('\\','/') ) )?DIRECTORY_SEPARATOR:'';//normalize path
	
		if(!file_exists($thumb_path) && !empty($thumb_path))
		{
			@mkdir($thumb_path, 0777, true);
		}
		
		// Get new dimensions
		list($width_orig, $height_orig) = getimagesize($filepath);
		if($width_orig>0 && $height_orig>0)
		{
			$ratioX	= $maxwidth/$width_orig;
			$ratioY	= $maxheight/$height_orig;
			$ratio 	= min($ratioX, $ratioY);
			$ratio	= ($ratio==0)?max($ratioX, $ratioY):$ratio;
			$newW	= $width_orig*$ratio;
			$newH	= $height_orig*$ratio;
				
			// Resample
			$thumb = imagecreatetruecolor($newW, $newH);
			$image = imagecreatefromstring(file_get_contents($filepath));
				
			imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newW, $newH, $width_orig, $height_orig);
	
			// Output
			switch (strtolower($format)) {
				case 'png':
					imagepng($thumb, $thumb_path.$thumb_name, 9);
					break;
						
				case 'gif':
					imagegif($thumb, $thumb_path.$thumb_name);
					break;
						
				default:
					imagejpeg($thumb, $thumb_path.$thumb_name, $quality);;
					break;
			}
			imagedestroy($image);
			imagedestroy($thumb);
		}
		else
		{
			return false;
		}
	}
	
	//Check if file size is allowed
	private function checkSize()
	{
		//------------------max file size check from js
		$max_file_size = $this->max_file_size;
		$size = $this->file_size;
		$rang 		= substr($max_file_size,-1);
		$max_size 	= !is_numeric($rang) && !is_numeric($max_file_size)? str_replace($rang, '', $max_file_size): $max_file_size;
		if($rang && $max_size)
		{
			switch (strtoupper($rang))//1024 or 1000??
			{
				case 'Y': $max_size = $max_size*1024;//Yotta byte, will arrive such day???
				case 'Z': $max_size = $max_size*1024;
				case 'E': $max_size = $max_size*1024;
				case 'P': $max_size = $max_size*1024;
				case 'T': $max_size = $max_size*1024;
				case 'G': $max_size = $max_size*1024;
				case 'M': $max_size = $max_size*1024;
				case 'K': $max_size = $max_size*1024;
			}
		}
	
		if(!empty($max_file_size) && $size>$max_size)
		{
			return false;
		}
		//-----------------End max file size check
	
		return true;
	}
	
	
	//Check if file name is allowed and remove illegal windows chars
	private function checkName()
	{
		//comment if not using windows web server
		$windowsReserved	= array('CON', 'PRN', 'AUX', 'NUL','COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
				'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9');
		$badWinChars		= array_merge(array_map('chr', range(0,31)), array("<", ">", ":", '"', "/", "\\", "|", "?", "*"));
	
		$this->file_name	= str_replace($badWinChars, '', $this->file_name);
	
		//check if legal windows file name
		if(in_array($this->file_name, $windowsReserved))
		{
			return false;
		}
		return true;
	}
	
	/**
	 * Check if a file exits or not and calculates a new name for not oovverring other files
	 * @param string $upload_path
	 */
	private function checkFileExits($upload_path='')
	{
		if($upload_path=='') $upload_path = $this->upload_path;
		if(!$this->override)
		{
			usleep(rand(100, 900));
			
			$filename 		= $this->file_name;
			//$upload_path 	= $this->upload_path;
			
			$file_data 	= pathinfo($filename);
			$file_base	= $file_data['filename'];
			$file_ext	= $file_data['extension'];//PHP 5.2>
		
			//Disable this lines of code to allow file override
			$c=0;
			while(file_exists($upload_path.$filename))
			{
				$find = preg_match('/\((.*?)\)/', $filename, $match);
				if(!$find) $match[1] = 0;
				else
					$file_base = str_replace("(".$match[1].")", "", $file_base);
					
				$match[1]++;
		
				$filename	= $file_base."(".$match[1].").".$file_ext;
			}
			// end
			$this->file_name = $filename;
		}
	}
	
	public function _checkFileExists()
	{
		$filename 		= $this->file_name;
		$upload_path 	= $this->upload_path;
		return file_exists($upload_path.$filename);
	}
	
	public function deleteFile(){
		$del = @unlink($this->upload_path.$this->file_name);
		return $del;
	}
	
	//Check if file type is allowed for upload
	private function checkExt()
	{
		$file_ext = strtolower( pathinfo($this->file_name, PATHINFO_EXTENSION) );
		
		//extensions not allowed for security reason and check if is allowed extension
		if(in_array($file_ext, $this->deny_ext)  || (!in_array($file_ext, $this->allow_ext) && count($this->allow_ext)) )
		{
			return false;
		}
		return true;
	}
	
	// Simle email sender function
	public function setEmail($main_receiver, $from='ajax@uploader')
	{
		$this->mail_receiver 	= $main_receiver;
		$this->mail_cc 			= '';
		$this->mail_from 		= $from ? $from : 'ajax@uploader';
	}
	
	private function sendEmail()
	{
		if($this->mail_receiver)
		{
			$msg = '<p> New file uploaded to your site at '.date('Y-m-i H:i'). ' from IP '.$_SERVER['REMOTE_ADDR'].':</p>';
			$msg.= '<div style="overflow:auto;padding:10px;border:1px solid black;border-radius:5px;">';
			$msg.= $this->upload_path.$this->file_name;
			$msg.= '</div>';
			$headers = 'From: '.$this->mail_from. "\r\n" .'Reply-To: '.$this->mail_from. "\r\n" ;
			$headers .= 'Cc: '.$this->mail_cc  . "\r\n";
			$headers .= "Content-type: text/html\r\n";
		
			@mail($this->mail_receiver, 'New file uploaded', $msg, $headers);
		}
	}

	private function uploadAjax()
	{
		$currByte	= isset($_REQUEST['ax-start-byte'])?$_REQUEST['ax-start-byte']:0;
		$isLast		= isset($_REQUEST['ax-last-chunk'])?$_REQUEST['ax-last-chunk']:'true';
		
		$flag = FILE_APPEND;
		if($currByte==0)
		{
			$this->checkFileExits($this->temp_path);//check if file exits in temp path, not so neccessary
			$flag = 0;
		}
		
		//we get the path only for the first chunk
		$full_path 	= $this->temp_path.$this->file_name;

		//formData post files just normal upload in $_FILES, older ajax upload post it in input
		$post_bytes	= file_get_contents( isset($_FILES['ax_file_input']) ? $_FILES['ax_file_input']['tmp_name'] : 'php://input' );
		
		//some rare times (on very very fast connection), file_put_contents will be unable to write on the file, so we try until it writes
		$try = 20;
		while(@file_put_contents($full_path, $post_bytes, $flag) === false && $try>0)
		{
			usleep(50);
			$try--;
		}
		
		if(!$try)
		{
			$this->message(-1, 'Cannot write on file.');
		}
		
		//delete the temporany chunk
		if(isset($_FILES['ax_file_input']))
		{
			@unlink($_FILES['ax_file_input']['tmp_name']);
		}
		
		//if it is not the last chunk just return success chunk upload
		if($isLast!='true')
		{
			$this->message(1, 'Chunk uploaded');
		}
		else
		{
			$this->checkFileExits($this->upload_path);
			$ret = rename($full_path, $this->upload_path.$this->file_name);//move file from temp dir to upload dir TODO this can be slow on big files and diffrent drivers
			if($ret)
			{
				$extra_info = $this->finish();
				$this->message(1, 'File uploaded', $extra_info);
			}
			else
			{
				$this->message(1, 'File move error', $extra_info);
			}
		}
	}
	
	private function uploadStandard()
	{
		$this->checkFileExits($this->upload_path);
		$full_path 	= $this->upload_path.$this->file_name;
		$result 	= move_uploaded_file($_FILES['ax_file_input']['tmp_name'], $full_path);//make the upload
		if(!$result) //if any error return the error
		{
			$this->message(-1, 'File move error');
		}
		else
		{
			$extra_info = $this->finish();
			$this->message(1, 'File uploaded', $extra_info);
		}
	}
	
	public function uploadFile()
	{
		if($this->checkFile())//this checks every chunk FIXME is right?
		{
			$is_ajax	= isset($_REQUEST['ax-last-chunk']) && isset($_REQUEST['ax-start-byte']);
			if($is_ajax)//Ajax Upload, FormData Upload and FF3.6 php://input upload
			{
				$this->uploadAjax();
			}
			else //Normal html and flash upload
			{
				$this->uploadStandard();
			}
		}
	}
	
	private function finish()
	{
		ob_start();
		//create a thumb if data is set
		$this->createThumbGD(100);
		
		//send a notification if is set
		$this->sendEmail();
		
		//run the external user success function
		if($this->finish_function && function_exists( $this->finish_function ))
		{
			try {
				call_user_func($this->finish_function, $this->upload_path.$this->file_name);
			} catch (Exception $e) {
				echo $e->getTraceAsString();
			}
			
		}
		$value = ob_get_contents();
		ob_end_clean();
		return $value;
	}
	
	private function checkFile()
	{
		//check uploads error
		if(isset($_FILES['ax_file_input']))
		{
			if( $_FILES['ax_file_input']['error'] !== UPLOAD_ERR_OK )
			{
				$this->message(-1, $this->upload_errors[$_FILES['ax_file_input']['error']]);
			}
		}
		
		//check ext
		$allow_ext = $this->checkExt();
		if(!$allow_ext)
		{
			$this->message(-1, 'File extension is not allowed');
		}

		//check name
		$fn_ok = $this->checkName();
		if(!$fn_ok)
		{
			$this->message(-1, 'File name is not allowed. System reserved.');
		}

		//check size
		if(!$this->checkSize())
		{
			$this->message(-1, 'File size exceeded maximum allowed: '.$this->max_file_size);
		}
		return true;
	}
	
	public function header()
	{
		header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
		header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
		header('X-Content-Type-Options: nosniff');
		if ($this->cross_origin) 
		{
			header('Access-Control-Allow-Origin: *');
        	header('Access-Control-Allow-Credentials: false');
        	header('Access-Control-Allow-Methods: OPTIONS, HEAD, GET, POST, PUT, PATCH, DELETE');
        	header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition');
		}
	}
	
	private function message($status, $msg, $extra_info='')
	{
		$this->header();
		echo json_encode(array('name'=>$this->file_name, 'size'=>$this->file_size, 'status'=>$status,'info'=>$msg, 'more'=>$extra_info));
		die();
	}
	
	public function onFinish($fun){
		$this->finish_function = $fun;
	}
}
//==========================================End of class=================================================\\

//======================== Start Upload Process===========================================================\\
$uploader = new RealAjaxUploader($DENY_EXT);  //create uploader object
if(isset($MAX_FILES_SIZE) && $MAX_FILES_SIZE) 		$uploader->setMaxFileSize($MAX_FILES_SIZE);
if(isset($ALLOW_EXTENSIONS) && $ALLOW_EXTENSIONS) 	$uploader->setAllowExt($ALLOW_EXTENSIONS);
if(isset($UPLOAD_PATH) && $UPLOAD_PATH) 			$uploader->setUploadPath($UPLOAD_PATH);

//register email send on file complete
if(isset($EMAIL_TO) && $EMAIL_TO) 					$uploader->setEmail($EMAIL_TO, $EMAIL_FROM);//the email will be send after file upload

//register a callback function on file complete
if(isset($FINISH_FUNCTION) && $FINISH_FUNCTION) 	$uploader->onFinish($FINISH_FUNCTION);//set name of external function to be called on finish upload

//check request, this check if file already exits only, depends from javascript part requests
if(isset($_REQUEST['ax-check-file']))
{
	$uploader->header();
	echo $uploader->_checkFileExists() ? 'yes': 'no';
}
elseif( isset($_REQUEST['ax-delete-file']) && $ALLOW_DELETE)
{
	$uploader->header();
	echo $uploader->deleteFile();
}
else
{
	$uploader->uploadFile();
}