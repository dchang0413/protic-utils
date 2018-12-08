<!DOCTYPE html>
<html>
<body>
<?php
include 'miru_common.php';
include 'sql_param.php';
$conn = connect_sql($host, $user, $pass, $schema);
$input_str = array_key_exists('input', $_POST) ? $_POST['input'] : '';
$om = array_key_exists('o', $_POST) ? $_POST['o'] : 'html';
?>
<form method="post">
Output Mode: <input type="radio" name="o" value="html" <?php if($om == 'html'){echo 'checked';}?>> HTML <input type="radio" name="o" value="shortcode" <?php if($om == 'shortcode'){echo 'checked';}?>> Shortcode <input type="submit"><br/>
<p>Paste In-Game Lineup Here:</p>
<textarea name="input" style="width:80vw;height:20vh;">
<?php echo $input_str;?>
</textarea>
</form>
<?php
$time_start = microtime(true);
$byrates = array('html' => array(), 'shortcode' => array());
foreach(explode(PHP_EOL, $input_str) as $line){
	$parts = explode('    ', $line);
	if(sizeof($parts) < 2){
		continue;
	}
	$name = $parts[sizeof($parts)-2];
	$rate = $parts[sizeof($parts)-1];
	if(!array_key_exists($rate, $byrates)){
		$byrates[$rate] = array();
	}
	$mon = query_monster($conn, $name);
	if($mon){
		if($mon['MONSTER_NO'] > 10000){ // crows in computedNames
			$mon['MONSTER_NO'] = $mon['MONSTER_NO'] - 10000;
		}
		$card = card_icon_img($mon['MONSTER_NO'], $mon['TM_NAME_US']);
		$byrates['html'][$rate][] = $card['html'];
		$byrates['shortcode'][$rate][] = $card['shortcode'];
	}
}
$conn->close();
echo '<p>Total execution time in seconds: ' . (microtime(true) - $time_start) . '</p>';
$output_arr = array('html' => array(), 'shortcode' => array());
foreach($byrates as $mode => $rate_group){
	$title = $mode == 'html' ? '<span class="su-highlight" style="background:#ddff99;color:#000000">PLACEHOLDER PLEASE CHANGE</span>' : '[shortcode_highlight]PLACEHOLDER PLEASE CHANGE[/shortcode_highlight]';
	foreach($rate_group as $rate => $out){
		$output_arr[$mode][] = '<strong>' . $title . ' | ' . $rate . ' each, ' . sizeof($out) * floatval(str_replace('%', '', $rate)) . '% total </strong><br/><span>' . implode(' ', $out) . '</span>';
	}
}
?>
<p>Output</p>
<?php echo '<textarea style="width:80vw;height:20vh;" readonly>' . implode(($om == 'html' ? '<br/>' : PHP_EOL), $output_arr[$om]) . '</textarea>'; ?>
<p>Preview</p>
<?php echo '<div>' . implode('<br/>', $output_arr['html']) . '</div>'; ?>

</body>
</html>