<?php 
$insert_size = 250;
$portrait_url = '/wp-content/uploads/pad-portrait/%05d.png';
$fullimg_url = '/wp-content/uploads/pad-img/%05d.png';
class mySQLConn{
	function connect_sql($host, $user, $pass, $schema){
		// Create connection
		$conn = new mysqli($host, $user, $pass);
		// Check connection
		if ($conn->connect_error) {
			trigger_error('Connection failed: ' . $conn->connect_error);
			header( 'HTTP/1.0 403 Forbidden', TRUE, 403 );
			die('you cannot');
		}
		$conn->set_charset('utf8');
		$conn->select_db($schema);
		return $conn;
	}
	public $conn;
    function __construct() {
		include 'sql_param.php';
        $this->conn = $this->connect_sql($host, $user, $pass, $schema);
    }
    function __destruct() {
        $this->conn->close();
    }
}
$miru = new mySQLConn();
function default_fieldnames($entry){
	$fieldnames = array();
	foreach($entry as $field => $value){
		$fieldnames[] = $field;
	}
	return $fieldnames;
}

function recreate_table($data, $tablename, $fieldnames, $pk){
	global $miru;
	$sql = 'DROP TABLE IF EXISTS ' . $tablename;
	if($miru->conn->query($sql)){
		$sql = 'CREATE TABLE ' . $tablename . ' (';
		foreach($fieldnames as $field){
			$sql = $sql . $field . ' ';
			if(ctype_digit($data[0][$field])){
				if(strlen($data[0][$field]) > 8){
					$sql = $sql . 'BIG';
				}
				$sql = $sql . 'INT ';
			}else{
				$maxlen = 11;
				foreach($data as $entry){
					$len = strlen($entry[$field]);
					if($len > $maxlen){
						$maxlen = $len;
					}
				}
				$maxlen = $maxlen * 2;
				$sql = $sql . 'VARCHAR(' . $maxlen . ') ';
			}
			if($field == $pk){
				$sql = $sql . 'PRIMARY KEY,';
			}else{
				$sql = $sql . 'NOT NULL,';
			}
		}
		
		$sql = substr($sql, 0, -1) . ');';
		if(!$miru->conn->query($sql)){
			trigger_error('Table creation failed: ' . $miru->conn->error);
			return false;
		}
	}else{
		trigger_error('Drop table failed: ' . $miru->conn->error);
		return false;
	}
}
function populate_table($data, $tablename, $fieldnames){
	global $miru;
	global $insert_size;
	$sql = 'INSERT INTO ' . $tablename . ' (';
	$paramtype = '';
	foreach($fieldnames as $field){
		$sql = $sql . $field . ',';
		if(ctype_digit($data[0][$field])){
			$paramtype = $paramtype . 'i';
		}else{
			$paramtype = $paramtype . 's';
		}
	}
	$valueGroup = ' (' . substr(str_repeat('?,', sizeof($fieldnames)), 0, -1) . '),';
	$sql = substr($sql, 0, -1) . ') VALUES ';
	$sql_m = $sql . substr(str_repeat($valueGroup, $insert_size), 0, -1) . ';';
	$paramtype_m = str_repeat($paramtype, $insert_size);
	$stmt = $miru->conn->prepare($sql_m);
	$count = 0;
	$value_arr = array();
	foreach($data as $entry){
		foreach($fieldnames as $field){
			if(ctype_digit($data[0][$field]) && $entry[$field] == ''){
				$value_arr[] = '0';
			}else{
				$value_arr[] = $entry[$field];
			}
		}
		if(sizeof($value_arr) == strlen($paramtype_m)){
			$stmt->bind_param($paramtype_m, ...$value_arr);
			if(!$stmt->execute()){
				trigger_error('Insert failed: ' . $miru->conn->error);
				echo 'Insert failed: ' . $miru->conn->error;
			}else{
				$count += $insert_size;
			}
			$value_arr = array();
		}
	}
	$stmt->close();
	if(sizeof($value_arr) > 0){
		$remaining = sizeof($value_arr) / sizeof($fieldnames);
		$sql = $sql . substr(str_repeat($valueGroup, $remaining), 0, -1) . ';';
		$stmt = $miru->conn->prepare($sql);
		$stmt->bind_param(str_repeat($paramtype, $remaining), ...$value_arr);
		if(!$stmt->execute()){
			trigger_error('Insert failed: ' . $miru->conn->error);
			echo 'Insert failed: ' . $miru->conn->error;
		}else{
			$count += $remaining;
		}
		$value_arr = array();
		$stmt->close();
	}
	echo 'Imported ' . $count . ' records out of ' . sizeof($data) . ' to ' . $tablename . PHP_EOL;
}
function execute_select_stmt($stmt, $pk = NULL){
	global $miru;
	if(!$stmt->execute()){
		trigger_error($miru->conn->error . '[select]');
		return false;
	}
	$stmt->store_result();
	if($stmt->num_rows == 0){
		$stmt->free_result();
		return array();
	}
	$fields = array();
	$row = array();
	$meta = $stmt->result_metadata(); 
	while($f = $meta->fetch_field()){
		$fields[] = & $row[$f->name];
	}
	call_user_func_array(array($stmt, 'bind_result'), $fields);
	$res = array();
	while ($stmt->fetch()){ 
		foreach($row as $key => $val){
			$c[$key] = $val; 
		} 
		if($pk != NULL){
			if(array_key_exists($c[$pk], $res)){
				$res[$c[$pk]][] = $c;
			}else{
				$res[$c[$pk]] = $c;
			}
		}else{
			$res[] = $c; 
		}
	}
	return $res;
}
function single_param_stmt($query, $q_str){
	global $miru;
	$stmt = $miru->conn->prepare($query);
	if (!$stmt){
		echo $query . PHP_EOL;
	}
	$stmt->bind_param('s', $q_str);
	$res = execute_select_stmt($stmt);
	$stmt->close();
	return $res;
}
function query_monster($q_str, $region = 'jp'){
	global $miru;
	$q_str = trim($q_str);
	if($q_str == ''){
		return false;
	}
	$region_key = 'monster_id';
	if ($region != ''){
		$region_key = 'monster_no_'.$region;
	}
	$base_query = 'SELECT
			monsters.monster_id,
			monsters.monster_no_jp,
			monsters.monster_no_na,
			monsters.rarity,
			monsters.name_ja,
			monsters.name_en,
			monster_name_overrides.name_en AS name_en_override
		FROM monsters LEFT JOIN monster_name_overrides ON monsters.monster_id=monster_name_overrides.monster_id ';
	if(ctype_digit($q_str)){
		$sql = $base_query.'WHERE monsters.'.$region_key.'=? ORDER BY monsters.monster_id DESC';
		$res = single_param_stmt($sql, $q_str);
		if(sizeof($res) > 0){
			if(sizeof($res) > 1){
				foreach($res as $r){
					if (($region == 'na' && $r['monster_id'] !== $r['monster_no_na']) ||
							($region == 'jp' && $r['monster_id'] === $r['monster_no_jp'])){
					return $r;
					}
				}
			}
			return $res[0];
		}
	}
	$matching = array(
		array('=?',$q_str),
		array(' LIKE ?', $q_str . '%'),
		array(' LIKE ?', '%' . $q_str . '%')
	);
	$query = array();
	if(!mb_check_encoding($q_str, 'ASCII')){
		$query['WHERE monsters.name_ja'] = ' ORDER BY monsters.monster_id DESC';
	}else{
		// $query['LEFT JOIN computedNames ON monsters.'.$region_key.'=computedNames.MONSTER_NO WHERE COMPUTED_NAME'] = ' ORDER BY LENGTH(COMPUTED_NAME) ASC';
		$query['WHERE name_en'] = ' ORDER BY monsters.monster_id DESC';
		$query['WHERE name_en_override'] = ' ORDER BY monsters.monster_id DESC';
	}
	foreach($matching as $m){
		foreach($query as $q => $o){
			$res = single_param_stmt($base_query.$q . $m[0] . $o, $m[1]);
			if(sizeof($res) > 0){
				if(sizeof($res) > 1){
					foreach($res as $r){
						if (($region == 'na' && $r['monster_id'] !== $r['monster_no_na']) ||
								($region == 'jp' && $r['monster_id'] === $r['monster_no_jp'])){
								return $r;
						}
					}
				}
				return $res[0];
			}
		}
	}
	return false;
}
function select_awakenings($id){
	global $miru;
	$sql = 'SELECT is_super, awoken_skill_id FROM awakenings WHERE monster_id=?;';
	$stmt = $miru->conn->prepare($sql);
	$stmt->bind_param('i', $id);
	$res = execute_select_stmt($stmt);
	$stmt->free_result();
	$stmt->close();
	if(sizeof($res) == 0){
		return false;
	}else{
		return $res;
	}
}
function select_evolutions($id){
	global $miru;
	$sql = 'SELECt from_id, to_id FROM evolutions where from_id=?';
	$stmt = $miru->conn->prepare($sql);
	$stmt->bind_param('i', $id);
	$res = execute_select_stmt($stmt);
	$stmt->free_result();
	$stmt->close();
	if(sizeof($res) == 0){
		return array();
	}else{
		$evo_ids = array();
		foreach($res as $r){
			$evo_ids[] = $r['to_id'];
		}
		foreach($evo_ids as $eid){
			$evo_ids = array_merge($evo_ids, select_evolutions($eid));
		}
		sort($evo_ids);
		return $evo_ids;
	}
}
function select_card($id){
	global $miru;
	$sql = 'SELECT 
		monsters.monster_id,
		monsters.monster_no_jp,
		monsters.monster_no_na,
		monsters.name_ja,
		monsters.name_en AS name_en_base,
		monster_name_overrides.name_en AS name_en_override,
		monster_name_overrides.is_translation,
		monsters.hp_max,
		monsters.atk_max,
		monsters.rcv_max,
		monsters.level,
		monsters.limit_mult,
		monsters.attribute_1_id,
		monsters.attribute_2_id,
		monsters.type_1_id,
		monsters.type_2_id,
		monsters.type_3_id, 
		monsters.inheritable,
		leader_skills.desc_en AS ls_desc_en,
		leader_skills.max_hp AS lead_hp, 
		leader_skills.max_atk AS lead_atk,
		leader_skills.max_rcv AS lead_rcv,
		leader_skills.max_shield AS lead_shield,
		leader_skills.max_combos AS max_combos,
		leader_skills.bonus_damage AS bonus_damage,
		leader_skills.mult_bonus_damage AS mult_bonus_damage,
		active_skills.desc_en AS as_desc_en,
		active_skills.turn_max,
		active_skills.turn_min
	FROM monsters
	LEFT JOIN monster_name_overrides ON monsters.monster_id=monster_name_overrides.monster_id
	LEFT JOIN leader_skills ON monsters.leader_skill_id=leader_skills.leader_skill_id
	LEFT JOIN active_skills ON monsters.active_skill_id=active_skills.active_skill_id
	WHERE monsters.monster_id=?;';
	$stmt = $miru->conn->prepare($sql);
	if (!$stmt){
		echo $sql . PHP_EOL;
	}
	$stmt->bind_param('i', $id);
	$res = execute_select_stmt($stmt);
	$stmt->free_result();
	$stmt->close();
	if(sizeof($res) == 0){
		return false;
	}else{
		$res = $res[0];
	}
	if ($res['name_en_override'] != NULL && ($res['is_translation'] == 0 || $res['name_en_base'] == $res['name_ja'])){
		$res['name_en'] = $res['name_en_override'];
	} else {
		$res['name_en'] = $res['name_en_base'];
	}
	foreach(array('1', '2') as $i){
		if ($res['attribute_'.$i.'_id'] !== NULL){
			$res['attribute_'.$i.'_id'] = strval(intval($res['attribute_'.$i.'_id'])+1);
		}
	}
	$res['awakenings'] = select_awakenings($id);
	//$res['EVOLUTIONS'] = select_evolutions($id);
	
	return $res;
}
function get_monster_exchange($server, $limited = TRUE){
	$sql = 'SELECT server_id, target_monster_id, required_monster_ids, required_count, start_timestamp, end_timestamp, permanent FROM exchanges WHERE end_timestamp>unix_timestamp() AND server_id=?';
	if ($limited){
		$sql .= ' AND permanent=0';
	}
	$server_id = $server == 'na' ? 1 : 0;
	$res = single_param_stmt($sql, $server_id);
	if(sizeof($res) > 0){
		foreach($res as $k => $r){
			$res[$k]['required_monster_ids'] = explode(',', str_replace(array('(', ')'), '', $r['required_monster_ids']));
		}
	}
	return $res;
}
function get_egg_machine_lineups($server = NULL) {
	$sql = 'SELECT server_id, egg_machine_type_id, start_timestamp, end_timestamp, machine_row, machine_type, cost, name, contents FROM egg_machines WHERE end_timestamp>unix_timestamp()';
	if ($server != NULL){
		$sql .= ' AND server_id=?';
		$server_id = $server == 'na' ? 1 : 0;
		$res = single_param_stmt($sql, $server_id);
	}else{
		$sql .= ' ORDER BY server_id ASC';
		global $miru;
		$stmt = $miru->conn->prepare($sql);
		$res = execute_select_stmt($stmt);
		$stmt->close();
	}
	if(sizeof($res) > 0){
		foreach($res as $k => $r){
			$contents = array();
			foreach(json_decode($r['contents'], TRUE) as $i => $c){
				$contents[str_replace(array('(', ')'), '', $i)] = $c;
			}
			$res[$k]['contents'] = $contents;
		}
	}
	return $res;
}
function grab_img_if_exists($url, $id, $savedir, $override = false){
	$saveto = sprintf($savedir, $id);
	if (!file_exists(dirname($savedir))) {
		mkdir(dirname($savedir), 0777, true);
	}else if(file_exists($saveto) && !$override){
		return true;
	}
	$ch = curl_init(sprintf($url, $id));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
	$raw = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if($httpCode >= 200 && $httpCode < 300){
		$fp = fopen($saveto ,'w');
		fwrite($fp, $raw);
		fclose($fp);
		return true;
	}
	return false;
}
function card_icon_img($id, $name = '', $region = 'jp', $w = '63', $h = '63', $href = 'http://www.puzzledragonx.com/en/monster.asp?n='){
	global $portrait_url;
	return array(
		'html' => '<a href="' . $href . $id . '"><img src="' . sprintf($portrait_url, $id) . '" title="' . $id . ($name == '' ? '' : '-' . $name) . '" width="' . $w . '" height="' . $h . '"/></a>', 
		'shortcode' => '[pdx id=' . $id . ($w == $h && $w == '63' ? '' : ' w=' . $w . ' h=' . $h) . ']');
}
function lb_stat($base, $mult){
	return round($base * (100 + $mult)/100);
}
function weighted($data, $level){
	if($level == 99){
		return round($data['hp_max'] / 10 + $data['atk_max'] / 5 + $data['rcv_max'] / 3);
	}else if ($level == 110){
		return round(lb_stat($data['hp_max'], $data['limit_mult']) / 10 + lb_stat($data['atk_max'], $data['limit_mult']) / 5 + lb_stat($data['rcv_max'], $data['limit_mult']) / 3);
	}
}
function stat_table($data, $plus = false){
	if($data['limit_mult'] == 0){
		return '<table class="card-stats-table"><thead><tr><td>Stat</td><td>Lv.' . $data['level'] . '</td><td>+297</td></tr></thead><tbody><tr><td>HP</td><td>' . $data['hp_max'] . '</td><td>' . ($data['hp_max'] + 990) . '</td></tr><tr><td>ATK</td><td>' . $data['atk_max'] . '</td><td>' . ($data['atk_max'] + 495) . '</td></tr><tr><td>RCV</td><td>' . $data['rcv_max'] . '</td><td>' . ($data['rcv_max'] + 297) . '</td></tr></tbody></table>';
	}else{
		if($plus){
			return '<table class="card-stats-table"><thead><tr><td>Stat</td><td>Lv.99 (+297)</td><td>Lv.110 (+297)</td></tr></thead><tbody><tr><td>HP</td><td>' . $data['hp_max'] . ' (' . ($data['hp_max'] + 990) . ')</td><td>' . lb_stat($data['hp_max'], $data['limit_mult']) . ' (' . (lb_stat($data['hp_max'], $data['limit_mult']) + 990) . ')</tr><tr><td>ATK</td><td>' . $data['atk_max'] . ' (' . ($data['atk_max'] + 495) . ')</td><td>' . lb_stat($data['atk_max'], $data['limit_mult']) . ' (' . (lb_stat($data['atk_max'], $data['limit_mult']) + 495) . ')</td></tr><tr><td>RCV</td><td>' . $data['rcv_max'] . ' (' . ($data['rcv_max'] + 297) . ')</td><td>' . lb_stat($data['rcv_max'], $data['limit_mult']) . ' (' . (lb_stat($data['rcv_max'], $data['limit_mult']) + 297) . ')</td></tr></tbody></table>';
		}else{
			return '<table class="card-stats-table"><thead><tr><td>Stat</td><td>Lv.99</td><td>Lv.110</td></tr></thead><tbody><tr><td>HP</td><td>' . $data['hp_max'] . '</td><td>' . lb_stat($data['hp_max'], $data['limit_mult']) . '</td></tr><tr><td>ATK</td><td>' . $data['atk_max'] . '</td><td>' . lb_stat($data['atk_max'], $data['limit_mult']) . '</td></tr><tr><td>RCV</td><td>' . $data['rcv_max'] . '</td><td>' . lb_stat($data['rcv_max'], $data['limit_mult']) . '</td></tr></tbody></table>';
		}
	}
}
function att_orbs($att1, $att2){
	return array('<img width="20" height="20" src="/wp-content/uploads/pad-orbs/' . $att1 . '.png">' . ($att2 == 0 ? '' : '<img width="20" height="20" src="/wp-content/uploads/pad-orbs/' . $att2 . '.png">'), '[orb id=' . $att1 . ']' . ($att2 == NULL ? '' : '[orb id=' . $att2 . ']'));
}
$type = array('Evolve', 'Balanced', 'Physical', 'Healer', 'Dragon', 'God', 'Attacker', 'Devil', 'Machine', '', '', '', 'Awoken', '', 'Enhance', 'Vendor');
function typings($t1, $t2, $t3){
	global $type;
	return $type[$t1] . ($t2 == 0 ? '' : ' / ' . $type[$t2]) . ($t3 == 0 ? '' : ' / ' . $type[$t3]);
}
function typing_killer_tooltip($t1, $t2, $t3){
	global $type;
	$types = array_filter(array($t1, $t2, $t3));
	$types_out = array();
	$latents = array();
	foreach($types as $t){
		$add = array();
		$types_out[] = $type[$t];
		if(!in_array('All', $latents)){
			switch($t){
				case 2: case 4: $add = array('Machine', 'Healer'); break; //dragon phys
				case 1: $latents = array('All'); break; //balance
				case 3: $add = array('Dragon', 'Attacker'); break; //healer
				case 6: $add = array('Devil', 'Physical'); break; //attacker
				case 5: $add = array('Devil'); break; //god
				case 7: $add = array('God'); break; //devil
				case 8: $add = array('God', 'Balanced'); break; //machine
			}
			$latents = array_unique(array_merge($latents, $add));
		}
	}
	$type_txt = implode(' / ', $types_out);
	if(sizeof($latents) == 0){
		return array($type_txt, $type_txt);
	}else{
		$latent_txt = implode(' / ', $latents);
		return array('<span class="su-tooltip" data-close="no" data-behavior="hover" data-my="bottom center" data-at="top center" data-classes="su-qtip qtip-light su-qtip-size-default" data-title="" data-hasqtip="0" oldtitle="Available Killers: ' . $latent_txt . '" title="" aria-describedby="qtip-0">' . $type_txt . '</span>', '[shortcode_tooltip style="light" content="Available Killers: ' . $latent_txt . '"]' . $type_txt . '[/shortcode_tooltip]');
	}
}
function lead_mult($data){
	return '['.pow($data['lead_hp'], 2).'/'.pow($data['lead_atk'], 2).'/'.pow($data['lead_rcv'], 2).($data['lead_shield'] == 0 ? '' : ', ' . round(100 * (1 - pow((1-$data['lead_shield']), 2)), 2) . '%').']'. (($data['max_combos'] || $data['bonus_damage'] || $data['mult_bonus_damage'] > 0) ? (' ['. ( implode( array_filter(array($data['max_combos'] ? '+'. 2 * $data['max_combos'] .'c' : false, $data['bonus_damage'] ? ($data['bonus_damage'] > 999999 ? round(2 * $data['bonus_damage'] / 1000000) . 'M' : ($data['bonus_damage'] > 999 ? round(2 * $data['bonus_damage'] / 1000) . 'k' : 2 * $data['bonus_damage'])) . ' fua' : false, $data['mult_bonus_damage'] > 0 ? 'fua': false) ) , '/') ) .']') : '');
}
function awake_icon($id, $w = '31', $h = '32', $awake_url = '/wp-content/uploads/pad-awks/', $info_url = 'http://www.puzzledragonx.com/en/awokenskill.asp?s='){
	return array('html' => '<a href="' . $info_url . $id . '"><img src="' . $awake_url . $id . '.png" width="' . $w. '" height="' . $h. '"/></a>', 'shortcode' => '[awk id=' . $id . ($w != '31' ? ' w=' . $w . ' h=' . $h : '') . ']');
}
function awake_list($awakenings, $w = '31', $h = '32'){
	if(!$awakenings){
		return array('', '');
	}
	global $aw;
	$awakes = array('<div>', '');
	$supers = array('<div>', '');
	
	foreach($awakenings as $awk){
		$awks = awake_icon($awk['awoken_skill_id']);
		if($awk['is_super'] == 1){
			$supers[0] = $supers[0] . $awks['html'];
			$supers[1] = $supers[1] . $awks['shortcode'];
		}else{
			$awakes[0] = $awakes[0] . $awks['html'];
			$awakes[1] = $awakes[1] . $awks['shortcode'];
		}
	}
	$awakes[0] = $awakes[0] . '</div>';
	$supers[0] = $supers[0] . '</div>';
	return array($awakes[0] . $supers[0], $awakes[1]  . '<br/>' . PHP_EOL . $supers[1]);
}
function get_youtubue_link($name_ja){
	$url = 'www.youtube.com/results?search_query=パズドラ+'.$name_ja;
	return '<a href="https://'.$url.'" target="_blank">'.$name_ja.'</a>';
}
function get_card_grid($id, $region = 'jp', $right_side_table = false, $headings = false){
	global $fullimg_url;
	global $fullimg_url_na;
	$data = select_card($id);
	if(!$data){
		return array('html' => 'NO CARD FOUND', 'shortcode' => 'NO CARD FOUND');
	}

	$atts = att_orbs($data['attribute_1_id'], $data['attribute_2_id']);
	$types = typing_killer_tooltip($data['type_1_id'], $data['type_2_id'], $data['type_3_id']);
	$awakes = awake_list($data['awakenings']);
	
	$stat1 = '';
	$stat2 = '';
	if($right_side_table){
		$stat2 = stat_table($data, true) . '<br/>' . PHP_EOL;
	}else{
		$stat1 = stat_table($data, true);
	}
	$name_arr = explode(', ', $data['name_en']);
	$head = '';
	if ($headings == 'yes'){
		$head = '<h2 id="card_' . $id . '">' . end($name_arr) . '</h2>' . PHP_EOL . '<div class="cardgrid">';
		$head_shortcode = '<h2 id="card_' . $id . '">' . end($name_arr) . '</h2>' . PHP_EOL .  '[cardgrid]';		
	} else if ($headings == 'tocOnly'){
		$head = '<div class="cardgrid" id="card_' . $id . '">';
		$head_shortcode = '[cardgrid card_id=' . $id . ']';
	} else {
		$head = '<div class="cardgrid">';
		$head_shortcode = '[cardgrid]';
	}
	$monster_id = $data['monster_id'];
	$monster_no = $data['monster_no_'.$region];
	$inherit = $data['inheritable'] == 0 ? 'Not Inheritable' : 'Inheritable';
	return array(
		'html' => $head . '<div class="col1"><img src="'. sprintf($fullimg_url, $monster_id) . '"/>' . $stat1 . '</div><div class="col-cardinfo"><p>[' . $monster_no . ']<b>' . $atts[0] . htmlentities($data['name_en']) . ($data['name_en'] != $data['name_ja'] ? '<br/>' . get_youtubue_link($data['name_ja']) : '') . '</b></p><p>' . $types[0] . '</p>' . $awakes[0] . $stat2 . '<br/><p><u>Active Skill:</u> <b>(' . $data['turn_max'] . ' &#10151; ' . $data['turn_min'] . ')</b> <span class="card-inherit">' . $inherit . '</span> <br> ' . htmlentities($data['as_desc_en']) . '</p>' . (strlen($data['ls_desc_en']) == 0 ? '' : '<p><u>Leader Skill:</u> <b>' . lead_mult($data) . '</b><br/> ' . htmlentities($data['ls_desc_en']) . '</p>') . '</div></div>',
		'shortcode' => $head_shortcode . PHP_EOL . '[col1][pdxp id=' . $monster_no . ' r=' . $region . ']' . $stat1 . '[/col1]' . PHP_EOL . '[col2][' . $monster_no . ']<b>' . $atts[1] . htmlentities($data['name_en']) . ($data['name_en'] != $data['name_ja'] ? '<br/>' .get_youtubue_link($data['name_ja']) : '') . '</b><br/><span class="card-type">' . PHP_EOL . $types[1] . '</span><br/>' . PHP_EOL . $awakes[1] . '<br/><br/>' . PHP_EOL . $stat2 . '<u>Active Skill:</u> <b>(' . $data['turn_max'] . ' &#10151; ' . $data['turn_min'] . ')</b> <span class="card-inherit">' . $inherit . '</span> <br/>' . htmlentities($data['as_desc_en'] . '<br/>' . PHP_EOL) . (strlen($data['ls_desc_en']) == 0 ? '' : '<br/><br/>' . PHP_EOL .'<u>Leader Skill:</u> <b>' . lead_mult($data) . '</b><br/>' . PHP_EOL . htmlentities($data['ls_desc_en']) ) . PHP_EOL . '[/col2]' . PHP_EOL . '[/cardgrid]');
}
function get_card_summary($id, $region = 'jp'){
	$data = select_card($id);
	if(!$data){
		return array('html' => 'NO CARD FOUND', 'shortcode' => 'NO CARD FOUND');
	}

	$monster_no = $data['monster_no_'.$region];
	$card = card_icon_img($data['monster_id'], $data['name_en'], $region);
	$awakes = awake_list($data['awakenings']);
	if($data['limit_mult']){
		$stats = ' <p><b>Lv.110</b> <b>HP</b> ' . lb_stat($data['hp_max'], $data['limit_mult']) . ' <b>ATK</b> ' . lb_stat($data['atk_max'], $data['limit_mult']) . ' <b>RCV</b> ' . lb_stat($data['rcv_max'], $data['limit_mult']) . ' (' . weighted($data, 110) . ' weighted)</p>';
	}else{
		$stats = ' <p><b>Lv.99</b> <b>HP</b> ' . $data['hp_max'] . ' <b>ATK</b> ' . $data['atk_max'] . ' <b>RCV</b> ' . $data['rcv_max'] . ' (' . weighted($data, 99) . ' weighted)</p>';
	}
	
	return array(
		'html' => '<h2 id="card_' . $monster_no . '">' . $card['html'] . ' ' . htmlentities($data['name_en']) .'</h2>' . $stats . $awakes[0], 
		'shortcode' => '<h2 id="card_' . $monster_no . '">' . $card['shortcode'] . ' ' . htmlentities($data['name_en']) .'</h2>' . $stats . $awakes[1]);
}
function get_lb_stats_row($id, $sa, $region = 'jp'){
	$data = select_card($id);
	if(!$data){
		return array('html' => '', 'shortcode' => '');
	}

	$card = card_icon_img($data['monster_id'], $data['name_en'], $region);
	$supers = array('','');
	if($sa){
		global $aw;
		$w = '31';
		$h = '32';
		$awakenings = $data['awakenings'];
		$info_url = 'http://www.puzzledragonx.com/en/awokenskill.asp?s=';
		$awake_url = '/wp-content/uploads/pad-awakenings/';
		$supers = array('<td>', '<td>');
		foreach($awakenings as $awk){
			if($awk['is_super'] == 1){
				$aid = $awk['awoken_skill_id'];
				$supers[0] = $supers[0] . '<a href="' . $info_url . $aid . '"><img src="' . $awake_url . $aid . '.png" width="' . $w. '" height="' . $h. '"/></a>';
				$supers[1] = $supers[1] . '[awk id=' . $aid . ' w=' . $w . ' h=' . $h . ']';
			}
		}
		$supers[0] = $supers[0] . '</td>';
		$supers[1] = $supers[1] . '</td>';
	}
	
	$stats = '<td>' . weighted($data, 110) . '</td><td>' . lb_stat($data['hp_max'], $data['limit_mult']) . '</td><td>' . lb_stat($data['atk_max'], $data['limit_mult']) . '</td><td>' . lb_stat($data['rcv_max'], $data['limit_mult']) . '</td>';
	
	return array(
		'html' => '<tr><td>' . $card['html'] . '</td>' . $stats . $supers[0] . '</tr>', 
		'shortcode' => '<tr><td>' . $card['shortcode'] . '</td>' . $stats . $supers[1] . '</tr>'
	);
}
function get_egg($rare){
	$url = '/wp-content/uploads/pad-eggs/';
	if(is_numeric($rare)){
		$rare = intval($rare);
		$img_name = '';
		$sc_name = '';
		if($rare > 5){
			$img_name = 'Diamond';
			$sc_name = 'dia';
		}else if($rare == 5){
			$img_name = 'Gold1';
			$sc_name = 'gold';
		}else if($rare == 4){
			$img_name = 'Silver1';
			$sc_name = 'silver';
		}else{
			$img_name = 'Star';
			$sc_name = 'star';
		}
		return array('html' => '<img src="' . $url . $img_name . '.png" width="30"/>', 'shortcode' => '[egg id=' . $sc_name . ' w=30]');
	}else{
		return array('html' => '[EGG]', 'shortcode' => '[EGG]');
	}
}
function search_ids($input_str, $region = 'jp', $delim = "\n"){
	$ids = array();
	foreach(explode($delim, $input_str) as $line){
		$mon = query_monster(trim($line), $region);
		if($mon){
			$ids[] = array($mon['monster_id'], $mon['monster_no_'.$region]);
		}
	}
	return $ids;
}

