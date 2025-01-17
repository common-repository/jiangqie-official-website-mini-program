<?php

/*
 * 追格企业官网Free
 * Author: 追格
 * Help document: https://www.zhuige.com/docs/gwfree.html
 * github: https://github.com/zhuige-com/jiangqie_ow_free
 * gitee: https://gitee.com/zhuige_com/jiangqie_ow_free
 * License：GPL-2.0
 * Copyright © 2021-2024 www.zhuige.com All rights reserved.
 */

class Jiangqie_Ow_Free_Post_Controller extends Jiangqie_Ow_Free_Base_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->module = 'posts';
		$this->routes = [
			'last' => 'get_last_posts',
			'detail' => 'get_post_detail',
			'wxacode' => 'get_wxacode',
			'bdacode' => 'get_bdacode',
		];
	}

	/**
	 * 按【时间倒序】获取文章列表
	 */
	public function get_last_posts($request)
	{
		$offset = (int)($this->param_value($request, 'offset', 0));
		$cat_id = (int)($this->param_value($request, 'cat_id', 0));

		$args = [
			'posts_per_page' => Jiangqie_Ow_Free::POSTS_PER_PAGE,
			'offset' => $offset,
			'orderby' => 'date',
		];

		if ($cat_id) {
			$args['cat'] = $cat_id;
			$args['ignore_sticky_posts'] = 1;
		}

		$query = new WP_Query();
		$result = $query->query($args);
		$posts = [];
		foreach ($result as $post) {
			$posts[] = $this->_formatPost($post);
		}

		return $this->make_success([
			'posts' => $posts,
			'more' => (count($result) == Jiangqie_Ow_Free::POSTS_PER_PAGE ? 'more' : 'nomore')
		]);
	}

	/**
	 * 获取文章详情
	 */
	public function get_post_detail($request)
	{
		$post_id = (int)($this->param_value($request, 'post_id'));
		if (!$post_id) {
			return $this->make_error('缺少参数');
		}

		$postObj = get_post($post_id);

		$post = [
			'id' => $postObj->ID,
			'time' => $this->time_beautify($postObj->post_date),
			'title' => $postObj->post_title,
			'content' => apply_filters('the_content', $postObj->post_content),
			'thumbnail' => $this->get_one_post_thumbnail($postObj),
		];

		//文章摘要
		$post["excerpt"] = $this->_getExcerpt($postObj);

		//处理文章浏览数
		$post_views = (int) get_post_meta($post_id, 'jiangqie_views', true);
		if (!update_post_meta($post_id, 'jiangqie_views', ($post_views + 1))) {
			add_post_meta($post_id, 'jiangqie_views', 1, true);
		}
		$post['views'] = $post_views + 1;

		// 海报开关
		$post['poster_switch'] = Jiangqie_Ow_Free::option_value('post_poster_switch') ? 1 : 0;

		// 客服开关
		$post['contact_switch'] = Jiangqie_Ow_Free::option_value('post_contact_switch') ? 1 : 0;

		//百度关键字
		$keywords = '';
		$os = $this->param_value($request, 'os');
		if ($os == 'bd') {
			$tags = get_the_tags($post_id);
			foreach ($tags  as $tag) {
				$keywords .= $tag->name . ',';
			}
			foreach (get_the_category($post_id) as $category) {
				$keywords .= $category->cat_name . ',';
			}
			$keywords = substr_replace($keywords, '', -1);
		}
		$post['keywords'] = $keywords;

		// 尾部推荐
		// 根据分类获取
		$categorys = get_the_category($post_id);
		$cat_ids = [];
		foreach ($categorys as $category) {
			$cat_ids[] = $category->cat_ID;
		}
		$args = [
			'posts_per_page' => 4,
			'offset' => 0,
			'orderby' => 'date',
			'category__in' => $cat_ids,
			'post__not_in' => [$post_id]
		];
		$query = new WP_Query();
		$result = $query->query($args);
		$posts = [];
		foreach ($result as $p) {
			$posts[] = $this->_formatPost($p);
		}
		$post['recs'] = $posts;

		// 备案信息
		$beian_icp = Jiangqie_Ow_Free::option_value('beian_icp');
		if ($beian_icp && $beian_icp['switch']) {
			$post['beian_icp'] = [
				'sn' => $beian_icp['sn'],
				'link' => $beian_icp['link'],
			];
		}

		return $this->make_success($post);
	}

	/**
	 * 获取微信小程序码
	 */
	public function get_wxacode($request)
	{
		$post_id = (int)($this->param_value($request, 'post_id', 0));
		if (!$post_id) {
			return $this->make_error('缺少参数');
		}

		$post_type = get_post_type($post_id);
		if ($post_type != 'post') {
			return $this->make_error('暂不支持');
		}

		$uploads = wp_upload_dir();
		$qrcode_path = $uploads['basedir'] . '/jiangqie_wxacode/';
		if (!is_dir($qrcode_path)) {
			mkdir($qrcode_path, 0755);
		}

		$qrcode = $qrcode_path . $post_type . '-' . $post_id . '.png';
		$qrcode_link = $uploads['baseurl'] . '/jiangqie_wxacode/' . $post_type . '-' . $post_id . '.png';
		if (is_file($qrcode)) {
			return $this->make_success($qrcode_link);
		}

		$wx_session = Jiangqie_Ow_Free::get_wx_token();
		$access_token = $wx_session['access_token'];
		if (empty($access_token)) {
			return $this->make_error('获取二维码失败');
		}

		$api = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . $access_token;

		$color = array(
			"r" => "0",  //这个颜色码自己到Photoshop里设
			"g" => "0",  //这个颜色码自己到Photoshop里设
			"b" => "0",  //这个颜色码自己到Photoshop里设
		);

		$page = 'pages/detail/detail';
		$data = array(
			'scene' => $post_id, //TODO 自定义信息，可以填写诸如识别用户身份的字段，注意用中文时的情况
			'page' => $page, // 前端传过来的页面path,不能为空，最大长度 128 字节
			// 'width' => 200, // 尺寸 单位px 默认430
			'auto_color' => false, // 自动配置线条颜色，如果颜色依然是黑色，则说明不建议配置主色调
			'line_color' => $color, // auth_color 为 false 时生效，使用 rgb 设置颜色 例如 {"r":"xxx","g":"xxx","b":"xxx"},十进制表示
			'is_hyaline' => true, // 是否需要透明底色， is_hyaline 为true时，生成透明底色的小程序码
		);

		$args = array(
			'method'  => 'POST',
			'body' 	  => wp_json_encode($data),
			'headers' => array(),
			'cookies' => array()
		);

		$remote = wp_remote_post($api, $args);
		if (is_wp_error($remote)) {
			return $this->make_error('系统异常');
		}

		$content = wp_remote_retrieve_body($remote);
		if (strstr($content, 'errcode') !== false || strstr($content, 'errmsg') !== false) {
			// $json = json_decode($content, TRUE);
			// return $this->make_error($json['errmsg']);
			return $this->make_success(plugins_url('images/wxacode.jpg', dirname(__FILE__)));
		}

		//输出二维码
		file_put_contents($qrcode, $content);

		//同步到媒体库
		$res = jiangqie_ow_free_import_image2attachment($qrcode, $post_id);
		if (!is_wp_error($res)) {
			$qrcode_link = $uploads['baseurl'] . '/jiangqie_wxacode/' . $res;
		}

		return $this->make_success($qrcode_link);
	}

	/**
	 * 获取百度小程序码
	 */
	public function get_bdacode($request)
	{
		$post_id = (int)($this->param_value($request, 'post_id', 0));
		if (!$post_id) {
			return $this->make_error('缺少参数');
		}

		$post_type = get_post_type($post_id);
		if ($post_type != 'post') {
			return $this->make_error('暂不支持');
		}

		$uploads = wp_upload_dir();
		$qrcode_path = $uploads['basedir'] . '/jiangqie_bdacode/';
		if (!is_dir($qrcode_path)) {
			mkdir($qrcode_path, 0755);
		}

		$qrcode = $qrcode_path . $post_type . '-' . $post_id . '.png';
		$qrcode_link = $uploads['baseurl'] . '/jiangqie_bdacode/' . $post_type . '-' . $post_id . '.png';
		if (is_file($qrcode)) {
			return $this->make_success($qrcode_link);
		}

		$wx_session = Jiangqie_Ow_Free::get_bd_token();
		$access_token = $wx_session['access_token'];
		if (empty($access_token)) {
			return $this->make_error('获取二维码失败');
		}

		$api = 'https://openapi.baidu.com/rest/2.0/smartapp/qrcode/getunlimited?access_token=' . $access_token;

		$data = array(
			'path' => 'pages/detail/detail?post_id=' . $post_id,
			// 'width' => 430, 尺寸 默认430
			// 'mf' => 1 是否包含logo 1001不包含 默认包含
		);

		$args = array(
			'method'  => 'POST',
			'body' 	  => $data,
			'headers' => array(),
			'cookies' => array()
		);

		$remote = wp_remote_post($api, $args);
		if (is_wp_error($remote)) {
			return $this->make_error('系统异常');
		}

		$content = wp_remote_retrieve_body($remote);
		if (strstr($content, 'errno') !== false || strstr($content, 'errmsg') !== false) {
			return $this->make_success(plugins_url('images/bdacode.jpg', dirname(__FILE__)));
		}

		//输出二维码
		file_put_contents($qrcode, $content);

		//同步到媒体库
		$res = jiangqie_ow_free_import_image2attachment($qrcode, $post_id);
		if (!is_wp_error($res)) {
			$qrcode_link = $uploads['baseurl'] . '/jiangqie_bdacode/' . $res;
		}

		return $this->make_success($qrcode_link);
	}

	private function _formatPost($post)
	{
		$data = [
			'id' => $post->ID,
			'title' => $post->post_title,
			'thumbnail' => $this->get_one_post_thumbnail($post),
			'views' => (int) get_post_meta($post->ID, 'jiangqie_views', true),
			'time' => $this->time_beautify($post->post_date)
		];

		return $data;
	}

	/**
	 * 获取摘要
	 */
	private function _getExcerpt($post)
	{
		if ($post->post_excerpt) {
			return html_entity_decode(wp_trim_words($post->post_excerpt, 50, '...'));
		} else {
			$content = apply_filters('the_content', $post->post_content);
			return html_entity_decode(wp_trim_words($content, 50, '...'));
		}
	}
}
