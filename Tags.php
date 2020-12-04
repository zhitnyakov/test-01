<?php

class Tags extends CI_Controller
{
	private $user;

	public function __construct()
	{
		parent::__construct();

		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Allow-Headers: *");
		header('Content-Type: application/json');

		$this->load->model('Auth_model', 'auth');
		$this->user = $this->auth->check();

		if (!$this->user) {
			echo json_encode(['success' => false, 'errors' => ['auth error']]);
			die();
		}
	}

	public function index()
	{
		$this->load->database();

		$tables_to_search_tags_from = [
			'accounts',
			'cabs',
			'fb_campaigns',
			'fb_adsets',
			'fb_ads',
			'bundles',
		];

		$all_tags = [];
		$only_from = $_GET['only_from'] ?? null;
		if ($only_from == 'campaigns') {
			$only_from = 'fb_campaigns';
		} elseif ($only_from == 'adsets') {
			$only_from = 'fb_adsets';
		} elseif ($only_from == 'ads') {
			$only_from = 'fb_ads';
		}

		if ($only_from) {
			$tables_to_search_tags_from = [$only_from];
		}

		if ($this->user['role_id'] == 1) {
			$users_ids = array_column(
				$this->db->select('id')->where('status', 1)->get('users')->result_array(),
				'id'
			);
		} elseif ($this->user['role_id'] == 3) {
			$users_ids = array_column(
				$this->db->select('id')->where('status', 1)->where('teamlead_id', $this->user['id'])->get('users')->result_array(),
				'id'
			);
			$users_ids[] = $this->user['id'];
		} else {
			$users_ids = [$this->user['id']];
		}


        $this->load->driver('cache');

        //сформируем ключ для кеша из списка таблиц в которых будет призведен поиск и списка пользователей
        //отсортируем массив с id пользователей, что избежать формирования другого ключа на одном и том же списке пользователей
		sort($users_ids);
		// объеденяем все в один строковый ключ
        $cacheKey = implode(',', $tables_to_search_tags_from) . ":" . implode(',', $users_ids);
        //для кранкости ключа получаем его хеш
        $cacheKey = sha1($cacheKey);
        if (!$all_tags = $this->cache->memcached->get($cacheKey)) {
            foreach ($tables_to_search_tags_from as $table) {
                $items = $this->db->select('tags')
                    ->where_in('user_id', $users_ids)
                    ->get($table)
                    ->result_array();
                foreach ($items as $item) {
                    $item_tags = json_decode($item['tags'], true) ?? [];
                    foreach ($item_tags as $tag) {
                        if (!in_array($tag, $all_tags)) {
                            $all_tags[] = $tag;
                        }
                    }
                }
            }

            $this->cache->memcached->save($cacheKey, json_encode($all_tags), 300);
        } else {
            $all_tags = json_decode($all_tags, true) ?? [];
        }

        $all_tags = array_values(
            array_diff(
                $all_tags ?? [],
                json_decode($this->user['excluded_tags'], true) ?? []
            )
        );

		$this->api_response(
			[
				'data' => $all_tags,
			]
		);
	}
}
