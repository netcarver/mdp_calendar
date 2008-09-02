<?php

$plugin=array(
'name'=>'mdp_calendar',
'version'=>'1.1',
'author'=>'Marshall Potter',
'author_uri'=>'http://www.outoverthevoid.com/',
'description'=>'Creates a monthly calendar view of posted articles.',
'type'=>'0',
);

@include_once('../zem_tpl.php');

# --- BEGIN PLUGIN CODE ---

// Handle the conversion from 'date' to 'month'
// thank you fri
if( $date = gps('date') ) {
	$_GET['month'] = $date;
}

function mdp_calendar_large($atts)
{
	return mdp_calendar($atts,false);
}

function mdp_calendar_small($atts)
{
	return mdp_calendar($atts,true);
}

	// use one function, with a subtle switch to do the same thing as two
	function mdp_calendar($atts,$small=false)
	{
		$atts = lAtts(array(
			'time' => 'past', // past, future, any
			'displayfuture' => 0, // DEPRECATED, use "time" instead
			'category' => '',
			'section' => '',
			'author' => '',
			'static' => '',
			'class' => '',
			'id' => '',
			'summary' => '',
			'month' => '',
			'year' => '',
			'dayformat' => 'ABBR',
			'firstday' => 0,
			'spandays' => 0,
			),$atts);

		if( $atts['displayfuture'] ) { $atts['time'] = "any"; } // backwards compatability
		$future = ( $atts['time'] != "past" ) ? true : false; // Determine heading style

		$dates = mdp_calendar_calcDates($atts['year'],$atts['month'],$atts['static'],$atts['id']);
		$events = mdp_calendar_getEvents($dates,$atts);

		if( $small ) {
			$calendar = new MDP_Small_Calendar($dates['year'],$dates['month'],$events,$atts['section'],$atts['category']);
		} else {
			$calendar = new MDP_Calendar($dates['year'],$dates['month'],$events);
		}
		$calendar->setTableID($atts['id']);
		$calendar->setTableClass($atts['class']);
		$calendar->setTableSummary($atts['summary']);
		$calendar->setDayNameFormat($atts['dayformat']);
		$calendar->setFirstDayOfWeek($atts['firstday']);
		return $calendar->display($atts['static'],$future);
	}

	// divine the year and month we want, and also the timestamps that define said month
	function mdp_calendar_calcDates($year='',$month='',$static,$calid)
	{
		global $gmtoffset, $is_dst;
		// Since year and date are now attributes, first check to make sure the user
		// hasn't set them.
		$incoming_calid = gps('calid');
		$incoming_year = ( gps('y') and is_numeric(gps('y')) ) ? (int)gps('y') : '';
		$incoming_month = ( gps('m') and is_numeric(gps('m')) ) ? (int)gps('m') : '';

		if($static) { // if we're static w/o any supplied var's, use the current
			if(!$year) { $year = safe_strftime('%Y'); }
			if(!$month) { $month = safe_strftime('%m'); }
		} else { // otherwise use current only if we arn't passed something else
			if( $calid == $incoming_calid ) { // use incoming
				if(!$year) { $year = ($incoming_year) ? $incoming_year : safe_strftime('%Y'); }
				if(!$month) { $month = ($incoming_month) ? $incoming_month : safe_strftime('%m'); }
			} else { // use current
				if(!$year) { $year = safe_strftime('%Y'); }
				if(!$month) { $month = safe_strftime('%m'); }
			}
		}

        // The times in the DB are stored using the servers localtime, so we don't
        // want to adjust anything since mktime uses the servers localtime.
		$ts_first = mktime(0, 0, 0, $month, 1, $year);
		$ts_last = mktime(23, 59, 59, $month, date('t',$ts_first), $year);

		return array(
			'year' => $year,
			'month' => $month,
			'ts_first' => $ts_first,
			'ts_last' => $ts_last
			);
	}

	// Add in the better SQL operations
	function mdp_calendar_getEvents($dates, $atts)
	{
		extract($dates);
		extract($atts);

		$sql_where = array();
		$sql_select = array();
		$extrasql = array();

		// Filtering options
		if($category) {	$extrasql[] = "AND (Category1='".$category."' OR Category2='".$category."')"; }
		if($section) { $extrasql[] = "AND Section='".$section."'";	}
		if($author) { $extrasql[] = "AND AuthorID='".$author."'"; }

		switch($time) {
			case "any" : /* Don't care about date */ break;
			case "future" : $extrasql[] = " AND Posted > NOW()"; break;
			default : $extrasql[] = " AND Posted <= NOW()"; break; // The past
		}

		$sql_where[] = '(';
		$sql_where[] = "(unix_timestamp(Posted) BETWEEN '".$ts_first."' AND '".$ts_last."')"; //  posted within month
		if( $spandays ) {
			$sql_where[] = "OR (unix_timestamp(Expires) BETWEEN '".$ts_first."' AND '".$ts_last."')"; // expire during month
			$sql_where[] = "OR (unix_timestamp(Posted) < '".$ts_first."' AND unix_timestamp(Expires) > '".$ts_last."')"; // span month
		}
		$sql_where[] = ')';

		$sql_where[] = "AND Status='4'";
		$sql_where[] = join($extrasql,' ');
		$sql_where[] = "ORDER BY Posted ASC";

		$sql_select = '*, unix_timestamp(Posted) as posted';
		if( $spandays )
			$sql_select = $sql_select . ', unix_timestamp(Expires) as expires';

		$info = safe_rows($sql_select,'textpattern',join($sql_where,' '));

		foreach($info as $i) { // Format our events in the proper format for class.Calendar
			if( $spandays ) {
				$day = (int) safe_strftime('%d',$i['posted']);
				$numdays = 0;

				// Adjust the $day and length of the article based on Posted and Expired dates
				if( $i['expires'] ) {
					// Posted and Expired in current month
					if( $i['posted'] >= $ts_first and $i['expires'] <= $ts_last ) {
						$numdays = (int) safe_strftime('%d',$i['expires']) - $day;
					}

					// Posted in current month, Expired in next month
					if( $i['posted'] >= $ts_first and $i['expires'] > $ts_last ) {
						$numdays = 31 - $day; // Go to end of monnth
					}

					// Posted in previous month, Expired in current month
					if( $i['posted'] < $ts_first and $i['expires'] <= $ts_last ) {
						$day = 0; // Start at beginning
						$numdays = (int) safe_strftime('%d',$i['expires']); // From start of month until Expired
					}

					// Posted and Expired in different, non-current month
					if($i['posted'] < $ts_first and $i['expires'] > $ts_last) {
						$day = 0; // Start at beginning
						$numdays = 31; // Cover the whole month
					}
				}

				for($j = 0; $j <= $numdays; $j++) {
					$out[$day+$j][] = array('link' => permlinkurl($i), 'title'=>$i['Title']);
				}
			}
			else {
				$out[(int)safe_strftime('%d',$i['posted'])][] = array('link' => permlinkurl($i), 'title'=>$i['Title']);
			}
		}

		return (isset($out)) ? $out : array(); // don't want to return an empty array
	}

