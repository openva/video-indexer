<?php


$video = mysql_fetch_array($result);

/*
 * Get a list of all bills that were addressed on this date.
 */
$sql = 'SELECT DISTINCT bills_status.bill_id AS id, bills.number
		FROM bills_status
		LEFT JOIN bills
			ON bills_status.bill_id = bills.id
		WHERE bills_status.date = "' . $video['date'] . '"';
$result = mysql_query($sql);
if (mysql_num_rows($result) == 0)
{
	die('Cannot resolve bill number chyrons for '.$video['date'].': no bills were found in '
		.'bills_status for that date.');
}

/*
 * Build up an array of bills, using the ID as the key and the number as the content.
 */
while ($bill = mysql_fetch_array($result))
{
	$bills[$bill{id}] = $bill['number'];
}

/*
 * Get a list of all bills that are in the legislature.
 */
$sql = 'SELECT DISTINCT id, number
		FROM bills
		WHERE session_id=
			(SELECT session_id
			FROM sessions
			WHERE ' . $video['date'] . ' > date_started
			AND ' . $video['date'] . ' < date_ended)';
$result = mysql_query($sql);
if (mysql_num_rows($result) > 0)
{

	/*
	 * Build up an array of bills, using the ID as the key and the number as the content.
	 */
	while ($bill = mysql_fetch_array($result))
	{
		$all_bills[$bill{id}] = $bill['number'];
	}
	
}

/*
 * Step through each bill chyron.
 */
$sql = 'SELECT id, raw_text
		FROM video_index
		WHERE file_id=' . $video['file_id'] . ' AND type="bill" AND linked_id IS NULL
		ORDER BY time ASC';

$result = mysql_query($sql);
while ($chyron = mysql_fetch_array($result))
{
	
	/*
	 * Strip out any spaces in the bill number -- just compare the bills straight up. Although
	 * bill numbers in the chyrons have spaces between the prefix ("HB") and the number ("1"),
	 * the OCR software doesn't always catch that. Better to just ignore the spaces entirely.
	 */
	$chyron['raw_text'] = str_replace(' ', '', $chyron['raw_text']);
	
	/*
	 * Also, we're dealing with this in lower case.
	 */
	$chyron['raw_text'] = strtolower($chyron['raw_text']);
	
	/*
	 * Make any obvious corrections to mistakes that tend to occur with OCR software.
	 */
	if (
		(substr($chyron['raw_text'], 0, 2) == 's8')
		||
		(substr($chyron['raw_text'], 0, 2) == '58')
		||
		(substr($chyron['raw_text'], 0, 2) == 'ss')
		||
		(substr($chyron['raw_text'], 0, 2) == '$8')
	)
	{
		$chyron['raw_text'] = 'sb' . substr($chyron['raw_text'], 2);
	}
	elseif (substr($chyron['raw_text'], 0, 3) == 'sir')
	{
		$chyron['raw_text'] = 'sj' . substr($chyron['raw_text'], 3);
	}
	elseif (
		(substr($chyron['raw_text'], 0, 3) == 'pur')
		||
		(substr($chyron['raw_text'], 0, 3) == 'fur')
		||
		(substr($chyron['raw_text'], 0, 3) == 'i-ur')
	)
	{
		$chyron['raw_text'] = 'hj' . substr($chyron['raw_text'], 3);
	}
	
	/*
	 * If there is a direct match with a bill dealt with on that day, insert it.
	 */
	$bill_id = array_search($chyron['raw_text'], $bills);
	if ( ($bill_id !== FALSE) && !empty($bill_id) )
	{
		echo '<li>' . $chyron['raw_text'] . ' matched to ' . $bills[$bill_id] . ' (' . $bill_id . ')</li>';
		insert_match($bill_id, $chyron['id']);
	}
	
	/*
	 * If we couldn't match it with a bill dealt with on that day, see if we can match it with
	 * any bill introduced that year. This helps to allow bills to be recognized in spite of
	 * legislative recordkeeping errors.
	 */
	else
	{
		$bill_id = array_search($chyron['raw_text'], $all_bills);
		if ( ($bill_id !== FALSE) && !empty($bill_id) )
		{
			echo '<li>' . $chyron['raw_text'] . ' matched to ' . $bills[$bill_id] . ' (' . $bill_id . ')</li>';
			insert_match($bill_id, $chyron['id']);
		}
	}
}

# If any single unresolved bill chyrons are found that are surrounded by resolved chyrons that
# are resolved on both sides, then we just fill in that gap with the obvious chyron, which is
# the bill number on either side of it.
$sql = 'SELECT id, time
		FROM video_index
		WHERE file_id = ' . $video['file_id'] . '
		AND TYPE = "bill"
		AND linked_id IS NULL';
$result = mysql_query($sql);
if (mysql_num_rows($result) > 0)
{

	while ($unresolved = mysql_fetch_array($result))
	{
	
		/*
		 * Retrieve a list of linked IDs present for fifteen seconds on either side of this
		 * unknown chyron.
		 */
		$sql = 'SELECT DISTINCT linked_id
				FROM video_index
				WHERE file_id = ' . $video['file_id'] . ' AND type="bill" AND linked_id IS NOT NULL
				AND
				(
					(TIMEDIFF("' . $unresolved['time'] . '", time)<=15)
					AND
					(TIMEDIFF("' . $unresolved['time'] . '", time)>=-15)
				)';
		$result2 = mysql_query($sql);
		
		/*
		 * If we've got just one row—which is to say that there's only one bill discussed in this
		 * thirty-second window—then we'll take it.
		 */
		if (mysql_num_rows($result2) == 1)
		{
			$resolved = mysql_fetch_array($result2);
			insert_match($resolved['linked_id'], $unresolved['id']);
		}
		
	}
	
}
