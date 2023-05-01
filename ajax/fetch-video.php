<?php 
if (IS_LOGGED == false) {
	$data = array('status' => 400, 'error' => 'Not logged in');
    echo json_encode($data);
    exit();
}
if ($pt->user->suspend_import) {
	$data = array('status' => 400);
    echo json_encode($data);
    exit();
}

$max_import = $pt->config->user_max_import;

if ($pt->user->is_pro != 1 && $pt->user->imports >= $max_import){
    $data = array('status' => 401);
    echo json_encode($data);
    exit();
}


$re_data        = array();
$is_there_video = false;
$thumbnail      = 'upload/photos/thumbnail.jpg';
$title          = '';
$description    = '';
$tags           = '';
$duration       = '';
$tags_array     = array();
$getID3         = new getID3;

if (!empty($_POST['link'])) {
	$link = $_POST['link'];
    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $link, $match)) {
        $re_data['youtube'] = PT_Secure($match[1]);
        $is_there_video     = true;
        $video_import_id    = $re_data['youtube'];
        $video_type         = 'youtube';
    } 

	else if (preg_match("#https?://vimeo.com/([0-9]+)#i", $link, $match)) {
        $re_data['vimeo'] = PT_Secure($match[1]);
        $is_there_video   = true;
        $video_import_id  = $re_data['vimeo'];
        $video_type       = 'vimeo';
    } 
	
	else if (preg_match('#https?:.*?\.(mp4|mov)#s', $link, $match)) {
        $is_there_video   = true;
        $re_data['mp4']   = PT_Secure($match[0]);
        $video_type       = 'mp4';
        $video_import_id  = $re_data['mp4'];
    }
	
	else if (preg_match('#(http|https)://www.dailymotion.com/video/([A-Za-z0-9]+)#s', $link, $match)) {
        $re_data['dailymotion'] = PT_Secure($match[2]);
        $video_import_id = $re_data['dailymotion'];
        $video_type      = 'daily';
        $is_there_video  = true;
    } 
    else if (preg_match('#(https://www.ok.ru/|https://ok.ru/)(video|live)/([A-Za-z0-9]+)#s', $link, $match) && $pt->config->ok_import == 'on') {
        $re_data['ok'] = PT_Secure($match[3]);
        $video_import_id = $re_data['ok'];
        $video_type      = 'ok';
        $is_there_video  = true;
    }
	else if (preg_match('~([A-Za-z0-9]+)/videos/(?:t\.\d+/)?(\d+)~i', $link, $match) && $pt->config->facebook_import == 'on') {
        $re_data['facebook'] = PT_Secure($match[0]);
        $video_import_id = $re_data['facebook'];
        $video_type      = 'facebook';
        $is_there_video  = true;
    }
    else if (preg_match('@^(?:https?:\/\/)?(?:www\.|go\.)?twitch\.tv(\/videos\/([A-Za-z0-9]+)|\/([A-Za-z0-9]+)\/clip\/(.*)|\/(.*))($|\?)@', $link, $match) && $pt->config->twitch_import == 'on' && !empty($pt->config->twitch_api)) {


    	$text = explode('/', $match[1]);
    	if (!empty($text[1]) && $text[1] == 'videos') {
    		$re_data['twitch'] = PT_Secure($text[2]);
			$re_data['twitch_type'] = 'videos';
			$video_type      = 'twitch';
			$video_import_id = $re_data['twitch'];
			$is_there_video  = true;
    	}
    	else if (!empty($text[2]) && $text[2] == 'clip') {
    		$re_data['twitch'] = PT_Secure($text[3]);
			$re_data['twitch_type'] = 'clip';
			$video_type      = 'twitch';
			$video_import_id = $re_data['twitch'];
			$is_there_video  = true;
    	}
    	else if (!empty($text[1]) && !empty($text[1])){
    		$re_data['twitch'] = PT_Secure($text[1]);
			$re_data['twitch_type'] = 'streams';
			$video_type      = 'twitch';
			$video_import_id = $re_data['twitch'];
			$is_there_video  = true;
    	}
    }
    elseif ((preg_match("/(http|https):\/\/www.tiktok\.com\/(.*)\/video\/(.*)+/", $link) || preg_match("/(http|https):\/\/vt.tiktok\.com\/(.*)+/", $link)) && $pt->config->tiktok_import == 'on') {
    	$links = GetTiktokVideoDownloadLink($link);
    	if ($links['status'] == 400) {
    		$data = $links;
    		header('Content-Type: application/json');
			echo json_encode($data);
			exit();
    	}
    	else{
    		$is_there_video  = true;
    		$thumbnail = $links['cover'];
    		$video_import_id = $links['id'];
    		$video_type      = 'tiktok';
    		$title          = $links['title'];
			$description    = $links['desc'];
    	}

    }



    if ($is_there_video == false) {
    	$error = $error_icon . $lang->url_not_supported;
    }

    if (empty($error)) {
	    
	    if (!empty($re_data['youtube'])) {
	    	if ($db->where('youtube', $re_data['youtube'])->getValue(T_VIDEOS, 'count(*)') > 0) {
	    		$data = array('status' => 400, 'message' => $error_icon . $lang->video_already_exist);
	    		header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	    	try {
	    		require 'assets/libs/youtube-sdk/vendor/autoload.php';
	    		$youtube = new Madcoda\Youtube\Youtube(array('key' => $pt->config->yt_api));
	            $get_videos = $youtube->getVideoInfo($re_data['youtube']);
	            if (!empty($get_videos)) {
		    		if (!empty($get_videos->snippet)) {
		    			
		    			if (!empty($get_videos->snippet->thumbnails->maxres->url)) {
	            			$thumbnail = $get_videos->snippet->thumbnails->maxres->url;
	            		} else if (!empty($get_videos->snippet->thumbnails->standard->url)) {
	            			$thumbnail = $get_videos->snippet->thumbnails->standard->url;
	            		} else if (!empty($get_videos->snippet->thumbnails->high->url)) {
	            			$thumbnail = $get_videos->snippet->thumbnails->high->url;
	            		} else if (!empty($get_videos->snippet->thumbnails->medium->url)) {
	            			$thumbnail = $get_videos->snippet->thumbnails->medium->url;
	            		}
		    			$info = $get_videos->snippet;
		    			$title = $info->title;
		    			if (!empty(covtime($get_videos->contentDetails->duration))) {
		    				$duration = covtime($get_videos->contentDetails->duration);
		    			}
		    			$description = $info->description;
		    			if (!empty($get_videos->snippet->tags)) {
		    				if (is_array($get_videos->snippet->tags)) {
			    				foreach ($get_videos->snippet->tags as $key => $tag) {
			    					$tags_array[] = $tag;
			    				}
			    				$tags = implode(',', $tags_array);
			    			}
		    			}
		    		}
		    	}
	    	} 
	    	catch (Exception $e) {
	    		$error = $error_icon . $e->getMessage();
	    		$data['status'] = 400;
	            $data['message'] = $error;
	    		header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	    } 

	    else if (!empty($re_data['dailymotion'])) {
	    	if ($db->where('daily', $re_data['dailymotion'])->getValue(T_VIDEOS, 'count(*)') > 0) {
	    		$data = array('status' => 400, 'message' => $error_icon . $lang->video_already_exist);
	    		header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	    	$api_request = connect_to_url('https://api.dailymotion.com/video/' . $re_data['dailymotion'] . '?fields=thumbnail_large_url,thumbnail_1080_url,title,duration,description,tags');
	    	if (!empty($api_request)) {
	    		$json_decode = json_decode($api_request);
	    		if (!empty($json_decode->title)) {
	    			$title = $json_decode->title;
	    		}
	    		if (!empty($json_decode->description)) {
	    			$description = $json_decode->description;
	    		}
	    		if (!empty($json_decode->thumbnail_1080_url)) {
	    			$thumbnail = $json_decode->thumbnail_1080_url;
	    		} else if (!empty($json_decode->thumbnail_large_url)) {
	    			$thumbnail = $json_decode->thumbnail_large_url;
	    		}
	    		$thumbnail = str_replace('http://', 'https://', $thumbnail);
	    		if (!empty($json_decode->duration)) {
	    			$duration = gmdate("i:s", $json_decode->duration);
	    		}
	    		if (is_array($json_decode->tags)) {
    				foreach ($json_decode->tags as $key => $tag) {
    					$tags_array[] = $tag;
    				}
    				$tags = implode(',', $tags_array);
    			}
	    	}
	    }
	    elseif (!empty($re_data['ok'])) {
	     	$title = '';
	     	$description = '';
	     	$thumbnail = 'upload/photos/thumbnail.jpg';
	     	$duration = '';
	     	$tags = '';
	     } 

	    else if (!empty($re_data['vimeo'])) {
	    	if ($db->where('vimeo', $re_data['vimeo'])->getValue(T_VIDEOS, 'count(*)') > 0) {
	    		$data = array('status' => 400, 'message' => $error_icon . $lang->video_already_exist);
	    		header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	    	$api_request = connect_to_url('http://vimeo.com/api/v2/video/' . $re_data['vimeo'] . '.json');
	    	if (!empty($api_request)) {
	    		$json_decode = json_decode($api_request);
	    		if (!empty($json_decode[0]->title)) {
	    			$title = $json_decode[0]->title;
	    		}
	    		if (!empty($json_decode[0]->description)) {
	    			$description = $json_decode[0]->description;
	    		}
	    		if (!empty($json_decode[0]->thumbnail_large)) {
	    			$thumbnail = $json_decode[0]->thumbnail_large;
	    		}
	    		$thumbnail = str_replace('http://', 'https://', $thumbnail);
	    		if (!empty($json_decode[0]->duration)) {
	    			$duration = gmdate("i:s", $json_decode[0]->duration);
	    		}
	    		if (!empty($json_decode[0]->tags)) {
    				$tags = $json_decode[0]->tags;
    			}
	    	}
	    } else if (!empty($re_data['facebook'])) {
	    	$get_access_token = json_decode(connect_to_url("https://graph.facebook.com/oauth/access_token?client_id={$pt->config->facebook_app_ID}&client_secret={$pt->config->facebook_app_key}&grant_type=client_credentials"));
	    	if (!empty($get_access_token->access_token)) {
	    		$video_import_id_ = substr($video_import_id, strrpos($video_import_id, '/' )+1);
	    		$get_video_info = json_decode(connect_to_url("https://graph.facebook.com/{$video_import_id_}?fields=format,source,description,length", array('bearer' => $get_access_token->access_token)), true);
	    		foreach ($get_video_info['format'] as $key => $value) {
	    			if ($value['filter'] == 'native') {
	    				$thumbnail = $value['picture'];
	    			}
	    		}
	    		$title = $get_video_info['description'];
	    		$duration = gmdate("i:s", $get_video_info['length']);
	    	} else {
	    		$data['status'] = 400;
	            $data['message'] = $get_access_token->error->message;
	            header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	    }
	     else if (!empty($re_data['twitch'])) {
	     	if ($db->where('twitch', $re_data['twitch'])->getValue(T_VIDEOS, 'count(*)') > 0) {
	    		$data = array('status' => 400, 'message' => $error_icon . $lang->video_already_exist);
	    		header('Content-Type: application/json');
	    		echo json_encode($data);
	    		exit();
	    	}
	     }

	    $db->where('id',$pt->user->id)->update(T_USERS,array('imports' => ($pt->user->imports += 1)));
	    $data = array(
	    	'status' => 200,
	        'title' => $title,
	        'description' => $description,
	        'description_br' => nl2br(mb_substr($description, 0, 300, "UTF-8") . '...'),
	        'tags' => $tags,
	        'duration' => $duration,
	        'thumbnail' => $thumbnail,
	        'full_thumb' => (strpos($thumbnail, 'upload/photos') !== false) ? PT_GetMedia($thumbnail) : $thumbnail,
	        'video_id' => $video_import_id,
	        'type' => $video_type,
	        'twitch_type' => (!empty($re_data['twitch_type']) ? $re_data['twitch_type'] : '')
	    );
    }

    else {
    	$data['status'] = 400;
	    $data['message'] = $error;
    }
} 

else {
	$data['status'] = 400;
	$data['message'] = $error_icon . $lang->please_check_details;
}

?>