class MDP_Calendar extends Calendar {

	// Override Constructor
	// Allows me to send in an array of events
	/* Events: two dimensional array
		$events[day_number][event_index] =
		'link' => link_to_event,
		'title' => title_of_event
	*/
	function MDP_Calendar($year,$month,$events)
	{
		$this->events = $events;
		$this->Calendar($year,$month);
	}

	// Override dspDayCell to display stuff right
	function dspDayCell($day) {
		$class = (isset($this->events[$day])) ? 'hasarticle' : '';
		if( $this->is_today($day, time()) ) {
			$class = ($class) ? 'hasarticle today' : 'today';
		}

		$c[] = hed($day,4);
		if( isset($this->events[$day]) ) {
			$days_events = $this->events[$day];

			foreach($days_events as $e) {
				extract($e);
				$c[] = doTag('<a title="'.$title.'" href="'.$link.'">'.$title.'</a>','div','permalink');
			}
		}
		return doTag(join('',$c),'td',$class);
	}

	function display($static=false, $future=false) {
		$id = ($this->tableID) ? ' id="'.$this->tableID.'"' : '';
		$summary = ($this->tableSummary) ? ' summary="'.$this->tableSummary.'"' : '';
		$c[] = $this->dspHeader($static, $future);
		$c[] = $this->dspDayNames();
		$c[] = $this->dspDayCells();
		return doTag(join('',$c),'table',$this->tableClass,$summary.$id);
	}