function get_button_info($id, $button_type_name){
	global $portrait_url;
	$data = select_card($id);
	if(!$data){
		return array('html' => 'NO CARD FOUND', 'shortcode' => 'NO CARD FOUND');
	}
	$card = card_icon_img($data['monster_id'], $data['name_en']);	
	return array(
		'html' => '<tr><td> <h2 id="card_' . $data['monster_id'] . '">' . $card['html'] . '</h2></td>' . '<td>' . htmlentities($button_type_name) . '</td></tr>');
}

function retrieve_some_buttons($button_type_id, $button_type_name)	{	
	global $miru;
	$sql = 'SELECT buttonList.MONSTER_NO from buttonList WHERE buttonList.SKILL_TYPE=? AND buttonList.INHERITABLE = 1;';
	$stmt = $miru->conn->prepare($sql);
	$stmt->bind_param('i', $button_type_id);
	$res = execute_select_stmt($stmt);
	$stmt->free_result();
	$stmt->close();
	echo ('<h1><span id=' . $button_type_name . '>' . $button_type_name . '</span></h1><table><thead><tr><td>Card</td><td>Active Skill</td></tr></thead><tbody>');
	foreach ($res as $id)	{
		echo (get_button_info($id['MONSTER_NO'], $button_type_name)['html']);
	}
echo ('</tbody></table>');
}
?>