	function dspHeader($static, $future) {
		$nav_back_link = $this->navigation($this->year, $this->month, '-');
		$nav_fwd_link  = $this->navigation($this->year, $this->month, '+');

		$nav_back = (!$static) ? '<a href="'.$nav_back_link.'">&#60;</a>' : '&nbsp;';
		$nav_fwd  = ((!$static and $future) or ($this->month != safe_strftime('%m',time()))) ? '<a href="'.$nav_fwd_link.'">&#62;</a>' : '&nbsp;';

		$c[] = doTag($nav_back,'th');
		$c[] = '<th colspan="5">'.$this->getFullMonthName().sp.$this->year.'</th>';
		$c[] = doTag($nav_fwd,'th');

		return doTag(join('',$c),'tr');
	}

	function navigation($year,$month,$direction)
	{
		global $permlink_mode;
		if($direction == '-') {
			if($month - 1 < 1) {
				$month = 12;
				$year -= 1;
			} else {
				$month -= 1;
			}
		} else {
			if($month + 1 > 12) {
				$month = 1;
				$year += 1;
			} else {
				$month += 1;
			}
		}

		$id = ($this->tableID) ? a.'calid='.$this->tableID : ''; // Allow specific calendar navigation, in case we have more than one per page
		if($permlink_mode != 'messy') {
			return '/?m='.$month.a.'y='.$year.$id;
		} else { // for messy URL's we need to build the entire request string first, then tack on the rest
			$out = makeOut('id','s','c','q','pg','p','month');
			$r = '/?';
			foreach($out as $key => $val ) {
				$r .= ($val) ? "$key=$val".a : '';
			}
			return $r.'m='.$month.a.'y='.$year.$id;
		}
	}
}

class MDP_Small_Calendar extends MDP_Calendar {
	var $section = '';
	var $category = '';
	function MDP_Small_Calendar($year,$month,$events,$section,$category)
	{
		$this->section = $section;
		$this->category = $category;
		$this->MDP_Calendar($year,$month,$events);
	}

	function dspDayCell($day)
	{
		global $permlink_mode;

		$class = (isset($this->events[$day])) ? 'hasarticle' : '';
		if( $this->is_today($day, time()) ) {
			$class = ($class) ? 'hasarticle today' : 'today';
		}
		if( isset($this->events[$day]) ) {

			if( $permlink_mode != 'year_month_day_title' ) {
				$href = ' href="'.hu.'?date='.$this->year.'-'.$this->doubledigit($this->month).'-'.$this->doubledigit($day);
				if($this->section) { $href = $href.a.'s='.$this->section; }
				if($this->category) { $href = $href.a.'c='.$this->category; }
				$href .= '"';
			} else {
				$section = ($this->section) ? $this->section.'/' : '';
				$href = ' href="'.hu.$section.$this->year.'/'.$this->doubledigit($this->month).'/'.$this->doubledigit($day).'"';
			}

			$title = ' title="'.safe_strftime('%x',gmmktime(0,0,0,$this->month,$day+1,$this->year)).'"'; // no idea why $day+1, but otherwise it won't work

			$c[] = doTag($day,'a','',$href.$title);
		} else {
			$c[] = $day;
		}

		return doTag(join('',$c),'td',$class);
	}

	function doubledigit($n)
	{
		if($n < 10) { $n = '0'.(int)$n; }
		return $n;
	}

}

/**
* Basic Calendar data and display
* http://www.oscarm.org/static/pg/calendarClass/
* @author Oscar Merida
* @created Jan 18 2004
* @package  goCoreLib
*/
class Calendar {
	var $year;
	var $month;
	var $monthNameFull;
	var $monthNameBrief;
	var $startDay;
	var $endDay;
	var $firstDayOfWeek = 0;
	var $startOffset = 0;

	function Calendar ( $yr, $mo )
	{
		$this->setYear($yr);
		$this->setMonth($mo);

	    $this->startTime = strtotime( "$yr-$mo-01 00:00" );
	    $this->startDay = safe_strftime('%a', $this->startTime); // Abbreviated weekday
	    $this->endDay = date( 't', $this->startTime ); // # of days in month
	    $this->endTime = strtotime( "$yr-$mo-".$this->endDay." 23:59" );

	    $this->monthNameFull  = strftime( '%B', $this->startTime );
	    $this->monthNameBrief = strftime( '%b', $this->startTime );

		$this->setDayNameFormat('FULL');
		$this->setFirstDayOfWeek(0);
		$this->setTableID('');
		$this->setTableClass('');
	}

	function setTableSummary($summary) { $this->tableSummary = $summary; }
	function getStartTime() { return $this->startTime; }
	function getEndTime() { return $this->endTime; }
	function getYear() { return $this->year; }
	function getFullMonthName() { return $this->monthNameFull; }
	function getBriefMonthName() { return $this->monthNameBrief; }
	function setTableID($id) { $this->tableID = $id; }
	function setTableClass($class) { $this->tableClass = $class; }
	function setYear($year){ $this->year = $year; }
	function setMonth($month) { $this->month = (int)$month; }
	function setFirstDayOfWeek($d)
	{
	   	$this->firstDayOfWeek = ((int)$d <= 6 and (int)$d >= 0) ? (int)$d : 0;

		$this->startOffset = strftime( '%w', $this->startTime ) - $this->firstDayOfWeek;
	    if ( $this->startOffset < 0 ) {
			$this->startOffset = 7 - abs($this->startOffset);
		}
	}

	/**
	* Any valid PHP date
	* Shortcuts: FULL == 'A', ABBR = 'a'
	*/
	function setDayNameFormat($f) {
		if($f == 'FULL') {
			$this->dayNameFmt = '%A';
		} else if ($f == 'ABBR' ) {
			$this->dayNameFmt = '%a';
		} else {
			$this->dayNameFmt = $f;
		}
	}

	/**
	* Returns markup for displaying the calendar.
	* @return
	* @public
	*/
	function display ( )
	{
		$id = ($this->tableID) ? ' id="'.$this->tableID.'"' : '';
		$summary = ($this->tableSummary) ? ' summary="'.$this->tableSummary.'"' : '';
		$c[] = '<table'.$id.$summary.'>';
		$c[] = $this->dspDayNames();
		$c[] = $this->dspDayCells();
		$c[] = '</table>';
		return join('',$c);
	}
	// ==== end display ================================================
	/**
	* Displays the row of day names.
	* @return string
	* @private
*/
	function dspDayNames ( )
	{
		// This is done to make sure Sunday is always the first day of our array
		// Unix time gets a little funky at the beginning depending upon your timezone.
		$serveroffset = gmmktime(0,0,0) - mktime(0,0,0);
		$start = ($serveroffset < 0) ? 4 : 3;
		$end = $start + 7;
		for($i=$start; $i<$end; $i++) {
			// Remove the tz_offset because safe_strftime adds it, but we get locale support
			$names[] = ucfirst(safe_strftime($this->dayNameFmt, 86400*$i - tz_offset() ));
		}
		# Here's Adi's patch to fix the off-by-one date...
		$names = array();
		$sunday = strtotime("1970-01-04");
		for($i=0; $i<7; $i++) $names[] = ucfirst(safe_strftime($this->dayNameFmt,$sunday + 86400 * $i));
		# End of Adi's patch.

		$c[] = '<tr>';

		$i = $this->firstDayOfWeek;
		$j = 0; // count number of days outputted
		$end = false;

		for($j = 0; $j<=6; $j++, $i++) {
			if($i == 7) { $i = 0; }
			$c[] = '<th>'.$names[$i]."</th>";
		}

	    $c[] = '</tr>';
	    return join('',$c);
	}
	// ==== end dspDayNames ================================================
	/**
	* Displays all day cells for the month
	*
	* @return string
	* @private
*/
	function dspDayCells ( )
	{
	    $i = 0; // cell counter

		$c[] = '<tr>';

	    // first display empty cells based on what weekday the month starts in
	    for( $j=0; $j<$this->startOffset; $j++ )	{
	        $i++;
	        $c[] = '<td class="invalidDay">&nbsp;</td>';
	    } // end offset cells

	    // write out the rest of the days, at each sunday, start a new row.
	    for( $d=1; $d<=$this->endDay; $d++ ) {
	        $i++;
	        $c[] = $this->dspDayCell( $d );
			if ( $i%7 == 0 ) { $c[] = '</tr>'; }
	        if ( $d<$this->endDay && $i%7 == 0 ) { $c[] = '<tr>'; }
	    }
	    // fill in the final row
	    $left = 7 - ( $i%7 );
	    if ( $left < 7)	{
	        for ( $j=0; $j<$left; $j++ )	{
	          $c[] = '<td class="invalidDay">&nbsp;</td>';
	        }
	        $c[] = "\n\t</tr>";
	    }

	    return join('',$c);
	}

	function dspDayCell ( $day ) {
	    return '<td>'.$day.'</td>';
	}

	function is_today($day, $ts) {
		if( 	$this->month == safe_strftime('%m' ,time())
			and $day == safe_strftime('%d',time())
			and $this->year == safe_strftime('%Y', time()) )
			return 1;
		else
			return 0;
	}

} // end class
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
<h1>mdp Calendar</h1>

<p>This plugin allows you to create a calendar of live articles within a certain month. The calendar itself is a small table of dates and links to the articles. Different months can be navigated to by the user, or only a particular month can be shown.</p>

<h2>Usage</h2>
<p>To place a calendar, put a <txp:mdp_calendar_[small/large] [options] /> tag where you wish it to appear. The options available are below.</p>

<h2>Options</h2>
<p>All of this options are attributes of the <txp:mdp_calendar />  tag. To enable any option that requires an integer, make the option equal to "1".</p>
<dl>
<dt>category="string"</dt>
<dd>Only articles with this category will be displayed. Only one category can be chosen currently</dd>

<dt>section="string"</dt>
<dd>Only articles from this section will be displayed.</dd>

<dt>author="string"</dt>
<dd>Only articles from this author will be displayed.</dd>

<dt>id="string"</dt>
<dd>This option allows you to change the id of the calendar's table. If you need to use multiple calendars, and allow navigation, on the same page, set a different ID for each calendar to allow independent navigation.</dd>

<dt>class="string"</dt>
<dd>This option allows you to change the class attribute of the calendar's table.</dd>

<dt>month="integer"</dt>
<dd>The month for the calendar to display. Use 1-12 format (1 being January, 12 being December). Use of the calendar navigation will override this option. If a year is not also defined, the current year is used.</dd>

<dt>year="integer"</dt>
<dd>The year for the calendar to display. Use of the calendar navigation will override this option. If a month is not also defined, the current year is used.</dd>

<dt>time="any|future|past"</dt>
<dd>Determines which articles are displayed.</dd>

<dt>static="integer"</dt>
<dd>Enabling this option removes the ability for the user to navigate to different months. Combine with the "month" and "year" options to display a month other than the current month.</dd>

<dt>dayformat="string"</dt>
<dd>Changes how the header day names are displayed. ABBR is abbreviated (Mon), FULL is the full name (Monday). Also accepts any strftime format.</dd>

<dt>firstday="integer"</dt>
<dd>Changes the first day of the week. Sunday is a &quot;0&quot;, Saturday is &quot;6&quot;. Default is Sunday</dd>

<dt>spandays="boolean"</dt>
<dd>For those using Textpattern with the article expiration code, this will display a link to the article for every day that it active.</dd>

</dl>

<h2>Example</h2>
<p>&lt;txp:mdp_calendar_large category="fun" section="blog" /&gt;</p>

<h2>Styling</h2>
<p>The appereance of the calendar should be styled using CSS, the important information is as follows:</p>
<dl>
<dt>&lt;table id="[option]"&gt;</dt>
<dd>Use this attribute to style everything pertaining only to a specific calendar.</dd>

<dt>&lt;table class="[option]"&gt;</dt>
<dd>The generic calendar class, apply styles to all calendars on your site easily.</dd>

<dt>&lt;div class="permalink"&gt;</dt>
<dd>Permalink to an article, not used in smallformat.</dd>

<dt>&lt;td class="today"&gt;</dt>
<dd>This is the table cell that contains the current day.</dd>

<dt>&lt;td class="hasarticle"&gt;</dt>
<dd>This class is present on days that have one or more articles.</dd>

<dt>&lt;td class="invalidDay"&gt;</dt>
<dd>These are days not part of the current month, but are there to make the calendar square.</dd>
</dl>
# --- END PLUGIN HELP ---
-->
<?php
}
?>